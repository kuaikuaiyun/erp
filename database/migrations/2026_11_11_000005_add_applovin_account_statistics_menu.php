<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $db = DB::connection(config('admin.database.connection') ?: config('database.default'));
        $portalId = $db->table('portals')->where('code', 'zhuli')->value('id');
        if (! $portalId) return;
        $parentId = $db->table('portal_menus')->where(['portal_id' => $portalId, 'code' => 'assistant_applovin_reports'])->value('id');
        if (! $parentId) return;
        $now = now();
        $db->table('portal_menus')->updateOrInsert(
            ['portal_id' => $portalId, 'code' => 'assistant_applovin_account_statistics'],
            ['parent_id' => $parentId, 'title' => '运营账号统计', 'title_key' => '',
                'path' => '/assistant/applovin-account-statistics', 'permission_slug' => 'portal.zhuli.daily.applovin_spends',
                'icon' => 'fa-users', 'sort' => 2, 'is_enabled' => 1, 'created_at' => $now, 'updated_at' => $now]
        );
        $db->table('portal_menus')->where(['portal_id' => $portalId, 'code' => 'assistant_applovin_performance'])->update(['sort' => 3, 'updated_at' => $now]);
        $db->table('portal_menus')->where(['portal_id' => $portalId, 'code' => 'assistant_fashion_applovin_performance'])->update(['sort' => 4, 'updated_at' => $now]);
    }

    public function down(): void
    {
        $db = DB::connection(config('admin.database.connection') ?: config('database.default'));
        $portalId = $db->table('portals')->where('code', 'zhuli')->value('id');
        if ($portalId) $db->table('portal_menus')->where(['portal_id' => $portalId, 'code' => 'assistant_applovin_account_statistics'])->delete();
    }
};
