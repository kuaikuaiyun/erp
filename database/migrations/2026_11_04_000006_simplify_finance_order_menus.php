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

        $db->table('portal_menus')->where('portal_id', $portalId)
            ->whereIn('code', ['finance_paypal-details', 'finance_xuzong2-details', 'finance_xuzong1-details'])
            ->update(['is_enabled' => false, 'updated_at' => now()]);
        $db->table('portal_menus')->where('portal_id', $portalId)->where('code', 'finance_xuzong2-orders')
            ->update(['title' => '信用卡订单数据', 'updated_at' => now()]);
    }

    public function down(): void {}
};
