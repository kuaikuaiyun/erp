<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);

        if ($schema->hasTable('logistics_shopify_sync_batches')) {
            foreach ([
                'sku_item_count' => fn (Blueprint $table) => $table->unsignedInteger('sku_item_count')->default(0)->after('sku_exception_count'),
                'sku_missing_item_count' => fn (Blueprint $table) => $table->unsignedInteger('sku_missing_item_count')->default(0)->after('sku_item_count'),
            ] as $column => $definition) {
                if (!$schema->hasColumn('logistics_shopify_sync_batches', $column)) {
                    $schema->table('logistics_shopify_sync_batches', function (Blueprint $table) use ($definition): void {
                        $definition($table);
                    });
                }
            }
        }

        if ($schema->hasTable('logistics_shopify_sync_tasks')) {
            foreach ([
                'sku_item_count' => fn (Blueprint $table) => $table->unsignedInteger('sku_item_count')->default(0)->after('sku_exception_count'),
                'sku_missing_item_count' => fn (Blueprint $table) => $table->unsignedInteger('sku_missing_item_count')->default(0)->after('sku_item_count'),
                'sku_missing_details' => fn (Blueprint $table) => $table->longText('sku_missing_details')->nullable()->after('sku_missing_item_count'),
            ] as $column => $definition) {
                if (!$schema->hasColumn('logistics_shopify_sync_tasks', $column)) {
                    $schema->table('logistics_shopify_sync_tasks', function (Blueprint $table) use ($definition): void {
                        $definition($table);
                    });
                }
            }
        }

        if ($schema->hasTable('logistics_orders') && !$schema->hasIndex('logistics_orders', 'logistics_order_store_created_index')) {
            $schema->table('logistics_orders', function (Blueprint $table): void {
                $table->index(['store_name', 'order_created_at', 'id'], 'logistics_order_store_created_index');
            });
        }
    }

    public function down(): void
    {
        // Operational synchronization history and performance indexes are intentionally retained.
    }
};
