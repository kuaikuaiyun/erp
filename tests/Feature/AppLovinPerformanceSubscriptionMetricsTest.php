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
            $table->unsignedBigInteger('owner_user_id');
        });
        Schema::create('logistics_orders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('assistant_domain_store_id');
            $table->unsignedBigInteger('assigned_owner_user_id');
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

    private function order(array|string $tags, string $type = '订阅首次购买', ?string $payload = ''): void
    {
        DB::table('logistics_orders')->insert([
            'assistant_domain_store_id' => 1,
            'assigned_owner_user_id' => 1,
            'operator_name' => 'Test Operator',
            'order_created_at' => '2026-11-15 12:00:00',
            'media_source' => 'AppLovin',
            'subscription_type' => $type,
            'paid_amount_usd' => '10.0000',
            'shopify_payload' => $payload === '' ? json_encode(['tags' => $tags], JSON_THROW_ON_ERROR) : $payload,
        ]);
    }
}
