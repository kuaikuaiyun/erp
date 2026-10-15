<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_request_link_skus', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            // 每个 1688 链接（归一化 hash）只维护一套 SKU，重复保存覆盖同一份
            $table->string('supplier_url_hash', 64)->unique();
            $table->string('supplier_url', 2048)->nullable();
            $table->text('skus')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_request_link_skus');
    }
};
