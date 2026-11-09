<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);
        if (! $schema->hasTable('daily_account_openings')) return;

        $schema->table('daily_account_openings', function (Blueprint $table): void {
            $table->unsignedBigInteger('agency_id')->nullable()->after('operation_department_name')->index();
            $table->string('agency_name', 100)->nullable()->after('agency_id');
        });
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);
        if (! $schema->hasTable('daily_account_openings')) return;

        $schema->table('daily_account_openings', function (Blueprint $table): void {
            $table->dropIndex(['agency_id']);
            $table->dropColumn(['agency_id', 'agency_name']);
        });
    }
};
