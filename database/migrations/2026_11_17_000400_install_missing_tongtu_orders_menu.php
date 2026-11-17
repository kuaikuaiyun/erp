<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CODE = 'logistics_missing_tongtu_orders';

    public function up(): void
    {
        $db = DB::connection(config('admin.database.connection') ?: config('database.default'));
        $portalId = $db->table('portals')->where('code', 'wuliu')->value('id');
        $ordersMenu = $db->table('portal_menus')
            ->where('portal_id', $portalId)
            ->where('code', 'logistics_orders')
            ->first();

        if (!$ordersMenu) {
            return;
        }

        $db->table('portal_menus')->updateOrInsert(
            ['portal_id' => $portalId, 'code' => self::CODE],
            [
                'parent_id' => $ordersMenu->parent_id,
                'title' => '缺失通途订单核对',
                'title_key' => 'logistics.orders.missing_tongtu',
                'path' => '/missing-tongtu-orders',
                'permission_slug' => $ordersMenu->permission_slug,
                'icon' => 'fa-search',
                'sort' => $ordersMenu->sort + 1,
                'is_enabled' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        $db = DB::connection(config('admin.database.connection') ?: config('database.default'));
        $db->table('portal_menus')
            ->where('portal_id', $db->table('portals')->where('code', 'wuliu')->value('id'))
            ->where('code', self::CODE)
            ->delete();
    }
};
