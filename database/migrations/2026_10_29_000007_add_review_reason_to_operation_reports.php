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
        if ($schema->hasTable('operation_reports') && !$schema->hasColumn('operation_reports', 'review_reason')) {
            $schema->table('operation_reports', fn (Blueprint $table) => $table->text('review_reason')->nullable()->after('reviewed_at'));
        }
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);
        if ($schema->hasTable('operation_reports') && $schema->hasColumn('operation_reports', 'review_reason')) {
            $schema->table('operation_reports', fn (Blueprint $table) => $table->dropColumn('review_reason'));
        }
    }
};
