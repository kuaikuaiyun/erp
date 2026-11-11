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
        $legacy = (clone $menus)->where('code', 'finance_credit-settlement');
        $menu = $legacy->first();
        if (! $menu) {
            return;
        }

        foreach (['ye-settlement' => '叶总支付明细', 'worldpay-settlement' => 'worldpy明细'] as $code => $title) {
            $db->table('portal_menus')->updateOrInsert(['portal_id' => $portalId, 'code' => 'finance_'.$code], [
                'parent_id' => $menu->parent_id, 'title' => $title, 'title_key' => 'finance.menus.'.$code,
                'path' => '/finance/reports/'.$code, 'icon' => $menu->icon, 'permission_slug' => $menu->permission_slug,
                'sort' => $menu->sort + ($code === 'worldpay-settlement' ? 1 : 0),
                'is_enabled' => $menu->is_enabled, 'updated_at' => now(),
            ]);
        }
        $legacy->update(['is_enabled' => false, 'updated_at' => now()]);
    }

    public function down(): void
    {
        $db = DB::connection(config('admin.database.connection') ?: config('database.default'));
        $portalId = $db->table('portals')->where('code', 'caiwu')->value('id');
        $menus = $db->table('portal_menus')->where('portal_id', $portalId);
        $enabled = (clone $menus)->where('code', 'finance_ye-settlement')->value('is_enabled');
        if ($enabled !== null) {
            (clone $menus)->where('code', 'finance_credit-settlement')->update(['is_enabled' => $enabled, 'updated_at' => now()]);
        }
        $menus->whereIn('code', ['finance_ye-settlement', 'finance_worldpay-settlement'])->delete();
    }
};
