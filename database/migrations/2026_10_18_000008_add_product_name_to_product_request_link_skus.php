<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_request_link_skus', function (Blueprint $table) {
            $table->string('product_name')->nullable()->after('pcl_product_no')->comment('商品名称（导入时保存）');
        });
    }

    public function down(): void
    {
        Schema::table('product_request_link_skus', function (Blueprint $table) {
            $table->dropColumn('product_name');
        });
    }
};
