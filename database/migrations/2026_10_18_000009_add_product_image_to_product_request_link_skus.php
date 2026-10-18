<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_request_link_skus', function (Blueprint $table) {
            $table->string('product_image')->nullable()->after('images')->comment('产品图路径（导入 Excel 主图时保存）');
        });
    }

    public function down(): void
    {
        Schema::table('product_request_link_skus', function (Blueprint $table) {
            $table->dropColumn('product_image');
        });
    }
};
