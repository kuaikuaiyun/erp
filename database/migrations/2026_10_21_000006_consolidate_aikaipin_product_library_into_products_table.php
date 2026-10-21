<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);

        $schema->table('aikaipin_products', function (Blueprint $table) use ($schema): void {
            if (!$schema->hasColumn('aikaipin_products', 'sku')) {
                $table->string('sku', 191)->nullable()->after('name');
            }

            if (!$schema->hasColumn('aikaipin_products', 'categories')) {
                $table->json('categories')->nullable()->after('sku');
            }

            if (!$schema->hasColumn('aikaipin_products', 'images')) {
                $table->json('images')->nullable()->after('landing_page_url');
            }

            if (!$schema->hasColumn('aikaipin_products', 'reference_file')) {
                $table->json('reference_file')->nullable()->after('images');
            }

            if (!$schema->hasColumn('aikaipin_products', 'package_file')) {
                $table->json('package_file')->nullable()->after('reference_file');
            }

            if (!$schema->hasColumn('aikaipin_products', 'visible_department_ids')) {
                $table->json('visible_department_ids')->nullable()->after('package_file');
            }
        });

        $database = DB::connection($connection);
        $tables = [
            'skus' => $schema->hasTable('aikaipin_product_skus'),
            'categories' => $schema->hasTable('aikaipin_product_categories'),
            'assignments' => $schema->hasTable('aikaipin_product_category_assignments'),
            'files' => $schema->hasTable('aikaipin_product_files'),
            'visibilities' => $schema->hasTable('aikaipin_product_department_visibilities'),
        ];

        $categoryNames = $tables['categories']
            ? $database->table('aikaipin_product_categories')->pluck('name', 'id')->all()
            : [];

        $database->table('aikaipin_products')->orderBy('id')->chunkById(100, function ($products) use ($database, $tables, $categoryNames): void {
            foreach ($products as $product) {
                $productId = (int) $product->id;
                $sku = null;
                $categories = [];
                $images = [];
                $referenceFile = null;
                $packageFile = null;
                $visibleDepartmentIds = [];

                if ($tables['skus']) {
                    $sku = $this->joinValues(
                        $database->table('aikaipin_product_skus')
                            ->where('product_id', $productId)
                            ->orderBy('sort')
                            ->orderBy('id')
                            ->pluck('sku')
                            ->all(),
                    );
                }

                if ($tables['assignments']) {
                    $categories = $this->uniqueStrings(
                        $database->table('aikaipin_product_category_assignments')
                            ->where('product_id', $productId)
                            ->orderBy('id')
                            ->pluck('category_id')
                            ->map(static fn ($categoryId) => $categoryNames[(int) $categoryId] ?? null)
                            ->all(),
                    );
                }

                if ($tables['files']) {
                    $files = $database->table('aikaipin_product_files')
                        ->where('product_id', $productId)
                        ->orderBy('kind')
                        ->orderBy('sort')
                        ->orderBy('id')
                        ->get();

                    foreach ($files as $file) {
                        $payload = [
                            'disk' => (string) ($file->disk ?: 'local'),
                            'path' => (string) $file->path,
                            'original_name' => (string) $file->original_name,
                            'mime_type' => $file->mime_type ?: null,
                            'size' => (int) $file->size,
                            'sha256' => $file->sha256 ?: null,
                        ];

                        if ($file->kind === 'image') {
                            $images[] = $payload;
                        } elseif ($file->kind === 'reference') {
                            $referenceFile = $payload;
                        } elseif ($file->kind === 'package') {
                            $packageFile = $payload;
                        }
                    }
                }

                if ($tables['visibilities']) {
                    $visibleDepartmentIds = $database->table('aikaipin_product_department_visibilities')
                        ->where('product_id', $productId)
                        ->orderBy('department_id')
                        ->pluck('department_id')
                        ->map(static fn ($departmentId): int => (int) $departmentId)
                        ->filter(static fn (int $departmentId): bool => $departmentId > 0)
                        ->unique()
                        ->values()
                        ->all();
                }

                $database->table('aikaipin_products')->where('id', $productId)->update([
                    'product_code' => 'kky-'.$productId,
                    'sku' => $sku,
                    'categories' => $categories === [] ? null : json_encode($categories, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'images' => $images === [] ? null : json_encode($images, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'reference_file' => $referenceFile === null ? null : json_encode($referenceFile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'package_file' => $packageFile === null ? null : json_encode($packageFile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'visible_department_ids' => $visibleDepartmentIds === [] ? null : json_encode($visibleDepartmentIds),
                ]);
            }
        }, 'id');
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);

        $schema->table('aikaipin_products', function (Blueprint $table) use ($schema): void {
            foreach (['visible_department_ids', 'package_file', 'reference_file', 'images', 'categories', 'sku'] as $column) {
                if ($schema->hasColumn('aikaipin_products', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    /** @param iterable<mixed> $values */
    private function joinValues(iterable $values): ?string
    {
        $items = $this->uniqueStrings($values);

        return $items === [] ? null : implode(',', $items);
    }

    /** @param iterable<mixed> $values
     *  @return list<string>
     */
    private function uniqueStrings(iterable $values): array
    {
        $seen = [];
        $normalized = [];

        foreach ($values as $value) {
            $item = trim(preg_replace('/\s+/u', ' ', (string) $value) ?: '');
            $key = mb_strtolower($item);

            if ($item === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $normalized[] = $item;
        }

        return $normalized;
    }
};
