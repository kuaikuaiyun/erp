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

        if ($schema->hasTable('logistics_orders') && !$schema->hasColumn('logistics_orders', 'tongtu_product_code')) {
            $schema->table('logistics_orders', function (Blueprint $table): void {
                $table->text('tongtu_product_code')->nullable()->after('tongtu_sku');
            });
        }

        if ($schema->hasTable('logistics_order_items') && !$schema->hasColumn('logistics_order_items', 'tongtu_product_code')) {
            $schema->table('logistics_order_items', function (Blueprint $table): void {
                $table->string('tongtu_product_code', 191)->nullable()->after('tongtu_sku');
                $table->index('tongtu_product_code', 'log_ord_item_pcl_idx');
            });
        }

        $database = DB::connection($connection);

        // Before this migration `tongtu_sku` stored Tongtu's productCode. Only
        // rows with a Tongtu product name are copied: unmatched Shopify rows
        // must never become a false PCL value.
        if ($schema->hasTable('logistics_orders')) {
            $database->table('logistics_orders')
                ->whereNull('tongtu_product_code')
                ->whereNotNull('tongtu_sku')
                ->whereNotNull('tongtu_product_name')
                ->update(['tongtu_product_code' => DB::raw('tongtu_sku')]);
        }

        if ($schema->hasTable('logistics_order_items')) {
            $database->table('logistics_order_items')
                ->whereNull('tongtu_product_code')
                ->whereNotNull('tongtu_sku')
                ->whereNotNull('tongtu_product_name')
                ->update(['tongtu_product_code' => DB::raw('tongtu_sku')]);
        }
    }

    public function down(): void
    {
        // Do not discard PCL/SKU separation data once a refresh has run.
    }
};
