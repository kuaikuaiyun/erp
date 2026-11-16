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

        if (! $schema->hasTable('aikaipin_product_pages')) {
            $schema->create('aikaipin_product_pages', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('aikaipin_product_id')->index();
                $table->string('name', 255);
                $table->string('source_file', 255)->nullable();
                $table->json('shopify_data');
                $table->boolean('is_default')->default(false)->index();
                $table->unsignedInteger('created_by')->nullable()->index();
                $table->string('created_by_name', 100)->nullable();
                $table->timestamps();
                $table->index(['aikaipin_product_id', 'is_default'], 'aikaipin_product_pages_product_default_index');
            });
        }
        if ($schema->hasTable('ai_product_shopify_publications') && ! $schema->hasColumn('ai_product_shopify_publications', 'aikaipin_product_page_id')) {
            $schema->table('ai_product_shopify_publications', function (Blueprint $table): void {
                $table->unsignedBigInteger('aikaipin_product_page_id')->nullable()->index()->after('aikaipin_product_id');
            });
        }

        if (! $schema->hasTable('aikaipin_products') || ! $schema->hasColumn('aikaipin_products', 'shopify_data')) {
            return;
        }

        $database = DB::connection($connection);
        $database->table('aikaipin_products')
            ->whereNotNull('shopify_data')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('aikaipin_product_pages')
                    ->whereColumn('aikaipin_product_pages.aikaipin_product_id', 'aikaipin_products.id');
            })
            ->orderBy('id')
            ->chunkById(200, function ($products) use ($database): void {
                $now = now();
                $rows = collect($products)->map(function ($product) use ($now): array {
                    $payload = json_decode((string) $product->shopify_data, true);
                    $title = trim((string) data_get($payload, 'title'));
                    $sourceFile = trim((string) data_get($payload, 'source_file'));

                    return [
                        'aikaipin_product_id' => (int) $product->id,
                        'name' => $title !== '' ? $title : ($sourceFile !== '' ? $sourceFile : '默认产品页'),
                        'source_file' => $sourceFile !== '' ? $sourceFile : null,
                        'shopify_data' => (string) $product->shopify_data,
                        'is_default' => true,
                        'created_by' => null,
                        'created_by_name' => '历史数据迁移',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                })->all();

                if ($rows !== []) {
                    $database->table('aikaipin_product_pages')->insert($rows);
                }
            });
    }

    public function down(): void
    {
        $schema = Schema::connection(config('admin.database.connection') ?: config('database.default'));
        if ($schema->hasTable('ai_product_shopify_publications') && $schema->hasColumn('ai_product_shopify_publications', 'aikaipin_product_page_id')) {
            $schema->table('ai_product_shopify_publications', function (Blueprint $table): void {
                $table->dropColumn('aikaipin_product_page_id');
            });
        }
        $schema->dropIfExists('aikaipin_product_pages');
    }
};
