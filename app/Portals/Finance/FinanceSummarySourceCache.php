<?php

namespace App\Portals\Finance;

use Illuminate\Support\Facades\Cache;
use Throwable;

/** Reuses canonical channel rows across report filters without changing financial calculations. */
class FinanceSummarySourceCache
{
    private const ROW_FILTERS = ['account_key', 'owner', 'team', 'subscription', 'payment_method', 'payment_channel', 'currency'];

    public function __construct(private readonly FinanceSummaryDataVersion $versions) {}

    public function stream(string $channel, array $filters, bool $transactions, callable $build, ?callable $options = null): iterable
    {
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
        $version = $this->versions->current($channel);
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
        $scope = hash('sha256', json_encode([4, $channel, $filters], JSON_THROW_ON_ERROR));
        $directory = rtrim(config('cache.stores.file.path'), '/\\').'/finance-summary-sources/'.$scope;
        $path = $directory.'/v-'.$version.'.jsonl';
        if (is_file($path)) {
            yield from $this->read($path, $build, $constraints, $options);
            return;
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
            if ($cacheable && $version === $this->versions->current($channel)) {
                // A cold, unfiltered report does not use offset indexes. Persist
                // only the small option list here; filter indexes are generated
                // lazily for the fields that are actually requested.
                $this->publishIndex($path, $index, $offset);
                if (! @rename($temporary, $path)) return;
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
