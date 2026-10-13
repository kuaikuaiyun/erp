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

        if (!$schema->hasTable('logistics_tongtu_match_batches')) {
            $schema->create('logistics_tongtu_match_batches', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedInteger('actor_id')->nullable();
                $table->string('actor_name', 100)->nullable();
                $table->string('status', 30)->default('queued');
                $table->unsignedInteger('total_count')->default(0);
                $table->unsignedInteger('processed_count')->default(0);
                $table->unsignedInteger('matched_count')->default(0);
                $table->unsignedInteger('sku_exception_count')->default(0);
                $table->unsignedInteger('not_found_count')->default(0);
                $table->unsignedInteger('failed_count')->default(0);
                $table->text('last_error')->nullable();
                $table->dateTime('started_at')->nullable();
                $table->dateTime('completed_at')->nullable();
                $table->timestamps();

                $table->index(['status', 'created_at'], 'logistics_tongtu_match_batch_status_created_index');
            });
        }

        if (!$schema->hasTable('logistics_tongtu_match_tasks')) {
            $schema->create('logistics_tongtu_match_tasks', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('logistics_tongtu_match_batch_id');
                $table->unsignedBigInteger('logistics_order_id');
                $table->string('status', 30)->default('queued');
                $table->unsignedTinyInteger('attempt_count')->default(0);
                $table->text('last_error')->nullable();
                $table->dateTime('started_at')->nullable();
                $table->dateTime('completed_at')->nullable();
                $table->timestamps();

                $table->unique(['logistics_tongtu_match_batch_id', 'logistics_order_id'], 'logistics_tongtu_match_task_batch_order_unique');
                $table->index(['logistics_order_id', 'status'], 'logistics_tongtu_match_task_order_status_index');
                $table->index(['status', 'created_at'], 'logistics_tongtu_match_task_status_created_index');
            });
        }
    }

    public function down(): void
    {
        // Operational task history is intentionally retained on rollback.
    }
};
