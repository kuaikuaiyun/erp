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
        $db = DB::connection($connection);
        $portal = $db->table('portals')->where('code', 'asset-manager')->value('id');
        if (! $portal) {
            throw new RuntimeException(__('asset_reminders.install_required', [], 'zh-CN'));
        }
        $schema = Schema::connection($connection);
        if (! $schema->hasTable('asset_reminder_groups')) {
            $schema->create('asset_reminder_groups', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('department_id')->index('ar_group_dept');
                $t->unsignedBigInteger('company_id');
                $t->string('name', 100);
                $t->string('robot_code', 190);
                $t->string('conversation_id', 255);
                $t->string('remark', 500)->nullable();
                $t->boolean('enabled')->default(false);
                $t->unsignedBigInteger('updated_by');
                $t->unsignedInteger('version')->default(1);
                $t->timestamps();
                $t->unique(['company_id', 'robot_code', 'conversation_id'], 'ar_group_target');
            });
        }
        if (! $schema->hasTable('asset_reminder_rules')) {
            $schema->create('asset_reminder_rules', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('department_id');
                $t->unsignedBigInteger('group_id');
                $t->string('type', 20);
                $t->unsignedBigInteger('attendance_group_id');
                $t->string('attendance_group_name', 190);
                $t->string('operator_userid', 190);
                $t->json('work_times');
                $t->json('rest_times');
                $t->date('starts_on');
                $t->string('entry_url', 1000);
                $t->boolean('enabled')->default(false);
                $t->unsignedBigInteger('updated_by');
                $t->unsignedInteger('version')->default(1);
                $t->text('last_error')->nullable();
                $t->dateTime('last_checked_at')->nullable();
                $t->timestamps();
                $t->unique(['department_id', 'type'], 'ar_rule_dept_type');
            });
        }
        if (! $schema->hasTable('asset_reminder_days')) {
            $schema->create('asset_reminder_days', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('rule_id');
                $t->date('data_date');
                $t->json('snapshot');
                $t->dateTime('synced_at');
                $t->unique(['rule_id', 'data_date'], 'ar_day_rule_date');
            });
        }
        if (! $schema->hasTable('asset_reminder_runs')) {
            $schema->create('asset_reminder_runs', function (Blueprint $t) {
                $t->id();
                $t->string('delivery_key', 64)->unique('ar_run_key');
                $t->unsignedBigInteger('group_id');
                $t->string('type', 20);
                $t->date('data_date');
                $t->string('slot', 5);
                $t->unsignedInteger('round');
                $t->json('rule_ids');
                $t->json('department_ids');
                $t->string('status', 30);
                $t->longText('message')->nullable();
                $t->json('snapshot')->nullable();
                $t->text('error')->nullable();
                $t->string('process_key', 255)->nullable();
                $t->boolean('is_test')->default(false);
                $t->unsignedBigInteger('actor_id')->nullable();
                $t->timestamps();
                $t->index(['group_id', 'created_at'], 'ar_run_group_time');
            });
        }
        if (! $db->table('portal_menus')->where('portal_id', $portal)->where('code', 'asset_manager_reminders')->exists()) {
            $db->table('portal_menus')->insert([
                'portal_id' => $portal, 'parent_id' => 0, 'code' => 'asset_manager_reminders', 'title' => null,
                'title_key' => 'asset_reminders.title', 'path' => '/asset-manager/reminders',
                'permission_slug' => 'asset-manager.computers', 'icon' => 'fa-bell', 'sort' => 80,
                'is_enabled' => true, 'created_at' => now('UTC'), 'updated_at' => now('UTC'),
            ]);
        }
    }

    public function down(): void
    {
        DB::connection(config('admin.database.connection') ?: config('database.default'))
            ->table('portal_menus')->where('code', 'asset_manager_reminders')->update(['is_enabled' => false]);
        // Keep configuration, recipient snapshots and delivery history for audit/recovery.
    }
};
