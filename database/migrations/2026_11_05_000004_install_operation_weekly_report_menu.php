<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSION_SLUG = 'portal.yunying.weekly_reports';

    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);
        if (! $schema->hasTable('portals') || ! $schema->hasTable('portal_menus')) return;

        $database = DB::connection($connection);
        $portalId = $database->table('portals')->where('code', 'yunying')->value('id');
        if (! $portalId) return;

        $parentId = $database->table('portal_menus')
            ->where('portal_id', $portalId)
            ->where('code', 'operation_performance_reports')
            ->value('id');
        if (! $parentId) {
            $parentId = $database->table('portal_menus')->insertGetId([
                'portal_id' => $portalId,
                'parent_id' => 0,
                'code' => 'operation_performance_reports',
                'title' => '业绩报表',
                'title_key' => 'operation.performance_reports.menu',
                'path' => '#',
                'icon' => 'fa-line-chart',
                'sort' => 5,
                'is_enabled' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $menu = [
            'parent_id' => $parentId,
            'title' => '运营周报',
            'title_key' => 'operation.weekly_reports.menu',
            'path' => '/operation/weekly-reports',
            'icon' => 'fa-calendar-check-o',
            'sort' => 2,
            'is_enabled' => true,
            'updated_at' => now(),
        ];
        if ($schema->hasColumn('portal_menus', 'permission_slug')) {
            $menu['permission_slug'] = self::PERMISSION_SLUG;
        }
        $database->table('portal_menus')->updateOrInsert(
            ['portal_id' => $portalId, 'code' => 'operation_weekly_reports'],
            [...$menu, 'created_at' => now()]
        );

        $permissionsTable = config('admin.database.permissions_table');
        $rolesTable = config('admin.database.roles_table');
        $rolePermissionsTable = config('admin.database.role_permissions_table');
        if (! $schema->hasTable($permissionsTable) || ! $schema->hasTable($rolesTable) || ! $schema->hasTable($rolePermissionsTable)) return;

        $permissionId = $database->table($permissionsTable)->where('slug', self::PERMISSION_SLUG)->value('id');
        if (! $permissionId) {
            $permissionId = $database->table($permissionsTable)->insertGetId([
                'name' => '[运营系统] 运营周报',
                'slug' => self::PERMISSION_SLUG,
                'http_method' => '',
                'http_path' => '',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach ($database->table($rolesTable)->where('slug', '!=', 'administrator')->pluck('id') as $roleId) {
            $database->table($rolePermissionsTable)->updateOrInsert(
                ['role_id' => $roleId, 'permission_id' => $permissionId],
                ['created_at' => now(), 'updated_at' => now()]
            );
        }
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);
        $database = DB::connection($connection);

        if ($schema->hasTable('portal_menus')) {
            $database->table('portal_menus')->where('code', 'operation_weekly_reports')->delete();
        }

        $permissionsTable = config('admin.database.permissions_table');
        if (! $schema->hasTable($permissionsTable)) return;
        $permissionId = $database->table($permissionsTable)->where('slug', self::PERMISSION_SLUG)->value('id');
        if (! $permissionId) return;

        foreach ([config('admin.database.role_permissions_table'), config('admin.database.user_permissions_table')] as $table) {
            if ($schema->hasTable($table)) $database->table($table)->where('permission_id', $permissionId)->delete();
        }
        if ($schema->hasTable('admin_user_permission_overrides')) {
            $database->table('admin_user_permission_overrides')->where('permission_id', $permissionId)->delete();
        }
        $database->table($permissionsTable)->where('id', $permissionId)->delete();
    }
};
