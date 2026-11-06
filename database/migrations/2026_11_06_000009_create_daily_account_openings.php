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
        if (! $schema->hasTable('daily_account_openings')) {
            $schema->create('daily_account_openings', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('operator_user_id')->index();
                $table->string('operator_name', 100);
                $table->unsignedBigInteger('operation_department_id')->nullable()->index();
                $table->string('operation_department_name', 100)->nullable();
                $table->string('media', 100)->index();
                $table->unsignedInteger('quantity');
                $table->string('email')->nullable();
                $table->text('profile_link')->nullable();
                $table->string('bcid', 191)->nullable();
                $table->string('status', 20)->default('pending')->index();
                $table->unsignedBigInteger('processed_by')->nullable();
                $table->string('processed_by_name', 100)->nullable();
                $table->timestamp('processed_at')->nullable();
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('portals') || ! $schema->hasTable('portal_menus')) return;
        $db = DB::connection($connection);
        $portalId = $db->table('portals')->where('code', 'zhuli')->value('id');
        if (! $portalId) return;
        $parentId = $db->table('portal_menus')->where(['portal_id' => $portalId, 'code' => 'assistant_daily_submissions'])->value('id');
        if (! $parentId) return;
        $values = ['parent_id' => $parentId, 'title' => '开户需求处理', 'title_key' => '', 'path' => '/assistant/daily-submissions/account-openings', 'icon' => 'fa-user-plus', 'sort' => 2, 'is_enabled' => 1, 'updated_at' => now()];
        if ($schema->hasColumn('portal_menus', 'permission_slug')) $values['permission_slug'] = 'portal.zhuli.daily.account_opening';
        $db->table('portal_menus')->updateOrInsert(['portal_id' => $portalId, 'code' => 'assistant_daily_account_openings'], [...$values, 'created_at' => now()]);

        $permissions = config('admin.database.permissions_table');
        $roles = config('admin.database.roles_table');
        $pivot = config('admin.database.role_permissions_table');
        if (! $schema->hasTable($permissions) || ! $schema->hasTable($roles) || ! $schema->hasTable($pivot)) return;
        $permissionId = $db->table($permissions)->where('slug', 'portal.zhuli.daily.account_opening')->value('id');
        if (! $permissionId) $permissionId = $db->table($permissions)->insertGetId(['name' => '[助理系统] 开户需求处理', 'slug' => 'portal.zhuli.daily.account_opening', 'http_method' => '', 'http_path' => '', 'created_at' => now(), 'updated_at' => now()]);
        foreach ($db->table($roles)->where('slug', '!=', 'administrator')->pluck('id') as $roleId) {
            $db->table($pivot)->updateOrInsert(['role_id' => $roleId, 'permission_id' => $permissionId], ['created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);
        $db = DB::connection($connection);
        if ($schema->hasTable('portal_menus')) $db->table('portal_menus')->where('code', 'assistant_daily_account_openings')->delete();
        $schema->dropIfExists('daily_account_openings');
    }
};
