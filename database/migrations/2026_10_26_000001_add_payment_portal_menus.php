<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');

        if (!Schema::connection($connection)->hasTable('portals') || !Schema::connection($connection)->hasTable('portal_menus')) {
            return;
        }

        $database = DB::connection($connection);
        $portalId = $database->table('portals')->where('code', 'ppay')->value('id');

        if (!$portalId) {
            return;
        }

        foreach ([
            ['code' => 'payment_account_risk', 'title_key' => 'payment.menus.account_risk', 'path' => '/account-risk', 'icon' => 'fa-shield', 'sort' => 10],
            ['code' => 'payment_product_risk_profit', 'title_key' => 'payment.menus.product_risk_profit', 'path' => '/product-risk-profit', 'icon' => 'fa-line-chart', 'sort' => 20],
            ['code' => 'payment_product_alerts', 'title_key' => 'payment.menus.product_alerts', 'path' => '/product-alerts', 'icon' => 'fa-bell-o', 'sort' => 30],
            ['code' => 'payment_orders', 'title_key' => 'payment.menus.payment_orders', 'path' => '/payment-orders', 'icon' => 'fa-search', 'sort' => 40],
        ] as $menu) {
            $values = [
                'parent_id' => 0,
                'title' => (string) Lang::get($menu['title_key'], [], 'zh-CN'),
                'title_key' => $menu['title_key'],
                'path' => $menu['path'],
                'icon' => $menu['icon'],
                'sort' => $menu['sort'],
                'is_enabled' => true,
                'updated_at' => now(),
            ];
            $existing = $database->table('portal_menus')->where('portal_id', $portalId)->where('code', $menu['code'])->first(['id']);

            if ($existing) {
                $database->table('portal_menus')->where('id', $existing->id)->update($values);

                continue;
            }

            $database->table('portal_menus')->insert([
                'portal_id' => $portalId,
                'code' => $menu['code'],
                ...$values,
                'created_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Portal menu configuration is intentionally retained on rollback.
    }
};
