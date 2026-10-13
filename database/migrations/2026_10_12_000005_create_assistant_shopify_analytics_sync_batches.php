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

        if (!$schema->hasTable('assistant_domain_stores')) {
            return;
        }

        if (!$schema->hasTable('assistant_shopify_analytics_sync_batches')) {
            $schema->create('assistant_shopify_analytics_sync_batches', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedInteger('actor_id')->nullable();
            $table->string('actor_name', 100)->nullable();
            $table->string('status', 30)->default('queued');
            $table->unsignedInteger('total_count')->default(0);
            $table->unsignedInteger('processed_count')->default(0);
            $table->unsignedInteger('success_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedBigInteger('current_store_id')->nullable();
            $table->string('current_store_name', 100)->nullable();
            $table->text('last_error')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();

            $table->index(['actor_id', 'status'], 'as_shopify_sync_batch_actor_status_idx');
            $table->index(['status', 'created_at'], 'as_shopify_sync_batch_status_created_idx');
            });
        }

        if (!$schema->hasTable('assistant_shopify_analytics_sync_tasks')) {
            $schema->create('assistant_shopify_analytics_sync_tasks', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('assistant_shopify_analytics_sync_batch_id');
            $table->unsignedBigInteger('assistant_domain_store_id');
            $table->string('store_name', 100);
            $table->string('status', 30)->default('queued');
            $table->unsignedTinyInteger('attempt_count')->default(0);
            $table->text('last_error')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['assistant_shopify_analytics_sync_batch_id', 'assistant_domain_store_id'],
                'as_shopify_sync_task_batch_store_u',
            );
            $table->index(['assistant_domain_store_id', 'status'], 'as_shopify_sync_task_store_status_idx');
            $table->index(['status', 'created_at'], 'as_shopify_sync_task_status_created_idx');
            });
        }
    }

    public function down(): void
    {
        // Operational synchronization history is intentionally retained on rollback.
    }
};
