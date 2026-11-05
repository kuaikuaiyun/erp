<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $db = DB::connection(config('admin.database.connection') ?: config('database.default'));
        $portalId = $db->table('portals')->where('code', 'caiwu')->value('id');
        if (! $portalId) {
            return;
        }
        $ordersId = $db->table('portal_menus')->where('portal_id', $portalId)->where('code', 'finance_orders')->value('id');
        if ($ordersId) {
            $db->table('portal_menus')->where('portal_id', $portalId)->whereIn('code', ['finance_paypal-details', 'finance_xuzong2-details', 'finance_xuzong1-details'])->update(['parent_id' => $ordersId]);
            $db->table('portal_menus')->where('portal_id', $portalId)->where('code', 'finance_details')->update(['is_enabled' => false]);
        }
        foreach (['finance_orders' => ['订单数据', '订单列表'], 'finance_reports' => ['汇总报表', '报表视图'], 'finance_settings' => ['基础设置', '配置中心']] as $code => [$before, $after]) {
            $db->table('portal_menus')->where('portal_id', $portalId)->where('code', $code)->where('title', $before)->update(['title' => $after]);
        }
    }

    public function down(): void {}
};
