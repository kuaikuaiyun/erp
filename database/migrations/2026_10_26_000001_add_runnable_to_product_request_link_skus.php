<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_request_link_skus', function (Blueprint $table): void {
            $table->boolean('is_runnable')->default(true)->after('pcl_product_no')->comment('是否可跑');
            $table->string('not_runnable_reason', 50)->nullable()->after('is_runnable')->comment('不可跑原因');
        });
    }

    public function down(): void
    {
        Schema::table('product_request_link_skus', function (Blueprint $table): void {
            $table->dropColumn(['is_runnable', 'not_runnable_reason']);
        });
    }
};
