<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);
        $db = DB::connection($connection);
        if (! $schema->hasTable('daily_advertising_account_authorizations')) {
            $schema->create('daily_advertising_account_authorizations', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('operator_user_id')->index('daa_operator_idx');
                $table->string('operator_name');
                $table->unsignedBigInteger('operation_department_id')->nullable()->index('daa_operation_dept_idx');
                $table->string('operation_department_name')->nullable();
                $table->unsignedBigInteger('assistant_department_id')->nullable()->index('daa_assistant_dept_idx');
                $table->unsignedBigInteger('agency_id')->index('daa_agency_idx');
                $table->string('agency_name');
                $table->string('media_name')->nullable();
                $table->string('account_name');
                $table->string('account_number');
                $table->string('new_email');
                $table->string('old_email')->nullable();
                $table->boolean('old_email_abandoned')->default(false);
                $table->string('status', 20)->default('pending')->index('daa_status_idx');
                $table->text('response_text')->nullable();
                $table->unsignedBigInteger('processed_by')->nullable();
                $table->string('processed_by_name')->nullable();
                $table->timestamp('processed_at')->nullable();
                $table->timestamps();
            });
        }
        if (! $schema->hasTable('portals') || ! $schema->hasTable('portal_menus')) return;
        $portalId = $db->table('portals')->where('code', 'zhuli')->value('id');
        $parentId = $portalId ? $db->table('portal_menus')->where(['portal_id' => $portalId, 'code' => 'assistant_daily_submissions'])->value('id') : null;
        if (! $parentId) return;
        $values = ['parent_id' => $parentId, 'title' => '广告账号授权处理', 'title_key' => '', 'path' => '/assistant/daily-submissions/advertising-account-authorizations', 'icon' => 'fa-key', 'sort' => 4, 'is_enabled' => true, 'updated_at' => now()];
        if ($schema->hasColumn('portal_menus', 'permission_slug')) $values['permission_slug'] = 'portal.zhuli.daily.ad_account_auth';
        $db->table('portal_menus')->updateOrInsert(['portal_id' => $portalId, 'code' => 'assistant_daily_ad_account_authorizations'], [...$values, 'created_at' => now()]);
        $permissions = config('admin.database.permissions_table', 'admin_permissions');
        $roles = config('admin.database.roles_table', 'admin_roles');
        $pivot = config('admin.database.role_permissions_table', 'admin_role_permissions');
        if (! $schema->hasTable($permissions) || ! $schema->hasTable($roles) || ! $schema->hasTable($pivot)) return;
        $permissionId = $db->table($permissions)->where('slug', 'portal.zhuli.daily.ad_account_auth')->value('id');
        if (! $permissionId) $permissionId = $db->table($permissions)->insertGetId(['name' => '[助理系统] 广告账号授权处理', 'slug' => 'portal.zhuli.daily.ad_account_auth', 'http_method' => '', 'http_path' => '', 'created_at' => now(), 'updated_at' => now()]);
        foreach ($db->table($roles)->whereIn('slug', ['administrator', 'assistant', 'zhuli'])->pluck('id') as $roleId) {
            $db->table($pivot)->updateOrInsert(['role_id' => $roleId, 'permission_id' => $permissionId]);
        }
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection); $db = DB::connection($connection);
        if ($schema->hasTable('portal_menus')) $db->table('portal_menus')->where('code', 'assistant_daily_ad_account_authorizations')->delete();
        $schema->dropIfExists('daily_advertising_account_authorizations');
    }
};
