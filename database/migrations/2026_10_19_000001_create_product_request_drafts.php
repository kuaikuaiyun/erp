<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_request_drafts', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->unsignedBigInteger('user_id')->index()->comment('草稿归属的运营账号');
            $table->string('skus', 2048)->nullable()->comment('运营填写的 SKU');
            $table->string('operation_supplier_url', 2048)->nullable()->comment('运营填写的 1688 链接');
            $table->string('has_stock', 10)->nullable()->comment('是否有现货：yes/no');
            $table->text('operation_remark')->nullable();
            $table->json('images')->nullable()->comment('产品图 [{path, original_name, mime_type, size}]');
            $table->json('purchase_images')->nullable()->comment('1688 具体购买项目截图');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_request_drafts');
    }
};
