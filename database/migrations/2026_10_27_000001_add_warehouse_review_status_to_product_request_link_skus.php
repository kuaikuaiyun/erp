<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_request_link_skus', function (Blueprint $table): void {
            $table->string('warehouse_review_status', 32)->default('pending')->after('not_runnable_reason')->index();
            $table->timestamp('warehouse_reviewed_at')->nullable()->after('warehouse_review_status');
        });
    }

    public function down(): void
    {
        Schema::table('product_request_link_skus', function (Blueprint $table): void {
            $table->dropColumn(['warehouse_review_status', 'warehouse_reviewed_at']);
        });
    }
};
