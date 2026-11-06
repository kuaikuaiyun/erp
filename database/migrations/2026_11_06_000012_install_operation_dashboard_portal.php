<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PORTAL_CODE = 'yunying-kanban';

    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);
        if (! $schema->hasTable('portals') || ! $schema->hasTable('portal_menus')) return;

        $db = DB::connection($connection);
        $db->transaction(function () use ($db, $schema): void {
            $now = now();
            $portalId = $db->table('portals')->where('code', self::PORTAL_CODE)->value('id');
            if (! $portalId) {
                $portalId = $db->table('portals')->insertGetId([
                    'code' => self::PORTAL_CODE,
                    'name' => '运营看板',
                    'remark' => '集中查看全部运营部门的 Shopify 运营报表和 AppLovin 账号消耗。',
                    'status' => 'enabled',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $menus = [
                ['code' => 'dashboard_operation_reports', 'title' => 'Shopify运营报表', 'path' => '/operation-dashboard/operation-reports', 'icon' => 'fa-bar-chart', 'slug' => 'portal.yunying-kanban.operation_reports', 'sort' => 10],
                ['code' => 'dashboard_applovin_spends', 'title' => 'AppLovin账号消耗', 'path' => '/operation-dashboard/applovin-spends', 'icon' => 'fa-line-chart', 'slug' => 'portal.yunying-kanban.applovin_spends', 'sort' => 20],
            ];
            foreach ($menus as $menu) {
                $values = [
                    'parent_id' => 0, 'title' => $menu['title'], 'title_key' => '',
                    'path' => $menu['path'], 'icon' => $menu['icon'], 'sort' => $menu['sort'],
                    'is_enabled' => true, 'updated_at' => $now,
                ];
                if ($schema->hasColumn('portal_menus', 'permission_slug')) $values['permission_slug'] = $menu['slug'];
                $db->table('portal_menus')->updateOrInsert(
                    ['portal_id' => $portalId, 'code' => $menu['code']],
                    [...$values, 'created_at' => $now],
                );
                $this->installPermission($db, $schema, $menu['slug'], $menu['title'], $now);
            }

            $managementId = $db->table('portal_menus')->where(['portal_id' => $portalId, 'code' => 'backend_management'])->value('id');
            if (! $managementId) {
                $managementId = $db->table('portal_menus')->insertGetId([
                    'portal_id' => $portalId, 'parent_id' => 0, 'code' => 'backend_management',
                    'title' => null, 'title_key' => 'portal.menu.backend_management', 'path' => '#',
                    'icon' => 'fa-cogs', 'sort' => 900, 'is_enabled' => true,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            $db->table('portal_menus')->updateOrInsert(
                ['portal_id' => $portalId, 'code' => 'menu_configuration'],
                ['parent_id' => $managementId, 'title' => null, 'title_key' => 'portal.menu.configuration',
                    'path' => '/management/menus', 'icon' => 'fa-list', 'sort' => 1,
                    'is_enabled' => true, 'created_at' => $now, 'updated_at' => $now],
            );
        });
    }

    private function installPermission($db, $schema, string $slug, string $title, $now): void
    {
        $permissions = config('admin.database.permissions_table');
        $roles = config('admin.database.roles_table');
        $pivot = config('admin.database.role_permissions_table');
        if (! $schema->hasTable($permissions) || ! $schema->hasTable($roles) || ! $schema->hasTable($pivot)) return;

        $permissionId = $db->table($permissions)->where('slug', $slug)->value('id');
        if (! $permissionId) {
            $permissionId = $db->table($permissions)->insertGetId([
                'name' => '[运营看板] '.$title, 'slug' => $slug, 'http_method' => '', 'http_path' => '',
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        foreach ($db->table($roles)->where('slug', '!=', 'administrator')->pluck('id') as $roleId) {
            $db->table($pivot)->updateOrInsert(
                ['role_id' => $roleId, 'permission_id' => $permissionId],
                ['created_at' => $now, 'updated_at' => $now],
            );
        }
    }

    public function down(): void
    {
        // Preserve portal access grants and administrator configuration on rollback.
    }
};
