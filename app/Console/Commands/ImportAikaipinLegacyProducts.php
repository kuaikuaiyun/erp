<?php

namespace App\Console\Commands;

use App\Models\AdminUser;
use App\Models\AikaipinProduct;
use App\Portals\Aikaipin\AikaipinPortalAuthorizationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ZipArchive;

class ImportAikaipinLegacyProducts extends Command
{
    protected $signature = 'aikaipin:import-legacy-products
        {file}
        {--limit=5}
        {--user-id=24}
        {--dry-run}';

    protected $description = '';

    public function configure(): void
    {
        parent::configure();

        $this->setDescription(__('aikaipin.import.command_description'));
        $this->getDefinition()->getArgument('file')->setDescription(__('aikaipin.import.arguments.file'));
        $this->getDefinition()->getOption('limit')->setDescription(__('aikaipin.import.options.limit'));
        $this->getDefinition()->getOption('user-id')->setDescription(__('aikaipin.import.options.user_id'));
        $this->getDefinition()->getOption('dry-run')->setDescription(__('aikaipin.import.options.dry_run'));
    }

    public function handle(AikaipinPortalAuthorizationService $authorization): int
    {
        try {
            $limit = max(1, min(100, (int) $this->option('limit')));
            $userId = max(1, (int) $this->option('user-id'));
            $sourcePath = $this->resolvePath((string) $this->argument('file'));
            $this->ensureProductTableIsReady();

            $actor = AdminUser::query()->find($userId);
            if ($actor === null) {
                throw new \RuntimeException(__('aikaipin.import.errors.user_not_found', ['id' => $userId]));
            }

            $sql = $this->readSqlDump($sourcePath);
            $sourceProducts = $this->readTableRows($sql, 'products');
            $sourceCategories = $this->readTableRows($sql, 'product_categories');
            $sourceAssignments = $this->readTableRows($sql, 'product_category');
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $categoriesById = $this->categoriesById($sourceCategories);
        $categoriesByProductId = $this->categoriesByProductId($sourceAssignments, $categoriesById);
        $visibleDepartmentIds = collect($authorization->quickAuthorizationDepartments())
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->values()
            ->all();
        $actorName = trim((string) ($actor->name ?: $actor->username));
        $candidates = [];
        $skippedDeleted = 0;
        $skippedUnsupportedPlatform = 0;

        foreach ($sourceProducts as $sourceProduct) {
            $sourceId = (int) ($sourceProduct['id'] ?? 0);
            if ($sourceId <= 0) {
                continue;
            }

            if (($sourceProduct['deleted_at'] ?? null) !== null) {
                $skippedDeleted++;

                continue;
            }

            $sourcePlatform = $this->sourcePlatform($sourceProduct['source_platform'] ?? null);
            if ($sourcePlatform === null) {
                $skippedUnsupportedPlatform++;

                continue;
            }

            $candidates[] = $this->productRecord(
                $sourceProduct,
                $sourcePlatform,
                $categoriesByProductId[$sourceId] ?? [],
                $visibleDepartmentIds,
                $actor,
                $actorName,
            );
        }

        usort($candidates, static fn (array $left, array $right): int => $left['id'] <=> $right['id']);
        $existingById = $this->existingProductIds($candidates);
        $existingByCode = $this->existingProductCodes($candidates);
        $available = [];
        $skippedConflicts = 0;

        foreach ($candidates as $candidate) {
            if (isset($existingById[(int) $candidate['id']]) || isset($existingByCode[(string) $candidate['product_code']])) {
                $skippedConflicts++;

                continue;
            }

            $available[] = $candidate;
        }
        $selected = array_slice($available, 0, $limit);

        $this->newLine();
        $this->info(__('aikaipin.import.preview_title'));
        $this->line(__('aikaipin.import.source_file', ['path' => $sourcePath]));
        $this->line(__('aikaipin.import.visibility_count', ['count' => count($visibleDepartmentIds)]));
        $this->table(
            [
                __('aikaipin.import.headers.id'),
                __('aikaipin.import.headers.code'),
                __('aikaipin.import.headers.name'),
                __('aikaipin.import.headers.sku'),
                __('aikaipin.import.headers.categories'),
                __('aikaipin.import.headers.images'),
            ],
            array_map(fn (array $record): array => [
                $record['id'],
                $record['product_code'],
                $record['name'],
                $record['sku'] ?: '—',
                implode(' / ', json_decode((string) ($record['categories'] ?? '[]'), true) ?: []) ?: '—',
                count(json_decode((string) ($record['images'] ?? '[]'), true) ?: []),
            ], $selected),
        );
        $this->line(__('aikaipin.import.summary', [
            'source' => count($sourceProducts),
            'eligible' => count($candidates),
            'selected' => count($selected),
            'conflicts' => $skippedConflicts,
            'deleted' => $skippedDeleted,
            'unsupported' => $skippedUnsupportedPlatform,
        ]));

        if ($selected === []) {
            $this->warn(__('aikaipin.import.no_records'));

            return self::SUCCESS;
        }

        if ((bool) $this->option('dry-run')) {
            $this->info(__('aikaipin.import.dry_run_complete'));

            return self::SUCCESS;
        }

        try {
            $connection = config('admin.database.connection') ?: config('database.default');
            DB::connection($connection)->transaction(function () use ($selected, $connection): void {
                foreach ($selected as $record) {
                    DB::connection($connection)->table('aikaipin_products')->insert($record);
                }

                $nextId = max(array_column($selected, 'id')) + 1;
                DB::connection($connection)->unprepared('ALTER TABLE `aikaipin_products` AUTO_INCREMENT = '.(int) $nextId);
            });
        } catch (\Throwable $exception) {
            report($exception);
            $this->error(__('aikaipin.import.errors.save_failed'));

            return self::FAILURE;
        }

        $this->info(__('aikaipin.import.complete', ['count' => count($selected)]));

        return self::SUCCESS;
    }

    private function ensureProductTableIsReady(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $requiredColumns = [
            'id',
            'product_code',
            'name',
            'sku',
            'categories',
            'source_platform',
            'product_type',
            'landing_page_url',
            'images',
            'reference_file',
            'package_file',
            'visible_department_ids',
            'created_by',
            'created_by_name',
            'updated_by',
            'updated_by_name',
            'created_at',
            'updated_at',
        ];

        if (!Schema::connection($connection)->hasTable('aikaipin_products')
            || !Schema::connection($connection)->hasColumns('aikaipin_products', $requiredColumns)) {
            throw new \RuntimeException(__('aikaipin.import.errors.migration_required'));
        }
    }

    private function resolvePath(string $path): string
    {
        $path = trim($path, " \t\n\r\0\x0B\"'");
        if ($path === '') {
            throw new \RuntimeException(__('aikaipin.import.errors.file_required'));
        }

        $isAbsolute = str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
        $candidate = $isAbsolute ? $path : base_path($path);
        $resolved = realpath($candidate);

        if ($resolved === false || !is_file($resolved)) {
            throw new \RuntimeException(__('aikaipin.import.errors.file_not_found', ['path' => $candidate]));
        }

        return $resolved;
    }

    private function readSqlDump(string $path): string
    {
        $size = filesize($path);
        if ($size === false || $size <= 0) {
            throw new \RuntimeException(__('aikaipin.import.errors.file_empty'));
        }

        if ($size > 100 * 1024 * 1024) {
            throw new \RuntimeException(__('aikaipin.import.errors.file_too_large'));
        }

        if (strtolower((string) pathinfo($path, PATHINFO_EXTENSION)) !== 'zip') {
            $sql = file_get_contents($path);
            if ($sql === false) {
                throw new \RuntimeException(__('aikaipin.import.errors.file_read_failed'));
            }

            return $sql;
        }

        if (!class_exists(ZipArchive::class)) {
            throw new \RuntimeException(__('aikaipin.import.errors.zip_extension_required'));
        }

        $archive = new ZipArchive();
        if ($archive->open($path) !== true) {
            throw new \RuntimeException(__('aikaipin.import.errors.zip_open_failed'));
        }

        try {
            for ($index = 0; $index < $archive->numFiles; $index++) {
                $entry = $archive->statIndex($index);
                if (!is_array($entry) || !str_ends_with(strtolower((string) ($entry['name'] ?? '')), '.sql')) {
                    continue;
                }

                if ((int) ($entry['size'] ?? 0) > 100 * 1024 * 1024) {
                    throw new \RuntimeException(__('aikaipin.import.errors.file_too_large'));
                }

                $sql = $archive->getFromIndex($index);
                if ($sql === false) {
                    throw new \RuntimeException(__('aikaipin.import.errors.file_read_failed'));
                }

                return $sql;
            }
        } finally {
            $archive->close();
        }

        throw new \RuntimeException(__('aikaipin.import.errors.sql_not_found'));
    }

    /** @return list<array<string, mixed>> */
    private function readTableRows(string $sql, string $table): array
    {
        $defaultColumns = $this->tableColumns($sql, $table);
        $rows = [];
        $needle = 'INSERT INTO `'.$table.'`';
        $offset = 0;

        while (($statementStart = strpos($sql, $needle, $offset)) !== false) {
            $header = substr($sql, $statementStart);
            $pattern = '/^INSERT INTO `'.preg_quote($table, '/').'`(?: \\((?<columns>[^)]*)\\))? VALUES\\s*/';
            if (preg_match($pattern, $header, $matches) !== 1) {
                throw new \RuntimeException(__('aikaipin.import.errors.sql_invalid', ['table' => $table]));
            }

            $valuesStart = $statementStart + strlen($matches[0]);
            $statementEnd = $this->findStatementEnd($sql, $valuesStart);
            $columns = $this->insertColumns($matches['columns'] ?? '', $defaultColumns);
            foreach ($this->parseValueTuples(substr($sql, $valuesStart, $statementEnd - $valuesStart)) as $values) {
                if (count($columns) !== count($values)) {
                    throw new \RuntimeException(__('aikaipin.import.errors.column_count_invalid', ['table' => $table]));
                }

                /** @var array<string, mixed> $row */
                $row = array_combine($columns, $values);
                $rows[] = $row;
            }

            $offset = $statementEnd + 1;
        }

        return $rows;
    }

    /** @return list<string> */
    private function tableColumns(string $sql, string $table): array
    {
        if (preg_match('/CREATE TABLE `'.preg_quote($table, '/').'` \\((?<body>.*?)\\) ENGINE/s', $sql, $matches) !== 1) {
            throw new \RuntimeException(__('aikaipin.import.errors.table_not_found', ['table' => $table]));
        }

        preg_match_all('/^\\s*`([^`]+)`/m', $matches['body'], $columns);

        return $columns[1] ?? [];
    }

    private function findStatementEnd(string $sql, int $offset): int
    {
        $quoted = false;
        $length = strlen($sql);

        for ($index = $offset; $index < $length; $index++) {
            $character = $sql[$index];
            if ($quoted) {
                if ($character === '\\') {
                    $index++;

                    continue;
                }

                if ($character === "'") {
                    $quoted = false;
                }

                continue;
            }

            if ($character === "'") {
                $quoted = true;
            } elseif ($character === ';') {
                return $index;
            }
        }

        throw new \RuntimeException(__('aikaipin.import.errors.sql_invalid', ['table' => 'unknown']));
    }

    /** @param list<string> $defaultColumns
     *  @return list<string>
     */
    private function insertColumns(string $insertColumns, array $defaultColumns): array
    {
        if (trim($insertColumns) === '') {
            return $defaultColumns;
        }

        preg_match_all('/`([^`]+)`/', $insertColumns, $columns);

        return $columns[1] ?? [];
    }

    /** @return list<list<mixed>> */
    private function parseValueTuples(string $values): array
    {
        $rows = [];
        $length = strlen($values);
        $offset = 0;

        while ($offset < $length) {
            while ($offset < $length && (ctype_space($values[$offset]) || $values[$offset] === ',')) {
                $offset++;
            }

            if ($offset >= $length) {
                break;
            }

            if ($values[$offset] !== '(') {
                throw new \RuntimeException(__('aikaipin.import.errors.sql_invalid', ['table' => 'unknown']));
            }
            $offset++;
            $row = [];

            while ($offset < $length) {
                while ($offset < $length && ctype_space($values[$offset])) {
                    $offset++;
                }

                if ($offset >= $length) {
                    throw new \RuntimeException(__('aikaipin.import.errors.sql_invalid', ['table' => 'unknown']));
                }

                if ($values[$offset] === "'") {
                    $row[] = $this->readQuotedValue($values, $offset);
                } else {
                    $valueStart = $offset;
                    while ($offset < $length && !in_array($values[$offset], [',', ')'], true)) {
                        $offset++;
                    }
                    $rawValue = trim(substr($values, $valueStart, $offset - $valueStart));
                    $row[] = strtoupper($rawValue) === 'NULL' ? null : $rawValue;
                }

                while ($offset < $length && ctype_space($values[$offset])) {
                    $offset++;
                }

                if ($offset >= $length) {
                    throw new \RuntimeException(__('aikaipin.import.errors.sql_invalid', ['table' => 'unknown']));
                }

                if ($values[$offset] === ',') {
                    $offset++;

                    continue;
                }

                if ($values[$offset] === ')') {
                    $offset++;
                    $rows[] = $row;

                    break;
                }

                throw new \RuntimeException(__('aikaipin.import.errors.sql_invalid', ['table' => 'unknown']));
            }
        }

        return $rows;
    }

    private function readQuotedValue(string $values, int &$offset): string
    {
        $offset++;
        $raw = '';
        $length = strlen($values);

        while ($offset < $length) {
            $character = $values[$offset];
            if ($character === '\\' && $offset + 1 < $length) {
                $raw .= $character.$values[$offset + 1];
                $offset += 2;

                continue;
            }

            if ($character === "'") {
                $offset++;

                return strtr($raw, [
                    '\\0' => "\0",
                    '\\b' => "\b",
                    '\\n' => "\n",
                    '\\r' => "\r",
                    '\\t' => "\t",
                    '\\Z' => "\x1a",
                    '\\\\' => '\\',
                    "\\'" => "'",
                    '\\"' => '"',
                ]);
            }

            $raw .= $character;
            $offset++;
        }

        throw new \RuntimeException(__('aikaipin.import.errors.sql_invalid', ['table' => 'unknown']));
    }

    /** @param list<array<string,mixed>> $sourceCategories
     *  @return array<int,string>
     */
    private function categoriesById(array $sourceCategories): array
    {
        $categories = [];

        foreach ($sourceCategories as $sourceCategory) {
            $id = (int) ($sourceCategory['id'] ?? 0);
            $name = trim((string) ($sourceCategory['name'] ?? ''));
            if ($id <= 0 || $name === '' || (string) ($sourceCategory['is_active'] ?? '1') === '0') {
                continue;
            }

            $categories[$id] = $name;
        }

        return $categories;
    }

    /** @param list<array<string,mixed>> $assignments
     *  @param array<int,string> $categoriesById
     *  @return array<int,list<string>>
     */
    private function categoriesByProductId(array $assignments, array $categoriesById): array
    {
        $categoriesByProductId = [];

        foreach ($assignments as $assignment) {
            $productId = (int) ($assignment['product_id'] ?? 0);
            $categoryName = $categoriesById[(int) ($assignment['product_category_id'] ?? 0)] ?? null;
            if ($productId <= 0 || $categoryName === null) {
                continue;
            }

            $categoriesByProductId[$productId] ??= [];
            if (!in_array($categoryName, $categoriesByProductId[$productId], true)) {
                $categoriesByProductId[$productId][] = $categoryName;
            }
        }

        return $categoriesByProductId;
    }

    /** @param array<string,mixed> $sourceProduct
     *  @param list<string> $categories
     *  @param list<int> $visibleDepartmentIds
     *  @return array<string,mixed>
     */
    private function productRecord(
        array $sourceProduct,
        string $sourcePlatform,
        array $categories,
        array $visibleDepartmentIds,
        AdminUser $actor,
        string $actorName,
    ): array {
        $sourceId = (int) $sourceProduct['id'];
        $createdAt = $this->timestampValue($sourceProduct['created_at'] ?? null) ?: now()->toDateTimeString();
        $updatedAt = $this->timestampValue($sourceProduct['updated_at'] ?? null) ?: $createdAt;
        $referenceFile = $this->legacyFilePayload(
            $sourceProduct['html_preview_path'] ?? null,
            'product-html-previews',
            $sourceProduct['html_preview_file_name'] ?? null,
            null,
        );
        $packageFile = $this->legacyFilePayload(
            $sourceProduct['source_path'] ?? null,
            'product-packages',
            $sourceProduct['source_file_name'] ?? null,
            null,
        );
        $images = $this->legacyImagePayloads($sourceProduct);

        return [
            'id' => $sourceId,
            'product_code' => 'kky-'.$sourceId,
            'name' => trim((string) ($sourceProduct['title'] ?? '')) ?: 'kky-'.$sourceId,
            'sku' => $this->skuValue($sourceProduct['sku'] ?? null),
            'categories' => $categories === [] ? null : json_encode($categories, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'source_platform' => $sourcePlatform,
            'product_type' => $this->productType($sourceProduct['product_type'] ?? null),
            'landing_page_url' => $this->nullableUrl($sourceProduct['landing_page_url'] ?? null),
            'images' => $images === [] ? null : json_encode($images, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'reference_file' => $referenceFile === null ? null : json_encode($referenceFile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'package_file' => $packageFile === null ? null : json_encode($packageFile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'visible_department_ids' => $visibleDepartmentIds === [] ? null : json_encode($visibleDepartmentIds),
            'created_by' => (int) $actor->getKey(),
            'created_by_name' => $actorName ?: null,
            'updated_by' => (int) $actor->getKey(),
            'updated_by_name' => $actorName ?: null,
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
        ];
    }

    private function sourcePlatform(mixed $sourcePlatform): ?string
    {
        return match (mb_strtolower(trim((string) $sourcePlatform))) {
            'shopify' => 'shopify',
            'shopline' => 'shopline',
            default => null,
        };
    }

    private function productType(mixed $productType): string
    {
        return match (mb_strtolower(trim((string) $productType))) {
            'xiaohei', '小黑' => 'xiaohei',
            default => 'general',
        };
    }

    private function skuValue(mixed $value): ?string
    {
        $values = preg_split('/[,，;；\\n]+/u', trim((string) $value)) ?: [];
        $normalized = [];
        $seen = [];

        foreach ($values as $item) {
            $item = trim(preg_replace('/\\s+/u', ' ', $item) ?: '');
            $key = mb_strtolower($item);
            if ($item === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $normalized[] = $item;
        }

        return $normalized === [] ? null : implode(',', $normalized);
    }

    private function nullableUrl(mixed $value): ?string
    {
        $value = trim((string) $value);

        return filter_var($value, FILTER_VALIDATE_URL) ? $value : null;
    }

    /** @param array<string,mixed> $sourceProduct
     *  @return list<array{disk:string,path:string,original_name:string,mime_type:?string,size:int,sha256:?string}>
     */
    private function legacyImagePayloads(array $sourceProduct): array
    {
        $candidates = [];
        $imageUrls = $this->decodedArray($sourceProduct['image_urls'] ?? null);
        foreach ($imageUrls as $imageUrl) {
            if (is_string($imageUrl)) {
                $candidates[] = $imageUrl;
            }
        }

        $rawPayload = $this->decodedArray($sourceProduct['raw_payload'] ?? null);
        foreach ((array) ($rawPayload['image_paths'] ?? []) as $imagePath) {
            if (is_string($imagePath)) {
                $candidates[] = $imagePath;
            }
        }

        $normalizedPayload = $this->decodedArray($sourceProduct['normalized_payload'] ?? null);
        foreach ((array) ($normalizedPayload['images'] ?? []) as $image) {
            if (is_array($image) && is_string($image['src'] ?? null)) {
                $candidates[] = $image['src'];
            }
        }

        if (is_string($sourceProduct['main_image_url'] ?? null)) {
            $candidates[] = $sourceProduct['main_image_url'];
        }

        $payloads = [];
        $seen = [];
        foreach ($candidates as $candidate) {
            $payload = $this->legacyFilePayload($candidate, 'product-images', null, $this->imageMimeType($candidate));
            if ($payload === null || isset($seen[$payload['path']])) {
                continue;
            }

            $seen[$payload['path']] = true;
            $payloads[] = $payload;
            if (count($payloads) === 3) {
                break;
            }
        }

        return $payloads;
    }

    /** @return array<string,mixed> */
    private function decodedArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /** @return array{disk:string,path:string,original_name:string,mime_type:?string,size:int,sha256:?string}|null */
    private function legacyFilePayload(mixed $legacyPath, string $sourceDirectory, mixed $originalName, ?string $mimeType): ?array
    {
        $relativePath = $this->legacyRelativePath((string) $legacyPath, $sourceDirectory);
        if ($relativePath === null) {
            return null;
        }

        return [
            'disk' => 'local',
            'path' => 'portals/aikaipin/legacy/'.$sourceDirectory.'/'.$relativePath,
            'original_name' => trim((string) $originalName) ?: basename($relativePath),
            'mime_type' => $mimeType,
            'size' => 0,
            'sha256' => null,
        ];
    }

    private function legacyRelativePath(string $legacyPath, string $sourceDirectory): ?string
    {
        $legacyPath = trim($legacyPath);
        if ($legacyPath === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $legacyPath) === 1) {
            $legacyPath = (string) (parse_url($legacyPath, PHP_URL_PATH) ?: '');
        }

        $legacyPath = ltrim(str_replace('\\', '/', rawurldecode($legacyPath)), '/');
        $prefixes = [
            $sourceDirectory.'/',
            'storage/'.$sourceDirectory.'/',
            'app/private/'.$sourceDirectory.'/',
            'app/public/'.$sourceDirectory.'/',
            'private/'.$sourceDirectory.'/',
            'public/'.$sourceDirectory.'/',
        ];
        $matchedPrefix = false;
        foreach ($prefixes as $prefix) {
            if (str_starts_with($legacyPath, $prefix)) {
                $legacyPath = substr($legacyPath, strlen($prefix));
                $matchedPrefix = true;

                break;
            }
        }

        if (!$matchedPrefix) {
            return null;
        }

        $segments = array_values(array_filter(explode('/', $legacyPath), static fn (string $segment): bool => $segment !== '' && $segment !== '.'));
        if ($segments === [] || in_array('..', $segments, true)) {
            return null;
        }

        return implode('/', $segments);
    }

    private function imageMimeType(string $path): ?string
    {
        return match (strtolower((string) pathinfo((string) parse_url($path, PHP_URL_PATH), PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'avif' => 'image/avif',
            default => null,
        };
    }

    private function timestampValue(mixed $value): ?string
    {
        $value = trim((string) $value);

        return preg_match('/^\\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}:\\d{2}$/', $value) === 1 ? $value : null;
    }

    /** @param list<array<string,mixed>> $candidates
     *  @return array<int,true>
     */
    private function existingProductIds(array $candidates): array
    {
        $ids = array_column($candidates, 'id');
        if ($ids === []) {
            return [];
        }

        return AikaipinProduct::query()
            ->whereIn('id', $ids)
            ->pluck('id')
            ->mapWithKeys(static fn (mixed $id): array => [(int) $id => true])
            ->all();
    }

    /** @param list<array<string,mixed>> $candidates
     *  @return array<string,true>
     */
    private function existingProductCodes(array $candidates): array
    {
        $codes = array_column($candidates, 'product_code');
        if ($codes === []) {
            return [];
        }

        return AikaipinProduct::query()
            ->whereIn('product_code', $codes)
            ->pluck('product_code')
            ->mapWithKeys(static fn (mixed $code): array => [(string) $code => true])
            ->all();
    }
}
