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
        if (! $schema->hasTable('portals') || ! $schema->hasTable('portal_menus')) {
            return;
        }

        $db = DB::connection($connection);
        $portalId = $db->table('portals')->where('code', self::PORTAL_CODE)->value('id');
        if (! $portalId) {
            return;
        }

        $now = now();
        $reportPermission = 'portal.yunying-kanban.operation_reports';
        $appLovinPermission = 'portal.yunying-kanban.applovin_spends';

        $this->upsertMenu($db, $portalId, 0, 'dashboard_fashion_operation_reports', '服装运营报表',
            '/operation-dashboard/fashion-operation-reports', $reportPermission, 'fa-line-chart', 15, $now);

        $parentId = $db->table('portal_menus')->where([
            'portal_id' => $portalId,
            'code' => 'dashboard_applovin_data',
        ])->value('id');
        $parentValues = [
            'parent_id' => 0,
            'title' => 'AppLovin数据',
            'title_key' => '',
            'path' => '#',
            'permission_slug' => $appLovinPermission,
            'icon' => 'fa-area-chart',
            'sort' => 20,
            'is_enabled' => true,
            'updated_at' => $now,
        ];
        if ($parentId) {
            $db->table('portal_menus')->where('id', $parentId)->update($parentValues);
        } else {
            $parentId = $db->table('portal_menus')->insertGetId([
                'portal_id' => $portalId,
                'code' => 'dashboard_applovin_data',
                ...$parentValues,
                'created_at' => $now,
            ]);
        }

        $this->upsertMenu($db, $portalId, (int) $parentId, 'dashboard_applovin_spends', 'AppLovin账号消耗',
            '/operation-dashboard/applovin-spends', $appLovinPermission, 'fa-line-chart', 1, $now);
        $this->upsertMenu($db, $portalId, (int) $parentId, 'dashboard_applovin_account_statistics', 'AppLovin账号统计',
            '/operation-dashboard/applovin-account-statistics', $appLovinPermission, 'fa-pie-chart', 2, $now);
        $this->upsertMenu($db, $portalId, (int) $parentId, 'dashboard_applovin_performance', '订阅 AppLovin 业绩报表',
            '/operation-dashboard/applovin-performance-reports', $appLovinPermission, 'fa-bar-chart', 3, $now);
        $this->upsertMenu($db, $portalId, (int) $parentId, 'dashboard_fashion_applovin_performance', '服装 AppLovin 业绩报表',
            '/operation-dashboard/fashion-applovin-performance-reports', $appLovinPermission, 'fa-bar-chart', 4, $now);

        $this->appendPermissionPaths($db, $schema, $reportPermission, [
            '/operation-dashboard/fashion-operation-reports',
        ], $now);
        $this->appendPermissionPaths($db, $schema, $appLovinPermission, [
            '/operation-dashboard/applovin-spends',
            '/operation-dashboard/applovin-spends/*',
            '/operation-dashboard/applovin-account-statistics',
            '/operation-dashboard/applovin-account-statistics/*',
            '/operation-dashboard/applovin-performance-reports',
            '/operation-dashboard/fashion-applovin-performance-reports',
        ], $now);
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
        if (! $portalId) {
            return;
        }

        $db->table('portal_menus')->where('portal_id', $portalId)->whereIn('code', [
            'dashboard_fashion_operation_reports',
            'dashboard_applovin_account_statistics',
            'dashboard_applovin_performance',
            'dashboard_fashion_applovin_performance',
        ])->delete();
        $db->table('portal_menus')->where([
            'portal_id' => $portalId,
            'code' => 'dashboard_applovin_spends',
        ])->update(['parent_id' => 0, 'sort' => 20, 'updated_at' => now()]);
        $db->table('portal_menus')->where([
            'portal_id' => $portalId,
            'code' => 'dashboard_applovin_data',
        ])->delete();
    }

    private function upsertMenu($db, int $portalId, int $parentId, string $code, string $title,
        string $path, string $permissionSlug, string $icon, int $sort, $now): void
    {
        $db->table('portal_menus')->updateOrInsert(
            ['portal_id' => $portalId, 'code' => $code],
            [
                'parent_id' => $parentId,
                'title' => $title,
                'title_key' => '',
                'path' => $path,
                'permission_slug' => $permissionSlug,
                'icon' => $icon,
                'sort' => $sort,
                'is_enabled' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );
    }

    /** @param list<string> $paths */
    private function appendPermissionPaths($db, $schema, string $slug, array $paths, $now): void
    {
        $permissions = config('admin.database.permissions_table');
        if (! $schema->hasTable($permissions)) {
            return;
        }

        $permission = $db->table($permissions)->where('slug', $slug)->first(['id', 'http_path']);
        if (! $permission) {
            return;
        }

        $httpPath = collect(preg_split('/\R+/', (string) $permission->http_path))
            ->merge($paths)
            ->map(fn ($path) => trim((string) $path))
            ->filter()
            ->unique()
            ->values()
            ->implode("\r\n");

        $db->table($permissions)->where('id', $permission->id)->update([
            'http_method' => 'GET,POST',
            'http_path' => $httpPath,
            'updated_at' => $now,
        ]);
    }
};
