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

        if (!$schema->hasTable('assistant_shopify_store_metrics')
            || $schema->hasColumn('assistant_shopify_store_metrics', 'orders_initial_backfilled_at')) {
            return;
        }

        $schema->table('assistant_shopify_store_metrics', function (Blueprint $table): void {
            $table->dateTime('orders_initial_backfilled_at')
                ->nullable()
                ->after('last_successful_synced_at');
            $table->index('orders_initial_backfilled_at', 'as_shopify_metric_initial_sync_idx');
        });
    }

    public function down(): void
    {
        // The initial order-sync marker is operational state and is retained.
    }
};
