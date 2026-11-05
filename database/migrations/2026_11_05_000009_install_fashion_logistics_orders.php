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

        if (! $schema->hasTable('fashion_logistics_orders')) {
            $schema->create('fashion_logistics_orders', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('assistant_domain_store_id')->index();
                $table->string('store_name', 191)->nullable()->index();
                $table->string('source_domain', 255)->index();
                $table->string('external_order_id', 100);
                $table->string('order_number', 100)->nullable()->index();
                $table->string('status', 50)->nullable()->index();
                $table->string('currency', 20)->nullable();
                $table->decimal('total_amount', 18, 4)->default(0);
                $table->decimal('shipping_amount', 18, 4)->default(0);
                $table->decimal('discount_amount', 18, 4)->default(0);
                $table->decimal('tax_amount', 18, 4)->default(0);
                $table->string('payment_method', 100)->nullable();
                $table->string('payment_method_title', 191)->nullable();
                $table->string('transaction_id', 191)->nullable()->index();
                $table->unsignedBigInteger('customer_id')->nullable();
                $table->string('customer_name', 191)->nullable();
                $table->string('customer_email', 191)->nullable()->index();
                $table->string('customer_phone', 100)->nullable();
                $table->string('shipping_country', 100)->nullable();
                $table->string('shipping_state', 100)->nullable();
                $table->string('shipping_city', 100)->nullable();
                $table->text('shipping_address')->nullable();
                $table->text('customer_note')->nullable();
                $table->dateTime('order_created_at')->nullable()->index();
                $table->dateTime('order_modified_at')->nullable()->index();
                $table->dateTime('paid_at')->nullable();
                $table->dateTime('completed_at')->nullable();
                $table->longText('source_payload')->nullable();
                $table->dateTime('source_synced_at')->nullable();
                $table->timestamps();
                $table->unique(['assistant_domain_store_id', 'external_order_id'], 'fashion_logistics_store_order_unique');
            });
        }

        if (! $schema->hasTable('fashion_logistics_order_items')) {
            $schema->create('fashion_logistics_order_items', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('fashion_logistics_order_id')->index();
                $table->string('external_item_id', 100)->nullable();
                $table->unsignedBigInteger('product_id')->nullable();
                $table->unsignedBigInteger('variation_id')->nullable();
                $table->string('product_name', 500)->nullable();
                $table->string('sku', 255)->nullable()->index();
                $table->decimal('quantity', 18, 4)->default(0);
                $table->decimal('subtotal', 18, 4)->default(0);
                $table->decimal('total', 18, 4)->default(0);
                $table->decimal('total_tax', 18, 4)->default(0);
                $table->string('image_url', 1000)->nullable();
                $table->json('meta_data')->nullable();
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('fashion_logistics_sync_states')) {
            $schema->create('fashion_logistics_sync_states', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('assistant_domain_store_id')->unique();
                $table->string('status', 30)->default('pending')->index();
                $table->dateTime('last_started_at')->nullable();
                $table->dateTime('last_succeeded_at')->nullable();
                $table->dateTime('last_cursor_at')->nullable();
                $table->unsignedInteger('last_created_count')->default(0);
                $table->unsignedInteger('last_updated_count')->default(0);
                $table->text('last_error')->nullable();
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('portals') || ! $schema->hasTable('portal_menus')) return;
        $db = DB::connection($connection);
        $now = now();
        $portal = $db->table('portals')->where('code', self::PORTAL_CODE)->first(['id']);
        $portalValues = ['name' => '服装物流系统', 'remark' => 'XShoppy 服装订单同步与管理', 'status' => 'enabled', 'updated_at' => $now];
        if ($portal) {
            $db->table('portals')->where('id', $portal->id)->update($portalValues);
            $portalId = (int) $portal->id;
        } else {
            $portalId = (int) $db->table('portals')->insertGetId(['code' => self::PORTAL_CODE, ...$portalValues, 'created_at' => $now]);
        }

        $permissionId = null;
        $permissions = config('admin.database.permissions_table');
        if ($schema->hasTable($permissions)) {
            $permission = $db->table($permissions)->where('slug', self::PERMISSION)->first(['id']);
            $values = ['name' => '[服装物流系统] 订单列表', 'http_method' => 'GET', 'http_path' => "/fashion-logistics/orders\r\n/fashion-logistics/orders/*", 'updated_at' => $now];
            if ($permission) {
                $db->table($permissions)->where('id', $permission->id)->update($values);
                $permissionId = (int) $permission->id;
            } else {
                $permissionId = (int) $db->table($permissions)->insertGetId(['slug' => self::PERMISSION, ...$values, 'created_at' => $now]);
            }
        }

        $menuValues = ['parent_id' => 0, 'title' => '订单列表', 'title_key' => 'fashion_logistics.orders.menu', 'path' => '/fashion-logistics/orders', 'permission_slug' => self::PERMISSION, 'icon' => 'fa-list-alt', 'sort' => 10, 'is_enabled' => true, 'updated_at' => $now];
        $menu = $db->table('portal_menus')->where('portal_id', $portalId)->where('code', 'fashion_logistics_orders')->first(['id']);
        if ($menu) $db->table('portal_menus')->where('id', $menu->id)->update($menuValues);
        else $db->table('portal_menus')->insert(['portal_id' => $portalId, 'code' => 'fashion_logistics_orders', ...$menuValues, 'created_at' => $now]);

        if ($permissionId) {
            $roleId = $db->table(config('admin.database.roles_table'))->where('slug', 'administrator')->value('id');
            $pivot = config('admin.database.role_permissions_table');
            if ($roleId && ! $db->table($pivot)->where('role_id', $roleId)->where('permission_id', $permissionId)->exists()) {
                $db->table($pivot)->insert(['role_id' => $roleId, 'permission_id' => $permissionId, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        // 保留订单业务数据，避免回滚时误删。
    }
};
