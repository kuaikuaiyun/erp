<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const MENU_CODE = 'dashboard_department_scopes';

    private const NEW_PERMISSION = 'portal.yunying-kanban.dashboard_department_scopes';

    private const OLD_PERMISSION = 'portal.yunying-kanban.operation_reports';

    private const PATH = '/operation-dashboard/department-scopes';

    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);
        if (! $schema->hasTable('portals') || ! $schema->hasTable('portal_menus')) {
            return;
        }

        $database = DB::connection($connection);
        $portalId = $database->table('portals')->where('code', 'yunying-kanban')->value('id');
        if (! $portalId) {
            return;
        }

        $now = now();
        $database->table('portal_menus')
            ->where('portal_id', $portalId)
            ->where('code', self::MENU_CODE)
            ->update(['permission_slug' => self::NEW_PERMISSION, 'updated_at' => $now]);

        $permissionsTable = config('admin.database.permissions_table', 'admin_permissions');
        if (! $schema->hasTable($permissionsTable)) {
            return;
        }

        $database->table($permissionsTable)->updateOrInsert(
            ['slug' => self::NEW_PERMISSION],
            [
                'name' => '[运营看板] 账号部门查看权限（仅 Boss）',
                'http_method' => 'GET,POST',
                'http_path' => self::PATH,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        $oldPermission = $database->table($permissionsTable)
            ->where('slug', self::OLD_PERMISSION)
            ->first(['id', 'http_path']);
        if ($oldPermission) {
            $paths = collect(preg_split('/\R+/', (string) $oldPermission->http_path))
                ->map(fn ($path): string => trim((string) $path))
                ->reject(fn (string $path): bool => $path === self::PATH)
                ->filter()->unique()->values()->implode("\r\n");
            $database->table($permissionsTable)->where('id', $oldPermission->id)
                ->update(['http_path' => $paths, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);
        if (! $schema->hasTable('portals') || ! $schema->hasTable('portal_menus')) {
            return;
        }

        $database = DB::connection($connection);
        $portalId = $database->table('portals')->where('code', 'yunying-kanban')->value('id');
        if ($portalId) {
            $database->table('portal_menus')
                ->where('portal_id', $portalId)
                ->where('code', self::MENU_CODE)
                ->update(['permission_slug' => self::OLD_PERMISSION, 'updated_at' => now()]);
        }
    }
};
