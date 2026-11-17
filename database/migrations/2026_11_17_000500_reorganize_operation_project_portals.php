<?php

use App\Models\Department;
use App\Portals\Fashion\OperationProjectCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);
        $db = DB::connection($connection);

        if (! $schema->hasTable('portals') || ! $schema->hasTable('portal_menus')) {
            return;
        }

        $now = now();
        $permissionsTable = config('admin.database.permissions_table');
        $rolesTable = config('admin.database.roles_table');
        $rolePermissionsTable = config('admin.database.role_permissions_table');

        if ($schema->hasTable($permissionsTable)) {
            $db->table($permissionsTable)
                ->where('name', 'like', '[服装运营系统]%')
                ->update([
                    'name' => DB::raw("REPLACE(name, '[服装运营系统]', '[单包服装运营系统]')"),
                    'updated_at' => $now,
                ]);
        }

        foreach (['kunpeng-shopify', 'kunpeng-general'] as $obsoleteCode) {
            $portalId = $db->table('portals')->where('code', $obsoleteCode)->value('id');
            if ($portalId) {
                $db->table('portals')->where('id', $portalId)->update(['status' => 'disabled', 'updated_at' => $now]);
                $db->table('portal_menus')->where('portal_id', $portalId)->update(['is_enabled' => 0, 'updated_at' => $now]);
            }
        }

        $pages = [
            ['advertising_expenses', '广告费填写', 'advertising-expenses', 'fa-pencil-square-o', 'GET,POST,PUT,DELETE', 10],
            ['performance_report', '业绩报表', 'performance-report', 'fa-line-chart', 'GET', 20],
            ['stores', '店铺列表', 'stores', 'fa-shopping-bag', 'GET,PATCH', 30],
        ];
        $sourcePermissionIds = $schema->hasTable($permissionsTable)
            ? $db->table($permissionsTable)->whereIn('slug', [
                'portal.fuzhuang.advertising_expenses',
                'portal.fuzhuang.performance_report',
                'portal.fuzhuang.stores',
            ])->pluck('id')
            : collect();
        $sourceRoleIds = $schema->hasTable($rolePermissionsTable)
            ? $db->table($rolePermissionsTable)->whereIn('permission_id', $sourcePermissionIds)->pluck('role_id')->unique()
            : collect();

        foreach (OperationProjectCatalog::all() as $code => $project) {
            // 单包服装门户已有沿用中的 fashion_* 菜单，不能再生成一套
            // fuzhuang_* 菜单，否则会出现路径相同的重复入口。
            if ($code === 'fuzhuang') {
                continue;
            }

            $projectRoleId = null;
            if ($code === 'danbao-general' && $schema->hasTable($rolesTable)) {
                $departmentName = $project['departments'][0];
                $projectRoleId = $db->table($rolesTable)->where('name', $departmentName)->value('id');
                if (! $projectRoleId) {
                    $projectRoleId = (int) $db->table($rolesTable)->insertGetId([
                        'name' => $departmentName,
                        'short_name' => $departmentName,
                        'slug' => 'project-danbao_general',
                        'parent_id' => null,
                        'sort' => 0,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }

            $db->table('portals')->updateOrInsert(
                ['code' => $code],
                [
                    'name' => $project['name'],
                    'remark' => $project['name'].'运营数据管理',
                    'status' => 'enabled',
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
            $portalId = (int) $db->table('portals')->where('code', $code)->value('id');

            foreach ($pages as [$key, $title, $path, $icon, $methods, $sort]) {
                $slug = "portal.{$code}.{$key}";
                $permissionId = null;
                if ($schema->hasTable($permissionsTable)) {
                    $db->table($permissionsTable)->updateOrInsert(['slug' => $slug], [
                        'name' => "[{$project['name']}] {$title}",
                        'http_method' => $methods,
                        'http_path' => "/{$project['path']}/{$path}\r\n/{$project['path']}/{$path}/*",
                        'updated_at' => $now,
                        'created_at' => $now,
                    ]);
                    $permissionId = $db->table($permissionsTable)->where('slug', $slug)->value('id');
                }

                $menuValues = [
                    'parent_id' => 0,
                    'title' => $title,
                    'title_key' => "{$code}.{$key}.menu",
                    'path' => "/{$project['path']}/{$path}",
                    'icon' => $icon,
                    'sort' => $sort,
                    'is_enabled' => 1,
                    'updated_at' => $now,
                    'created_at' => $now,
                ];
                if ($schema->hasColumn('portal_menus', 'permission_slug')) {
                    $menuValues['permission_slug'] = $slug;
                }
                $db->table('portal_menus')->updateOrInsert(
                    ['portal_id' => $portalId, 'code' => "{$code}_{$key}"],
                    $menuValues
                );

                if ($permissionId && $schema->hasTable($rolePermissionsTable)) {
                    foreach ($sourceRoleIds->when($projectRoleId, fn ($ids) => $ids->push($projectRoleId))->unique() as $roleId) {
                        $db->table($rolePermissionsTable)->updateOrInsert(
                            ['role_id' => $roleId, 'permission_id' => $permissionId],
                            ['created_at' => $now, 'updated_at' => $now]
                        );
                    }
                }
            }

            if ($schema->hasTable('portal_departments')) {
                $departmentTable = (new Department)->getTable();
                $departments = $db->table($departmentTable)
                    ->whereIn('name', $project['departments'])
                    ->get(['id', 'parent_id']);
                foreach ($departments as $department) {
                    $rootId = (int) ($department->parent_id ?: $department->id);
                    $db->table('portal_departments')->updateOrInsert(
                        ['portal_id' => $portalId, 'department_id' => $rootId],
                        [
                            'includes_descendants' => 1,
                            'granted_at' => $now,
                            'updated_at' => $now,
                            'created_at' => $now,
                        ]
                    );
                }
            }
        }
    }

    public function down(): void
    {
        // Keep portal records and historical reports; rollback is intentionally non-destructive.
    }
};
