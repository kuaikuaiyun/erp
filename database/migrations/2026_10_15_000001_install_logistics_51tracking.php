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

        if ($schema->hasTable('logistics_orders')) {
            $missingColumns = [];
            foreach ([
                'tracking_courier_code', 'tracking_courier_name', 'tracking_api_status', 'tracking_api_substatus',
                'tracking_latest_event', 'tracking_latest_checkpoint_at', 'tracking_signed_by', 'tracking_registered_at',
                'tracking_last_checked_at', 'tracking_next_sync_at', 'tracking_attempts', 'tracking_last_error',
                'tracking_raw_payload', 'tracking_status_source',
            ] as $column) {
                if (!$schema->hasColumn('logistics_orders', $column)) {
                    $missingColumns[] = $column;
                }
            }

            if ($missingColumns !== []) {
                $schema->table('logistics_orders', function (Blueprint $table) use ($missingColumns): void {
                    if (in_array('tracking_courier_code', $missingColumns, true)) {
                        $table->string('tracking_courier_code', 80)->nullable();
                    }
                    if (in_array('tracking_courier_name', $missingColumns, true)) {
                        $table->string('tracking_courier_name', 100)->nullable();
                    }
                    if (in_array('tracking_api_status', $missingColumns, true)) {
                        $table->string('tracking_api_status', 50)->nullable();
                    }
                    if (in_array('tracking_api_substatus', $missingColumns, true)) {
                        $table->string('tracking_api_substatus', 100)->nullable();
                    }
                    if (in_array('tracking_latest_event', $missingColumns, true)) {
                        $table->text('tracking_latest_event')->nullable();
                    }
                    if (in_array('tracking_latest_checkpoint_at', $missingColumns, true)) {
                        $table->dateTime('tracking_latest_checkpoint_at')->nullable();
                    }
                    if (in_array('tracking_signed_by', $missingColumns, true)) {
                        $table->string('tracking_signed_by', 191)->nullable();
                    }
                    if (in_array('tracking_registered_at', $missingColumns, true)) {
                        $table->dateTime('tracking_registered_at')->nullable();
                    }
                    if (in_array('tracking_last_checked_at', $missingColumns, true)) {
                        $table->dateTime('tracking_last_checked_at')->nullable();
                    }
                    if (in_array('tracking_next_sync_at', $missingColumns, true)) {
                        $table->dateTime('tracking_next_sync_at')->nullable();
                    }
                    if (in_array('tracking_attempts', $missingColumns, true)) {
                        $table->unsignedInteger('tracking_attempts')->default(0);
                    }
                    if (in_array('tracking_last_error', $missingColumns, true)) {
                        $table->text('tracking_last_error')->nullable();
                    }
                    if (in_array('tracking_raw_payload', $missingColumns, true)) {
                        $table->longText('tracking_raw_payload')->nullable();
                    }
                    if (in_array('tracking_status_source', $missingColumns, true)) {
                        $table->string('tracking_status_source', 30)->nullable();
                    }
                });
            }

            $indexes = collect(DB::connection($connection)->select('SHOW INDEX FROM logistics_orders'))
                ->pluck('Key_name')
                ->all();
            if (!in_array('lo_trk_next_idx', $indexes, true)) {
                $schema->table('logistics_orders', function (Blueprint $table): void {
                    $table->index('tracking_next_sync_at', 'lo_trk_next_idx');
                });
            }
            if (!in_array('lo_trk_code_idx', $indexes, true)) {
                $schema->table('logistics_orders', function (Blueprint $table): void {
                    $table->index('tracking_courier_code', 'lo_trk_code_idx');
                });
            }

            DB::connection($connection)
                ->table('logistics_orders')
                ->whereNotNull('tracking_number')
                ->where('tracking_number', '!=', '')
                ->whereIn('logistics_status', ['已发货', '已签收', '物流异常'])
                ->update([
                    'logistics_status' => '待查询',
                    'tracking_status_source' => '51tracking',
                    'tracking_next_sync_at' => now(),
                    'updated_at' => now(),
                ]);
        }

        if (!$schema->hasTable('logistics_tracking_sync_batches')) {
            $schema->create('logistics_tracking_sync_batches', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedInteger('actor_id')->nullable();
                $table->string('actor_name', 100)->nullable();
                $table->string('source', 30)->default('manual');
                $table->string('status', 30)->default('queued');
                $table->unsignedInteger('total_count')->default(0);
                $table->unsignedInteger('processed_count')->default(0);
                $table->unsignedInteger('success_count')->default(0);
                $table->unsignedInteger('skipped_count')->default(0);
                $table->unsignedInteger('failed_count')->default(0);
                $table->string('current_tracking_number', 191)->nullable();
                $table->text('last_error')->nullable();
                $table->dateTime('started_at')->nullable();
                $table->dateTime('completed_at')->nullable();
                $table->timestamps();

                $table->index(['actor_id', 'status'], 'ltsb_actor_stat_idx');
                $table->index(['status', 'created_at'], 'ltsb_stat_created_idx');
            });
        }

        if (!$schema->hasTable('logistics_tracking_sync_tasks')) {
            $schema->create('logistics_tracking_sync_tasks', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('logistics_tracking_sync_batch_id')->nullable();
                $table->unsignedBigInteger('logistics_order_id');
                $table->string('tracking_number', 191)->nullable();
                $table->string('courier_code', 80)->nullable();
                $table->string('source', 30)->default('manual');
                $table->string('stage', 30)->default('register');
                $table->string('status', 30)->default('queued');
                $table->unsignedTinyInteger('attempt_count')->default(0);
                $table->dateTime('due_at')->nullable();
                $table->text('last_error')->nullable();
                $table->dateTime('started_at')->nullable();
                $table->dateTime('completed_at')->nullable();
                $table->timestamps();

                $table->index(['status', 'due_at'], 'ltst_stat_due_idx');
                $table->index(['logistics_order_id', 'status'], 'ltst_order_stat_idx');
                $table->index('logistics_tracking_sync_batch_id', 'ltst_batch_idx');
            });
        }
    }

    public function down(): void
    {
        // 物流查询历史与真实状态属于运营数据，回滚时保留。
    }
};
