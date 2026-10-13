<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(config('admin.database.connection') ?: config('database.default'));

        if ($schema->hasTable('logistics_order_items') && !$schema->hasColumn('logistics_order_items', 'tongtu_product_type')) {
            $schema->table('logistics_order_items', function (Blueprint $table): void {
                $table->string('tongtu_product_type', 16)->nullable()->after('tongtu_product_name')->index();
                $table->index(['shopify_sku', 'tongtu_product_type'], 'logistics_item_sku_type_idx');
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection(config('admin.database.connection') ?: config('database.default'));

        if ($schema->hasTable('logistics_order_items') && $schema->hasColumn('logistics_order_items', 'tongtu_product_type')) {
            $schema->table('logistics_order_items', function (Blueprint $table): void {
                $table->dropIndex('logistics_item_sku_type_idx');
                $table->dropColumn('tongtu_product_type');
            });
        }
    }
};
