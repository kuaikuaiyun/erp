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
        $parentId = $db->table('portal_menus')->where(['portal_id' => $portalId, 'code' => 'assistant_applovin_reports'])->value('id');
        if (! $parentId) {
            return;
        }
        $now = now();
        $slug = 'portal.zhuli.daily.applovin_spends';
        $db->table('portal_menus')->where(['portal_id' => $portalId, 'code' => 'assistant_applovin_performance'])
            ->update(['title' => '订阅 AppLovin 业绩报表', 'sort' => 2, 'updated_at' => $now]);
        $db->table('portal_menus')->updateOrInsert(
            ['portal_id' => $portalId, 'code' => 'assistant_fashion_applovin_performance'],
            ['parent_id' => $parentId, 'title' => '服装AppLovin 业绩报表', 'title_key' => '', 'path' => '/assistant/fashion-applovin-performance-reports',
                'permission_slug' => $slug, 'icon' => 'fa-shopping-bag', 'sort' => 3, 'is_enabled' => 1, 'created_at' => $now, 'updated_at' => $now]
        );
    }

    public function down(): void
    {
        $db = DB::connection(config('admin.database.connection') ?: config('database.default'));
        $portalId = $db->table('portals')->where('code', 'zhuli')->value('id');
        if (! $portalId) {
            return;
        }
        $db->table('portal_menus')->where(['portal_id' => $portalId, 'code' => 'assistant_fashion_applovin_performance'])->delete();
        $db->table('portal_menus')->where(['portal_id' => $portalId, 'code' => 'assistant_applovin_performance'])
            ->update(['title' => 'AppLovin业绩报表', 'updated_at' => now()]);
    }
};
