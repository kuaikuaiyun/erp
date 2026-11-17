<?php

use App\Models\Department;
use App\Portals\Fashion\OperationProjectCatalog;
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
        $db = DB::connection($connection);

        if ($schema->hasTable('fashion_advertising_reports') && ! $schema->hasColumn('fashion_advertising_reports', 'project_code')) {
            $schema->table('fashion_advertising_reports', function (Blueprint $table): void {
                $table->string('project_code', 50)->default('fuzhuang')->after('id')->index();
            });
        }
        if ($schema->hasTable('fashion_advertising_reports')) {
            try {
                $schema->table('fashion_advertising_reports', fn (Blueprint $table) => $table->dropUnique('fashion_ad_report_date_user_unique'));
            } catch (Throwable) {
            }
            try {
                $schema->table('fashion_advertising_reports', fn (Blueprint $table) => $table->unique(['project_code', 'report_date', 'submitter_id'], 'fashion_ad_report_project_date_user_unique'));
            } catch (Throwable) {
            }
        }

        if (! $schema->hasTable('portals') || ! $schema->hasTable('portal_menus')) {
            return;
        }
        $now = now();
        $permissionsTable = config('admin.database.permissions_table');
        $rolesTable = config('admin.database.roles_table');
        $pivotTable = config('admin.database.role_permissions_table');
        $sourceRoleIds = $schema->hasTable($permissionsTable) && $schema->hasTable($pivotTable)
            ? $db->table($pivotTable)->whereIn('permission_id', $db->table($permissionsTable)->whereIn('slug', [
                'portal.fuzhuang.advertising_expenses', 'portal.fuzhuang.performance_report', 'portal.fuzhuang.stores',
            ])->pluck('id'))->pluck('role_id')->unique()
            : collect();
        if ($schema->hasTable($rolesTable) && ($administratorId = $db->table($rolesTable)->where('slug', 'administrator')->value('id'))) {
            $sourceRoleIds->push($administratorId);
        }

        foreach (OperationProjectCatalog::all() as $code => $project) {
            if ($code === 'fuzhuang') {
                continue;
            }
            $departmentName = $project['departments'][0];
            $departmentId = $db->table($rolesTable)->where('name', $departmentName)->value('id');
            if (! $departmentId) {
                $departmentId = (int) $db->table($rolesTable)->insertGetId([
                    'name' => $departmentName, 'short_name' => $departmentName, 'slug' => 'project-'.str_replace('-', '_', $code),
                    'parent_id' => null, 'sort' => 0, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            $db->table('portals')->updateOrInsert(
                ['code' => $code],
                ['name' => $project['name'], 'remark' => $project['name'].'运营数据管理', 'status' => 'enabled', 'updated_at' => $now, 'created_at' => $now]
            );
            $portalId = (int) $db->table('portals')->where('code', $code)->value('id');

            $pages = [
                ['advertising_expenses', '广告费填写', 'advertising-expenses', 'fa-pencil-square-o', 'GET,POST,PUT,DELETE', 10],
                ['performance_report', '业绩报表', 'performance-report', 'fa-line-chart', 'GET', 20],
                ['stores', '店铺列表', 'stores', 'fa-shopping-bag', 'GET,PATCH', 30],
            ];
            foreach ($pages as [$key, $title, $path, $icon, $methods, $sort]) {
                $slug = "portal.{$code}.{$key}";
                $permissionId = null;
                if ($schema->hasTable($permissionsTable)) {
                    $db->table($permissionsTable)->updateOrInsert(['slug' => $slug], [
                        'name' => "[{$project['name']}] {$title}", 'http_method' => $methods,
                        'http_path' => "/{$project['path']}/{$path}\r\n/{$project['path']}/{$path}/*",
                        'updated_at' => $now, 'created_at' => $now,
                    ]);
                    $permissionId = $db->table($permissionsTable)->where('slug', $slug)->value('id');
                }
                $db->table('portal_menus')->updateOrInsert(['portal_id' => $portalId, 'code' => "{$code}_{$key}"], [
                    'parent_id' => 0, 'title' => $title, 'title_key' => "{$code}.{$key}.menu", 'path' => "/{$project['path']}/{$path}",
                    'permission_slug' => $slug, 'icon' => $icon, 'sort' => $sort, 'is_enabled' => 1,
                    'updated_at' => $now, 'created_at' => $now,
                ]);
                if ($permissionId && $schema->hasTable($pivotTable)) {
                    foreach ($sourceRoleIds->merge([$departmentId])->unique() as $roleId) {
                        $db->table($pivotTable)->updateOrInsert(['role_id' => $roleId, 'permission_id' => $permissionId], ['created_at' => $now, 'updated_at' => $now]);
                    }
                }
            }

            if ($schema->hasTable('portal_departments')) {
                $departmentIds = $db->table((new Department)->getTable())->whereIn('name', $project['departments'])->pluck('id')->push($departmentId)->unique();
                foreach ($departmentIds as $departmentId) {
                    $db->table('portal_departments')->updateOrInsert(['portal_id' => $portalId, 'department_id' => $departmentId], ['includes_descendants' => 1, 'granted_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
                }
            }
        }
    }

    public function down(): void
    {
        // Keep portals and business data to make rollback non-destructive.
    }
};
