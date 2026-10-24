<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_sku_weight_exceptions', function (Blueprint $table): void {
            $table->id();
            // 关联通途产品信息（来自 logistics_order_items / 产品统计的同一套 SKU）
            $table->string('tongtu_sku', 191)->nullable()->index();
            $table->string('shopify_sku', 191)->nullable()->index();
            $table->string('tongtu_product_code', 191)->nullable()->index();
            $table->string('tongtu_product_name', 255)->nullable();

            // 产品维度（来自产品统计 product_id，格式 product-xxx），方便按 PCL 聚合查看
            $table->string('product_id', 512)->nullable()->index();

            // 重量字段：原始（通途计算值，g）、仓库上报的正确值（g）
            $table->decimal('original_weight_gram', 12, 4)->default(0);
            $table->decimal('corrected_weight_gram', 12, 4)->nullable();

            // 货品图片：展示用（从 logistics_order_items.product_image_url 透传）
            $table->string('product_image_url', 1024)->nullable();

            // 提报备注
            $table->string('remark', 500)->nullable();

            // 状态：pending 待处理 / resolved 已修正
            $table->enum('status', ['pending', 'resolved'])->default('pending')->index();
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedBigInteger('resolved_by_user_id')->nullable();

            // 提报人 / 时间
            $table->unsignedBigInteger('reported_by_user_id')->nullable();
            $table->string('reporter_name', 64)->nullable();
            $table->timestamps();

            $table->index(['status', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_sku_weight_exceptions');
    }
};
