<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_requests', function (Blueprint $table): void {
            $table->string('pcl_product_no', 100)->nullable()->after('standard_chinese_name');
        });
    }

    public function down(): void
    {
        Schema::table('product_requests', function (Blueprint $table): void {
            $table->dropColumn('pcl_product_no');
        });
    }
};
