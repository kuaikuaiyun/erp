<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_requests', function (Blueprint $table): void {
            // 采购检验勾选的 SKU 单独存储，与运营填写的 skus 区分开
            $table->text('procurement_skus')->nullable()->after('skus');
        });
    }

    public function down(): void
    {
        Schema::table('product_requests', function (Blueprint $table): void {
            $table->dropColumn('procurement_skus');
        });
    }
};
