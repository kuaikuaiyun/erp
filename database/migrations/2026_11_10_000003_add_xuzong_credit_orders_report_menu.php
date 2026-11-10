<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $db = DB::connection(config('admin.database.connection') ?: config('database.default'));
        $portalId = $db->table('portals')->where('code', 'caiwu')->value('id');
        $menus = $db->table('portal_menus')->where('portal_id', $portalId);
        $parentId = (clone $menus)->where('code', 'finance_reports')->value('id');
        $creditOrders = (clone $menus)->where('code', 'finance_xuzong2-orders')->first(['permission_slug']);
        if (! $parentId || ! $creditOrders) {
            return;
        }

        $db->table('portal_menus')->insertOrIgnore([
            'portal_id' => $portalId, 'parent_id' => $parentId, 'code' => 'finance_xuzong-credit',
            'title' => '徐总信用卡', 'title_key' => 'finance.menus.xuzong-credit', 'path' => '/finance/reports/xuzong-credit',
            'icon' => 'fa-list-alt', 'permission_slug' => $creditOrders->permission_slug,
            'sort' => 50, 'is_enabled' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        $db = DB::connection(config('admin.database.connection') ?: config('database.default'));
        $portalId = $db->table('portals')->where('code', 'caiwu')->value('id');
        $db->table('portal_menus')->where('portal_id', $portalId)->where('code', 'finance_xuzong-credit')->delete();
    }
};
