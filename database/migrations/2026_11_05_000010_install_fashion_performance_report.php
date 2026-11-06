<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PORTAL_CODE = 'fuzhuang';
    private const PERMISSION = 'portal.fuzhuang.performance_report';
    private const SOURCE_PERMISSION = 'portal.fuzhuang.advertising_expenses';

    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);
        if (! $schema->hasTable('portals') || ! $schema->hasTable('portal_menus')) return;

        $db = DB::connection($connection);
        $portalId = $db->table('portals')->where('code', self::PORTAL_CODE)->value('id');
        if (! $portalId) return;

        $now = now();
        $permissionId = null;
        $permissionsTable = config('admin.database.permissions_table');
        if ($schema->hasTable($permissionsTable)) {
            $permission = $db->table($permissionsTable)->where('slug', self::PERMISSION)->first(['id']);
            $values = [
                'name' => '[服装运营系统] 业绩报表',
                'http_method' => 'GET',
                'http_path' => '/fashion/performance-report',
                'updated_at' => $now,
            ];
            if ($permission) {
                $db->table($permissionsTable)->where('id', $permission->id)->update($values);
                $permissionId = (int) $permission->id;
            } else {
                $permissionId = (int) $db->table($permissionsTable)->insertGetId([
                    'slug' => self::PERMISSION,
                    ...$values,
                    'created_at' => $now,
                ]);
            }
        }

        $menuValues = [
            'parent_id' => 0,
            'title' => '业绩报表',
            'title_key' => 'fashion.performance_report.menu',
            'path' => '/fashion/performance-report',
            'permission_slug' => self::PERMISSION,
            'icon' => 'fa-bar-chart',
            'sort' => 20,
            'is_enabled' => true,
            'updated_at' => $now,
        ];
        $menu = $db->table('portal_menus')->where('portal_id', $portalId)->where('code', 'fashion_performance_report')->first(['id']);
        if ($menu) {
            $db->table('portal_menus')->where('id', $menu->id)->update($menuValues);
        } else {
            $db->table('portal_menus')->insert([
                'portal_id' => $portalId,
                'code' => 'fashion_performance_report',
                ...$menuValues,
                'created_at' => $now,
            ]);
        }

        if (! $permissionId) return;
        $rolePermissionsTable = config('admin.database.role_permissions_table');
        $rolesTable = config('admin.database.roles_table');
        $sourcePermissionId = $db->table($permissionsTable)->where('slug', self::SOURCE_PERMISSION)->value('id');
        $roleIds = collect();
        if ($sourcePermissionId) {
            $roleIds = $db->table($rolePermissionsTable)->where('permission_id', $sourcePermissionId)->pluck('role_id');
        }
        $administratorId = $db->table($rolesTable)->where('slug', 'administrator')->value('id');
        if ($administratorId) $roleIds->push($administratorId);

        foreach ($roleIds->unique() as $roleId) {
            if (! $db->table($rolePermissionsTable)->where('role_id', $roleId)->where('permission_id', $permissionId)->exists()) {
                $db->table($rolePermissionsTable)->insert([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $db = DB::connection($connection);
        $portalId = $db->table('portals')->where('code', self::PORTAL_CODE)->value('id');
        if ($portalId) $db->table('portal_menus')->where('portal_id', $portalId)->where('code', 'fashion_performance_report')->delete();

        $permissionsTable = config('admin.database.permissions_table');
        $permissionId = $db->table($permissionsTable)->where('slug', self::PERMISSION)->value('id');
        if ($permissionId) {
            $db->table(config('admin.database.role_permissions_table'))->where('permission_id', $permissionId)->delete();
            $db->table($permissionsTable)->where('id', $permissionId)->delete();
        }
    }
};
