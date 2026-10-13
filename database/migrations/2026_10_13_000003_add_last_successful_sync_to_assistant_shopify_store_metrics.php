<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);
        $table = 'assistant_shopify_store_metrics';

        if (!$schema->hasTable($table)) {
            return;
        }

        if (!$schema->hasColumn($table, 'last_successful_synced_at')) {
            $schema->table($table, function (Blueprint $table): void {
                $table->timestamp('last_successful_synced_at')->nullable()->after('synced_at');
            });
        }

        // Existing known-success rows keep their previous timestamp. Failed
        // rows intentionally receive no success watermark, making them due
        // for the next 30-minute automatic retry.
        DB::connection($connection)
            ->table($table)
            ->where('sync_status', 'success')
            ->whereNull('last_successful_synced_at')
            ->whereNotNull('synced_at')
            ->update([
                'last_successful_synced_at' => DB::raw('synced_at'),
            ]);
    }

    public function down(): void
    {
        // Preserve the successful-sync watermark during rollback.
    }
};
