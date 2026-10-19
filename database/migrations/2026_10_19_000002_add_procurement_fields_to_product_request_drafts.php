<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_request_drafts', function (Blueprint $table) {
            $table->string('stage', 20)->default('operation')->after('user_id')->index()->comment('草稿类型：operation 需求提报 / procurement 商品信息检验');
            $table->unsignedBigInteger('product_request_id')->nullable()->after('stage')->index()->comment('商品信息检验草稿所属需求提报记录');
            $table->json('payload')->nullable()->after('product_request_id')->comment('商品信息检验草稿表单数据');
        });
    }

    public function down(): void
    {
        Schema::table('product_request_drafts', function (Blueprint $table) {
            $table->dropColumn(['stage', 'product_request_id', 'payload']);
        });
    }
};
