<?php

namespace Tests\Feature;

use App\Console\Commands\SyncNbcFashionOrders;
use App\Models\AssistantDomainStore;
use App\Models\FashionLogisticsOrder;
use App\Models\FashionLogisticsSyncState;
use App\Portals\FashionLogistics\NbcOrderSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class NbcFashionOrderSyncTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::createNbcSchema();
        config(['fashion_logistics.nbc.request_interval_ms' => 0]);
        Http::preventStrayRequests();
        $this->travelTo(CarbonImmutable::parse('2026-11-16 12:00:00', 'UTC'));
    }

    public static function createNbcSchema(): void
    {
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'admin.database.connection' => null, 'cache.default' => 'array']);
        app('db')->purge('sqlite');
        Schema::create('assistant_domain_stores', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('site_builder', 50)->nullable();
            $table->string('shop_name', 191)->nullable();
            $table->string('domain', 191)->nullable();
            $table->string('shop_login_url', 500)->nullable();
            $table->string('xshoppy_api_key', 191)->nullable();
            $table->string('xshoppy_password', 191)->nullable();
            $table->string('shopline_app_token', 500)->nullable();
            $table->text('nbc_app_token')->nullable();
            $table->string('shop_status')->nullable();
            $table->unsignedBigInteger('owner_user_id')->nullable();
            $table->string('user_name')->nullable();
            $table->json('team')->nullable();
            $table->timestamps();
        });
        Schema::create('fashion_logistics_sync_states', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('assistant_domain_store_id')->unique();
            $table->string('status', 30)->nullable();
            $table->dateTime('last_started_at')->nullable();
            $table->dateTime('last_succeeded_at')->nullable();
            $table->dateTime('last_cursor_at')->nullable();
            $table->unsignedInteger('last_created_count')->default(0);
            $table->unsignedInteger('last_updated_count')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
        Schema::create('fashion_logistics_orders', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('assistant_domain_store_id');
            $table->string('source_platform', 30);
            $table->string('store_name', 191)->nullable();
            $table->string('source_domain', 191)->nullable();
            $table->string('marketing_source', 40)->nullable();
            $table->string('external_order_id', 191);
            $table->string('order_number', 191)->nullable();
            $table->string('status', 50)->nullable();
            $table->string('payment_status', 50)->nullable();
            $table->string('fulfillment_status', 50)->nullable();
            $table->string('currency', 20)->nullable();
            $table->decimal('total_amount', 18, 4)->default(0);
            $table->decimal('shipping_amount', 18, 4)->default(0);
            $table->decimal('discount_amount', 18, 4)->default(0);
            $table->decimal('tax_amount', 18, 4)->default(0);
            $table->string('payment_method', 191)->nullable();
            $table->string('payment_method_title', 191)->nullable();
            $table->string('transaction_id', 191)->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('customer_name', 191)->nullable();
            $table->string('customer_email', 191)->nullable();
            $table->string('customer_phone', 100)->nullable();
            $table->string('shipping_country', 100)->nullable();
            $table->string('shipping_state', 100)->nullable();
            $table->string('shipping_city', 100)->nullable();
            $table->text('shipping_address')->nullable();
            $table->text('customer_note')->nullable();
            $table->dateTime('source_order_created_at')->nullable();
            $table->string('source_order_timezone', 100)->nullable();
            $table->dateTime('order_created_at_utc')->nullable();
            $table->dateTime('order_created_at')->nullable();
            $table->dateTime('order_modified_at')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->dateTime('paid_at_utc')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->json('source_payload')->nullable();
            $table->dateTime('source_synced_at')->nullable();
            $table->timestamps();
            $table->unique(['assistant_domain_store_id', 'source_platform', 'external_order_id'], 'fashion_order_store_platform_external_unique');
        });
        Schema::create('fashion_logistics_order_items', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('fashion_logistics_order_id');
            $table->string('external_item_id', 191)->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('variation_id')->nullable();
            $table->string('product_name', 500)->nullable();
            $table->string('sku', 191)->nullable();
            $table->decimal('quantity', 18, 4)->default(0);
            $table->decimal('subtotal', 18, 4)->default(0);
            $table->decimal('total', 18, 4)->default(0);
            $table->decimal('total_tax', 18, 4)->default(0);
            $table->string('image_url', 1000)->nullable();
            $table->json('meta_data')->nullable();
            $table->timestamps();
        });
    }

    private function store(array $attributes = []): AssistantDomainStore
    {
        return AssistantDomainStore::query()->create(array_merge(['site_builder' => 'nbc', 'shop_status' => 'in_use', 'shop_name' => 'NBC Test', 'domain' => 'store.example', 'nbc_app_token' => 'secret-test-token'], $attributes));
    }

    private function payload(array $attributes = []): array
    {
        return array_replace(['id' => '1001', 'order_number' => 'NBC-1001', 'financial_status' => 230, 'status' => 110,
            'fulfillment_status' => 300, 'currency_code' => 'USD', 'current_total_price' => '18.50',
            'created_at' => 1794787200, 'updated_at' => 1794787260, 'pay_at' => 1794787230,
            'current_coupon_price' => '1.25', 'current_promotion_price' => '0.50',
            'transaction' => ['transaction_no' => 'tx-1001'], 'utm_source' => 'google',
            'products' => [['id' => '11', 'product_id' => '12', 'variant_id' => '13', 'product_title' => 'Test shirt', 'sku' => 'TEST-SKU', 'quantity' => 2, 'price' => '10.0001', 'discount_price' => '8.0001', 'tax_price' => '0.5000', 'src' => 'https://images.example/shirt.png']],
        ], $attributes);
    }

    private function response(array $orders, int $current = 1, int $pages = 1): array
    {
        return ['code' => 0, 'data' => ['list' => $orders, 'paginate' => ['current' => $current, 'pageTotal' => $pages, 'total' => count($orders)]]];
    }

    public function test_paid_orders_use_token_timestamp_mapping_and_platform_unique_key(): void
    {
        $store = $this->store();
        $other = FashionLogisticsOrder::query()->create(['assistant_domain_store_id' => $store->id, 'source_platform' => 'shopline', 'external_order_id' => '1001', 'total_amount' => '99']);
        $payload = $this->payload();
        Http::fake(fn () => Http::response($this->response([$payload, $this->payload(['id' => '1002', 'financial_status' => 200])])));
        $result = app(NbcOrderSyncService::class)->syncStore($store);
        $this->assertSame(1, $result['created']);
        $this->assertSame(2, FashionLogisticsOrder::query()->count());
        $this->assertSame('99.0000', $other->fresh()->total_amount);
        $order = FashionLogisticsOrder::query()->where('source_platform', 'nbc')->sole();
        $this->assertSame('18.5000', $order->total_amount);
        $this->assertSame('1.7500', $order->discount_amount);
        $this->assertSame('tx-1001', $order->transaction_id);
        $this->assertSame('google_ads', $order->marketing_source);
        $this->assertSame(gmdate('Y-m-d H:i:s', $payload['created_at']), $order->order_created_at_utc->format('Y-m-d H:i:s'));
        $this->assertSame(gmdate('Y-m-d H:i:s', $payload['created_at'] + 28800), $order->order_created_at->format('Y-m-d H:i:s'));
        $this->assertSame(gmdate('Y-m-d H:i:s', $payload['pay_at'] + 28800), $order->paid_at_utc->format('Y-m-d H:i:s'));
        $this->assertSame('20.0002', $order->items->sole()->subtotal);
        $this->assertSame('16.0002', $order->items->sole()->total);
        Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://openapi.oemapps.com/orders?') && $r->hasHeader('token', 'secret-test-token') && $r['group'] === 'all' && $r['created_at_min'] === 0 && ! isset($r['financial_status']));
    }

    public function test_repeated_sync_updates_items_and_refund_is_retained_as_hidden_status(): void
    {
        $store = $this->store();
        Http::fakeSequence()->push($this->response([$this->payload()]))->push($this->response([$this->payload()]))->push($this->response([$this->payload(['financial_status' => 260])]));
        $sync = app(NbcOrderSyncService::class);
        $sync->syncStore($store);
        $result = $sync->syncStore($store);
        $this->assertSame(1, $result['updated']);
        $this->assertSame(1, FashionLogisticsOrder::query()->count());
        $this->assertSame(1, app('db')->table('fashion_logistics_order_items')->count());
        $sync->syncStore($store);
        $this->assertSame('260', FashionLogisticsOrder::query()->sole()->payment_status);
        $this->assertSame(0, FashionLogisticsOrder::query()->where('payment_status', '230')->count());
    }

    public function test_pagination_failure_preserves_watermark_and_safe_error(): void
    {
        $store = $this->store();
        $cursor = '2026-11-10 00:00:00';
        FashionLogisticsSyncState::query()->create(['assistant_domain_store_id' => $store->id, 'last_cursor_at' => $cursor]);
        Http::fake(fn ($r) => $r['page'] === 1 ? Http::response($this->response([$this->payload()], 1, 2)) : Http::response(['message' => 'secret-test-token'], 429));
        $result = app(NbcOrderSyncService::class)->syncAll();
        $this->assertSame(1, $result['failed']);
        $state = FashionLogisticsSyncState::query()->sole();
        $this->assertSame('failed', $state->status);
        $this->assertSame($cursor, $state->last_cursor_at->format('Y-m-d H:i:s'));
        $this->assertStringNotContainsString('secret-test-token', $state->last_error);
        Http::assertSent(fn ($r) => $r['created_at_min'] === now('Asia/Shanghai')->startOfDay()->subDays(7)->timestamp && $r['created_at_max'] === now('Asia/Shanghai')->startOfDay()->addDays(7)->endOfDay()->timestamp);
    }

    public function test_multiple_pages_complete_before_watermark_advances(): void
    {
        $store = $this->store();
        Http::fake(fn ($r) => Http::response($this->response([$this->payload(['id' => (string) $r['page']])], $r['page'], 2)));
        $result = app(NbcOrderSyncService::class)->syncStore($store);
        $this->assertSame(2, $result['created']);
        Http::assertSentCount(2);
        $this->assertSame(now('UTC')->format('Y-m-d H:i:s'), FashionLogisticsSyncState::query()->sole()->last_cursor_at->format('Y-m-d H:i:s'));
    }

    public function test_api_error_and_page_limit_do_not_mark_success(): void
    {
        $store = $this->store();
        Http::fakeSequence()->push(['code' => 403, 'msg' => 'secret-test-token'])->push($this->response([$this->payload()], 1, 2));
        $this->assertSame(1, app(NbcOrderSyncService::class)->syncAll()['failed']);
        $this->assertNull(FashionLogisticsSyncState::query()->sole()->last_cursor_at);
        config(['fashion_logistics.nbc.max_pages' => 1]);
        $this->assertSame(1, app(NbcOrderSyncService::class)->syncAll()['failed']);
        $this->assertNull(FashionLogisticsSyncState::query()->sole()->last_cursor_at);
    }

    public function test_missing_token_fails_without_http_and_other_platforms_are_not_synced(): void
    {
        $this->store(['nbc_app_token' => '  ']);
        $this->store(['site_builder' => 'shopline']);
        $this->store(['site_builder' => 'xshoppy']);
        $this->store(['shop_status' => 'abandoned']);
        Http::fake();
        $result = app(NbcOrderSyncService::class)->syncAll();
        $this->assertSame(1, $result['stores']);
        $this->assertSame(1, $result['failed']);
        $this->assertSame('failed', FashionLogisticsSyncState::query()->sole()->status);
        Http::assertNothingSent();
    }

    public function test_empty_result_advances_marker_and_next_sync_uses_creation_date_window(): void
    {
        $store = $this->store();
        Http::fake(fn () => Http::response($this->response([], 1, 0)));
        app(NbcOrderSyncService::class)->syncStore($store);
        $this->travel(10)->minutes();
        app(NbcOrderSyncService::class)->syncStore($store);
        Http::assertSent(fn ($r) => $r['created_at_min'] > 0);
        $this->assertSame(0, FashionLogisticsOrder::query()->count());
    }

    public function test_store_lock_prevents_duplicate_sync(): void
    {
        $store = $this->store();
        $lock = Cache::lock('fashion-logistics:nbc-sync:'.$store->id, 86400);
        $lock->get();
        Http::fake();
        try {
            $this->assertSame(1, app(NbcOrderSyncService::class)->syncAll()['failed']);
        } finally {
            $lock->release();
        }
        Http::assertNothingSent();
    }

    public function test_malformed_amount_rolls_back_order_and_preserves_cursor(): void
    {
        $this->store();
        Http::fake(fn () => Http::response($this->response([$this->payload(['current_total_price' => 'bad'])])));
        $this->assertSame(1, app(NbcOrderSyncService::class)->syncAll()['failed']);
        $this->assertSame(0, FashionLogisticsOrder::query()->count());
        $this->assertNull(FashionLogisticsSyncState::query()->sole()->last_cursor_at);
    }

    public function test_command_rejects_unknown_store(): void
    {
        Http::fake();
        $this->artisan('fashion-logistics:sync-nbc-orders', ['--store' => '999'])->assertExitCode(1);
        Http::assertNothingSent();
    }

    public function test_empty_last_page_is_failure_and_does_not_advance_cursor(): void
    {
        $this->store();
        Http::fakeSequence()->push($this->response([$this->payload()], 1, 2))->push($this->response([], 2, 2));
        $this->assertSame(1, app(NbcOrderSyncService::class)->syncAll()['failed']);
        $this->assertNull(FashionLogisticsSyncState::query()->sole()->last_cursor_at);
    }

    public function test_first_nbc_sync_does_not_use_another_platforms_cursor(): void
    {
        $store = $this->store();
        FashionLogisticsOrder::query()->create(['assistant_domain_store_id' => $store->id, 'source_platform' => 'shopline', 'external_order_id' => 'old']);
        FashionLogisticsSyncState::query()->create(['assistant_domain_store_id' => $store->id, 'last_cursor_at' => '2026-11-15 00:00:00']);
        Http::fake(fn () => Http::response($this->response([$this->payload()])));
        app(NbcOrderSyncService::class)->syncStore($store);
        Http::assertSent(fn ($r) => $r['created_at_min'] === 0);
        $this->assertSame(2, FashionLogisticsOrder::query()->count());
    }

    public function test_business_error_exposes_code_reason_and_trace_without_advancing_cursor(): void
    {
        app()->setLocale('zh-CN');
        $store = $this->store();
        $cursor = '2026-11-10 00:00:00';
        FashionLogisticsSyncState::query()->create(['assistant_domain_store_id' => $store->id, 'last_cursor_at' => $cursor]);
        Http::fake(fn () => Http::response(['code' => 403, 'msg' => '无权访问订单接口', 'trace_id' => 'trace-test-403']));

        $result = app(NbcOrderSyncService::class)->syncAll();
        $state = FashionLogisticsSyncState::query()->sole();

        $this->assertSame(1, $result['failed']);
        $this->assertSame('failed', $state->status);
        $this->assertStringContainsString('错误码：403', $state->last_error);
        $this->assertStringContainsString('无权访问订单接口', $state->last_error);
        $this->assertStringContainsString('trace-test-403', $state->last_error);
        $this->assertStringContainsString($state->last_error, $result['errors'][0]);
        $this->assertSame($cursor, $state->last_cursor_at->format('Y-m-d H:i:s'));
        $this->assertSame(0, FashionLogisticsOrder::query()->count());
    }

    public function test_diagnostics_redact_tokens_and_credentials_before_shortening(): void
    {
        app()->setLocale('zh-CN');
        $token = 'nbc/secret+token=123';
        $this->store(['nbc_app_token' => $token]);
        $message = '<b>认证失败</b>'."\n".' token='.$token.'; Authorization: Bearer bearer-secret; client_secret="client-secret"; '
            .'encoded='.rawurlencode($token).'; base64='.base64_encode($token);
        Http::fake(fn () => Http::response(['code' => 403, 'msg' => $message, 'trace_id' => 'trace-'.$token]));

        $result = app(NbcOrderSyncService::class)->syncAll();
        $error = FashionLogisticsSyncState::query()->sole()->last_error;

        $this->assertStringContainsString('认证失败', $error);
        $this->assertStringContainsString('[已隐藏]', $error);
        foreach ([$token, rawurlencode($token), base64_encode($token), 'bearer-secret', 'client-secret', '<b>', "\n"] as $secret) {
            $this->assertStringNotContainsString($secret, $error);
            $this->assertStringNotContainsString($secret, $result['errors'][0]);
        }
    }

    public function test_missing_or_nested_diagnostic_fields_use_translated_fallbacks(): void
    {
        app()->setLocale('zh-CN');
        $this->store();
        Http::fakeSequence()
            ->push(['message' => '接口缺少状态字段'])
            ->push(['code' => ['secret' => 'nested-secret'], 'msg' => ['secret' => 'nested-secret'], 'trace_id' => ['secret' => 'nested-secret']]);

        $sync = app(NbcOrderSyncService::class);
        $sync->syncAll();
        $error = FashionLogisticsSyncState::query()->sole()->last_error;
        $this->assertStringContainsString('错误码：未提供', $error);
        $this->assertStringContainsString('接口缺少状态字段', $error);
        $this->assertStringContainsString('追踪号：未提供', $error);

        $sync->syncAll();
        $error = FashionLogisticsSyncState::query()->sole()->last_error;
        $this->assertStringContainsString('接口未提供具体原因', $error);
        $this->assertStringNotContainsString('nested-secret', $error);
        $this->assertNull(FashionLogisticsSyncState::query()->sole()->last_cursor_at);
    }

    public function test_diagnostic_reason_is_bounded_and_english_translation_is_available(): void
    {
        app()->setLocale('en');
        $this->store();
        Http::fake(fn () => Http::response(['code' => 405, 'msg' => str_repeat('A', 1000), 'trace_id' => str_repeat('B', 200)]));

        app(NbcOrderSyncService::class)->syncAll();
        $error = FashionLogisticsSyncState::query()->sole()->last_error;

        $this->assertStringContainsString('code: 405', $error);
        $this->assertStringContainsString('reason: '.str_repeat('A', 240).';', $error);
        $this->assertStringContainsString('trace: '.str_repeat('B', 96).')', $error);
        $this->assertStringNotContainsString('nbc_orders.', $error);
    }

    public function test_string_zero_success_code_still_imports_paid_orders(): void
    {
        $store = $this->store();
        $response = $this->response([$this->payload()]);
        $response['code'] = '0';
        Http::fake(fn () => Http::response($response));

        $this->assertSame(1, app(NbcOrderSyncService::class)->syncStore($store)['created']);
        $this->assertSame('success', FashionLogisticsSyncState::query()->sole()->status);
    }

    public function test_both_builders_use_their_own_tokens_and_preserve_platform_identity(): void
    {
        $nbc = $this->store(['nbc_app_token' => 'nbc-token']);
        $shopyy = $this->store(['site_builder' => ' ShOpYy ', 'nbc_app_token' => 'shopyy-token']);
        $this->store(['site_builder' => 'shopline']);
        Http::fake(fn () => Http::response($this->response([$this->payload()])));

        $result = app(NbcOrderSyncService::class)->syncAll();

        $this->assertSame(2, $result['stores']);
        $this->assertSame(2, $result['success']);
        $this->assertSame(['nbc', 'shopyy'], FashionLogisticsOrder::query()->orderBy('id')->pluck('source_platform')->all());
        $this->assertSame('1001', FashionLogisticsOrder::query()->where('assistant_domain_store_id', $nbc->id)->sole()->external_order_id);
        $this->assertSame('1001', FashionLogisticsOrder::query()->where('assistant_domain_store_id', $shopyy->id)->sole()->external_order_id);
        Http::assertSent(fn ($r) => $r->hasHeader('token', 'nbc-token'));
        Http::assertSent(fn ($r) => $r->hasHeader('token', 'shopyy-token'));
        Http::assertSentCount(2);
    }

    public function test_changing_between_nbc_and_shopyy_does_not_overwrite_old_platform_orders(): void
    {
        $store = $this->store();
        Http::fake(fn () => Http::response($this->response([$this->payload()])));
        $sync = app(NbcOrderSyncService::class);
        $sync->syncStore($store);
        $store->update(['site_builder' => 'shopyy']);
        $sync->syncStore($store);

        $this->assertSame(2, FashionLogisticsOrder::query()->count());
        $this->assertSame(['nbc', 'shopyy'], FashionLogisticsOrder::query()->orderBy('id')->pluck('source_platform')->all());
        $this->assertSame(2, app('db')->table('fashion_logistics_order_items')->count());
        Http::assertSent(fn ($r) => $r['created_at_min'] === 0);
    }

    public function test_incremental_creation_window_uses_china_calendar_days_across_month_and_year_boundaries(): void
    {
        $store = $this->store(['site_builder' => 'shopyy']);
        FashionLogisticsSyncState::query()->create(['assistant_domain_store_id' => $store->id, 'last_cursor_at' => '2020-01-01 00:00:00']);
        $cases = [
            ['2026-10-31 16:05:00', '2026-10-25 00:00:00', '2026-11-08 23:59:59'],
            ['2026-12-31 16:05:00', '2026-12-25 00:00:00', '2027-01-08 23:59:59'],
            ['2028-02-29 16:05:00', '2028-02-23 00:00:00', '2028-03-08 23:59:59'],
        ];
        $requests = [];
        Http::fake(function ($request) use (&$requests) {
            $requests[] = $request;

            return Http::response($this->response([], 1, 0));
        });
        foreach ($cases as [$utc, $from, $to]) {
            $this->travelTo(CarbonImmutable::parse($utc, 'UTC'));
            app(NbcOrderSyncService::class)->syncStore($store);
            $request = $requests[array_key_last($requests)];
            $this->assertSame(CarbonImmutable::parse($from, 'Asia/Shanghai')->timestamp, $request['created_at_min']);
            $this->assertSame(CarbonImmutable::parse($to, 'Asia/Shanghai')->timestamp, $request['created_at_max']);
            $this->assertSame('id', $request['order_field']);
            $this->assertSame('asc', $request['order_by']);
            $this->assertArrayNotHasKey('updated_at_min', $request->data());
            $this->assertArrayNotHasKey('updated_at_max', $request->data());
            $this->assertSame($utc, FashionLogisticsSyncState::query()->sole()->last_cursor_at->format('Y-m-d H:i:s'));
        }
    }

    public function test_full_and_all_commands_read_all_history_even_when_a_cursor_exists(): void
    {
        $store = $this->store(['site_builder' => 'shopyy']);
        FashionLogisticsSyncState::query()->create(['assistant_domain_store_id' => $store->id, 'last_cursor_at' => '2026-11-15 00:00:00']);
        $oldOrder = $this->payload(['created_at' => 978307200, 'updated_at' => 978307260, 'pay_at' => 978307230]);
        Http::fake(fn () => Http::response($this->response([$oldOrder])));
        foreach (['--full', '--all'] as $option) {
            $this->artisan('fashion-logistics:sync-nbc-orders', ['--store' => (string) $store->id, $option => true])->assertExitCode(0);
        }
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($r) => $r['created_at_min'] !== 0 || $r['created_at_max'] !== now('UTC')->timestamp);
        $this->assertSame(1, FashionLogisticsOrder::query()->count());
        $this->assertSame('2001-01-01 00:00:00', FashionLogisticsOrder::query()->sole()->order_created_at_utc->format('Y-m-d H:i:s'));
        $this->assertFalse(app(SyncNbcFashionOrders::class)->getDefinition()->hasOption('days'));
    }
}
