<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $database = DB::connection($connection);
        $schema = Schema::connection($connection);
        if (! $schema->hasTable('portals') || ! $schema->hasTable('portal_menus')) {
            return;
        }

        $portalId = $database->table('portals')->where('code', 'fuzhuang')->value('id');
        if (! $portalId) {
            return;
        }

        $now = now();
        $parentId = $this->upsertMenu($database, (int) $portalId, 'fashion_operation_tasks', [
            'parent_id' => 0,
            'title' => '运营任务',
            'title_key' => '',
            'path' => '#',
            'permission_slug' => null,
            'icon' => 'fa-tasks',
            'sort' => 30,
            'is_enabled' => true,
            'updated_at' => $now,
        ]);

        foreach ([
            ['fashion_operation_task_recaps', '每日复盘', '/fashion/operation-tasks/recaps', 'fa-calendar-check-o', 10, 'portal.fuzhuang.operation-tasks.recaps', 'portal.operation-tasks.recaps'],
            ['fashion_operation_task_tests', '测品任务', '/fashion/operation-tasks/tests', 'fa-flask', 20, 'portal.fuzhuang.operation-tasks.tests', 'portal.operation-tasks.tests'],
            ['fashion_operation_task_reviews', '审核中心', '/fashion/operation-tasks/reviews', 'fa-check-square-o', 30, 'portal.fuzhuang.operation-tasks.reviews', 'portal.operation-tasks.reviews'],
            ['fashion_operation_task_dashboard', '组长看板', '/fashion/operation-tasks/dashboard', 'fa-bar-chart', 40, 'portal.fuzhuang.operation-tasks.dashboard', 'portal.operation-tasks.dashboard'],
        ] as [$code, $title, $path, $icon, $sort, $slug, $legacySlug]) {
            $this->upsertMenu($database, (int) $portalId, $code, [
                'parent_id' => $parentId,
                'title' => $title,
                'title_key' => '',
                'path' => $path,
                'permission_slug' => $slug,
                'icon' => $icon,
                'sort' => $sort,
                'is_enabled' => true,
                'updated_at' => $now,
            ]);
            $this->copyPermission($database, $slug, '[服装运营系统] 运营任务 - '.$title, $path, $legacySlug);
        }

        $this->copyPermission(
            $database,
            'portal.fuzhuang.operation.review.department',
            '[服装运营系统] 运营任务审核部门审核权限',
            '/fashion/operation-tasks/reviews',
            'portal.operation-tasks.review.department'
        );

        $wrongPortalId = $database->table('portals')->where('code', 'fuzhuangwuliu')->value('id');
        if ($wrongPortalId) {
            $database->table('portal_menus')->where('portal_id', $wrongPortalId)->whereIn('code', [
                'fashion_logistics_operation_tasks',
                'fashion_logistics_operation_task_recaps',
                'fashion_logistics_operation_task_tests',
                'fashion_logistics_operation_task_reviews',
                'fashion_logistics_operation_task_dashboard',
            ])->update(['is_enabled' => false, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        // Do not remove business data, menus, or access history during rollback.
    }

    private function upsertMenu($database, int $portalId, string $code, array $values): int
    {
        $menu = $database->table('portal_menus')->where('portal_id', $portalId)->where('code', $code)->first(['id']);
        if ($menu) {
            $database->table('portal_menus')->where('id', $menu->id)->update($values);
            return (int) $menu->id;
        }

        return (int) $database->table('portal_menus')->insertGetId([
            'portal_id' => $portalId,
            'code' => $code,
            ...$values,
            'created_at' => now(),
        ]);
    }

    private function copyPermission($database, string $slug, string $name, string $path, string $legacySlug): void
    {
        $schema = $database->getSchemaBuilder();
        $permissions = config('admin.database.permissions_table', 'admin_permissions');
        $pivot = config('admin.database.role_permissions_table', 'admin_role_permissions');
        if (! $schema->hasTable($permissions) || ! $schema->hasTable($pivot)) {
            return;
        }

        $now = now();
        $permission = $database->table($permissions)
            ->where('slug', $slug)
            ->orWhere('name', $name)
            ->first(['id']);
        $values = ['slug' => $slug, 'name' => $name, 'http_method' => '', 'http_path' => $path."\r\n".$path.'/*', 'updated_at' => $now];
        if ($permission) {
            $permissionId = (int) $permission->id;
            $database->table($permissions)->where('id', $permissionId)->update($values);
        } else {
            $permissionId = $database->table($permissions)->insertGetId([...$values, 'created_at' => $now]);
        }

        $legacyPermissionId = $database->table($permissions)->where('slug', $legacySlug)->value('id');
        if (! $legacyPermissionId) {
            return;
        }

        foreach ($database->table($pivot)->where('permission_id', $legacyPermissionId)->pluck('role_id') as $roleId) {
            $database->table($pivot)->updateOrInsert(['role_id' => $roleId, 'permission_id' => $permissionId], ['created_at' => $now, 'updated_at' => $now]);
        }
    }
};
