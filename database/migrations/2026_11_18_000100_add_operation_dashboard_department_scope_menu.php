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
        if (! $schema->hasTable('portals') || ! $schema->hasTable('portal_menus')) return;

        $db = DB::connection($connection);
        $portalId = $db->table('portals')->where('code', 'yunying-kanban')->value('id');
        if (! $portalId) return;

        $now = now();
        $permission = 'portal.yunying-kanban.operation_reports';
        $db->table('portal_menus')->updateOrInsert(
            ['portal_id' => $portalId, 'code' => 'dashboard_department_scopes'],
            ['parent_id' => 0, 'title' => '账号部门查看权限', 'title_key' => '',
                'path' => '/operation-dashboard/department-scopes', 'permission_slug' => $permission,
                'icon' => 'fa-shield', 'sort' => 90, 'is_enabled' => true,
                'created_at' => $now, 'updated_at' => $now]
        );

        $permissionsTable = config('admin.database.permissions_table');
        if (! $schema->hasTable($permissionsTable)) return;
        $record = $db->table($permissionsTable)->where('slug', $permission)->first(['id', 'http_path']);
        if (! $record) return;
        $paths = collect(preg_split('/\R+/', (string) $record->http_path))
            ->merge(['/operation-dashboard/department-scopes'])->map(fn ($path) => trim((string) $path))
            ->filter()->unique()->values()->implode("\r\n");
        $db->table($permissionsTable)->where('id', $record->id)->update([
            'http_method' => 'GET,POST', 'http_path' => $paths, 'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        if (! Schema::connection($connection)->hasTable('portal_menus')) return;
        $portalId = DB::connection($connection)->table('portals')->where('code', 'yunying-kanban')->value('id');
        DB::connection($connection)->table('portal_menus')->where('portal_id', $portalId)
            ->where('code', 'dashboard_department_scopes')->delete();
    }
};
