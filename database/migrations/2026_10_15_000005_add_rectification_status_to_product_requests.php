<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::connection(config('admin.database.connection') ?: config('database.default'))
            ->table('product_requests', function (Blueprint $table): void {
                $table->string('operation_rectification_status', 20)->default('none')->comment('运营整改状态')->after('warehouse_reject_reason');
                $table->string('procurement_rectification_status', 20)->default('none')->comment('录入人员整改状态')->after('operation_rectification_status');
            });
    }

    public function down(): void
    {
        Schema::connection(config('admin.database.connection') ?: config('database.default'))
            ->table('product_requests', function (Blueprint $table): void {
                $table->dropColumn(['operation_rectification_status', 'procurement_rectification_status']);
            });
    }
};
