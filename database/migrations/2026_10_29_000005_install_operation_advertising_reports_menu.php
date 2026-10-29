<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);
        if (!$schema->hasTable('portals') || !$schema->hasTable('portal_menus')) return;

        $database = DB::connection($connection);
        $portalId = $database->table('portals')->where('code', 'yunying')->value('id');
        if (!$portalId) return;

        $slug = 'portal.yunying.advertising_reports';
        $menu = [
            'parent_id' => 0,
            'title' => '广告费提报',
            'title_key' => 'operation.advertising_reports.title',
            'path' => '/operation/advertising-reports',
            'permission_slug' => $schema->hasColumn('portal_menus', 'permission_slug') ? $slug : null,
            'icon' => 'fa-bullhorn',
            'sort' => 7,
            'is_enabled' => 1,
            'updated_at' => now(),
        ];
        if (!$schema->hasColumn('portal_menus', 'permission_slug')) unset($menu['permission_slug']);
        $database->table('portal_menus')->updateOrInsert(
            ['portal_id' => $portalId, 'code' => 'advertising_reports'],
            [...$menu, 'created_at' => now()],
        );

        $permissionsTable = config('admin.database.permissions_table');
        $rolesTable = config('admin.database.roles_table');
        $rolePermissionsTable = config('admin.database.role_permissions_table');
        if (!$schema->hasTable($permissionsTable) || !$schema->hasTable($rolesTable) || !$schema->hasTable($rolePermissionsTable)) return;

        $permissionId = $database->table($permissionsTable)->where('slug', $slug)->value('id');
        if (!$permissionId) {
            $permissionId = $database->table($permissionsTable)->insertGetId([
                'name' => '[运营系统] 广告费提报', 'slug' => $slug, 'http_method' => '', 'http_path' => '',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        foreach ($database->table($rolesTable)->where('slug', '!=', 'administrator')->pluck('id') as $roleId) {
            $database->table($rolePermissionsTable)->updateOrInsert(
                ['role_id' => $roleId, 'permission_id' => $permissionId],
                ['created_at' => now(), 'updated_at' => now()],
            );
        }
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $database = DB::connection($connection);
        $slug = 'portal.yunying.advertising_reports';
        $portalId = $database->table('portals')->where('code', 'yunying')->value('id');
        if ($portalId) $database->table('portal_menus')->where('portal_id', $portalId)->where('code', 'advertising_reports')->delete();
        $permissionId = $database->table(config('admin.database.permissions_table'))->where('slug', $slug)->value('id');
        if ($permissionId) {
            $database->table(config('admin.database.role_permissions_table'))->where('permission_id', $permissionId)->delete();
            $database->table(config('admin.database.user_permissions_table'))->where('permission_id', $permissionId)->delete();
            if (Schema::connection($connection)->hasTable('admin_user_permission_overrides')) {
                $database->table('admin_user_permission_overrides')->where('permission_id', $permissionId)->delete();
            }
            $database->table(config('admin.database.permissions_table'))->where('id', $permissionId)->delete();
        }
    }
};
