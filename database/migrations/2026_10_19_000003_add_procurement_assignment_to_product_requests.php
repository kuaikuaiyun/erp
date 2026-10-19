<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::connection(config('admin.database.connection') ?: config('database.default'))
            ->table('product_requests', function (Blueprint $table): void {
                $table->unsignedBigInteger('procurement_assignee_id')->nullable()->index()->comment('商品信息检验分配的录入人员 ID，为空表示待分配')->after('procurement_sku_values');
                $table->string('procurement_assignee_name', 191)->nullable()->comment('分配的录入人员显示名（冗余）')->after('procurement_assignee_id');
                $table->dateTime('procurement_assigned_at')->nullable()->comment('分配时间')->after('procurement_assignee_name');
            });
    }

    public function down(): void
    {
        Schema::connection(config('admin.database.connection') ?: config('database.default'))
            ->table('product_requests', function (Blueprint $table): void {
                $table->dropIndex(['procurement_assignee_id']);
                $table->dropColumn(['procurement_assignee_id', 'procurement_assignee_name', 'procurement_assigned_at']);
            });
    }
};
