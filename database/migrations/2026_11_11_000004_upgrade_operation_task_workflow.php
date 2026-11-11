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

        if ($schema->hasTable('operation_task_assets') && ! $schema->hasColumn('operation_task_assets', 'version_history')) {
            $schema->table('operation_task_assets', function (Blueprint $table): void {
                $table->json('version_history')->nullable()->after('version');
            });
        }

        if ($schema->hasTable('operation_asset_daily_records')) {
            if (! $schema->hasColumn('operation_asset_daily_records', 'updated_by')) {
                $schema->table('operation_asset_daily_records', function (Blueprint $table): void {
                    $table->unsignedInteger('updated_by')->nullable()->after('created_by');
                });
            }
            if (! $schema->hasColumn('operation_asset_daily_records', 'updated_by_name')) {
                $schema->table('operation_asset_daily_records', function (Blueprint $table): void {
                    $table->string('updated_by_name', 100)->nullable()->after('updated_by');
                });
            }
        }

        if (! $schema->hasTable('operation_task_review_events')) {
            $schema->create('operation_task_review_events', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('review_id')->nullable()->index();
                $table->string('target_type', 20)->index();
                $table->unsignedBigInteger('target_id')->index();
                $table->unsignedInteger('target_version');
                $table->string('channel', 30)->index();
                $table->string('result', 30)->index();
                $table->text('opinion')->nullable();
                $table->string('rejection_scope', 30)->nullable();
                $table->unsignedInteger('reviewer_id');
                $table->string('reviewer_name', 100);
                $table->timestamp('reviewed_at');
                $table->timestamps();
                $table->index(['target_type', 'target_id', 'target_version'], 'operation_task_review_event_target_idx');
            });
        }

        if (! $schema->hasTable('operation_task_daily_plans')) {
            $schema->create('operation_task_daily_plans', function (Blueprint $table): void {
                $table->id();
                $table->date('plan_date');
                $table->unsignedInteger('user_id')->index();
                $table->string('user_name', 100);
                $table->unsignedInteger('department_id')->nullable()->index();
                $table->string('department_name', 191)->nullable();
                $table->string('plan_mode', 20)->index();
                $table->unsignedInteger('single_target')->default(0);
                $table->unsignedInteger('category_target')->default(0);
                $table->text('plan_note')->nullable();
                $table->unsignedInteger('confirmed_by')->nullable();
                $table->string('confirmed_by_name', 100)->nullable();
                $table->timestamp('confirmed_at')->nullable();
                $table->timestamps();
                $table->unique(['plan_date', 'user_id'], 'operation_task_daily_plan_user_date_unique');
            });
        }

        if (! $schema->hasTable('operation_task_delivery_rounds')) {
            $schema->create('operation_task_delivery_rounds', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('task_id')->index();
                $table->unsignedBigInteger('asset_id')->index();
                $table->string('media', 100);
                $table->string('ad_identifier', 191)->nullable();
                $table->text('delivery_purpose')->nullable();
                $table->timestamp('launched_at');
                $table->timestamp('stopped_at')->nullable();
                $table->text('notes')->nullable();
                $table->unsignedInteger('created_by');
                $table->string('created_by_name', 100);
                $table->unsignedInteger('stopped_by')->nullable();
                $table->string('stopped_by_name', 100)->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);
        $schema->dropIfExists('operation_task_delivery_rounds');
        $schema->dropIfExists('operation_task_daily_plans');
        $schema->dropIfExists('operation_task_review_events');
    }
};
