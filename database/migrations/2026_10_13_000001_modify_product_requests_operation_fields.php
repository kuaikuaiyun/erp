<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 需求提报表单不再采集城市 / 参考页面链接 / 最小售卖单元 / 是否有多属性，改为可空
        DB::statement("ALTER TABLE product_requests MODIFY city VARCHAR(100) NULL, MODIFY minimum_sale_unit VARCHAR(255) NULL, MODIFY has_multiple_attributes VARCHAR(255) NULL");

        Schema::table('product_requests', function (Blueprint $table): void {
            $table->string('skus', 1000)->nullable()->after('has_multiple_attributes')->comment('SKU 列表，英文逗号分隔');
            $table->string('operation_supplier_url', 2048)->nullable()->after('skus')->comment('运营填写的 1688 链接');
        });
    }

    public function down(): void
    {
        Schema::table('product_requests', function (Blueprint $table): void {
            $table->dropColumn(['skus', 'operation_supplier_url']);
        });

        DB::statement("ALTER TABLE product_requests MODIFY city VARCHAR(100) NOT NULL, MODIFY minimum_sale_unit VARCHAR(255) NOT NULL, MODIFY has_multiple_attributes VARCHAR(255) NOT NULL");
    }
};
