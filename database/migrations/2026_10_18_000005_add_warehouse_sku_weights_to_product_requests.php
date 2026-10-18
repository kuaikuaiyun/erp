<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::connection(config('admin.database.connection') ?: config('database.default'))
            ->table('product_requests', function (Blueprint $table): void {
                $table->json('warehouse_sku_weights')->nullable()->comment('仓库按 SKU 实测重量 {sku:weight}')->after('actual_shipping_weight_g');
            });
    }

    public function down(): void
    {
        Schema::connection(config('admin.database.connection') ?: config('database.default'))
            ->table('product_requests', function (Blueprint $table): void {
                $table->dropColumn(['warehouse_sku_weights']);
            });
    }
};
