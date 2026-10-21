<?php

namespace App\Console\Commands;

use App\Models\AdminUser;
use App\Support\AikaipinLegacySqlDumpReader;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ImportAikaipinLegacyPlacementEvaluations extends Command
{
    protected $signature = 'aikaipin:import-legacy-placement-evaluations
        {file}
        {--limit=5}
        {--user-id=24}
        {--dry-run}';

    protected $description = '';

    public function configure(): void
    {
        parent::configure();

        $this->setDescription(__('aikaipin.placement_import.command_description'));
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
                'product_evaluation_results',
            );
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $targetProducts = $this->targetProducts($sourceRows);
        $actorName = trim((string) ($actor->name ?: $actor->username));
        $candidates = [];
        $skippedDeleted = 0;
        $skippedMissingProduct = 0;
        $skippedInvalid = 0;

        foreach ($sourceRows as $sourceRow) {
            $sourceId = (int) ($sourceRow['id'] ?? 0);
            if ($sourceId <= 0) {
                continue;
            }
            if (($sourceRow['deleted_at'] ?? null) !== null) {
                $skippedDeleted++;

                continue;
            }

            $sourceProductId = (int) ($sourceRow['product_id'] ?? 0);
            $targetProduct = $targetProducts[$sourceProductId] ?? null;
            if (!$this->matchesSourceProduct($sourceRow, $targetProduct)) {
                $skippedMissingProduct++;

                continue;
            }

            $candidate = $this->evaluationRecord($sourceRow, $targetProduct, $actor, $actorName);
            if ($candidate === null) {
                $skippedInvalid++;

                continue;
            }
            $candidates[] = $candidate;
        }

        usort($candidates, static fn (array $left, array $right): int => $left['evaluation']['id'] <=> $right['evaluation']['id']);
        $existingIds = $this->existingEvaluationIds($candidates);
        $existingCodes = $this->existingEvaluationCodes($candidates);
        $available = [];
        $skippedConflicts = 0;

        foreach ($candidates as $candidate) {
            $evaluation = $candidate['evaluation'];
            if (isset($existingIds[(int) $evaluation['id']]) || isset($existingCodes[(string) $evaluation['evaluation_code']])) {
                $skippedConflicts++;

                continue;
            }
            $available[] = $candidate;
        }
        $selected = array_slice($available, 0, $limit);

        $this->newLine();
        $this->info(__('aikaipin.placement_import.preview_title'));
        $this->line(__('aikaipin.placement_import.source_file', ['path' => $sourcePath]));
        $this->line(__('aikaipin.placement_import.assigned_user', [
            'name' => $actorName !== '' ? $actorName : (string) $actor->getKey(),
            'id' => $actor->getKey(),
        ]));
        $this->table(
            [
                __('aikaipin.placement_import.headers.id'),
                __('aikaipin.placement_import.headers.code'),
                __('aikaipin.placement_import.headers.product'),
                __('aikaipin.placement_import.headers.period'),
                __('aikaipin.placement_import.headers.records'),
            ],
            array_map(static fn (array $record): array => [
                $record['evaluation']['id'],
                $record['evaluation']['evaluation_code'],
                $record['evaluation']['product_code'].' '.$record['evaluation']['product_name'],
                $record['evaluation']['test_started_on'].' → '.$record['evaluation']['data_ended_on'],
                count($record['records']),
            ], $selected),
        );
        $this->line(__('aikaipin.placement_import.summary', [
            'source' => count($sourceRows),
            'eligible' => count($candidates),
            'selected' => count($selected),
            'conflicts' => $skippedConflicts,
            'deleted' => $skippedDeleted,
            'missing_product' => $skippedMissingProduct,
            'invalid' => $skippedInvalid,
        ]));

        if ($selected === []) {
            $this->warn(__('aikaipin.placement_import.no_records'));

            return self::SUCCESS;
        }
        if ((bool) $this->option('dry-run')) {
            $this->info(__('aikaipin.placement_import.dry_run_complete'));

            return self::SUCCESS;
        }

        $connection = config('admin.database.connection') ?: config('database.default');
        try {
            DB::connection($connection)->transaction(function () use ($connection, $selected): void {
                foreach ($selected as $record) {
                    DB::connection($connection)->table('aikaipin_placement_evaluations')->insert($record['evaluation']);
                    DB::connection($connection)->table('aikaipin_placement_evaluation_records')->insert($record['records']);
                    $this->syncCodeSequence(
                        $connection,
                        $record['period'],
                        (int) $record['evaluation']['id'],
                        (string) $record['evaluation']['updated_at'],
                    );
                }
            });
        } catch (\Throwable $exception) {
            report($exception);
            $this->error(__('aikaipin.placement_import.errors.save_failed'));

            return self::FAILURE;
        }

        try {
            $nextId = max(array_map(static fn (array $record): int => (int) $record['evaluation']['id'], $selected)) + 1;
            DB::connection($connection)->unprepared('ALTER TABLE aikaipin_placement_evaluations AUTO_INCREMENT = '.(int) $nextId);
        } catch (\Throwable $exception) {
            report($exception);
            $this->warn(__('aikaipin.placement_import.auto_increment_warning'));
        }

        $this->info(__('aikaipin.placement_import.complete', ['count' => count($selected)]));

        return self::SUCCESS;
    }

    private function ensureTablesAreReady(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);
        $requiredColumns = [
            'id', 'evaluation_code', 'product_id', 'product_code', 'product_name',
            'test_started_on', 'data_ended_on', 'remark',
            'created_by', 'created_by_name', 'updated_by', 'updated_by_name', 'created_at', 'updated_at',
        ];

        if (!$schema->hasTable('aikaipin_placement_evaluations')
            || !$schema->hasColumns('aikaipin_placement_evaluations', $requiredColumns)
            || !$schema->hasTable('aikaipin_placement_evaluation_records')
            || !$schema->hasTable('aikaipin_placement_evaluation_code_sequences')
            || !$schema->hasTable('aikaipin_products')
            || !$schema->hasColumns('aikaipin_products', ['id', 'product_code', 'name'])) {
            throw new \RuntimeException(__('aikaipin.placement_import.errors.migration_required'));
        }
    }

    /** @param list<array<string,mixed>> $sourceRows
     *  @return array<int,object>
     */
    private function targetProducts(array $sourceRows): array
    {
        $ids = collect($sourceRows)
            ->pluck('product_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
        if ($ids === []) {
            return [];
        }

        $connection = config('admin.database.connection') ?: config('database.default');

        return DB::connection($connection)
            ->table('aikaipin_products')
            ->whereIn('id', $ids)
            ->get(['id', 'product_code', 'name'])
            ->keyBy('id')
            ->all();
    }

    /** @param array<string,mixed> $sourceRow */
    private function matchesSourceProduct(array $sourceRow, ?object $targetProduct): bool
    {
        if ($targetProduct === null) {
            return false;
        }
        $sourceCode = trim((string) ($sourceRow['product_code'] ?? ''));

        return $sourceCode === ''
            || mb_strtolower($sourceCode) === mb_strtolower((string) $targetProduct->product_code);
    }

    /** @param array<string,mixed> $sourceRow
     *  @return array{evaluation:array<string,mixed>,records:list<array<string,mixed>>,period:string}|null
     */
    private function evaluationRecord(array $sourceRow, object $product, AdminUser $actor, string $actorName): ?array
    {
        $sourceId = (int) $sourceRow['id'];
        $testStartedOn = $this->dateValue($sourceRow['test_started_on'] ?? null);
        $dataEndedOn = $this->dateValue($sourceRow['data_cutoff_on'] ?? null);
        $records = $this->deliveryRecords($sourceId, $sourceRow['delivery_rows'] ?? null);
        if ($testStartedOn === null || $dataEndedOn === null || $dataEndedOn < $testStartedOn || $records === []) {
            return null;
        }

        $createdAt = $this->timestampValue($sourceRow['created_at'] ?? null) ?: now()->toDateTimeString();
        $updatedAt = $this->timestampValue($sourceRow['updated_at'] ?? null) ?: $createdAt;
        $period = substr(str_replace('-', '', $createdAt), 0, 6);
        $evaluation = [
            'id' => $sourceId,
            'evaluation_code' => sprintf('ATE-%s-%06d', $period, $sourceId),
            'product_id' => (int) $product->id,
            'product_code' => (string) $product->product_code,
            'product_name' => (string) $product->name,
            'test_started_on' => $testStartedOn,
            'data_ended_on' => $dataEndedOn,
            'remark' => $this->nullableString($sourceRow['remarks'] ?? null),
            'created_by' => (int) $actor->getKey(),
            'created_by_name' => $actorName !== '' ? $actorName : null,
            'updated_by' => (int) $actor->getKey(),
            'updated_by_name' => $actorName !== '' ? $actorName : null,
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
        ];

        foreach ($records as &$record) {
            $record['evaluation_id'] = $sourceId;
            $record['created_at'] = $createdAt;
            $record['updated_at'] = $updatedAt;
        }
        unset($record);

        return [
            'evaluation' => $evaluation,
            'records' => $records,
            'period' => $period,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function deliveryRecords(int $evaluationId, mixed $value): array
    {
        $records = [];
        foreach ($this->decodedArray($value) as $deliveryRow) {
            if (!is_array($deliveryRow)) {
                return [];
            }
            $platform = $this->platformValue($deliveryRow['platform'] ?? null);
            $currency = $this->currencyValue($deliveryRow['currency'] ?? null);
            $actualSpend = $this->decimalValue($deliveryRow['actual_consumption'] ?? null);
            $orders = $this->ordersValue($deliveryRow['order_count'] ?? null);
            if ($platform === null || $currency === null || $actualSpend === null || $orders === null) {
                return [];
            }
            $records[] = [
                'evaluation_id' => $evaluationId,
                'platform' => $platform,
                'currency' => $currency,
                'actual_spend' => $actualSpend,
                'orders' => $orders,
                'sort' => count($records),
            ];
            if (count($records) > 60) {
                return [];
            }
        }

        return $records;
    }

    private function platformValue(mixed $value): ?string
    {
        return match (mb_strtolower(trim((string) $value))) {
            'facebook', 'fb' => 'facebook',
            'google' => 'google',
            'tiktok' => 'tiktok',
            'mediago' => 'mediago',
            'newsbreak' => 'newsbreak',
            default => null,
        };
    }

    private function currencyValue(mixed $value): ?string
    {
        return match (mb_strtolower(trim((string) $value))) {
            'usd', '美元' => 'usd',
            'eur', '欧元' => 'eur',
            'cny', 'rmb', '人民币' => 'cny',
            default => null,
        };
    }

    private function decimalValue(mixed $value): ?string
    {
        $value = trim((string) $value);
        if (preg_match('/^(?:0|[1-9]\d{0,13})(?:\.\d{1,2})?$/', $value) !== 1) {
            return null;
        }

        return $value;
    }

    private function ordersValue(mixed $value): ?int
    {
        $value = trim((string) $value);
        if (preg_match('/^\d+$/', $value) !== 1
            || strlen($value) > 10
            || (strlen($value) === 10 && strcmp($value, '4294967295') > 0)) {
            return null;
        }

        return (int) $value;
    }

    private function syncCodeSequence(string $connection, string $period, int $number, string $updatedAt): void
    {
        $sequences = DB::connection($connection)->table('aikaipin_placement_evaluation_code_sequences');
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

    /** @param list<array{evaluation:array<string,mixed>,records:list<array<string,mixed>>,period:string}> $records
     *  @return array<int,true>
     */
    private function existingEvaluationIds(array $records): array
    {
        $ids = array_map(static fn (array $record): int => (int) $record['evaluation']['id'], $records);
        if ($ids === []) {
            return [];
        }
        $connection = config('admin.database.connection') ?: config('database.default');

        return DB::connection($connection)->table('aikaipin_placement_evaluations')
            ->whereIn('id', $ids)
            ->pluck('id')
            ->mapWithKeys(static fn (mixed $id): array => [(int) $id => true])
            ->all();
    }

    /** @param list<array{evaluation:array<string,mixed>,records:list<array<string,mixed>>,period:string}> $records
     *  @return array<string,true>
     */
    private function existingEvaluationCodes(array $records): array
    {
        $codes = array_map(static fn (array $record): string => (string) $record['evaluation']['evaluation_code'], $records);
        if ($codes === []) {
            return [];
        }
        $connection = config('admin.database.connection') ?: config('database.default');

        return DB::connection($connection)->table('aikaipin_placement_evaluations')
            ->whereIn('evaluation_code', $codes)
            ->pluck('evaluation_code')
            ->mapWithKeys(static fn (mixed $code): array => [(string) $code => true])
            ->all();
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

    private function dateValue(mixed $value): ?string
    {
        $value = trim((string) $value);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }

    private function timestampValue(mixed $value): ?string
    {
        $value = trim((string) $value);

        return preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value) === 1 ? $value : null;
    }
}
