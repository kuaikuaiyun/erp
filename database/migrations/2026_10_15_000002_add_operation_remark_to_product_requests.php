<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_requests', function (Blueprint $table): void {
            $table->text('operation_remark')->nullable()->after('has_stock');
        });
    }

    public function down(): void
    {
        Schema::table('product_requests', function (Blueprint $table): void {
            $table->dropColumn('operation_remark');
        });
    }
};
