<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PORTAL_CODE = 'yunying-kanban';
    private const MENU_CODE = 'dashboard_danbao_general_operation_reports';

    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);
        if (! $schema->hasTable('portals') || ! $schema->hasTable('portal_menus')) {
            return;
        }

        $db = DB::connection($connection);
        $portalId = $db->table('portals')->where('code', self::PORTAL_CODE)->value('id');
        if (! $portalId) {
            return;
        }

        $permission = (string) ($db->table('portal_menus')->where([
            'portal_id' => $portalId,
            'code' => 'dashboard_fashion_operation_reports',
        ])->value('permission_slug') ?: 'portal.yunying-kanban.operation_reports');

        $now = now();
        $db->table('portal_menus')->updateOrInsert(
            ['portal_id' => $portalId, 'code' => self::MENU_CODE],
            [
                'parent_id' => 0,
                'title' => '单包百货项目报表',
                'title_key' => '',
                'path' => '/operation-dashboard/fashion-operation-reports?project_code=danbao-general',
                'permission_slug' => $permission,
                'icon' => 'fa-line-chart',
                'sort' => 16,
                'is_enabled' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);
        if (! $schema->hasTable('portals') || ! $schema->hasTable('portal_menus')) {
            return;
        }

        $db = DB::connection($connection);
        $portalId = $db->table('portals')->where('code', self::PORTAL_CODE)->value('id');
        if ($portalId) {
            $db->table('portal_menus')->where([
                'portal_id' => $portalId,
                'code' => self::MENU_CODE,
            ])->delete();
        }
    }
};
