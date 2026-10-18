<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_request_link_skus', function (Blueprint $table): void {
            $table->string('pcl_product_no')->nullable()->after('supplier_url');
            $table->json('sku_values')->nullable()->after('skus');
        });
    }

    public function down(): void
    {
        Schema::table('product_request_link_skus', function (Blueprint $table): void {
            $table->dropColumn(['pcl_product_no', 'sku_values']);
        });
    }
};
