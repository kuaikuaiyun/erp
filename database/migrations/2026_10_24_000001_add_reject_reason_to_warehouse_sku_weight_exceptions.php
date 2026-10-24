<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouse_sku_weight_exceptions', function (Blueprint $table): void {
            // 驳回理由：仓库在「填写」弹窗里选的原因（重量不符 / 图片不符 / 两者都有）
            $table->string('reject_reason', 64)->nullable()->after('corrected_weight_gram');
            // 入录审核处理备注：独立于仓库提报备注，避免处理时覆盖提报内容
            $table->string('resolution_remark', 500)->nullable()->after('remark');
        });
    }

    public function down(): void
    {
        Schema::table('warehouse_sku_weight_exceptions', function (Blueprint $table): void {
            $table->dropColumn(['reject_reason', 'resolution_remark']);
        });
    }
};
