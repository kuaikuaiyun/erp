<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PORTAL_CODE = 'wuliu';

    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);

        if ($schema->hasTable('assistant_domain_stores')) {
            $columns = [
                'store_prefix' => 100,
                'shopify_client_id' => 255,
                'shopify_client_secret' => 255,
            ];

            $missing = array_filter(
                $columns,
                static fn (int $length, string $column): bool => !$schema->hasColumn('assistant_domain_stores', $column),
                ARRAY_FILTER_USE_BOTH,
            );

            if ($missing !== []) {
                $schema->table('assistant_domain_stores', function (Blueprint $table) use ($missing): void {
                    foreach ($missing as $column => $length) {
                        $table->string($column, $length)->nullable();
                    }
                });
            }
        }

        if (!$schema->hasTable('logistics_shopify_connections')) {
            $schema->create('logistics_shopify_connections', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('assistant_domain_store_id')->unique();
                $table->string('shop_domain', 255)->nullable();
                $table->text('access_token')->nullable();
                $table->text('granted_scopes')->nullable();
                $table->string('status', 30)->default('not_authorized');
                $table->string('state_token', 128)->nullable()->index();
                $table->dateTime('state_expires_at')->nullable();
                $table->dateTime('authorized_at')->nullable();
                $table->dateTime('last_synced_at')->nullable();
                $table->string('last_sync_status', 30)->nullable();
                $table->text('last_sync_message')->nullable();
                $table->timestamps();

                $table->index('status', 'logistics_shopify_connection_status_index');
            });
        }

        if (!$schema->hasTable('logistics_orders')) {
            $schema->create('logistics_orders', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('assistant_domain_store_id')->index();
                $table->string('shopify_order_id', 100);
                $table->string('shopify_order_number', 100)->nullable();
                $table->string('tongtu_order_number', 191)->nullable()->index();
                $table->string('store_name', 100)->nullable();
                $table->string('department_name', 100)->default('厦门快云');
                $table->string('operator_name', 100)->nullable();
                $table->text('group_names')->nullable();
                $table->string('staff_status', 30)->default('正常');
                $table->string('buyer_name', 191)->nullable();
                $table->string('buyer_email', 191)->nullable();
                $table->string('buyer_phone', 100)->nullable();
                $table->string('is_add_on', 30)->default('未加购');
                $table->string('subscription_type', 100)->nullable();
                $table->string('subscription_origin_order_id', 100)->nullable();
                $table->string('subscription_status', 100)->nullable();
                $table->text('tongtu_sku')->nullable();
                $table->text('tongtu_product_name')->nullable();
                $table->text('sale_quantity')->nullable();
                $table->text('sale_product_weight')->nullable();
                $table->string('payment_status', 100)->nullable();
                $table->string('payment_method', 191)->nullable();
                $table->decimal('paid_amount_usd', 18, 4)->nullable();
                $table->dateTime('paid_at')->nullable();
                $table->decimal('product_cost_usd', 18, 4)->nullable();
                $table->decimal('handling_fee_usd', 18, 4)->nullable();
                $table->decimal('total_shipping_usd', 18, 4)->nullable();
                $table->decimal('proportional_refund_usd', 18, 4)->nullable();
                $table->decimal('proportional_profit_usd', 18, 4)->nullable();
                $table->decimal('proportional_profit_rate', 18, 4)->nullable();
                $table->decimal('financial_profit_usd', 18, 4)->nullable();
                $table->decimal('financial_profit_rate', 18, 4)->nullable();
                $table->decimal('operation_profit_usd', 18, 4)->nullable();
                $table->decimal('operation_profit_rate', 18, 4)->nullable();
                $table->decimal('channel_refund_amount_usd', 18, 4)->nullable();
                $table->decimal('shopify_refund_amount_usd', 18, 4)->nullable();
                $table->dateTime('refunded_at')->nullable();
                $table->text('refund_reason')->nullable();
                $table->decimal('received_amount_usd', 18, 4)->nullable();
                $table->decimal('platform_fee_usd', 18, 4)->nullable();
                $table->decimal('net_amount_usd', 18, 4)->nullable();
                $table->string('collection_account', 255)->nullable();
                $table->string('collection_account_email', 191)->nullable();
                $table->string('transaction_id', 255)->nullable();
                $table->string('bill_number', 255)->nullable();
                $table->string('destination', 255)->nullable();
                $table->string('shipping_company', 191)->nullable();
                $table->text('tracking_number')->nullable();
                $table->string('tracking_sync_status', 50)->default('待匹配');
                $table->dateTime('order_created_at')->nullable();
                $table->string('data_status', 50)->default('待授权');
                $table->text('sku_exception_reason')->nullable();
                $table->string('time_zone', 100)->nullable();
                $table->string('shopify_checkout_id', 255)->nullable();
                $table->text('shopify_cart_token')->nullable();
                $table->string('payment_gateway_order_id', 255)->nullable();
                $table->string('paypal_merchant_id', 255)->nullable();
                $table->longText('page_source')->nullable();
                $table->longText('shopify_payload')->nullable();
                $table->longText('tongtu_payload')->nullable();
                $table->timestamps();

                $table->unique(['assistant_domain_store_id', 'shopify_order_id'], 'logistics_order_store_shopify_unique');
                $table->index(['store_name', 'shopify_order_number'], 'logistics_order_store_number_index');
                $table->index('data_status', 'logistics_order_data_status_index');
                $table->index('order_created_at', 'logistics_order_created_index');
                $table->index('buyer_email', 'logistics_order_buyer_email_index');
                $table->index('transaction_id', 'logistics_order_transaction_index');
            });
        }

        if (!$schema->hasTable('logistics_sync_logs')) {
            $schema->create('logistics_sync_logs', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('shopify_connection_id')->nullable()->index();
                $table->unsignedBigInteger('logistics_order_id')->nullable()->index();
                $table->string('source', 30);
                $table->string('action', 50);
                $table->string('status', 30);
                $table->text('message')->nullable();
                $table->longText('context')->nullable();
                $table->unsignedInteger('actor_id')->nullable();
                $table->string('actor_name', 100)->nullable();
                $table->timestamps();

                $table->index(['source', 'created_at'], 'logistics_sync_log_source_created_index');
            });
        }

        $this->installMenus($connection);
    }

    public function down(): void
    {
        // The portal keeps operational records. Rollback intentionally does not remove order data.
    }

    private function installMenus(?string $connection): void
    {
        $schema = Schema::connection($connection);

        if (!$schema->hasTable('portals') || !$schema->hasTable('portal_menus')) {
            return;
        }

        $database = DB::connection($connection);
        $portalId = $database->table('portals')->where('code', self::PORTAL_CODE)->value('id');

        if (!$portalId) {
            return;
        }

        $timestamp = now();
        $menus = [
            [
                'code' => 'logistics_shopify_stores',
                'title' => 'Shopify 店铺授权与同步',
                'title_key' => 'logistics.shopify_stores.menu',
                'path' => '/shopify-stores',
                'icon' => 'fa-plug',
                'sort' => 10,
            ],
            [
                'code' => 'logistics_orders',
                'title' => '物流订单',
                'title_key' => 'logistics.orders.menu',
                'path' => '/orders',
                'icon' => 'fa-truck',
                'sort' => 20,
            ],
        ];

        foreach ($menus as $menu) {
            $values = [
                'parent_id' => 0,
                'title' => $menu['title'],
                'title_key' => $menu['title_key'],
                'path' => $menu['path'],
                'icon' => $menu['icon'],
                'sort' => $menu['sort'],
                'is_enabled' => true,
                'updated_at' => $timestamp,
            ];

            $exists = $database->table('portal_menus')
                ->where('portal_id', $portalId)
                ->where('code', $menu['code'])
                ->first(['id']);

            if ($exists) {
                $database->table('portal_menus')->where('id', $exists->id)->update($values);
            } else {
                $database->table('portal_menus')->insert([
                    'portal_id' => $portalId,
                    'code' => $menu['code'],
                    ...$values,
                    'created_at' => $timestamp,
                ]);
            }
        }
    }
};
