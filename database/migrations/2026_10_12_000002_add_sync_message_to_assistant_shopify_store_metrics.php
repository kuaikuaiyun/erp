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

        if ($schema->hasTable('assistant_shopify_store_metrics')
            && !$schema->hasColumn('assistant_shopify_store_metrics', 'sync_message')) {
            $schema->table('assistant_shopify_store_metrics', function (Blueprint $table): void {
                $table->string('sync_message', 80)->nullable()->after('sync_status');
            });
        }
    }

    public function down(): void
    {
        // Keep the last safe synchronization reason during rollback.
    }
};
