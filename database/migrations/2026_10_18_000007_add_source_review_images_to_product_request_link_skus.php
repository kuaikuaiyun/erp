<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_request_link_skus', function (Blueprint $table) {
            $table->string('source', 16)->default('system')->after('sku_values')->comment('来源：system 系统添加 / import 导入添加');
            $table->string('review_status', 16)->default('done')->after('source')->comment('复查状态：pending 待复查 / done 已复查');
            $table->json('images')->nullable()->after('review_status')->comment('链接实拍图路径集合');
        });
    }

    public function down(): void
    {
        Schema::table('product_request_link_skus', function (Blueprint $table) {
            $table->dropColumn(['source', 'review_status', 'images']);
        });
    }
};
