<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PORTALS = [
        'yunying' => [
            'slug' => 'portal.yunying.daily.applovin_spends',
            'parent' => 'operation_applovin_data',
            'spend' => 'operation_daily_applovin_spends',
            'spend_path' => '/operation/daily-submissions/applovin-spends',
            'statistics' => 'operation_applovin_account_statistics',
            'statistics_path' => '/operation/applovin-account-statistics',
            'performance' => 'operation_applovin_performance',
            'performance_path' => '/operation/applovin-performance-reports',
        ],
        'fuzhuang' => [
            'slug' => 'portal.fuzhuang.applovin_spends',
            'parent' => 'fashion_applovin_data',
            'spend' => 'fashion_applovin_spends',
            'spend_path' => '/fashion/applovin-spends',
            'statistics' => 'fashion_applovin_account_statistics',
            'statistics_path' => '/fashion/applovin-account-statistics',
            'performance' => 'fashion_applovin_performance',
            'performance_path' => '/fashion/applovin-performance-reports',
        ],
    ];

    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);
        if (! $schema->hasTable('portals') || ! $schema->hasTable('portal_menus')) return;

        $db = DB::connection($connection);
        $permissions = config('admin.database.permissions_table');
        $now = now();
        foreach (self::PORTALS as $portalCode => $config) {
            $portalId = $db->table('portals')->where('code', $portalCode)->value('id');
            if (! $portalId) continue;

            if ($schema->hasTable($permissions)) {
                $permission = $db->table($permissions)->where('slug', $config['slug'])->first(['id', 'http_path']);
                if ($permission) {
                    $paths = collect(preg_split('/\R+/', (string) $permission->http_path))
                        ->merge([
                            $config['spend_path'], $config['spend_path'].'/*',
                            $config['statistics_path'], $config['statistics_path'].'/*',
                            $config['performance_path'], $config['performance_path'].'/*',
                        ])->map(fn ($path) => trim((string) $path))->filter()->unique()->values()->implode("\r\n");
                    $db->table($permissions)->where('id', $permission->id)->update([
                        'http_method' => 'GET,POST', 'http_path' => $paths, 'updated_at' => $now,
                    ]);
                }
            }

            $parentId = $db->table('portal_menus')->where([
                'portal_id' => $portalId, 'code' => $config['parent'],
            ])->value('id');
            $parentValues = [
                'parent_id' => 0, 'title' => 'AppLovin数据', 'title_key' => '', 'path' => '#',
                'permission_slug' => $config['slug'], 'icon' => 'fa-area-chart', 'sort' => 25,
                'is_enabled' => true, 'updated_at' => $now,
            ];
            if ($parentId) {
                $db->table('portal_menus')->where('id', $parentId)->update($parentValues);
            } else {
                $parentId = $db->table('portal_menus')->insertGetId([
                    'portal_id' => $portalId, 'code' => $config['parent'], ...$parentValues, 'created_at' => $now,
                ]);
            }

            $db->table('portal_menus')->where([
                'portal_id' => $portalId, 'code' => $config['spend'],
            ])->update(['parent_id' => $parentId, 'sort' => 1, 'updated_at' => $now]);

            $this->upsertMenu($db, $portalId, $parentId, $config['statistics'], 'AppLovin账号统计',
                $config['statistics_path'], $config['slug'], 'fa-pie-chart', 2, $now);
            $this->upsertMenu($db, $portalId, $parentId, $config['performance'], 'AppLovin业绩报表',
                $config['performance_path'], $config['slug'], 'fa-bar-chart', 3, $now);
        }
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);
        if (! $schema->hasTable('portals') || ! $schema->hasTable('portal_menus')) return;

        $db = DB::connection($connection);
        foreach (self::PORTALS as $portalCode => $config) {
            $portalId = $db->table('portals')->where('code', $portalCode)->value('id');
            if (! $portalId) continue;
            $db->table('portal_menus')->where('portal_id', $portalId)
                ->whereIn('code', [$config['statistics'], $config['performance']])->delete();
            $db->table('portal_menus')->where(['portal_id' => $portalId, 'code' => $config['spend']])
                ->update(['parent_id' => 0, 'updated_at' => now()]);
            $db->table('portal_menus')->where(['portal_id' => $portalId, 'code' => $config['parent']])->delete();
        }
    }

    private function upsertMenu($db, int $portalId, int $parentId, string $code, string $title,
        string $path, string $slug, string $icon, int $sort, $now): void
    {
        $db->table('portal_menus')->updateOrInsert(
            ['portal_id' => $portalId, 'code' => $code],
            [
                'parent_id' => $parentId, 'title' => $title, 'title_key' => '', 'path' => $path,
                'permission_slug' => $slug, 'icon' => $icon, 'sort' => $sort, 'is_enabled' => true,
                'created_at' => $now, 'updated_at' => $now,
            ]
        );
    }
};
