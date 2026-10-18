<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::connection(config('admin.database.connection') ?: config('database.default'))
            ->table('product_requests', function (Blueprint $table): void {
                $table->json('procurement_sku_values')->nullable()->comment('按 SKU 存储采购价/重量 {sku:{price,weight}}')->after('procurement_skus');
            });
    }

    public function down(): void
    {
        Schema::connection(config('admin.database.connection') ?: config('database.default'))
            ->table('product_requests', function (Blueprint $table): void {
                $table->dropColumn(['procurement_sku_values']);
            });
    }
};
