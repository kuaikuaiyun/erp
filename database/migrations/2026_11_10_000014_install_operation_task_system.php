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
        $database = DB::connection($connection);

        if (! $schema->hasTable('operation_task_recaps')) {
            $schema->create('operation_task_recaps', function (Blueprint $table): void {
                $table->id();
                $table->date('data_date');
                $table->unsignedInteger('user_id')->index();
                $table->string('user_name', 100);
                $table->unsignedInteger('department_id')->nullable()->index();
                $table->string('department_name', 191)->nullable();
                $table->json('media_items');
                $table->decimal('total_revenue_usd', 18, 2)->default(0);
                $table->unsignedInteger('total_orders')->default(0);
                $table->decimal('total_spend_usd', 18, 2)->default(0);
                $table->text('finding_answer');
                $table->text('action_answer');
                $table->text('advertising_plan');
                $table->text('support_needed')->nullable();
                $table->unsignedInteger('version')->default(1);
                $table->json('version_history')->nullable();
                $table->timestamps();
                $table->unique(['user_id', 'data_date'], 'operation_task_recap_user_date_unique');
            });
        }

        if (! $schema->hasTable('operation_test_tasks')) {
            $schema->create('operation_test_tasks', function (Blueprint $table): void {
                $table->id();
                $table->string('task_no', 64)->unique();
                $table->unsignedInteger('owner_user_id')->index();
                $table->string('owner_name', 100);
                $table->unsignedInteger('department_id')->nullable()->index();
                $table->string('department_name', 191)->nullable();
                $table->string('page_type', 20)->index();
                $table->string('task_type', 20)->index();
                $table->string('landing_url', 2048);
                $table->text('selection_objective');
                $table->unsignedInteger('version')->default(1);
                $table->json('version_history')->nullable();
                $table->string('rectification_status', 30)->default('none')->index();
                $table->string('delivery_status', 30)->default('not_started')->index();
                $table->timestamp('submitted_at');
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('operation_task_assets')) {
            $schema->create('operation_task_assets', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('task_id')->index();
                $table->unsignedInteger('uploader_user_id')->index();
                $table->string('uploader_name', 100);
                $table->text('selling_point');
                $table->string('file_path', 1024)->nullable();
                $table->string('original_name', 255)->nullable();
                $table->string('external_url', 2048)->nullable();
                $table->string('mime_type', 100)->nullable();
                $table->boolean('ai_confirmed')->default(false);
                $table->unsignedBigInteger('parent_asset_id')->nullable()->index();
                $table->unsignedInteger('version')->default(1);
                $table->timestamp('first_live_at')->nullable();
                $table->timestamp('retired_at')->nullable();
                $table->unsignedInteger('retired_by')->nullable();
                $table->text('retired_reason')->nullable();
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('operation_task_reviews')) {
            $schema->create('operation_task_reviews', function (Blueprint $table): void {
                $table->id();
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
                $table->unique(['target_type', 'target_id', 'target_version', 'channel'], 'operation_task_review_version_channel_unique');
            });
        }

        if (! $schema->hasTable('operation_asset_daily_records')) {
            $schema->create('operation_asset_daily_records', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('asset_id')->index();
                $table->string('media', 100);
                $table->date('data_date');
                $table->decimal('spend_usd', 18, 2)->default(0);
                $table->unsignedInteger('orders')->default(0);
                $table->decimal('roi', 18, 6)->nullable();
                $table->text('analysis');
                $table->text('next_action');
                $table->unsignedInteger('version')->default(1);
                $table->json('version_history')->nullable();
                $table->unsignedInteger('created_by');
                $table->timestamps();
                $table->unique(['asset_id', 'media', 'data_date'], 'operation_asset_daily_unique');
            });
        }

        if (! $schema->hasTable('portals') || ! $schema->hasTable('portal_menus')) return;
        $portalId = $database->table('portals')->where('code', 'operation-tasks')->value('id');
        $portalValues = ['name' => '运营任务系统', 'remark' => '运营复盘、测品、审核整改与组长看板', 'status' => 'enabled', 'updated_at' => now()];
        if ($portalId) $database->table('portals')->where('id', $portalId)->update($portalValues);
        else $portalId = $database->table('portals')->insertGetId([...$portalValues, 'code' => 'operation-tasks', 'created_at' => now()]);

        $menus = [
            ['operation_task_recaps', '每日复盘', '/operation-tasks/recaps', 'fa-calendar-check-o', 10, 'portal.operation-tasks.recaps'],
            ['operation_task_tests', '测品任务', '/operation-tasks/tests', 'fa-flask', 20, 'portal.operation-tasks.tests'],
            ['operation_task_reviews', '审核中心', '/operation-tasks/reviews', 'fa-check-square-o', 30, 'portal.operation-tasks.reviews'],
            ['operation_task_dashboard', '组长看板', '/operation-tasks/dashboard', 'fa-bar-chart', 40, 'portal.operation-tasks.dashboard'],
        ];
        foreach ($menus as [$code, $title, $path, $icon, $sort, $slug]) {
            $values = ['parent_id' => 0, 'title' => $title, 'title_key' => '', 'path' => $path, 'icon' => $icon, 'sort' => $sort, 'is_enabled' => 1, 'updated_at' => now()];
            if ($schema->hasColumn('portal_menus', 'permission_slug')) $values['permission_slug'] = $slug;
            $database->table('portal_menus')->updateOrInsert(['portal_id' => $portalId, 'code' => $code], [...$values, 'created_at' => now()]);
            $this->installPermission($database, $slug, '[运营任务系统] '.$title);
        }

        if ($schema->hasTable('portal_departments')) {
            $roles = config('admin.database.roles_table', 'admin_roles');
            foreach ($database->table($roles)->where('slug', '!=', 'administrator')->where(function ($query): void { $query->whereNull('parent_id')->orWhere('parent_id', 0); })->pluck('id') as $departmentId) {
                $database->table('portal_departments')->updateOrInsert(['portal_id' => $portalId, 'department_id' => $departmentId], ['includes_descendants' => 1, 'granted_at' => now(), 'updated_at' => now(), 'created_at' => now()]);
            }
        }
    }

    private function installPermission($database, string $slug, string $name): void
    {
        $permissions = config('admin.database.permissions_table', 'admin_permissions');
        $roles = config('admin.database.roles_table', 'admin_roles');
        $pivot = config('admin.database.role_permissions_table', 'admin_role_permissions');
        if (! $database->getSchemaBuilder()->hasTable($permissions) || ! $database->getSchemaBuilder()->hasTable($pivot)) return;
        $id = $database->table($permissions)->where('slug', $slug)->value('id');
        if (! $id) $id = $database->table($permissions)->insertGetId(['name' => $name, 'slug' => $slug, 'http_method' => '', 'http_path' => '', 'created_at' => now(), 'updated_at' => now()]);
        foreach ($database->table($roles)->where('slug', '!=', 'administrator')->pluck('id') as $roleId) $database->table($pivot)->updateOrInsert(['role_id' => $roleId, 'permission_id' => $id], ['created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void {}
};
