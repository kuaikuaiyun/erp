<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouse_sku_weight_exceptions', function (Blueprint $table): void {
            $table->string('physical_image_path', 500)->nullable()->after('reject_reason')->comment('仓库上传的实物图路径（local disk）');
        });
    }

    public function down(): void
    {
        Schema::table('warehouse_sku_weight_exceptions', function (Blueprint $table): void {
            $table->dropColumn('physical_image_path');
        });
    }
};
