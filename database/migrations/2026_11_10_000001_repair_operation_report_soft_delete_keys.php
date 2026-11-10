<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);

        if (! $schema->hasTable('operation_reports') || ! $schema->hasColumn('operation_reports', 'deletion_key')) {
            return;
        }

        DB::connection($connection)->table('operation_reports')
            ->whereNotNull('deleted_at')
            ->where('deletion_key', 0)
            ->update(['deletion_key' => DB::raw('id')]);
    }

    public function down(): void
    {
        // Historical soft-deleted rows must retain their distinct keys.
    }
};
