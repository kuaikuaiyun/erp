<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PORTAL_CODE = 'yunying-kanban';

    private const SUBSCRIPTION_PERMISSION = 'portal.yunying-kanban.operation_reports';

    private const FASHION_PERMISSION = 'portal.yunying-kanban.fashion_reports';

    private const GENERAL_PERMISSION = 'portal.yunying-kanban.general_reports';

    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);
        $permissionsTable = config('admin.database.permissions_table');

        if (! $schema->hasTable('portals')
            || ! $schema->hasTable('portal_menus')
            || ! $schema->hasTable($permissionsTable)) {
            return;
        }

        $db = DB::connection($connection);
        $db->transaction(function () use ($db, $schema, $permissionsTable): void {
            $portalId = $db->table('portals')->where('code', self::PORTAL_CODE)->value('id');
            if (! $portalId) {
                return;
            }

            $now = now();
            $sourcePermissionId = $db->table($permissionsTable)
                ->where('slug', self::SUBSCRIPTION_PERMISSION)
                ->value('id');

            $fashionPermissionId = $this->ensurePermission(
                $db,
                $permissionsTable,
                self::FASHION_PERMISSION,
                '[业绩看板] 单包服装项目报表',
                '/operation-dashboard/fashion-operation-reports',
                $now,
            );
            $generalPermissionId = $this->ensurePermission(
                $db,
                $permissionsTable,
                self::GENERAL_PERMISSION,
                '[业绩看板] 单包百货项目报表',
                '/operation-dashboard/fashion-operation-reports',
                $now,
            );

            $db->table('portal_menus')->where([
                'portal_id' => $portalId,
                'code' => 'dashboard_fashion_operation_reports',
            ])->update(['permission_slug' => self::FASHION_PERMISSION, 'updated_at' => $now]);
            $db->table('portal_menus')->where([
                'portal_id' => $portalId,
                'code' => 'dashboard_danbao_general_operation_reports',
            ])->update(['permission_slug' => self::GENERAL_PERMISSION, 'updated_at' => $now]);

            if ($sourcePermissionId) {
                $db->table($permissionsTable)->where('id', $sourcePermissionId)->update([
                    'name' => '[业绩看板] 订阅项目报表',
                    'updated_at' => $now,
                ]);
                foreach ([$fashionPermissionId, $generalPermissionId] as $targetPermissionId) {
                    $this->copyPermissionAssignments(
                        $db,
                        $schema,
                        (int) $sourcePermissionId,
                        $targetPermissionId,
                        $now,
                    );
                }
            }
        });
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);
        $permissionsTable = config('admin.database.permissions_table');

        if (! $schema->hasTable('portals')
            || ! $schema->hasTable('portal_menus')
            || ! $schema->hasTable($permissionsTable)) {
            return;
        }

        $db = DB::connection($connection);
        $db->transaction(function () use ($db, $schema, $permissionsTable): void {
            $portalId = $db->table('portals')->where('code', self::PORTAL_CODE)->value('id');
            $sourcePermissionId = $db->table($permissionsTable)
                ->where('slug', self::SUBSCRIPTION_PERMISSION)
                ->value('id');
            $splitPermissionIds = $db->table($permissionsTable)
                ->whereIn('slug', [self::FASHION_PERMISSION, self::GENERAL_PERMISSION])
                ->pluck('id');
            $now = now();

            if ($sourcePermissionId) {
                foreach ($splitPermissionIds as $splitPermissionId) {
                    $this->copyPermissionAssignments(
                        $db,
                        $schema,
                        (int) $splitPermissionId,
                        (int) $sourcePermissionId,
                        $now,
                    );
                }
            }

            if ($portalId) {
                $db->table('portal_menus')
                    ->where('portal_id', $portalId)
                    ->whereIn('code', [
                        'dashboard_fashion_operation_reports',
                        'dashboard_danbao_general_operation_reports',
                    ])
                    ->update(['permission_slug' => self::SUBSCRIPTION_PERMISSION, 'updated_at' => $now]);
            }

            foreach ($splitPermissionIds as $splitPermissionId) {
                $this->deletePermissionAssignments($db, $schema, (int) $splitPermissionId);
            }
            if ($splitPermissionIds->isNotEmpty()) {
                $db->table($permissionsTable)->whereIn('id', $splitPermissionIds)->delete();
            }
        });
    }

    private function ensurePermission($db, string $table, string $slug, string $name, string $path, $now): int
    {
        $id = $db->table($table)->where('slug', $slug)->value('id');
        if ($id) {
            $db->table($table)->where('id', $id)->update([
                'name' => $name,
                'http_method' => 'GET',
                'http_path' => $path,
                'updated_at' => $now,
            ]);

            return (int) $id;
        }

        return (int) $db->table($table)->insertGetId([
            'name' => $name,
            'slug' => $slug,
            'http_method' => 'GET',
            'http_path' => $path,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function copyPermissionAssignments($db, $schema, int $sourceId, int $targetId, $now): void
    {
        $simplePivots = [
            config('admin.database.role_permissions_table') => 'role_id',
            config('admin.database.user_permissions_table') => 'user_id',
        ];
        foreach ($simplePivots as $table => $ownerColumn) {
            if (! $schema->hasTable($table)) {
                continue;
            }
            foreach ($db->table($table)->where('permission_id', $sourceId)->pluck($ownerColumn) as $ownerId) {
                $db->table($table)->updateOrInsert(
                    [$ownerColumn => $ownerId, 'permission_id' => $targetId],
                    ['created_at' => $now, 'updated_at' => $now],
                );
            }
        }

        if ($schema->hasTable('admin_department_permission_scopes')) {
            foreach ($db->table('admin_department_permission_scopes')->where('permission_id', $sourceId)->get() as $scope) {
                $db->table('admin_department_permission_scopes')->updateOrInsert(
                    ['role_id' => $scope->role_id, 'permission_id' => $targetId],
                    ['scope' => $scope->scope, 'created_at' => $now, 'updated_at' => $now],
                );
            }
        }

        if ($schema->hasTable('admin_user_permission_overrides')) {
            foreach ($db->table('admin_user_permission_overrides')->where('permission_id', $sourceId)->get() as $override) {
                $db->table('admin_user_permission_overrides')->updateOrInsert(
                    ['user_id' => $override->user_id, 'permission_id' => $targetId],
                    [
                        'effect' => $override->effect,
                        'updated_by' => $override->updated_by,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                );
            }
        }
    }

    private function deletePermissionAssignments($db, $schema, int $permissionId): void
    {
        foreach ([
            config('admin.database.role_permissions_table'),
            config('admin.database.user_permissions_table'),
            'admin_department_permission_scopes',
            'admin_user_permission_overrides',
        ] as $table) {
            if ($schema->hasTable($table)) {
                $db->table($table)->where('permission_id', $permissionId)->delete();
            }
        }
    }
};
