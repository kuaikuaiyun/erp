<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $db = DB::connection(config('admin.database.connection') ?: config('database.default'));
        $portalId = $db->table('portals')->where('code', 'zhuli')->value('id');
        if (! $portalId) {
            return;
        }

        $now = now();
        $slug = 'portal.zhuli.daily.applovin_spends';
        $parentId = $db->table('portal_menus')->where(['portal_id' => $portalId, 'code' => 'assistant_applovin_reports'])->value('id');
        if (! $parentId) {
            $parentId = $db->table('portal_menus')->insertGetId([
                'portal_id' => $portalId, 'parent_id' => 0, 'code' => 'assistant_applovin_reports', 'title' => 'AppLovin报表',
                'title_key' => '', 'path' => '#', 'permission_slug' => $slug, 'icon' => 'fa-area-chart', 'sort' => 7,
                'is_enabled' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $db->table('portal_menus')->where(['portal_id' => $portalId, 'code' => 'assistant_applovin_performance'])
            ->update(['parent_id' => $parentId, 'title' => '订阅 AppLovin 业绩报表', 'path' => '/assistant/applovin-performance-reports',
                'permission_slug' => $slug, 'sort' => 2, 'is_enabled' => 1, 'updated_at' => $now]);

        $db->table('portal_menus')->updateOrInsert(
            ['portal_id' => $portalId, 'code' => 'assistant_fashion_applovin_performance'],
            ['parent_id' => $parentId, 'title' => '服装AppLovin 业绩报表', 'title_key' => '',
                'path' => '/assistant/fashion-applovin-performance-reports', 'permission_slug' => $slug,
                'icon' => 'fa-shopping-bag', 'sort' => 3, 'is_enabled' => 1, 'created_at' => $now, 'updated_at' => $now]
        );
    }

    public function down(): void
    {
        // 修复迁移不回退菜单，避免误删已授权的线上菜单。
    }
};
