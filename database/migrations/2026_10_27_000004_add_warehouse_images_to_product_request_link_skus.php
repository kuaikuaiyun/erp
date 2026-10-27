<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_request_link_skus', function (Blueprint $table): void {
            $table->json('warehouse_images')->nullable()->after('images')->comment('仓库异常反馈实拍图路径集合');
        });
    }

    public function down(): void
    {
        Schema::table('product_request_link_skus', function (Blueprint $table): void {
            $table->dropColumn('warehouse_images');
        });
    }
};
