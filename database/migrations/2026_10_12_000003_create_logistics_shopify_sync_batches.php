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

        if (!$schema->hasTable('logistics_shopify_sync_batches')) {
            $schema->create('logistics_shopify_sync_batches', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedInteger('actor_id')->nullable();
                $table->string('actor_name', 100)->nullable();
                $table->string('status', 30)->default('queued');
                $table->unsignedSmallInteger('sync_days')->default(60);
                $table->unsignedInteger('total_count')->default(0);
                $table->unsignedInteger('processed_count')->default(0);
                $table->unsignedInteger('success_count')->default(0);
                $table->unsignedInteger('failed_count')->default(0);
                $table->unsignedInteger('cancelled_count')->default(0);
                $table->unsignedInteger('created_order_count')->default(0);
                $table->unsignedInteger('updated_order_count')->default(0);
                $table->unsignedInteger('sku_exception_count')->default(0);
                $table->unsignedInteger('skipped_unpaid_count')->default(0);
                $table->unsignedInteger('webhook_warning_count')->default(0);
                $table->unsignedBigInteger('current_store_id')->nullable();
                $table->string('current_store_name', 100)->nullable();
                $table->text('last_error')->nullable();
                $table->dateTime('cancel_requested_at')->nullable();
                $table->dateTime('started_at')->nullable();
                $table->dateTime('completed_at')->nullable();
                $table->timestamps();

                $table->index(['actor_id', 'status'], 'logistics_shopify_sync_batch_actor_status_index');
                $table->index(['status', 'created_at'], 'logistics_shopify_sync_batch_status_created_index');
            });
        }

        if (!$schema->hasTable('logistics_shopify_sync_tasks')) {
            $schema->create('logistics_shopify_sync_tasks', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('logistics_shopify_sync_batch_id');
                $table->unsignedBigInteger('assistant_domain_store_id');
                $table->string('store_name', 100);
                $table->string('status', 30)->default('queued');
                $table->unsignedTinyInteger('attempt_count')->default(0);
                $table->unsignedInteger('created_order_count')->default(0);
                $table->unsignedInteger('updated_order_count')->default(0);
                $table->unsignedInteger('sku_exception_count')->default(0);
                $table->unsignedInteger('skipped_unpaid_count')->default(0);
                $table->boolean('webhook_active')->nullable();
                $table->text('webhook_error')->nullable();
                $table->text('last_error')->nullable();
                $table->dateTime('started_at')->nullable();
                $table->dateTime('completed_at')->nullable();
                $table->timestamps();

                $table->unique(
                    ['logistics_shopify_sync_batch_id', 'assistant_domain_store_id'],
                    'logistics_shopify_sync_task_batch_store_unique'
                );
                $table->index(
                    ['assistant_domain_store_id', 'status'],
                    'logistics_shopify_sync_task_store_status_index'
                );
                $table->index(['status', 'created_at'], 'logistics_shopify_sync_task_status_created_index');
            });
        }
    }

    public function down(): void
    {
        // Operational synchronization history is intentionally retained on rollback.
    }
};
