<?php

namespace Tests\Feature;

use App\Portals\Payment\PaymentRenewalAnalysisService;
use App\Portals\Payment\PaymentRenewalAnalysisExportService;
use App\Portals\Payment\Http\Controllers\PaymentPortalController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class PaymentRenewalAnalysisTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'admin.database.connection' => null, 'cache.default' => 'array']);
        app('db')->purge('sqlite');
        app()->setLocale('zh-CN');
        Schema::create('logistics_orders', function (Blueprint $table): void {
            $table->increments('id');
            foreach (['paid_at', 'subscription_origin_order_id', 'subscription_type', 'is_add_on', 'operator_name', 'store_name', 'transaction_id', 'shopify_order_id', 'shopify_order_number', 'payment_gateway_order_id', 'bill_number', 'payment_gateway', 'payment_method', 'paypal_association_source', 'tongtu_order_number'] as $field) $table->string($field)->nullable();
            $table->decimal('paid_amount_usd', 16, 4)->default(0);
            $table->unsignedInteger('paypal_account_id')->nullable();
        });
        Schema::create('logistics_paypal_transactions', function (Blueprint $table): void {
            $table->increments('id'); $table->unsignedInteger('logistics_order_id')->nullable();
        });
        Schema::create('logistics_order_payment_references', function (Blueprint $table): void {
            $table->increments('id'); $table->unsignedInteger('logistics_order_id'); $table->string('reference_type'); $table->string('gateway_order_id')->nullable();
        });
        foreach (['logistics_paypal_refunds', 'logistics_paypal_disputes', 'logistics_credit_card_refunds'] as $name) {
            Schema::create($name, function (Blueprint $table): void {
                $table->increments('id'); $table->unsignedInteger('logistics_order_id');
                foreach (['manual_reason', 'refund_reason', 'event_code', 'status', 'refunded_at', 'reason', 'dispute_created_at', 'occurred_at'] as $field) $table->string($field)->nullable();
                foreach (['refund_amount_usd', 'dispute_amount_usd', 'original_amount'] as $field) $table->decimal($field, 16, 4)->nullable();
            });
        }
        $this->order(1, '2026-10-02 12:00:00', '订阅首次购买', 'paypal');
        $this->order(2, '2026-10-02 12:00:00', '订阅首次购买', 'credit_card');
        $this->order(3, '2026-11-01 12:00:00', '订阅复购', 'credit_card', '1');
        $this->order(4, '2026-11-01 12:00:00', '订阅复购', 'credit_card', '2');
        $this->order(5, '2026-11-01 12:00:00', '订阅首次购买', 'paypal');
        $this->order(6, '2026-11-01 12:00:00', '无订阅', 'credit_card');
        DB::table('logistics_credit_card_refunds')->insert(['logistics_order_id' => 6, 'refund_amount_usd' => 5, 'refund_reason' => '质量问题']);
        DB::table('logistics_paypal_refunds')->insert(['logistics_order_id' => 5, 'refund_amount_usd' => 10, 'manual_reason' => '测试退款', 'status' => 'COMPLETED']);
        $this->order(7, '2026-11-01 12:00:00', '无订阅', 'credit_card', '', 'Other');
        $this->order(8, '2026-11-01 12:00:00', '无订阅', 'credit_card', '', 'Test', ['is_add_on' => '已加购']);
        $this->order(9, '2026-11-01 12:00:00', '无订阅', 'credit_card', '', 'Test', ['operator_name' => '刷单']);
        $this->order(11, '2026-11-02 12:00:00', '订阅复购', 'paypal', '1');
        $this->order(10, '2026-11-01 12:00:00', '无订阅', 'credit_card', '999');
    }

    private function order(int $id, string $time, string $type, string $channel, string $origin = '', string $store = 'Test', array $extra = []): void
    {
        DB::table('logistics_orders')->insert(array_merge([
            'id' => $id, 'paid_at' => $time, 'subscription_type' => $type, 'payment_gateway' => $channel,
            'paid_amount_usd' => 20, 'subscription_origin_order_id' => $origin, 'store_name' => $store,
            'tongtu_order_number' => 'T-'.$id, 'transaction_id' => 'TX-'.$id,
            'paypal_account_id' => $channel === 'paypal' ? 1 : null,
            'paypal_association_source' => $channel === 'paypal' ? 'actual_transaction' : null,
        ], $extra));
        if ($channel === 'paypal') DB::table('logistics_paypal_transactions')->insert(['logistics_order_id' => $id]);
    }

    private function filters(array $overrides = []): array
    {
        return array_merge([
            'initial_date_from' => '2026-11-01', 'initial_date_to' => '2026-11-01', 'initial_timezone' => '+08:00', 'initial_store' => 'Test',
            'renewal_date_from' => '2026-11-01', 'renewal_date_to' => '2026-11-01', 'renewal_timezone' => '+08:00', 'renewal_store' => 'Test',
            'stage' => '', 'stage_page' => 1, 'risk_date_mode' => 'payment', 'channel' => 'paypal',
        ], $overrides);
    }

    public function test_refunds_include_non_subscription_first_orders_and_all_channel_totals_reconcile(): void
    {
        $data = app(PaymentRenewalAnalysisService::class)->dashboard($this->filters());
        $groups = $data['initial_refunds_by_type'];
        self::assertSame(2, $groups['total']['all']['orders']);
        self::assertSame(1, $groups['total']['subscription']['orders']);
        self::assertSame(1, $groups['total']['non_subscription']['orders']);
        self::assertEquals(15, $groups['total']['all']['refund_amount']);
        foreach (['orders', 'revenue', 'refund_order_count', 'refund_amount'] as $metric) {
            self::assertEquals($groups['total']['all'][$metric], $groups['paypal']['all'][$metric] + $groups['credit_card']['all'][$metric]);
            self::assertEquals($groups['total']['all'][$metric], $groups['total']['subscription'][$metric] + $groups['total']['non_subscription'][$metric]);
        }
        self::assertEquals(100, $groups['total']['all']['refund_count_rate']);
    }

    public function test_stage_channel_denominators_use_each_period_actual_channel_and_no_base_is_null(): void
    {
        $data = app(PaymentRenewalAnalysisService::class)->dashboard($this->filters());
        $stage = $data['stages'][0];
        self::assertSame(2, $stage['previous_orders']);
        self::assertSame(2, $stage['orders']);
        self::assertSame(1, $stage['by_channel']['paypal']['previous_orders']);
        self::assertSame(0, $stage['by_channel']['paypal']['orders']);
        self::assertEquals(200, $stage['by_channel']['credit_card']['rate']);
        $data = app(PaymentRenewalAnalysisService::class)->dashboard($this->filters(['renewal_date_from' => '2026-11-02', 'renewal_date_to' => '2026-11-02']));
        self::assertSame(1, $data['stages'][0]['by_channel']['paypal']['orders']);
        self::assertNull($data['stages'][0]['by_channel']['paypal']['rate']);
    }

    public function test_modules_have_independent_period_timezone_store_and_stage_filters(): void
    {
        $service = app(PaymentRenewalAnalysisService::class);
        $original = $service->dashboard($this->filters());
        $changed = $service->dashboard($this->filters(['initial_store' => 'Other', 'initial_timezone' => '-05:00', 'initial_date_from' => '2026-10-31', 'initial_date_to' => '2026-11-02']));
        self::assertSame($original['stages'], $changed['stages']);
        self::assertSame(1, $changed['initial_refunds']['total']['orders']);
        $changed = $service->dashboard($this->filters(['renewal_store' => 'Other', 'renewal_date_to' => '2026-11-03', 'renewal_timezone' => '-05:00', 'stage' => 'renewal_9']));
        self::assertSame($original['initial_refunds_by_type'], $changed['initial_refunds_by_type']);
        self::assertSame([], $changed['stages']);
    }

    public function test_controller_ignores_legacy_channel_and_rejects_reversed_module_dates(): void
    {
        $method = new \ReflectionMethod(PaymentPortalController::class, 'renewalAnalysisFilters');
        $controller = (new \ReflectionClass(PaymentPortalController::class))->newInstanceWithoutConstructor();
        $filters = $method->invoke($controller, Request::create('/', 'GET', $this->filters()));
        self::assertArrayNotHasKey('channel', $filters);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $method->invoke($controller, Request::create('/', 'GET', $this->filters(['initial_date_to' => '2026-10-01'])));
    }

    public function test_renewal_pagination_retains_first_purchase_totals(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->order(30 + $i, sprintf('2026-11-%02d 12:00:00', $i + 3), '订阅复购', 'paypal', '1');
        }
        $filters = $this->filters(['renewal_date_to' => '2026-11-30']);
        $pageOne = app(PaymentRenewalAnalysisService::class)->dashboard($filters);
        $pageTwo = app(PaymentRenewalAnalysisService::class)->dashboard(array_merge($filters, ['stage_page' => 2]));
        self::assertCount(10, $pageOne['stages']);
        self::assertSame(2, $pageTwo['stage_pagination']['current_page']);
        self::assertGreaterThan(10, $pageTwo['stages'][0]['number']);
        self::assertSame($pageOne['initial_refunds_by_type'], $pageTwo['initial_refunds_by_type']);
    }

    public function test_timezone_changes_only_own_module_at_day_boundary_and_store_options_remain_available(): void
    {
        $this->order(20, '2026-11-01 18:30:00', '无订阅', 'credit_card');
        $service = app(PaymentRenewalAnalysisService::class);
        $china = $service->dashboard($this->filters());
        $western = $service->dashboard($this->filters(['initial_timezone' => '-05:00']));
        self::assertSame(2, $china['initial_refunds']['total']['orders']);
        self::assertSame(3, $western['initial_refunds']['total']['orders']);
        self::assertSame($china['stages'], $western['stages']);
        self::assertContains('Other', $china['initial_stores']);
        self::assertContains('Test', $service->dashboard($this->filters(['initial_store' => 'Other']))['initial_stores']);
    }

    public function test_page_renders_two_independent_forms_and_refund_types_without_channel_filter(): void
    {
        $dashboard = app(PaymentRenewalAnalysisService::class)->dashboard($this->filters());
        $source = str_replace("@extends('portal.layout')", '', file_get_contents(resource_path('views/portals/payment/renewal-analysis.blade.php')));
        $html = \Illuminate\Support\Facades\Blade::render($source.'@yield("content") @stack("scripts")', ['dashboard' => $dashboard, 'errors' => new \Illuminate\Support\ViewErrorBag()]);
        self::assertStringContainsString('data-renewal-module="initial"', $html);
        self::assertStringContainsString('data-renewal-module="renewal"', $html);
        self::assertStringNotContainsString('name="channel"', $html);
        self::assertStringContainsString('非订阅首购', $html);
        self::assertStringContainsString('name="initial_store" value="Test"', $html);
        self::assertStringContainsString('name="renewal_store" value="Test"', $html);
    }

    public function test_export_contains_nine_refund_rows_channel_stage_rows_and_both_timezones(): void
    {
        $response = app(PaymentRenewalAnalysisExportService::class)->download($this->filters(['initial_timezone' => '-05:00']));
        ob_start(); $response->sendContent(); $bytes = ob_get_clean();
        $path = tempnam(sys_get_temp_dir(), 'renewal-check-');
        try {
            file_put_contents($path, $bytes);
            $book = IOFactory::load($path);
            self::assertSame(4, $book->getSheetCount());
            self::assertSame('UTC-05:00', $book->getSheet(0)->getCell('B1')->getValue());
            self::assertSame(14, $book->getSheet(1)->getHighestRow());
            self::assertSame('UTC+08:00', $book->getSheet(2)->getCell('B1')->getValue());
            self::assertSame('总体', $book->getSheet(2)->getCell('B7')->getValue());
            self::assertSame('PayPal', $book->getSheet(2)->getCell('B8')->getValue());
            self::assertSame('信用卡', $book->getSheet(2)->getCell('B9')->getValue());
            $book->disconnectWorksheets();
        } finally { @unlink($path); }
    }
}
