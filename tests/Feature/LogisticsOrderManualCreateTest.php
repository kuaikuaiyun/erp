<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\AssistantDomainStore;
use App\Models\LogisticsOrder;
use App\Models\LogisticsOrderItem;
use App\Models\LogisticsSyncLog;
use App\Models\Portal;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\Assistant\AssistantStoreOwnerTimelineService;
use App\Portals\Logistics\Http\Controllers\LogisticsOrderController;
use App\Portals\Logistics\LogisticsOrderItemService;
use App\Portals\Logistics\LogisticsOrderPaymentReferenceService;
use App\Portals\Logistics\PayPalOrderAssociationService;
use App\Portals\Logistics\TongtuSkuMappingService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LogisticsOrderManualCreateTest extends TestCase
{
    private mixed $mapping;
    private array $context;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'admin.database.connection' => null, 'cache.default' => 'array']);
        DB::purge('sqlite');
        Http::preventStrayRequests();
        foreach ([new LogisticsOrder(), new LogisticsOrderItem(), new LogisticsSyncLog()] as $model) {
            Schema::create($model->getTable(), function (Blueprint $table) use ($model): void {
                $table->increments('id');
                foreach ($model->getFillable() as $field) $table->text($field)->nullable();
                $table->timestamps();
                if ($model instanceof LogisticsOrder) {
                    $table->unique(['assistant_domain_store_id', 'shopify_order_id']);
                    $table->unique(['source_shop_domain', 'shopify_order_id']);
                }
                if ($model instanceof LogisticsOrderItem) $table->unique(['logistics_order_id', 'source_key']);
            });
        }
        Schema::create((new AssistantDomainStore())->getTable(), function (Blueprint $table): void {
            $table->increments('id');
            foreach (['shop_name','site_builder','shop_status','shop_login_url','tongtu_prefix','user_name','team'] as $field) $table->text($field)->nullable();
        });
        DB::table((new AssistantDomainStore())->getTable())->insert([
            'id' => 1, 'shop_name' => '无应用店铺', 'site_builder' => 'shopify', 'shop_status' => AssistantDomainStore::SHOP_STATUS_IN_USE,
            'shop_login_url' => 'https://manual-test.myshopify.com', 'tongtu_prefix' => 'TEST', 'team' => '["测试组"]',
        ]);
        $portal = (new Portal())->forceFill(['id'=>1,'code'=>'wuliu']);
        $portal->setRelation('menus', collect());
        $this->context = ['portal'=>$portal, 'user'=>(new AdminUser())->forceFill(['id'=>7,'name'=>'补录操作人'])];
        $this->mock(PortalEntryController::class)->shouldReceive('portalContext')->andReturnUsing(fn () => $this->context);
        $this->mock(AssistantStoreOwnerTimelineService::class)->shouldReceive('applySnapshotIfMissing')->andReturnUsing(function ($order): void {
            $order->assigned_owner_user_id = 12; $order->assigned_owner_name = '历史归属人'; $order->owner_assignment_resolved_at = now();
        });
        $this->mock(LogisticsOrderPaymentReferenceService::class)->shouldReceive('syncFromAttributes')->andReturn(0);
        $this->mock(PayPalOrderAssociationService::class)->shouldReceive('reconcileOrder')->andReturn(0);
        $this->mapping = $this->mock(TongtuSkuMappingService::class);
        $this->mapping->shouldReceive('scheduleForOrder')->andReturnNull()->byDefault();
    }

    private function payload(array $extra = []): array
    {
        return array_merge([
            'assistant_domain_store_id'=>'1', 'shopify_order_id'=>'18994365563187', 'shopify_order_number'=>'#1067',
            'order_created_at'=>'2026-11-06T14:04:18', 'paid_at'=>'2026-11-06T14:04:17',
            'paid_amount_usd'=>'30.0000', 'total_shipping_usd'=>'3.0000', 'buyer_email'=>'buyer@example.com',
            'payment_method'=>'pp', 'payment_gateway'=>'PayPal', 'collection_account'=>'收款账号', 'bill_number'=>'BILL-1',
            'is_add_on'=>'no', 'subscription_kind'=>'none', 'tracking_number'=>'WY300601835458',
            'items'=>[
                ['shopify_sku'=>'SKU-A','product_title'=>'商品 A','quantity'=>'2','unit_price_usd'=>'10.00'],
                ['shopify_sku'=>'SKU-B','product_title'=>'商品 B','quantity'=>'1','unit_price_usd'=>'10.00'],
            ],
        ], $extra);
    }

    private function submit(array $payload): mixed
    {
        return app(LogisticsOrderController::class)->store(Request::create('/orders','POST',$payload), app(LogisticsOrderItemService::class));
    }

    public function test_manual_order_and_items_are_atomic_use_utc_and_do_not_call_shopify(): void
    {
        $response = $this->submit($this->payload(['shopify_payload'=>'{"fake":true}', 'source_shop_domain'=>'evil.example', 'paypal_account_id'=>999, 'assigned_owner_user_id'=>999]));
        self::assertSame(302, $response->getStatusCode());
        $order = LogisticsOrder::query()->sole();
        self::assertSame('2026-11-06 06:04:18', $order->order_created_at->format('Y-m-d H:i:s'));
        self::assertSame('2026-11-06 06:04:17', $order->paid_at->format('Y-m-d H:i:s'));
        self::assertSame('1067', $order->shopify_order_number);
        self::assertSame('TEST-1067', $order->tongtu_order_number);
        self::assertSame('manual-test.myshopify.com', $order->source_shop_domain);
        self::assertNull($order->shopify_payload);
        self::assertNull($order->paypal_account_id);
        self::assertEquals(12, $order->assigned_owner_user_id);
        self::assertSame('已付款', $order->payment_status);
        self::assertSame('未加购', $order->is_add_on);
        self::assertSame(LogisticsOrder::LOGISTICS_WAITING_QUERY, $order->logistics_status);
        self::assertNotNull($order->tracking_next_sync_at);
        self::assertSame(['SKU-A','SKU-B'], $order->items()->orderBy('id')->pluck('shopify_sku')->all());
        self::assertEquals([20,10], $order->items()->orderBy('id')->pluck('line_revenue_usd')->all());
        self::assertEquals(30, $order->items()->sum('line_revenue_usd'));
        $log = LogisticsSyncLog::query()->sole();
        self::assertSame('manual', $log->source);
        self::assertEquals(7, $log->actor_id);
        Http::assertNothingSent();
    }

    public function test_repeated_submission_does_not_change_existing_order_or_corrected_sku(): void
    {
        $this->submit($this->payload());
        LogisticsOrderItem::query()->first()->update(['shopify_sku'=>'CORRECTED']);
        try { $this->submit($this->payload(['paid_amount_usd'=>'99'])); self::fail('Duplicate must be rejected'); }
        catch (ValidationException $e) { self::assertArrayHasKey('shopify_order_id', $e->errors()); }
        self::assertSame(1, LogisticsOrder::query()->count());
        self::assertEquals(30, LogisticsOrder::query()->sole()->paid_amount_usd);
        self::assertSame('CORRECTED', LogisticsOrderItem::query()->first()->shopify_sku);
        self::assertSame(1, LogisticsSyncLog::query()->count());
    }

    public function test_wrong_store_and_wrong_portal_cannot_create_orders(): void
    {
        DB::table((new AssistantDomainStore())->getTable())->where('id',1)->update(['site_builder'=>'shopline']);
        try { $this->submit($this->payload()); self::fail('Wrong store must be rejected'); }
        catch (ValidationException $e) { self::assertArrayHasKey('assistant_domain_store_id', $e->errors()); }
        self::assertSame(0, LogisticsOrder::query()->count());
        $this->context['portal']->code = 'fuzhuang';
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->submit($this->payload());
    }

    public function test_existing_unassigned_order_with_same_source_domain_is_not_duplicated(): void
    {
        LogisticsOrder::query()->create(['shopify_order_id'=>'18994365563187','source_shop_domain'=>'manual-test.myshopify.com','paid_amount_usd'=>77]);
        try { $this->submit($this->payload()); self::fail('Source-domain duplicate must be rejected'); }
        catch (ValidationException $e) { self::assertArrayHasKey('shopify_order_id', $e->errors()); }
        self::assertSame(1, LogisticsOrder::query()->count());
        self::assertEquals(77, LogisticsOrder::query()->sole()->paid_amount_usd);
        self::assertSame(0, LogisticsOrderItem::query()->count());
    }

    public function test_same_native_id_is_allowed_for_another_store_without_credentials_or_login_domain(): void
    {
        $this->submit($this->payload());
        DB::table((new AssistantDomainStore())->getTable())->insert(['id'=>2,'shop_name'=>'第二家店','site_builder'=>'shopify','shop_status'=>AssistantDomainStore::SHOP_STATUS_IN_USE]);
        $this->submit($this->payload(['assistant_domain_store_id'=>'2']));
        self::assertSame(2, LogisticsOrder::query()->count());
        self::assertNull(LogisticsOrder::query()->where('assistant_domain_store_id',2)->sole()->source_shop_domain);
        Http::assertNothingSent();
    }

    public function test_validation_rejects_order_numbers_as_ids_bad_money_empty_sku_and_missing_renewal_origin(): void
    {
        foreach ([
            ['shopify_order_id'=>'#1067'], ['paid_amount_usd'=>'-1'], ['paid_amount_usd'=>'1e2'],
            ['paid_at'=>'2026-11-31T10:00:00'], ['subscription_kind'=>'renewal'],
            ['items'=>[['shopify_sku'=>'  ','product_title'=>'商品','quantity'=>'1','unit_price_usd'=>'1']]],
        ] as $extra) {
            try { $this->submit($this->payload($extra)); self::fail('Invalid input must be rejected'); }
            catch (ValidationException $e) { self::assertNotEmpty($e->errors()); self::assertStringNotContainsString('validation.', json_encode($e->errors(), JSON_UNESCAPED_UNICODE)); }
        }
        self::assertSame(0, LogisticsOrder::query()->count());
        self::assertSame(0, LogisticsOrderItem::query()->count());
    }

    public function test_failure_during_follow_up_scheduling_rolls_back_order_and_items(): void
    {
        $this->mapping->shouldReceive('scheduleForOrder')->once()->andThrow(new \RuntimeException('Simulated scheduling failure'));
        try { $this->submit($this->payload()); self::fail('Failure must propagate'); }
        catch (\RuntimeException $e) { self::assertSame('Simulated scheduling failure', $e->getMessage()); }
        self::assertSame(0, LogisticsOrder::query()->count());
        self::assertSame(0, LogisticsOrderItem::query()->count());
        self::assertSame(0, LogisticsSyncLog::query()->count());
    }

    public function test_renewal_type_uses_existing_business_values_and_does_not_guess_origin(): void
    {
        $this->submit($this->payload(['subscription_kind'=>'renewal','renewal_number'=>'2','subscription_origin_order_id'=>'123456789']));
        $order = LogisticsOrder::query()->sole();
        self::assertSame('订阅复购2次', $order->subscription_type);
        self::assertSame('123456789', $order->subscription_origin_order_id);
        self::assertSame('已订阅', $order->subscription_status);
        Http::assertNothingSent();
    }

    public function test_create_page_renders_without_application_credentials(): void
    {
        $view = app(LogisticsOrderController::class)->create(Request::create('/orders/create'));
        self::assertSame('portals.logistics.orders.create', $view->name());
        $source = str_replace("@extends('portal.layout')", '', file_get_contents(resource_path('views/portals/logistics/orders/create.blade.php')));
        $html = Blade::render($source.'@yield("content") @stack("scripts")', [...$view->getData(), 'errors'=>new \Illuminate\Support\ViewErrorBag()]);
        self::assertStringContainsString('无应用店铺', $html);
        self::assertStringContainsString('name="items[0][shopify_sku]"', $html);
        self::assertStringContainsString('name="shopify_order_id"', $html);
        self::assertStringContainsString('北京时间', $html);
        self::assertStringNotContainsString('logistics.orders.manual.', $html);
        Http::assertNothingSent();
    }
}
