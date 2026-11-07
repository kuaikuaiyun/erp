<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PORTAL_CODE = 'fuzhuangwuliu';

    private const PERMISSION = 'portal.fuzhuangwuliu.orders';

    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);
        $db = DB::connection($connection);

        if ($schema->hasTable('fashion_logistics_orders')) {
            $hasPlatform = $schema->hasColumn('fashion_logistics_orders', 'source_platform');
            $hasPaymentStatus = $schema->hasColumn('fashion_logistics_orders', 'payment_status');
            $hasFulfillmentStatus = $schema->hasColumn('fashion_logistics_orders', 'fulfillment_status');
            if (! $hasPlatform || ! $hasPaymentStatus || ! $hasFulfillmentStatus) {
                $schema->table('fashion_logistics_orders', function (Blueprint $table) use ($hasPlatform, $hasPaymentStatus, $hasFulfillmentStatus): void {
                    if (! $hasPlatform) {
                        $table->string('source_platform', 30)->default('xshoppy')->after('assistant_domain_store_id')->index();
                    }
                    if (! $hasPaymentStatus) {
                        $table->string('payment_status', 50)->nullable()->after('status')->index();
                    }
                    if (! $hasFulfillmentStatus) {
                        $table->string('fulfillment_status', 50)->nullable()->after('payment_status')->index();
                    }
                });
            }
            $db->table('fashion_logistics_orders')->whereNull('source_platform')->update(['source_platform' => 'xshoppy']);
            $indexNames = collect($db->select('SHOW INDEX FROM fashion_logistics_orders'))
                ->pluck('Key_name')
                ->filter()
                ->unique()
                ->values()
                ->all();
            if (in_array('fashion_logistics_store_order_unique', $indexNames, true)) {
                $db->statement('ALTER TABLE fashion_logistics_orders DROP INDEX fashion_logistics_store_order_unique');
            }
            if (! in_array('fashion_logistics_store_platform_order_unique', $indexNames, true)) {
                $db->statement('ALTER TABLE fashion_logistics_orders ADD UNIQUE INDEX fashion_logistics_store_platform_order_unique (assistant_domain_store_id, source_platform, external_order_id)');
            }
        }

        if (! $schema->hasTable('portals') || ! $schema->hasTable('portal_menus')) {
            return;
        }
        $portalId = $db->table('portals')->where('code', self::PORTAL_CODE)->value('id');
        if (! $portalId) {
            return;
        }

        $now = now();
        $parent = $db->table('portal_menus')->where('portal_id', $portalId)->where('code', 'fashion_logistics_orders')->first(['id']);
        if (! $parent) {
            return;
        }
        $parentId = (int) $parent->id;
        $db->table('portal_menus')->where('id', $parentId)->update([
            'parent_id' => 0,
            'title' => '订单列表',
            'title_key' => 'fashion_logistics.orders.menu',
            'path' => '#',
            'permission_slug' => null,
            'icon' => 'fa-list-alt',
            'sort' => 10,
            'is_enabled' => true,
            'updated_at' => $now,
        ]);

        $this->upsertMenu($db, (int) $portalId, 'fashion_logistics_xshoppy_orders', [
            'parent_id' => $parentId,
            'title' => 'XShoppy 订单',
            'title_key' => 'fashion_logistics.xshoppy_orders.menu',
            'path' => '/fashion-logistics/orders',
            'permission_slug' => self::PERMISSION,
            'icon' => 'fa-shopping-bag',
            'sort' => 10,
            'is_enabled' => true,
            'updated_at' => $now,
        ]);
        $this->upsertMenu($db, (int) $portalId, 'fashion_logistics_shopline_orders', [
            'parent_id' => $parentId,
            'title' => 'Shopline 订单',
            'title_key' => 'fashion_logistics.shopline_orders.menu',
            'path' => '/fashion-logistics/shopline-orders',
            'permission_slug' => self::PERMISSION,
            'icon' => 'fa-shopping-cart',
            'sort' => 20,
            'is_enabled' => true,
            'updated_at' => $now,
        ]);

        $permissions = config('admin.database.permissions_table');
        if ($schema->hasTable($permissions)) {
            $db->table($permissions)->where('slug', self::PERMISSION)->update([
                'http_path' => "/fashion-logistics/orders\r\n/fashion-logistics/orders/*\r\n/fashion-logistics/shopline-orders\r\n/fashion-logistics/shopline-orders/*",
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // 保留已同步订单与菜单记录，避免回滚业务代码时误删订单数据。
    }

    private function upsertMenu($db, int $portalId, string $code, array $values): void
    {
        $menu = $db->table('portal_menus')->where('portal_id', $portalId)->where('code', $code)->first(['id']);
        if ($menu) {
            $db->table('portal_menus')->where('id', $menu->id)->update($values);
            return;
        }
        $db->table('portal_menus')->insert(['portal_id' => $portalId, 'code' => $code, ...$values, 'created_at' => now()]);
    }
};
