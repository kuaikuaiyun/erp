<?php

namespace Tests\Feature;

use App\Models\AssistantDomainStore;
use App\Models\LogisticsOrder;
use App\Models\AssistantAdvertisingMedia;
use App\Portals\Assistant\AssistantStoreOwnerTimelineService;
use App\Portals\Logistics\{ShopifyOrderSyncService,LogisticsOrderItemService,TongtuSkuMappingService,LogisticsTrackingService,LogisticsOrderPaymentReferenceService,PayPalOrderAssociationService};
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LogisticsOrderIdentifierPreservationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default'=>'sqlite','database.connections.sqlite.database'=>':memory:','admin.database.connection'=>null]);
        DB::purge('sqlite');
        Http::preventStrayRequests();
        Schema::create((new LogisticsOrder())->getTable(), function (Blueprint $table): void {
            $table->increments('id');
            foreach ((new LogisticsOrder())->getFillable() as $field) $table->text($field)->nullable();
            $table->timestamps();
        });
        Schema::create((new AssistantAdvertisingMedia())->getTable(), function (Blueprint $table): void {
            $table->increments('id'); $table->string('name'); $table->boolean('is_enabled')->default(true); $table->integer('sort')->default(0);
        });
        $this->mock(LogisticsOrderItemService::class)->shouldReceive('syncFromShopifyPayload')->andReturnNull();
        $this->mock(TongtuSkuMappingService::class)->shouldReceive('scheduleForOrder')->andReturnNull();
        $this->mock(LogisticsTrackingService::class)->shouldReceive('scheduleForOrder')->andReturnNull();
        $this->mock(LogisticsOrderPaymentReferenceService::class)->shouldReceive('syncFromAttributes')->andReturn(0);
        $this->mock(PayPalOrderAssociationService::class)->shouldReceive('reconcileOrder')->andReturn(0);
        $this->mock(AssistantStoreOwnerTimelineService::class)->shouldReceive('applySnapshotIfMissing')->andReturnNull();
    }

    public function test_sync_keeps_manual_number_and_generates_only_for_new_or_empty_orders(): void
    {
        $store = (new AssistantDomainStore())->forceFill(['id'=>1,'shop_name'=>'测试','tongtu_prefix'=>'a']);
        $sync = app(ShopifyOrderSyncService::class);
        $method = new \ReflectionMethod($sync,'persistOrder');
        $payload = ['legacyResourceId'=>'123','name'=>'#1001','displayFinancialStatus'=>'PAID','createdAt'=>'2026-11-10T00:00:00Z'];
        $first = $method->invoke($sync,$store,$payload,'test.myshopify.com')['order'];
        self::assertSame('a-1001',$first->tongtu_order_number);
        $first->update(['tongtu_order_number'=>'人工-77']);
        $store->tongtu_prefix = 'b';
        $updated = $method->invoke($sync,$store,$payload,'test.myshopify.com')['order'];
        self::assertSame($first->getKey(),$updated->getKey());
        self::assertSame('人工-77',$updated->tongtu_order_number);
        self::assertSame(1,LogisticsOrder::query()->count());
        $updated->update(['tongtu_order_number'=>null]);
        self::assertSame('b-1001',$method->invoke($sync,$store,$payload,'test.myshopify.com')['order']->tongtu_order_number);
        Http::assertNothingSent();
    }
}
