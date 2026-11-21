<?php

namespace App\Portals\Finance;

use Illuminate\Support\Facades\Cache;
use Throwable;

/** Reuses canonical channel rows across report filters without changing financial calculations. */
class FinanceSummarySourceCache
{
    private static function schemaVersion(string $channel): int
    {
        return FinanceSettlementService::summarySourceSchemaVersion($channel);
    }

    private const ROW_FILTERS = ['account_key', 'owner', 'team', 'subscription', 'payment_method', 'payment_channel', 'currency'];

    public function __construct(private readonly FinanceSummaryDataVersion $versions) {}

    public static function directory(): string
    {
        return rtrim(config('finance.summaries.source_cache_path') ?: rtrim(config('cache.stores.file.path'), '/\\').'/finance-summary-sources', '/\\');
    }

    public static function sharedFilters(array $filters): array
    {
        if (empty($filters['summary_shared_source'])) return $filters;
        unset($filters['summary_settlement_only'], $filters['summary_account_counts'], $filters['summary_native_settlement']);
        $filters['logistics_costs'] = true;
        return $filters;
    }

    public static function nativeRow(array $row): array
    {
        $row = array_replace($row, $row['_summary_native_values'] ?? []);
        $row['_summary_settlement_currency'] = $row['_summary_native_currency'] ?? 'USD';
        $row['_summary_settlement_currency_version'] = 1;
        return $row;
    }

    public function ready(string $channel, array $filters): bool
    {
        $filters = self::sharedFilters($filters);
        $version = $this->versions->forRange($channel, $filters);
        if ($version === null) return false;
        unset($filters['team'], $filters['subscription'], $filters['summary_currency'], $filters['summary_row_filters']);
        $filters = array_filter($filters, fn ($value) => $value !== null && $value !== '');
        if ($channel === 'paypal') unset($filters['include_unmatched_fees']);
        ksort($filters);
        $scope = hash('sha256', json_encode([self::schemaVersion($channel), $channel, $filters], JSON_THROW_ON_ERROR));
        $path = self::directory().'/'.$scope.'/v-'.$version.'.jsonl';
        return is_file($path) || $this->preparedParts($channel, $filters) !== null || $this->compatibleParts($channel, $filters) !== null;
    }

    public function stream(string $channel, array $filters, bool $transactions, callable $build, ?callable $options = null): iterable
    {
        $native = ! empty($filters['summary_shared_source']) && ! empty($filters['summary_native_settlement']);
        $filters = self::sharedFilters($filters);
        $team = $filters['team'] ?? null;
        $subscription = $filters['subscription'] ?? null;
        $currency = $filters['summary_currency'] ?? null;
        $rowFilters = array_filter(array_intersect_key($filters['summary_row_filters'] ?? [], array_flip(self::ROW_FILTERS)), fn ($value) => filled($value));
        unset($filters['team'], $filters['subscription'], $filters['summary_currency'], $filters['summary_row_filters']);
        $constraints = array_filter(['team' => $team, 'subscription' => filled($subscription) ? FinanceSettlementService::subscriptionLabel($subscription) : null, 'currency' => $currency], fn ($value) => filled($value));
        foreach ($rowFilters as $field => $value) {
            // These row filters read the same value as the canonical indexes.
            // Reusing them avoids storing a second full set of offsets.
            $constraints[in_array($field, ['subscription', 'currency'], true) ? $field : 'row_'.$field] = $value;
        }
        foreach ($this->canonicalStream($channel, $filters, $transactions, fn () => $build($filters), $constraints, $options) as $row) {
            if ($native) $row = self::nativeRow($row);
            if (! empty($filters['summary_payment_channels']) && ! in_array($row['payment_channel'] ?? null, $filters['summary_payment_channels'], true)) continue;
            if (filled($team) && ($row['_summary_team'] ?? $row['team']) !== $team
                || filled($subscription) && $row['subscription'] !== FinanceSettlementService::subscriptionLabel($subscription)
                || filled($currency) && $row['currency'] !== $currency) {
                continue;
            }
            foreach ($rowFilters as $field => $value) {
                if (($row[$field] ?? '') !== $value) continue 2;
            }
            yield $row;
        }
    }

    private function canonicalStream(string $channel, array $filters, bool $transactions, callable $build, array $constraints, ?callable $options): iterable
    {
        $version = $this->versions->forRange($channel, $filters);
        if ($version === null) {
            yield from $this->uncached($build, $options);
            return;
        }
        $filters = array_filter($filters, fn ($value) => $value !== null && $value !== '');
        ksort($filters);
        // Canonical rows now carry grouping fields for both consumers. Reuse the
        // same scan for totals and failure allocation instead of recalculating it.
        if ($channel === 'paypal') {
            unset($filters['include_unmatched_fees']);
        }
        $scope = hash('sha256', json_encode([self::schemaVersion($channel), $channel, $filters], JSON_THROW_ON_ERROR));
        $directory = self::directory().'/'.$scope;
        $path = $directory.'/v-'.$version.'.jsonl';
        if (is_file($path)) {
            yield from $this->read($path, $build, $constraints, $options);
            return;
        }
        $parts = $this->preparedParts($channel, $filters);
        if ($parts !== null) {
            foreach ($parts as $part) {
                yield from $this->read($part, static fn () => throw new \RuntimeException('已计算的源数据损坏，请重试查询。'), $constraints, $options);
            }
            return;
        }
        if (($compatible = $this->compatibleParts($channel, $filters)) !== null) {
            // Adopt already calculated rows with identical dates, timezone and
            // amount flags. Publish the selected channel under its own revision
            // so a later edit to another channel cannot evict this selection.
            $selected = $filters['summary_payment_channels'] ?? [];
            $build = function () use ($compatible, $selected) {
                foreach ($compatible as $part) foreach ($this->read($part,
                    static fn () => throw new \RuntimeException('已计算的源数据损坏，请重试查询。'), [], null) as $row) {
                    if ($selected === [] || in_array($row['payment_channel'] ?? null, $selected, true)) yield $row;
                }
            };
        }
        try {
            $lock = Cache::store('file')->lock('finance:summary-source:'.hash('sha256', $directory.':'.$version), 900);
            $acquired = $lock->get();
        } catch (Throwable) {
            $acquired = false;
        }
        // Never wait behind another builder. If a worker died with a lease still
        // present, this complete, version-checked build may also publish atomically.
        // Otherwise a fatal error forced every reader to recalculate for 15 minutes.
        $temporary = null;
        $file = null;
        try {
            if (is_file($path)) {
                yield from $this->read($path, $build, $constraints, $options);
                return;
            }
            if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
                $this->storageFailure($channel, $filters);
                yield from $this->uncached($build, $options);
                return;
            }
            $temporary = $directory.'/'.bin2hex(random_bytes(12)).'.tmp';
            $file = @fopen($temporary, 'xb');
            $cacheable = $file !== false;
            $index = [];
            $buffer = '';
            $offset = 0;
            foreach ($build() as $row) {
                if ($options !== null) $this->indexOptions($index, $row);
                if ($cacheable) {
                    try {
                        $line = json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)."\n";
                        $offset += strlen($line);
                        $buffer .= $line;
                        if (strlen($buffer) >= 1024 * 1024) {
                            $cacheable = @fwrite($file, $buffer) === strlen($buffer);
                            $buffer = '';
                        }
                    } catch (\JsonException) {
                        $cacheable = false;
                    }
                }
                yield $row;
            }
            if ($cacheable && $buffer !== '') $cacheable = @fwrite($file, $buffer) === strlen($buffer);
            unset($buffer);
            if ($options !== null) $options($index['_options'] ?? []);
            if (is_resource($file)) {
                fclose($file);
                $file = null;
            }
            if ($cacheable && $version === $this->versions->forRange($channel, $filters)) {
                // A cold, unfiltered report does not use offset indexes. Persist
                // only the small option list here; filter indexes are generated
                // lazily for the fields that are actually requested.
                $this->publishIndex($path, $index, $offset);
                if (! @rename($temporary, $path)) {
                    if (! is_file($path)) $this->storageFailure($channel, $filters);
                    return;
                }
                $temporary = null;
                // Bound retained disk use; an in-flight reader may still have the old file open.
                $files = glob($directory.'/v-*.jsonl') ?: [];
                usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));
                foreach (array_slice($files, 2) as $old) {
                    if (@unlink($old)) {
                        @unlink($old.'.index.json');
                        foreach (glob($old.'.*.idx') ?: [] as $oldIndex) @unlink($oldIndex);
                    }
                }
            }
            if (! $cacheable) $this->storageFailure($channel, $filters);
        } finally {
            if (is_resource($file)) {
                fclose($file);
            }
            if ($temporary !== null) {
                @unlink($temporary);
            }
            if ($acquired) $lock->release();
        }
    }

    private function preparedParts(string $channel, array $filters): ?array
    {
        if (empty($filters['from']) || empty($filters['to'])) return null;
        $start = \Carbon\CarbonImmutable::parse($filters['from'])->startOfMonth();
        $end = $start->endOfMonth();
        if ($filters['from'] !== $start->toDateString() || $filters['to'] !== $end->toDateString()) return null;
        $parts = [];
        for ($day = $start; $day <= $end; $day = $day->addDays(7)) {
            $chunk = [...$filters, 'from' => $day->toDateString(), 'to' => min($day->addDays(6)->toDateString(), $end->toDateString())];
            ksort($chunk);
            $partVersion = $this->versions->forRange($channel, $chunk);
            if ($partVersion === null) return null;
            $scope = hash('sha256', json_encode([self::schemaVersion($channel), $channel, $chunk], JSON_THROW_ON_ERROR));
            $path = self::directory().'/'.$scope.'/v-'.$partVersion.'.jsonl';
            if (! is_file($path)) return null;
            $parts[] = $path;
        }
        return array_reverse($parts);
    }

    private function existingParts(string $channel, array $filters): ?array
    {
        ksort($filters);
        $version = $this->versions->forRange($channel, $filters);
        if ($version === null) return null;
        $scope = hash('sha256', json_encode([self::schemaVersion($channel), $channel, $filters], JSON_THROW_ON_ERROR));
        $path = self::directory().'/'.$scope.'/v-'.$version.'.jsonl';
        return is_file($path) ? [$path] : $this->preparedParts($channel, $filters);
    }

    /** Reuse channel selections only when all other calculation inputs match. */
    private function compatibleParts(string $channel, array $filters): ?array
    {
        if ($channel !== 'credit_card') return null;
        $selected = $filters['summary_payment_channels'] ?? [];
        $all = ['xuzong1', 'xuzong2', 'ye', 'worldpay'];
        if (array_diff($selected, $all) !== []) return null;
        if ($parts = $this->supersetParts($channel, $filters)) return $parts;
        $parts = [];
        $groups = [['xuzong1', 'xuzong2'], ['ye'], ['worldpay']];
        $needed = $selected ?: $all;
        foreach ($groups as $group) {
            if (array_intersect($needed, $group) === []) continue;
            $candidate = [...$filters, 'summary_payment_channels' => $group];
            $available = $this->existingParts($channel, $candidate) ?? $this->supersetParts($channel, $candidate);
            if ($available === null) return null;
            array_push($parts, ...$available);
        }
        return $parts ? array_values(array_unique($parts)) : null;
    }

    private function supersetParts(string $channel, array $filters): ?array
    {
        $selected = $filters['summary_payment_channels'] ?? [];
        if ($selected === []) return null;
        $all = ['xuzong1', 'xuzong2', 'ye', 'worldpay'];
        foreach ([['xuzong1', 'xuzong2'], ['ye', 'worldpay'], $all] as $superset) {
            if (array_diff($selected, $superset) !== [] || (count($selected) === count($superset) && $superset !== $all)) continue;
            $candidate = $filters;
            if ($superset === $all) unset($candidate['summary_payment_channels']);
            else $candidate['summary_payment_channels'] = $superset;
            if ($parts = $this->existingParts($channel, $candidate)) return $parts;
        }
        return null;
    }

    private function storageFailure(string $channel, array $filters): void
    {
        \Illuminate\Support\Facades\Log::warning('Finance source calculations could not be persisted; later queries will rebuild them.', [
            'channel' => $channel, 'from' => $filters['from'] ?? null, 'to' => $filters['to'] ?? null,
        ]);
    }

    private function uncached(callable $build, ?callable $options): iterable
    {
        $index = [];
        foreach ($build() as $row) {
            if ($options !== null) $this->indexOptions($index, $row);
            yield $row;
        }
        if ($options !== null) $options($index['_options'] ?? []);
    }

    private function read(string $path, callable $fallback, array $constraints, ?callable $options): iterable
    {
        $file = @fopen($path, 'rb');
        if ($file === false) {
            yield from $this->uncached($fallback, $options);
            return;
        }
        try {
            $size = fstat($file)['size'];
            $needsIndex = $constraints !== [] || $options !== null;
            $savedIndex = $needsIndex ? $this->savedIndex($path, $size) : null;
            if ($options !== null && $savedIndex !== null) $options($savedIndex['options']);
            $offsets = $this->indexedOffsets($path, $savedIndex, $constraints);
            if ($offsets !== null) {
                for ($position = 0; $position < strlen($offsets); $position += 16) {
                    $entry = unpack('Joffset/Jlength', $offsets, $position);
                    if ($entry['offset'] < 0 || $entry['length'] < 1 || $entry['offset'] + $entry['length'] > $size) {
                        throw new \RuntimeException('Invalid finance source index.');
                    }
                    fseek($file, $entry['offset']);
                    yield json_decode(stream_get_contents($file, $entry['length']), true, 512, JSON_THROW_ON_ERROR);
                }
                return;
            }
            $index = [];
            $missingFields = $constraints === [] ? [] : array_values(array_filter(
                array_keys($constraints),
                fn ($field) => ! isset($savedIndex['fields'][$field])
            ));
            $buildIndex = $missingFields !== [];
            $collectOptions = $options !== null && $savedIndex === null;
            $offset = 0;
            while (($line = fgets($file)) !== false) {
                $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                if ($buildIndex) {
                    $this->indexRow($index, $row, $offset, strlen($line), $missingFields);
                } elseif ($collectOptions) {
                    $this->indexOptions($index, $row);
                }
                $offset += strlen($line);
                yield $row;
            }
            if ($buildIndex || $collectOptions) {
                $this->publishIndex($path, $index, $size);
                if ($options !== null) $options($index['_options'] ?? []);
            }
        } finally {
            fclose($file);
        }
    }

    private function indexRow(array &$index, array $row, int $offset, int $length, ?array $onlyFields = null): void
    {
        $fields = ['team' => $row['_summary_team'] ?? $row['team'] ?? '', 'subscription' => $row['subscription'] ?? '', 'currency' => $row['currency'] ?? ''];
        foreach (array_diff(self::ROW_FILTERS, ['subscription', 'currency']) as $field) $fields['row_'.$field] = $row[$field] ?? '';
        if ($onlyFields !== null) $fields = array_intersect_key($fields, array_flip($onlyFields));
        foreach ($fields as $field => $value) {
            $index[$field][(string) $value] ??= '';
            $index[$field][(string) $value] .= pack('J2', $offset, $length);
        }
        $this->indexOptions($index, $row);
    }

    private function indexOptions(array &$index, array $row): void
    {
        $platform = $row['payment_channel'] ?? '';
        foreach (['account_key', 'owner', 'team', 'subscription', 'payment_method'] as $field) {
            if (filled($row[$field] ?? null)) {
                $index['_options'][$platform][$field][(string) $row[$field]] = $field === 'account_key' ? ($row['account'] ?? $row[$field]) : $row[$field];
            }
        }
    }

    private function publishIndex(string $path, array $index, int $size): void
    {
        $existing = is_file($path) ? $this->savedIndex($path, $size) : null;
        $options = $index['_options'] ?? $existing['options'] ?? [];
        unset($index['_options']);
        // Keep offsets binary, with a small manifest. Encoding/decoding every
        // field's offsets as one JSON document exhausted 128 MB PHP workers.
        $temporary = $path.'.'.bin2hex(random_bytes(8)).'.index.tmp';
        $fields = $existing['fields'] ?? [];
        $file = null;
        $part = null;
        try {
            foreach ($index as $field => $values) {
                $part = $temporary.'.'.$field;
                $file = @fopen($part, 'xb');
                if ($file === false) return;
                $offset = 0;
                foreach ($values as $value => $positions) {
                    $length = strlen($positions);
                    if (@fwrite($file, $positions) !== $length) return;
                    $fields[$field][$value] = [$offset, $length];
                    $offset += $length;
                }
                fclose($file);
                $file = null;
                if (! @rename($part, $path.'.'.$field.'.idx')) return;
                $part = null;
            }
            if (@file_put_contents($temporary, json_encode(['format' => 3, 'size' => $size, 'fields' => $fields, 'options' => $options], JSON_THROW_ON_ERROR)) !== false) {
                @rename($temporary, $path.'.index.json');
            }
        } finally {
            if (is_resource($file)) fclose($file);
            if ($part !== null && is_file($part)) @unlink($part);
            if (is_file($temporary)) @unlink($temporary);
        }
    }

    private function savedIndex(string $path, int $size): ?array
    {
        if (! is_file($path.'.index.json')) return null;
        try {
            $index = json_decode(file_get_contents($path.'.index.json'), true, 512, JSON_THROW_ON_ERROR);
            if (($index['size'] ?? null) !== $size || ($index['format'] ?? null) !== 3
                || ! is_array($index['fields'] ?? null) || ! is_array($index['options'] ?? null)) return null;
            foreach (array_keys($index['fields']) as $field) {
                if (! in_array($field, ['team', 'subscription', 'currency', ...array_map(fn ($name) => 'row_'.$name, array_diff(self::ROW_FILTERS, ['subscription', 'currency']))], true)
                    || ! is_file($path.'.'.$field.'.idx')) return null;
            }
            return $index;
        } catch (\JsonException) {
            return null;
        }
    }

    private function indexedOffsets(string $path, ?array $index, array $constraints): ?string
    {
        if ($index === null || $constraints === []) return null;
        $selected = null;
        $selectedField = null;
        foreach ($constraints as $field => $value) {
            if (! isset($index['fields'][$field])) continue;
            $positions = $index['fields'][$field][(string) $value] ?? [0, 0];
            if (! is_array($positions) || count($positions) !== 2
                || ! is_int($positions[0]) || ! is_int($positions[1])
                || $positions[0] < 0 || $positions[1] < 0 || $positions[1] % 16 !== 0) return null;
            if ($selected === null || $positions[1] < $selected[1]) {
                $selected = $positions;
                $selectedField = $field;
            }
        }
        if ($selected === null) return null;
        if ($selected[1] === 0) return '';
        $file = @fopen($path.'.'.$selectedField.'.idx', 'rb');
        if ($file === false) return null;
        try {
            if ($selected[0] + $selected[1] > fstat($file)['size'] || fseek($file, $selected[0]) !== 0) return null;
            $positions = stream_get_contents($file, $selected[1]);
            return strlen($positions) === $selected[1] ? $positions : null;
        } finally {
            fclose($file);
        }
    }
}
