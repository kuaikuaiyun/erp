<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        Schema::connection($connection)->table('product_requests', function (Blueprint $table): void {
            $table->text('inspection_reject_reason')->nullable()->after('inspected_at');
        });
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        Schema::connection($connection)->table('product_requests', function (Blueprint $table): void {
            $table->dropColumn('inspection_reject_reason');
        });
    }
};
