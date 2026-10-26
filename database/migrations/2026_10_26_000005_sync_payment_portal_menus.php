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
        $schema = Schema::connection($connection);

        if (!$schema->hasTable('portals') || !$schema->hasTable('portal_menus')) {
            throw new \RuntimeException('支付门户菜单同步失败：缺少 portals 或 portal_menus 数据表。');
        }

        $database = DB::connection($connection);
        $portalId = $database->table('portals')->where('code', 'ppay')->value('id');

        if (!$portalId) {
            throw new \RuntimeException('支付门户菜单同步失败：未找到门户编码 ppay。');
        }

        foreach ([
            ['code' => 'payment_account_risk', 'title_key' => 'payment.menus.account_risk', 'path' => '/account-risk', 'icon' => 'fa-shield', 'sort' => 10],
            ['code' => 'payment_product_risk_profit', 'title_key' => 'payment.menus.product_risk_profit', 'path' => '/product-risk-profit', 'icon' => 'fa-line-chart', 'sort' => 20],
            ['code' => 'payment_product_alerts', 'title_key' => 'payment.menus.product_alerts', 'path' => '/product-alerts', 'icon' => 'fa-bell-o', 'sort' => 30],
            ['code' => 'payment_orders', 'title_key' => 'payment.menus.payment_orders', 'path' => '/payment-orders', 'icon' => 'fa-search', 'sort' => 40],
        ] as $menu) {
            $key = ['portal_id' => (int) $portalId, 'code' => $menu['code']];
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

            if ($database->table('portal_menus')->where($key)->exists()) {
                $database->table('portal_menus')->where($key)->update($values);

                continue;
            }

            $database->table('portal_menus')->insert([
                ...$key,
                ...$values,
                'created_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // 支付中台菜单属于门户配置，回滚时保留，避免删除管理员后续调整的配置。
    }
};
