<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::connection(config('admin.database.connection') ?: config('database.default'))
            ->table('product_requests', function (Blueprint $table): void {
                $table->string('placement_recheck_status', 20)->default('none')->comment('审核链接人员复查状态')->after('procurement_rectification_status');
            });
    }

    public function down(): void
    {
        Schema::connection(config('admin.database.connection') ?: config('database.default'))
            ->table('product_requests', function (Blueprint $table): void {
                $table->dropColumn(['placement_recheck_status']);
            });
    }
};
