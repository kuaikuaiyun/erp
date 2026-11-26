<?php

namespace Tests\Feature;

use App\Portals\Operation\OperationReportMetricsService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AppLovinPerformanceSubscriptionMetricsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'admin.database.connection' => null]);
        app('db')->purge('sqlite');

        Schema::create('admin_users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('username');
        });
        Schema::create('assistant_domain_stores', function (Blueprint $table): void {
            $table->id();
            $table->string('domain');
            $table->string('shop_name')->nullable();
            $table->unsignedBigInteger('owner_user_id');
        });
        Schema::create('logistics_orders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('assistant_domain_store_id');
            $table->unsignedBigInteger('assigned_owner_user_id');
            $table->string('shopify_order_id')->nullable();
            $table->string('operator_name');
            $table->dateTime('order_created_at');
            $table->string('media_source');
            $table->string('subscription_type');
            $table->decimal('paid_amount_usd', 18, 4);
            $table->text('shopify_payload')->nullable();
        });
        Schema::create('logistics_order_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('logistics_order_id');
        });
        Schema::create('logistics_tongtu_sku_mappings', function (Blueprint $table): void {
            $table->id();
        });
        Schema::create('assistant_domain_store_owner_histories', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('assistant_domain_store_id');
            $table->unsignedBigInteger('owner_user_id')->nullable();
            $table->string('owner_name_snapshot')->nullable();
            $table->unsignedBigInteger('operation_department_id')->nullable();
            $table->string('operation_department_name_snapshot')->nullable();
            $table->string('event')->default('assigned');
            $table->dateTime('effective_from');
            $table->dateTime('effective_to')->nullable();
            $table->timestamps();
        });
        Schema::create('assistant_shopify_store_orders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('assistant_domain_store_id');
            $table->string('shopify_order_id')->nullable();
            $table->dateTime('shopify_created_at');
            $table->boolean('is_test')->default(false);
            $table->string('financial_status');
            $table->string('subscription_category');
            $table->string('original_currency_code');
            $table->decimal('original_total_price', 18, 4);
            $table->text('shopify_tags')->nullable();
        });

        config(['assistant_shopify_analytics.reference_rates_to_usd.USD' => 1]);

        DB::table('admin_users')->insert(['id' => 1, 'name' => 'Test Operator', 'username' => 'test-operator']);
        DB::table('assistant_domain_stores')->insert(['id' => 1, 'domain' => 'test.example', 'owner_user_id' => 1]);
    }

    public function test_applovin_requires_both_complete_tags_and_excludes_renewals(): void
    {
        $this->order(['subscription', 'subscription first order'], '订阅');
        $this->order([' SUBSCRIPTION ', ' Subscription First Order ']);
        $this->order('subscription, subscription first order, subscription');
        $this->order(['subscription'], '订阅');
        $this->order(['subscription first order']);
        $this->order(['subscription', 'subscription first order extra']);
        $this->order(['subscription', 'subscription first order', 'ouyicart subscription recurring order']);
        $this->order(['subscription', 'subscription first order'], '订阅复购1次');
        $this->order([], '无订阅');
        $this->order([], '订阅首次购买', null);

        $totals = app(OperationReportMetricsService::class)->buildRangeForOperators(
            '2026-11-15', '2026-11-15', [1], '-04:00', requireBothSubscriptionTags: true,
        )[1]['totals'];

        $this->assertEquals(3, $totals['first_subscription_orders']);
        $this->assertEquals(30, $totals['first_subscription_revenue']);
        $this->assertEquals(1, $totals['first_normal_orders']);
        $this->assertEquals(4, $totals['first_total_orders']);
        $this->assertEquals(40, $totals['first_total_revenue']);
        $this->assertEquals(2, $totals['renewal_orders']);
    }

    public function test_other_reports_keep_the_existing_subscription_type_rule(): void
    {
        $this->order(['subscription first order']);
        $this->order(['subscription', 'subscription first order'], '订阅');

        $totals = app(OperationReportMetricsService::class)->buildRangeForOperators(
            '2026-11-15', '2026-11-15', [1], '-04:00',
        )[1]['totals'];

        $this->assertEquals(1, $totals['first_subscription_orders']);
        $this->assertEquals(10, $totals['first_subscription_revenue']);
        $this->assertEquals(1, $totals['first_total_orders']);
    }

    public function test_applovin_can_reassign_orders_using_store_owner_history(): void
    {
        $this->order(['subscription', 'subscription first order']);
        DB::table('logistics_orders')->update(['assigned_owner_user_id' => 2, 'operator_name' => 'New Operator']);
        DB::table('assistant_domain_store_owner_histories')->insert([
            'assistant_domain_store_id' => 1,
            'owner_user_id' => 1,
            'event' => 'assigned',
            'effective_from' => '2026-11-16 00:00:00',
            'created_at' => '2026-11-16 00:00:00',
            'updated_at' => '2026-11-16 00:00:00',
        ]);

        $totals = app(OperationReportMetricsService::class)->buildRangeForOperators(
            '2026-11-15', '2026-11-15', [1], '-04:00',
            requireBothSubscriptionTags: true,
            attributeByStoreOwnerHistory: true,
        )[1]['totals'];

        self::assertEquals(1, $totals['first_total_orders']);
        self::assertEquals(10, $totals['first_total_revenue']);
    }

    public function test_shopify_report_uses_logistics_order_before_store_analytics_sync(): void
    {
        $this->order([], '无订阅', '', 'gid://shopify/Order/1001');

        $totals = app(OperationReportMetricsService::class)->buildRangeForOperators(
            '2026-11-15', '2026-11-15', [1], '-04:00', useShopifySourceMetrics: true,
        )[1]['totals'];

        self::assertEquals(1, $totals['first_normal_orders']);
        self::assertEquals(10, $totals['first_normal_revenue']);
    }

    public function test_shopify_report_replaces_matching_logistics_order_without_double_counting(): void
    {
        $this->order([], '无订阅', '', 'gid://shopify/Order/1002');
        DB::table('assistant_shopify_store_orders')->insert([
            'assistant_domain_store_id' => 1,
            'shopify_order_id' => '1002',
            'shopify_created_at' => '2026-11-15 12:00:00',
            'is_test' => false,
            'financial_status' => 'PAID',
            'subscription_category' => 'subscribed',
            'original_currency_code' => 'USD',
            'original_total_price' => '15.0000',
            'shopify_tags' => json_encode(['subscription'], JSON_THROW_ON_ERROR),
        ]);

        $totals = app(OperationReportMetricsService::class)->buildRangeForOperators(
            '2026-11-15', '2026-11-15', [1], '-04:00', useShopifySourceMetrics: true,
        )[1]['totals'];

        self::assertEquals(1, $totals['first_total_orders']);
        self::assertEquals(1, $totals['first_subscription_orders']);
        self::assertEquals(0, $totals['first_normal_orders']);
        self::assertEquals(15, $totals['first_total_revenue']);
    }

    private function order(array|string $tags, string $type = '订阅首次购买', ?string $payload = '', ?string $shopifyOrderId = null): void
    {
        DB::table('logistics_orders')->insert([
            'assistant_domain_store_id' => 1,
            'assigned_owner_user_id' => 1,
            'shopify_order_id' => $shopifyOrderId,
            'operator_name' => 'Test Operator',
            'order_created_at' => '2026-11-15 12:00:00',
            'media_source' => 'AppLovin',
            'subscription_type' => $type,
            'paid_amount_usd' => '10.0000',
            'shopify_payload' => $payload === '' ? json_encode(['tags' => $tags], JSON_THROW_ON_ERROR) : $payload,
        ]);
    }
}
