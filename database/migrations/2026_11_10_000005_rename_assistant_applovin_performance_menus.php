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
        $db->table('portal_menus')->where(['portal_id' => $portalId, 'code' => 'assistant_applovin_performance'])
            ->update(['title' => '订阅 AppLovin 业绩报表', 'updated_at' => $now]);
        $db->table('portal_menus')->where(['portal_id' => $portalId, 'code' => 'assistant_fashion_applovin_performance'])
            ->update(['title' => '服装AppLovin 业绩报表', 'updated_at' => $now]);
    }

    public function down(): void
    {
        $db = DB::connection(config('admin.database.connection') ?: config('database.default'));
        $portalId = $db->table('portals')->where('code', 'zhuli')->value('id');
        if (! $portalId) {
            return;
        }
        $now = now();
        $db->table('portal_menus')->where(['portal_id' => $portalId, 'code' => 'assistant_applovin_performance'])
            ->update(['title' => '订阅运营 AppLovin 业绩报表', 'updated_at' => $now]);
        $db->table('portal_menus')->where(['portal_id' => $portalId, 'code' => 'assistant_fashion_applovin_performance'])
            ->update(['title' => '服装运营 AppLovin 业绩报表', 'updated_at' => $now]);
    }
};
