<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        if (! Schema::connection($connection)->hasTable('portals') || ! Schema::connection($connection)->hasTable('portal_menus')) {
            return;
        }

        $database = DB::connection($connection);
        $portalId = $database->table('portals')->where('code', 'ppay')->value('id');
        if (! $portalId) return;

        $now = now();
        $database->table('portal_menus')->where('portal_id', $portalId)->where('code', 'payment_product_alerts')->update(['sort' => 40, 'updated_at' => $now]);
        $database->table('portal_menus')->where('portal_id', $portalId)->where('code', 'payment_orders')->update(['sort' => 50, 'updated_at' => $now]);

        $key = ['portal_id' => (int) $portalId, 'code' => 'payment_renewal_analysis'];
        $values = [
            'parent_id' => 0,
            'title' => '续费订单分析',
            'title_key' => 'payment.menus.renewal_analysis',
            'path' => '/renewal-analysis',
            'icon' => 'fa-refresh',
            'sort' => 30,
            'is_enabled' => true,
            'updated_at' => $now,
        ];

        if ($database->table('portal_menus')->where($key)->exists()) {
            $database->table('portal_menus')->where($key)->update($values);
        } else {
            $database->table('portal_menus')->insert([...$key, ...$values, 'created_at' => $now]);
        }
    }

    public function down(): void
    {
        // 门户菜单作为业务配置保留，避免回滚时误删管理员后续调整。
    }
};
