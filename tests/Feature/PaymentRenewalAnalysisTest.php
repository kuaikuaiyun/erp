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
            foreach (['paid_at', 'subscription_origin_order_id', 'subscription_type', 'is_add_on', 'operator_name', 'store_name', 'transaction_id', 'shopify_order_id', 'shopify_order_number', 'payment_gateway_order_id', 'bill_number', 'payment_gateway', 'payment_method', 'paypal_association_source', 'tongtu_order_number', 'buyer_email'] as $field) $table->string($field)->nullable();
            $table->decimal('paid_amount_usd', 16, 4)->default(0);
            $table->unsignedInteger('paypal_account_id')->nullable();
        });
        Schema::create('logistics_paypal_transactions', function (Blueprint $table): void {
            $table->increments('id'); $table->unsignedInteger('logistics_order_id')->nullable();
            $table->string('event_code')->nullable(); $table->string('status')->nullable();
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
            'payment_method' => $channel === 'paypal' ? 'pp' : 'cc',
            'tongtu_order_number' => 'T-'.$id, 'transaction_id' => 'TX-'.$id,
            'paypal_account_id' => $channel === 'paypal' ? 1 : null,
            'paypal_association_source' => $channel === 'paypal' ? 'actual_transaction' : null,
        ], $extra));
        if ($channel === 'paypal') DB::table('logistics_paypal_transactions')->insert(['logistics_order_id' => $id, 'event_code'=>'T0006', 'status'=>'S']);
    }

    private function filters(array $overrides = []): array
    {
        return array_merge([
            'initial_date_from' => '2026-11-01', 'initial_date_to' => '2026-11-01', 'initial_timezone' => '+08:00', 'initial_store' => 'Test',
            'renewal_date_from' => '2026-11-01', 'renewal_date_to' => '2026-11-01', 'renewal_timezone' => '+08:00', 'renewal_store' => 'Test',
            'stage' => '', 'stage_page' => 1, 'risk_date_mode' => 'payment', 'channel' => 'paypal',
        ], $overrides);
    }

    public function test_payment_methods_override_gateway_and_account_and_reconcile_all_groups(): void
    {
        foreach (['ap', 'gp', 'pp', 'cc', '', 'other'] as $index => $method) {
            // Deliberately conflicting gateway and PayPal account for cc; method must win.
            $this->order(100+$index, '2026-10-02 12:00:00', '订阅首次购买', 'paypal', '', 'Methods', ['payment_method'=>$method]);
            $this->order(110+$index, '2026-11-01 12:00:00', '订阅复购', 'paypal', (string)(100+$index), 'Methods', ['payment_method'=>$method]);
            $this->order(120+$index, '2026-11-01 12:00:00', $index % 2 ? '无订阅' : '订阅首次购买', 'paypal', '', 'Methods', ['payment_method'=>$method]);
        }
        $this->order(140, '2026-11-01 12:00:00', '订阅复购', 'credit_card', '100', 'Methods', ['payment_method'=>' GP ']);
        DB::table('logistics_credit_card_refunds')->insert(['logistics_order_id'=>113,'refund_amount_usd'=>4,'refund_reason'=>'质量问题']);
        DB::table('logistics_paypal_refunds')->insert(['logistics_order_id'=>110,'refund_amount_usd'=>3]);
        $data = app(PaymentRenewalAnalysisService::class)->dashboard($this->filters(['initial_store'=>'Methods','renewal_store'=>'Methods']));
        $stage = $data['stages'][0];
        self::assertSame(6, $stage['orders']);
        self::assertSame(3, $stage['by_channel']['paypal']['orders']);
        self::assertSame(1, $stage['by_channel']['credit_card']['orders']);
        self::assertSame(2, $stage['by_channel']['unknown']['orders']);
        foreach (['ap','gp','pp','cc'] as $method) {
            self::assertSame(1, $stage['by_channel']['method_'.$method]['previous_orders']);
            self::assertSame(1, $stage['by_channel']['method_'.$method]['orders']);
            self::assertEquals(100, $stage['by_channel']['method_'.$method]['rate']);
        }
        self::assertEquals(4, $stage['by_channel']['method_cc']['refund_amount']);
        self::assertEquals(3, $stage['by_channel']['method_ap']['refund_amount']);
        $second = $data['stages'][1]['by_channel']['method_gp'];
        self::assertSame(1, $second['orders']);
        self::assertSame(0, $second['previous_orders']);
        self::assertNull($second['rate']);
        $refunds = $data['initial_refunds_by_type'];
        foreach (['orders','revenue','refund_order_count','refund_amount'] as $metric) {
            self::assertEquals($refunds['total']['all'][$metric],
                $refunds['paypal']['all'][$metric]+$refunds['credit_card']['all'][$metric]+$refunds['unknown']['all'][$metric]);
            self::assertEquals($refunds['paypal']['all'][$metric],
                $refunds['method_ap']['all'][$metric]+$refunds['method_gp']['all'][$metric]+$refunds['method_pp']['all'][$metric]);
        }
        $rates = $data['summary']['subscription_rate'];
        self::assertSame(6, $rates['total']['total_orders']);
        self::assertSame(2, $rates['unknown']['total_orders']);
        self::assertSame(1, $rates['method_cc']['total_orders']);
        self::assertSame(3, $rates['total']['subscription_orders']);
        $this->order(150, '2026-10-02 12:00:00', '订阅首次购买', 'credit_card', '', 'Methods', ['payment_method'=>' AP ']);
        $this->order(151, '2026-11-01 12:00:00', '订阅复购', 'credit_card', '150', 'Methods', ['payment_method'=>' GP ']);
        // This ap first purchase has no matched PayPal transaction and remains unqualified.
        $this->order(152, '2026-11-01 12:00:00', '订阅首次购买', 'credit_card', '', 'Methods', ['payment_method'=>'ap']);
        \Illuminate\Support\Facades\Cache::flush();
        $updated = app(PaymentRenewalAnalysisService::class)->dashboard($this->filters(['initial_store'=>'Methods','renewal_store'=>'Methods']));
        self::assertEquals(50, $updated['stages'][0]['by_channel']['method_ap']['rate']);
        self::assertEquals(200, $updated['stages'][0]['by_channel']['method_gp']['rate']);
        self::assertSame(6, $updated['summary']['subscription_rate']['total']['total_orders']);
        self::assertSame(7, $updated['initial_refunds']['total']['orders']);
    }

    public function test_add_on_status_does_not_exclude_first_purchases_or_renewal_history(): void
    {
        $flag = ['is_add_on'=>'已加购'];
        $this->order(400,'2026-09-02 12:00:00','订阅首次购买','credit_card','', 'AddOn', $flag);
        $this->order(401,'2026-10-02 12:00:00','订阅复购','credit_card','400', 'AddOn', $flag);
        $this->order(402,'2026-11-01 12:00:00','订阅复购','credit_card','400', 'AddOn', $flag);
        $this->order(403,'2026-11-01 12:00:00','订阅首次购买','credit_card','', 'AddOn', $flag);
        $this->order(404,'2026-11-01 12:00:00','无订阅','credit_card','', 'AddOn', $flag);
        DB::table('logistics_credit_card_refunds')->insert([
            ['logistics_order_id'=>402,'refund_amount_usd'=>3,'refund_reason'=>'质量问题'],
            ['logistics_order_id'=>404,'refund_amount_usd'=>5,'refund_reason'=>'质量问题'],
        ]);
        $filters = $this->filters(['initial_store'=>'AddOn','renewal_store'=>'AddOn']);
        $service = app(PaymentRenewalAnalysisService::class);
        $data = $service->dashboard($filters);
        self::assertSame(2, $data['summary']['subscription_rate']['total']['total_orders']);
        self::assertSame(1, $data['summary']['subscription_rate']['total']['subscription_orders']);
        self::assertEquals(50, $data['summary']['subscription_rate']['total']['rate']);
        self::assertSame(2, $data['initial_refunds']['total']['orders']);
        self::assertSame(1, $data['initial_refunds_by_type']['total']['subscription']['orders']);
        self::assertSame(1, $data['initial_refunds_by_type']['total']['non_subscription']['orders']);
        $stages = array_column($data['stages'],null,'key');
        self::assertSame(1, $stages['renewal_2']['orders']);
        self::assertSame(1, $stages['renewal_2']['previous_orders']);
        self::assertEquals(100, $stages['renewal_2']['rate']);
        self::assertEquals(3, $stages['renewal_2']['refund_amount']);
        $detail = $service->initialOrderDetails($filters,$this->detailFilters());
        self::assertSame([404,403],array_column($detail['rows'],'erp_order_id'));
        self::assertSame(1, $detail['summary']['refund_order_count']);
        self::assertEquals(5, $detail['summary']['refund_amount']);
        $refunds = $service->initialOrderDetails($filters,$this->detailFilters(['detail_kind'=>'refunds']));
        self::assertSame([404],array_column($refunds['rows'],'erp_order_id'));
        self::assertSame(2, $service->exportData($filters)['initial_refunds']['total']['orders']);
    }

    private function detailFilters(array $overrides = []): array
    {
        return array_replace([
            'detail_group'=>'total', 'detail_purchase'=>'all', 'detail_kind'=>'orders',
            'detail_keyword'=>'', 'detail_store'=>'', 'detail_method'=>'', 'detail_type'=>'', 'detail_refund'=>'',
            'detail_from'=>'', 'detail_to'=>'', 'detail_per_page'=>20, 'detail_page'=>1,
        ], $overrides);
    }

    public function test_detail_populations_reconcile_with_every_summary_row_and_deduplicate_refunded_orders(): void
    {
        DB::table('logistics_paypal_refunds')->insert(['logistics_order_id'=>5,'refund_amount_usd'=>7,'manual_reason'=>'部分退款']);
        $service = app(PaymentRenewalAnalysisService::class);
        $dashboard = $service->dashboard($this->filters());
        foreach (['total','method_pp','method_cc','method_gp','method_ap'] as $group) {
            foreach (['all','subscription','non_subscription'] as $type) {
                $expected = $dashboard['initial_refunds_by_type'][$group][$type];
                $base = ['detail_group'=>$group,'detail_purchase'=>$type];
                $detail = $service->initialOrderDetails($this->filters(), $this->detailFilters($base));
                foreach (['orders','revenue','refund_order_count','refund_amount'] as $metric) self::assertEquals($expected[$metric], $detail['scope'][$metric]);
                self::assertSame($expected['orders'], $detail['pagination']['total']);
                $refunds = $service->initialOrderDetails($this->filters(), $this->detailFilters([...$base,'detail_kind'=>'refunds']));
                self::assertSame($expected['refund_order_count'], $refunds['pagination']['total']);
                self::assertEquals($expected['refund_amount'], $refunds['summary']['refund_amount']);
            }
        }
        $refunds = $service->initialOrderDetails($this->filters(), $this->detailFilters(['detail_kind'=>'refunds','detail_group'=>'method_pp']));
        self::assertCount(1, $refunds['rows']);
        self::assertSame(5, $refunds['rows'][0]['erp_order_id']);
        self::assertSame(2, $refunds['rows'][0]['refund_count']);
        self::assertEquals(17, $refunds['summary']['refund_amount']);
    }

    public function test_detail_search_and_filters_only_narrow_the_selected_population(): void
    {
        $service = app(PaymentRenewalAnalysisService::class);
        $base = ['detail_group'=>'method_pp','detail_purchase'=>'subscription'];
        foreach ([
            ['detail_method'=>'cc'], ['detail_type'=>'non_subscription'], ['detail_store'=>'Other'],
            ['detail_refund'=>'no'], ['detail_from'=>'2026-11-02'],
            ['detail_to'=>'2026-10-31'], ['detail_keyword'=>'TX-6'],
        ] as $filter) {
            $detail = $service->initialOrderDetails($this->filters(), $this->detailFilters([...$base,...$filter]));
            self::assertSame(0, $detail['pagination']['total']);
            self::assertSame(1, $detail['scope']['orders']);
        }
        foreach (['5','TX-5','Test'] as $keyword) {
            $detail = $service->initialOrderDetails($this->filters(), $this->detailFilters([...$base,'detail_keyword'=>$keyword]));
            self::assertSame([5], array_column($detail['rows'],'erp_order_id'));
        }
        $detail = $service->initialOrderDetails($this->filters(), $this->detailFilters(['detail_kind'=>'refunds','detail_refund'=>'no']));
        self::assertSame(0, $detail['pagination']['total']);
        $this->order(21,'2026-11-01 18:30:00','无订阅','credit_card');
        \Illuminate\Support\Facades\Cache::flush();
        $china = $service->initialOrderDetails($this->filters(), $this->detailFilters());
        $west = $service->initialOrderDetails($this->filters(['initial_timezone'=>'-05:00']), $this->detailFilters());
        self::assertSame(3, $china['scope']['orders']);
        self::assertSame(4, $west['scope']['orders']);
        self::assertSame($china['scope'], $service->initialOrderDetails($this->filters(['stage'=>'renewal_9','renewal_store'=>'Other']), $this->detailFilters())['scope']);
    }

    public function test_detail_pagination_renders_escaped_rows_refund_records_and_filter_state(): void
    {
        for ($i=0;$i<45;$i++) $this->order(200+$i,'2026-11-01 13:00:00','无订阅','credit_card');
        DB::table('logistics_orders')->where('id',244)->update(['shopify_order_number'=>'<script>alert(1)</script>']);
        $service = app(PaymentRenewalAnalysisService::class);
        $detail = $service->initialOrderDetails($this->filters(), $this->detailFilters(['detail_per_page'=>20,'detail_page'=>2]));
        self::assertSame(48, $detail['pagination']['total']);
        self::assertCount(20, $detail['rows']);
        self::assertEquals(960, $detail['summary']['revenue']);
        self::assertSame(21, $detail['pagination']['from']);
        $last = $service->initialOrderDetails($this->filters(), $this->detailFilters(['detail_page'=>100]));
        self::assertSame(3, $last['pagination']['current_page']);
        self::assertCount(8, $last['rows']);
        $detail = $service->initialOrderDetails($this->filters(), $this->detailFilters(['detail_keyword'=>'<script>']));
        $html = view('portals.payment.renewal-order-details', compact('detail'))->render();
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('data-renewal-detail-filter', $html);
        self::assertStringContainsString('data-renewal-detail-fragment', $html);
        self::assertStringNotContainsString('payment.renewal_details.', $html);
        $detail = $service->initialOrderDetails($this->filters(), $this->detailFilters(['detail_kind'=>'refunds']));
        $html = view('portals.payment.renewal-order-details', compact('detail'))->render();
        self::assertStringContainsString('查看退款记录', $html);
        self::assertStringContainsString('测试退款', $html);
        $detail = $service->initialOrderDetails($this->filters(), $this->detailFilters(['detail_keyword'=>'not-found']));
        self::assertStringContainsString('当前筛选条件下没有订单', view('portals.payment.renewal-order-details', compact('detail'))->render());
    }

    public function test_detail_request_validation_rejects_invalid_scope_and_unbounded_pages(): void
    {
        $method = new \ReflectionMethod(PaymentPortalController::class,'renewalOrderDetailFilters');
        $controller = (new \ReflectionClass(PaymentPortalController::class))->newInstanceWithoutConstructor();
        foreach ([
            ['detail_group'=>'paypal'], ['detail_kind'=>'invalid'], ['detail_purchase'=>'invalid'],
            ['detail_per_page'=>10000], ['detail_page'=>0], ['detail_keyword'=>str_repeat('a',101)],
            ['detail_from'=>'2026-11-02','detail_to'=>'2026-11-01'],
        ] as $invalid) {
            try {
                $method->invoke($controller, Request::create('/','GET',$this->detailFilters($invalid)));
                self::fail('Invalid detail filters accepted');
            } catch (\Illuminate\Validation\ValidationException $e) {
                self::assertNotEmpty($e->errors());
            }
        }
        $valid = $method->invoke($controller, Request::create('/','GET',$this->detailFilters(['detail_per_page'=>50])));
        self::assertSame(50,$valid['detail_per_page']);
    }

    public function test_refunds_include_non_subscription_first_orders_and_all_channel_totals_reconcile(): void
    {
        $data = app(PaymentRenewalAnalysisService::class)->dashboard($this->filters());
        $groups = $data['initial_refunds_by_type'];
        self::assertSame(3, $groups['total']['all']['orders']);
        self::assertSame(1, $groups['total']['subscription']['orders']);
        self::assertSame(2, $groups['total']['non_subscription']['orders']);
        self::assertEquals(15, $groups['total']['all']['refund_amount']);
        foreach (['orders', 'revenue', 'refund_order_count', 'refund_amount'] as $metric) {
            self::assertEquals($groups['total']['all'][$metric], $groups['paypal']['all'][$metric] + $groups['credit_card']['all'][$metric]);
            self::assertEquals($groups['total']['all'][$metric], $groups['total']['subscription'][$metric] + $groups['total']['non_subscription'][$metric]);
        }
        self::assertEquals(66.67, $groups['total']['all']['refund_count_rate']);
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
        self::assertSame(3, $china['initial_refunds']['total']['orders']);
        self::assertSame(4, $western['initial_refunds']['total']['orders']);
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
        self::assertStringContainsString('data-payment-method="ap"', $html);
        self::assertStringContainsString('data-payment-method="cc"', $html);
        self::assertStringContainsString('payment-stage-start', $html);
        self::assertStringContainsString('payment-method-rate-grid', $html);
        self::assertStringContainsString('支付方式', $html);
        self::assertSame(30, substr_count($html,'data-renewal-detail-open'));
        self::assertStringContainsString('data-renewal-detail-content', $html);
        self::assertStringContainsString('分母订单明细', $html);
        self::assertStringContainsString('退款订单明细', $html);
        self::assertStringNotContainsString('data-payment-method="unknown"', $html);
        preg_match_all('/class="payment-method-rate" data-payment-method="([^"]+)"/', $html, $methods);
        self::assertSame(['pp','cc','gp','ap'], $methods[1]);
        foreach (['苹果支付','谷歌支付','PayPal支付','信用卡支付'] as $name) self::assertStringContainsString($name, $html);
        self::assertStringNotContainsString('<th>渠道</th>', $html);
        self::assertStringNotContainsString('>PayPal</', $html);
        self::assertStringNotContainsString('>信用卡</', $html);
        self::assertStringNotContainsString('payment.renewal_two_sections.', $html);
        self::assertStringContainsString('2 ÷ 2 × 100%', $html);
        self::assertStringContainsString('name="initial_store" value="Test"', $html);
        self::assertStringContainsString('name="renewal_store" value="Test"', $html);
    }

    public function test_bill_and_email_are_searchable_and_export_covers_all_filtered_rows_as_literal_text(): void
    {
        $longBill = '00009549574570768548249622';
        for ($i=0;$i<45;$i++) $this->order(200+$i,'2026-11-01 13:00:00','无订阅','credit_card','', 'Test', [
            'bill_number'=>$i === 44 ? $longBill : ($i === 43 ? '=1+1' : 'BILL-'.$i),
            'buyer_email'=>'buyer'.$i.'@example.com',
        ]);
        $service = app(PaymentRenewalAnalysisService::class);
        foreach ([$longBill,'BUYER44@EXAMPLE.COM'] as $keyword) {
            $detail = $service->initialOrderDetails($this->filters(),$this->detailFilters(['detail_keyword'=>$keyword]));
            self::assertSame([244],array_column($detail['rows'],'erp_order_id'));
            self::assertSame($longBill,$detail['rows'][0]['bill_number']);
            self::assertSame('buyer44@example.com',$detail['rows'][0]['buyer_email']);
            $html = view('portals.payment.renewal-order-details',compact('detail'))->render();
            self::assertStringContainsString('账单号',$html);
            self::assertStringContainsString('客户邮箱',$html);
            self::assertStringContainsString('data-renewal-detail-export',$html);
            self::assertStringContainsString('buyer44@example.com',$html);
        }
        $filter = $this->detailFilters(['detail_group'=>'method_cc','detail_purchase'=>'non_subscription','detail_page'=>2]);
        self::assertCount(20,$service->initialOrderDetails($this->filters(),$filter)['rows']);
        foreach ([$filter, [...$filter,'detail_keyword'=>'buyer44@'], [...$filter,'detail_keyword'=>'nothing-matches']] as $index=>$exportFilters) {
            $expected = $service->initialOrderDetails($this->filters(),$exportFilters,false);
            $response = app(PaymentRenewalAnalysisExportService::class)->downloadOrderDetails($this->filters(),$exportFilters);
            ob_start(); $response->sendContent(); $bytes=ob_get_clean();
            $path = tempnam(sys_get_temp_dir(),'renewal-details-export-');
            try {
                file_put_contents($path,$bytes);
                $book = IOFactory::load($path);
                self::assertSame(2,$book->getSheetCount());
                $sheet=$book->getSheet(0);
                self::assertSame(count($expected['rows'])+1,$sheet->getHighestRow());
                self::assertSame('账单号',$sheet->getCell('D1')->getValue());
                self::assertSame('客户邮箱',$sheet->getCell('E1')->getValue());
                if ($index === 0) {
                    self::assertSame(48,$sheet->getHighestRow());
                    self::assertSame($longBill,$sheet->getCell('D2')->getValue());
                    self::assertSame('s',$sheet->getCell('D2')->getDataType());
                    self::assertSame('=1+1',$sheet->getCell('D3')->getValue());
                    self::assertSame('s',$sheet->getCell('D3')->getDataType());
                    self::assertSame('buyer44@example.com',$sheet->getCell('E2')->getValue());
                    self::assertSame('UTC+08:00',$book->getSheet(1)->getCell('B3')->getValue());
                }
                $book->disconnectWorksheets();
            } finally { unlink($path); }
        }
    }

    public function test_main_export_includes_multiline_event_times_in_each_module_timezone(): void
    {
        DB::table('logistics_paypal_refunds')->where('logistics_order_id',5)->update(['refunded_at'=>'2026-11-02 03:00:00']);
        DB::table('logistics_paypal_refunds')->insert(['logistics_order_id'=>5,'refund_amount_usd'=>2,'refunded_at'=>'2026-11-03 23:00:00']);
        DB::table('logistics_paypal_disputes')->insert([
            ['logistics_order_id'=>3,'dispute_amount_usd'=>6,'dispute_created_at'=>'2026-11-04 04:00:00'],
            ['logistics_order_id'=>3,'dispute_amount_usd'=>6,'dispute_created_at'=>'2026-11-05 20:00:00'],
        ]);
        $response = app(PaymentRenewalAnalysisExportService::class)->download($this->filters(['initial_timezone'=>'-05:00']));
        ob_start(); $response->sendContent(); $bytes=ob_get_clean();
        $path=tempnam(sys_get_temp_dir(),'renewal-event-times-');
        try {
            file_put_contents($path,$bytes);
            $book=IOFactory::load($path);
            $sheet=$book->getSheet(3);
            self::assertSame('退款时间',$sheet->getCell('L1')->getValue());
            self::assertSame('争议时间',$sheet->getCell('O1')->getValue());
            self::assertSame('时间所属时区',$sheet->getCell('Q1')->getValue());
            $matched=[];
            for ($r=2;$r<=$sheet->getHighestRow();$r++) {
                $id=(string)$sheet->getCell('E'.$r)->getValue();
                if ($id==='5') {
                    self::assertSame("2026-11-01 22:00:00\n2026-11-03 18:00:00",$sheet->getCell('L'.$r)->getValue());
                    self::assertNull($sheet->getCell('O'.$r)->getValue());
                    self::assertSame('UTC-05:00',$sheet->getCell('Q'.$r)->getValue());
                    self::assertTrue($sheet->getStyle('L'.$r)->getAlignment()->getWrapText());
                    $matched[]=$id;
                } elseif ($id==='3') {
                    self::assertSame("2026-11-04 12:00:00\n2026-11-06 04:00:00",$sheet->getCell('O'.$r)->getValue());
                    self::assertNull($sheet->getCell('L'.$r)->getValue());
                    self::assertSame('UTC+08:00',$sheet->getCell('Q'.$r)->getValue());
                    $matched[]=$id;
                } elseif ($id==='6') {
                    // A refund with no event timestamp remains blank; never invent a payment-time substitute.
                    self::assertNull($sheet->getCell('L'.$r)->getValue());
                    $matched[]=$id;
                }
            }
            sort($matched);
            self::assertSame(['3','5','6'],$matched);
            $book->disconnectWorksheets();
        } finally { unlink($path); }
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
            self::assertSame(20, $book->getSheet(1)->getHighestRow());
            self::assertSame('支付方式', $book->getSheet(1)->getCell('A5')->getValue());
            self::assertSame('PayPal支付', $book->getSheet(1)->getCell('A9')->getValue());
            self::assertSame('UTC+08:00', $book->getSheet(2)->getCell('B1')->getValue());
            self::assertSame('总体', $book->getSheet(2)->getCell('B7')->getValue());
            self::assertSame('PayPal支付', $book->getSheet(2)->getCell('B8')->getValue());
            self::assertSame('信用卡支付', $book->getSheet(2)->getCell('B9')->getValue());
            self::assertSame('谷歌支付', $book->getSheet(2)->getCell('B10')->getValue());
            self::assertSame('苹果支付', $book->getSheet(2)->getCell('B11')->getValue());
            $book->disconnectWorksheets();
        } finally { @unlink($path); }
    }
}
