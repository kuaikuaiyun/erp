<?php

namespace App\Console\Commands;

use App\Models\AdminUser;
use App\Support\AikaipinLegacySqlDumpReader;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ImportAikaipinLegacySelectionProposals extends Command
{
    protected $signature = 'aikaipin:import-legacy-selection-proposals
        {file}
        {--limit=5}
        {--user-id=24}
        {--dry-run}';

    protected $description = '';

    public function configure(): void
    {
        parent::configure();

        $this->setDescription(__('aikaipin.selection_import.command_description'));
    }

    public function handle(AikaipinLegacySqlDumpReader $dumpReader): int
    {
        try {
            $limit = max(1, min(100, (int) $this->option('limit')));
            $userId = max(1, (int) $this->option('user-id'));
            $sourcePath = $dumpReader->resolvePath((string) $this->argument('file'));
            $this->ensureTablesAreReady();

            $actor = AdminUser::query()->find($userId);
            if ($actor === null) {
                throw new \RuntimeException(__('aikaipin.import.errors.user_not_found', ['id' => $userId]));
            }

            $sourceRows = $dumpReader->readTableRows(
                $dumpReader->readSqlDump($sourcePath),
                'product_development_requests',
            );
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $actorName = trim((string) ($actor->name ?: $actor->username));
        $candidates = [];
        $skippedDeleted = 0;

        try {
            foreach ($sourceRows as $sourceRow) {
                $sourceId = (int) ($sourceRow['id'] ?? 0);
                if ($sourceId <= 0) {
                    continue;
                }
                if (($sourceRow['deleted_at'] ?? null) !== null) {
                    $skippedDeleted++;

                    continue;
                }

                $candidates[] = $this->proposalRecord($sourceRow, $actor, $actorName);
            }
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        usort($candidates, static fn (array $left, array $right): int => $left['proposal']['id'] <=> $right['proposal']['id']);
        $existingIds = $this->existingProposalIds($candidates);
        $existingCodes = $this->existingProposalCodes($candidates);
        $available = [];
        $skippedConflicts = 0;

        foreach ($candidates as $candidate) {
            $proposal = $candidate['proposal'];
            if (isset($existingIds[(int) $proposal['id']]) || isset($existingCodes[(string) $proposal['proposal_code']])) {
                $skippedConflicts++;

                continue;
            }

            $available[] = $candidate;
        }
        $selected = array_slice($available, 0, $limit);

        $this->newLine();
        $this->info(__('aikaipin.selection_import.preview_title'));
        $this->line(__('aikaipin.selection_import.source_file', ['path' => $sourcePath]));
        $this->line(__('aikaipin.selection_import.assigned_user', [
            'name' => $actorName !== '' ? $actorName : (string) $actor->getKey(),
            'id' => $actor->getKey(),
        ]));
        $this->table(
            [
                __('aikaipin.selection_import.headers.id'),
                __('aikaipin.selection_import.headers.code'),
                __('aikaipin.selection_import.headers.name'),
                __('aikaipin.selection_import.headers.specifications'),
                __('aikaipin.selection_import.headers.attributes'),
                __('aikaipin.selection_import.headers.images'),
            ],
            array_map(static fn (array $record): array => [
                $record['proposal']['id'],
                $record['proposal']['proposal_code'],
                $record['proposal']['product_name'],
                count(array_filter($record['tags'], static fn (array $tag): bool => $tag['type'] === 'specification')),
                count(array_filter($record['tags'], static fn (array $tag): bool => $tag['type'] === 'attribute')),
                count($record['images']),
            ], $selected),
        );
        $this->line(__('aikaipin.selection_import.summary', [
            'source' => count($sourceRows),
            'eligible' => count($candidates),
            'selected' => count($selected),
            'conflicts' => $skippedConflicts,
            'deleted' => $skippedDeleted,
        ]));

        if ($selected === []) {
            $this->warn(__('aikaipin.selection_import.no_records'));

            return self::SUCCESS;
        }
        if ((bool) $this->option('dry-run')) {
            $this->info(__('aikaipin.selection_import.dry_run_complete'));

            return self::SUCCESS;
        }

        $connection = config('admin.database.connection') ?: config('database.default');
        try {
            DB::connection($connection)->transaction(function () use ($connection, $selected): void {
                foreach ($selected as $record) {
                    DB::connection($connection)->table('aikaipin_selection_proposals')->insert($record['proposal']);
                    if ($record['tags'] !== []) {
                        DB::connection($connection)->table('aikaipin_selection_proposal_tags')->insert($record['tags']);
                    }
                    if ($record['images'] !== []) {
                        DB::connection($connection)->table('aikaipin_selection_proposal_images')->insert($record['images']);
                    }
                    $this->syncCodeSequence(
                        $connection,
                        $record['period'],
                        (int) $record['proposal']['id'],
                        (string) $record['proposal']['updated_at'],
                    );
                }
            });
        } catch (\Throwable $exception) {
            report($exception);
            $this->error(__('aikaipin.selection_import.errors.save_failed'));

            return self::FAILURE;
        }

        try {
            $nextId = max(array_map(static fn (array $record): int => (int) $record['proposal']['id'], $selected)) + 1;
            DB::connection($connection)->unprepared('ALTER TABLE aikaipin_selection_proposals AUTO_INCREMENT = '.(int) $nextId);
        } catch (\Throwable $exception) {
            report($exception);
            $this->warn(__('aikaipin.selection_import.auto_increment_warning'));
        }

        $this->info(__('aikaipin.selection_import.complete', ['count' => count($selected)]));

        return self::SUCCESS;
    }

    private function ensureTablesAreReady(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);
        $requiredColumns = [
            'id', 'proposal_code', 'product_name', 'link_1688', 'competitor_url', 'remark',
            'created_by', 'created_by_name', 'updated_by', 'updated_by_name', 'created_at', 'updated_at',
        ];

        if (!$schema->hasTable('aikaipin_selection_proposals')
            || !$schema->hasColumns('aikaipin_selection_proposals', $requiredColumns)
            || !$schema->hasTable('aikaipin_selection_proposal_tags')
            || !$schema->hasTable('aikaipin_selection_proposal_images')
            || !$schema->hasTable('aikaipin_selection_proposal_code_sequences')) {
            throw new \RuntimeException(__('aikaipin.selection_import.errors.migration_required'));
        }
    }

    /** @param array<string,mixed> $sourceRow
     *  @return array{proposal:array<string,mixed>,tags:list<array<string,mixed>>,images:list<array<string,mixed>>,period:string}
     */
    private function proposalRecord(array $sourceRow, AdminUser $actor, string $actorName): array
    {
        $sourceId = (int) $sourceRow['id'];
        $productName = trim((string) ($sourceRow['product_name'] ?? ''));
        if (mb_strlen($productName) > 255) {
            throw new \RuntimeException(__('aikaipin.selection_import.errors.product_name_too_long', ['id' => $sourceId]));
        }
        $productName = $productName !== '' ? $productName : 'SPR-'.$sourceId;
        $createdAt = $this->timestampValue($sourceRow['created_at'] ?? null) ?: now()->toDateTimeString();
        $updatedAt = $this->timestampValue($sourceRow['updated_at'] ?? null) ?: $createdAt;
        $period = substr(str_replace('-', '', $createdAt), 0, 6);
        $proposal = [
            'id' => $sourceId,
            'proposal_code' => sprintf('SPR-%s-%06d', $period, $sourceId),
            'product_name' => $productName,
            'link_1688' => $this->nullableString($sourceRow['source_1688_url'] ?? null),
            'competitor_url' => $this->nullableString($sourceRow['competitor_url'] ?? null),
            'remark' => $this->nullableString($sourceRow['remarks'] ?? null),
            'created_by' => (int) $actor->getKey(),
            'created_by_name' => $actorName !== '' ? $actorName : null,
            'updated_by' => (int) $actor->getKey(),
            'updated_by_name' => $actorName !== '' ? $actorName : null,
            'withdrawn_at' => null,
            'withdrawn_by' => null,
            'withdrawn_by_name' => null,
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
        ];

        return [
            'proposal' => $proposal,
            'tags' => [
                ...$this->tagRecords($sourceId, $sourceRow['specifications'] ?? null, 'specification', $createdAt, $updatedAt),
                ...$this->tagRecords($sourceId, $sourceRow['optional_attributes'] ?? null, 'attribute', $createdAt, $updatedAt),
            ],
            'images' => $this->imageRecords($sourceId, $sourceRow['image_paths'] ?? null, $createdAt, $updatedAt),
            'period' => $period,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function tagRecords(int $proposalId, mixed $value, string $type, string $createdAt, string $updatedAt): array
    {
        $records = [];
        foreach ($this->normalizedStringValues($value) as $sort => $item) {
            if (mb_strlen($item) > 80) {
                throw new \RuntimeException(__('aikaipin.selection_import.errors.tag_value_too_long', [
                    'id' => $proposalId,
                    'field' => $type === 'specification'
                        ? __('aikaipin.selection_proposal.fields.specifications')
                        : __('aikaipin.selection_proposal.fields.attributes'),
                ]));
            }
            $records[] = [
                'proposal_id' => $proposalId,
                'type' => $type,
                'value' => $item,
                'sort' => $sort,
                'created_at' => $createdAt,
                'updated_at' => $updatedAt,
            ];
        }

        return $records;
    }

    /** @return list<array<string,mixed>> */
    private function imageRecords(int $proposalId, mixed $value, string $createdAt, string $updatedAt): array
    {
        $records = [];
        foreach ($this->decodedArray($value) as $imagePath) {
            if (!is_string($imagePath)) {
                continue;
            }
            $relativePath = $this->legacyRelativePath($imagePath, 'product-development-images');
            if ($relativePath === null) {
                continue;
            }
            $records[] = [
                'proposal_id' => $proposalId,
                'disk' => 'local',
                'path' => 'portals/aikaipin/legacy/selection-proposal-images/'.$relativePath,
                'original_name' => basename($relativePath),
                'mime_type' => $this->imageMimeType($relativePath),
                'size' => 0,
                'sha256' => null,
                'sort' => count($records),
                'created_at' => $createdAt,
                'updated_at' => $updatedAt,
            ];
            if (count($records) === 3) {
                break;
            }
        }

        return $records;
    }

    private function syncCodeSequence(string $connection, string $period, int $number, string $updatedAt): void
    {
        $sequences = DB::connection($connection)->table('aikaipin_selection_proposal_code_sequences');
        $sequences->insertOrIgnore([
            'period' => $period,
            'last_number' => 0,
            'updated_at' => $updatedAt,
        ]);
        $sequence = $sequences->where('period', $period)->lockForUpdate()->first();
        if ((int) ($sequence->last_number ?? 0) < $number) {
            $sequences->where('period', $period)->update([
                'last_number' => $number,
                'updated_at' => $updatedAt,
            ]);
        }
    }

    /** @param list<array{proposal:array<string,mixed>,tags:list<array<string,mixed>>,images:list<array<string,mixed>>,period:string}> $records
     *  @return array<int,true>
     */
    private function existingProposalIds(array $records): array
    {
        $ids = array_map(static fn (array $record): int => (int) $record['proposal']['id'], $records);
        if ($ids === []) {
            return [];
        }
        $connection = config('admin.database.connection') ?: config('database.default');

        return DB::connection($connection)->table('aikaipin_selection_proposals')
            ->whereIn('id', $ids)
            ->pluck('id')
            ->mapWithKeys(static fn (mixed $id): array => [(int) $id => true])
            ->all();
    }

    /** @param list<array{proposal:array<string,mixed>,tags:list<array<string,mixed>>,images:list<array<string,mixed>>,period:string}> $records
     *  @return array<string,true>
     */
    private function existingProposalCodes(array $records): array
    {
        $codes = array_map(static fn (array $record): string => (string) $record['proposal']['proposal_code'], $records);
        if ($codes === []) {
            return [];
        }
        $connection = config('admin.database.connection') ?: config('database.default');

        return DB::connection($connection)->table('aikaipin_selection_proposals')
            ->whereIn('proposal_code', $codes)
            ->pluck('proposal_code')
            ->mapWithKeys(static fn (mixed $code): array => [(string) $code => true])
            ->all();
    }

    /** @return list<string> */
    private function normalizedStringValues(mixed $value): array
    {
        $normalized = [];
        $seen = [];
        foreach ($this->decodedArray($value) as $item) {
            if (!is_string($item)) {
                continue;
            }
            $item = trim(preg_replace('/\s+/u', ' ', $item) ?: '');
            $key = mb_strtolower($item);
            if ($item === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $normalized[] = $item;
        }

        return $normalized;
    }

    /** @return list<mixed> */
    private function decodedArray(mixed $value): array
    {
        if (is_array($value)) {
            return array_values($value);
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? array_values($decoded) : [];
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function timestampValue(mixed $value): ?string
    {
        $value = trim((string) $value);

        return preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value) === 1 ? $value : null;
    }

    private function legacyRelativePath(string $path, string $sourceDirectory): ?string
    {
        $path = ltrim(str_replace('\\', '/', rawurldecode(trim($path))), '/');
        foreach ([
            $sourceDirectory.'/',
            'storage/'.$sourceDirectory.'/',
            'app/private/'.$sourceDirectory.'/',
            'app/public/'.$sourceDirectory.'/',
            'private/'.$sourceDirectory.'/',
            'public/'.$sourceDirectory.'/',
        ] as $prefix) {
            if (str_starts_with($path, $prefix)) {
                $path = substr($path, strlen($prefix));
                $segments = array_values(array_filter(explode('/', $path), static fn (string $segment): bool => $segment !== '' && $segment !== '.'));

                return $segments !== [] && !in_array('..', $segments, true) ? implode('/', $segments) : null;
            }
        }

        return null;
    }

    private function imageMimeType(string $path): ?string
    {
        return match (strtolower((string) pathinfo($path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'avif' => 'image/avif',
            default => null,
        };
    }
}
