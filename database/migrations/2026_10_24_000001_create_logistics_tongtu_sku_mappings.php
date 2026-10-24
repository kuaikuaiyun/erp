<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);

        if (!$schema->hasTable('logistics_tongtu_sku_mappings')) {
            $schema->create('logistics_tongtu_sku_mappings', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('shopify_sku', 191)->unique();
                $table->string('status', 32)->default('queued')->index();
                $table->unsignedTinyInteger('not_found_attempts')->default(0);
                $table->dateTime('next_retry_at')->nullable()->index();
                $table->dateTime('refresh_lock_until')->nullable()->index();

                $table->string('tongtu_product_id', 191)->nullable()->index();
                $table->string('tongtu_product_code', 191)->nullable()->index();
                $table->string('tongtu_sku', 191)->nullable()->index();
                $table->string('tongtu_goods_detail_id', 191)->nullable();
                $table->string('tongtu_product_type', 16)->nullable()->index();
                $table->string('tongtu_product_name', 255)->nullable();
                $table->string('tongtu_product_packing_name', 255)->nullable();

                $table->decimal('goods_current_cost_cny', 18, 4)->nullable();
                $table->decimal('goods_average_cost_cny', 18, 4)->nullable();
                $table->decimal('package_cost_cny', 18, 4)->nullable();
                $table->decimal('product_guide_cost_cny', 18, 4)->nullable();
                $table->string('cost_currency', 8)->default('CNY');
                $table->decimal('goods_weight_gram', 18, 4)->nullable();
                $table->decimal('package_weight_gram', 18, 4)->nullable();
                $table->decimal('product_length_cm', 18, 4)->nullable();
                $table->decimal('product_width_cm', 18, 4)->nullable();
                $table->decimal('product_height_cm', 18, 4)->nullable();
                $table->decimal('package_length_cm', 18, 4)->nullable();
                $table->decimal('package_width_cm', 18, 4)->nullable();
                $table->decimal('package_height_cm', 18, 4)->nullable();

                $table->text('tongtu_primary_image_url')->nullable();
                $table->longText('tongtu_images')->nullable();
                $table->dateTime('tongtu_updated_at')->nullable()->index();
                $table->dateTime('last_requested_at')->nullable();
                $table->dateTime('last_synced_at')->nullable()->index();
                $table->text('last_error')->nullable();
                $table->longText('last_response')->nullable();
                $table->timestamps();

                $table->index(['status', 'last_synced_at'], 'log_tongtu_sku_status_sync_idx');
                $table->index(['tongtu_product_code', 'tongtu_sku'], 'log_tongtu_sku_pcl_sku_idx');
            });
        }

        if ($schema->hasTable('logistics_order_items')) {
            $columns = [
                'tongtu_sku_mapping_id' => static fn (Blueprint $table) => $table->unsignedBigInteger('tongtu_sku_mapping_id')->nullable()->after('shopify_sku')->index(),
                'tongtu_product_id' => static fn (Blueprint $table) => $table->string('tongtu_product_id', 191)->nullable()->after('tongtu_sku_mapping_id')->index(),
                'tongtu_goods_detail_id' => static fn (Blueprint $table) => $table->string('tongtu_goods_detail_id', 191)->nullable()->after('tongtu_product_id'),
                'tongtu_goods_weight_gram' => static fn (Blueprint $table) => $table->decimal('tongtu_goods_weight_gram', 18, 4)->nullable()->after('tongtu_product_type'),
                'tongtu_package_weight_gram' => static fn (Blueprint $table) => $table->decimal('tongtu_package_weight_gram', 18, 4)->nullable()->after('tongtu_goods_weight_gram'),
            ];

            foreach ($columns as $column => $definition) {
                if (!$schema->hasColumn('logistics_order_items', $column)) {
                    $schema->table('logistics_order_items', $definition);
                }
            }
        }
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);

        if ($schema->hasTable('logistics_order_items')) {
            $columns = [
                'tongtu_sku_mapping_id',
                'tongtu_product_id',
                'tongtu_goods_detail_id',
                'tongtu_goods_weight_gram',
                'tongtu_package_weight_gram',
            ];
            $existing = array_values(array_filter($columns, fn (string $column): bool => $schema->hasColumn('logistics_order_items', $column)));
            if ($existing !== []) {
                $schema->table('logistics_order_items', function (Blueprint $table) use ($existing): void {
                    if (in_array('tongtu_sku_mapping_id', $existing, true)) {
                        $table->dropIndex(['tongtu_sku_mapping_id']);
                    }
                    if (in_array('tongtu_product_id', $existing, true)) {
                        $table->dropIndex(['tongtu_product_id']);
                    }
                    $table->dropColumn($existing);
                });
            }
        }

        $schema->dropIfExists('logistics_tongtu_sku_mappings');
    }
};
