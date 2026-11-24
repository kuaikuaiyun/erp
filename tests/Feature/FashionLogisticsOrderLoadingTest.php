<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\AssistantDomainStore;
use App\Models\FashionLogisticsOrder;
use App\Models\Portal;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\FashionLogistics\Http\Controllers\FashionOrderController;
use App\Portals\FashionLogistics\Http\Controllers\NbcOrderController;
use App\Portals\FashionLogistics\Http\Controllers\ShoplineOrderController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ViewErrorBag;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FashionLogisticsOrderLoadingTest extends TestCase
{
    private bool $mayViewOrders = true;

    protected function setUp(): void
    {
        parent::setUp();
        NbcFashionOrderSyncTest::createNbcSchema();
        Schema::create('admin_users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('username');
        });
        $user = Mockery::mock(AdminUser::class)->makePartial();
        $user->setRawAttributes(['id' => 1, 'name' => 'Owner One', 'username' => 'owner1']);
        $user->shouldReceive('isAdministrator')->andReturn(false);
        $user->shouldReceive('can')->with('portal.fuzhuangwuliu.orders')->andReturnUsing(fn () => $this->mayViewOrders);
        $entry = Mockery::mock(PortalEntryController::class);
        $entry->shouldReceive('portalContext')->andReturn([
            'portal' => new Portal(['code' => 'fuzhuangwuliu']),
            'user' => $user,
        ]);
        $this->app->instance(PortalEntryController::class, $entry);
    }

    private function makeOrder(string $platform, string $number, int $owner = 1): FashionLogisticsOrder
    {
        $store = AssistantDomainStore::query()->create([
            'site_builder' => $platform,
            'shop_status' => AssistantDomainStore::SHOP_STATUS_IN_USE,
            'shop_name' => $number,
            'owner_user_id' => $owner,
            'xshoppy_password' => 'store-credential-marker',
        ]);
        $order = FashionLogisticsOrder::query()->create([
            'assistant_domain_store_id' => $store->id,
            'source_platform' => $platform,
            'external_order_id' => $number,
            'order_number' => $number,
            'status' => 'processing',
            'payment_status' => '230',
            'order_created_at' => '2026-11-19 07:34:14',
            'shipping_address' => 'detail-address-marker',
            'source_payload' => ['marker' => 'raw-detail-marker'],
        ]);
        foreach (range(1, 3) as $index) {
            $order->items()->create([
                'product_name' => 'Product '.$index,
                'sku' => $number.'-'.$index,
                'quantity' => 1,
                'image_url' => 'https://example.test/detail-image-'.$index.'.jpg',
            ]);
        }

        return $order;
    }

    public static function lists(): array
    {
        return [
            'XShoppy' => ['xshoppy', 'orders', FashionOrderController::class],
            'Shopline' => ['shopline', 'shopline-orders', ShoplineOrderController::class],
            'NBC' => ['nbc', 'nbc-orders', NbcOrderController::class],
            'ShopYY' => ['shopyy', 'nbc-orders', NbcOrderController::class],
        ];
    }

    #[DataProvider('lists')]
    public function test_list_only_loads_product_summaries_and_can_search_later_items(string $platform, string $page, string $controller): void
    {
        $match = $this->makeOrder($platform, 'first');
        $this->makeOrder($platform, 'second');
        $controller = $this->app->make($controller);
        $path = '/fashion-logistics/'.$page;
        $data = $controller->index(Request::create($path))->getData();

        $this->assertCount(2, $data['orders']);
        foreach ($data['orders'] as $order) {
            $this->assertArrayNotHasKey('source_payload', $order->getAttributes());
            $this->assertArrayNotHasKey('shipping_address', $order->getAttributes());
            $this->assertSame(3, (int) $order->items_count);
            $this->assertSame([$order->order_number.'-1', $order->order_number.'-2'], $order->items->pluck('sku')->all());
            $this->assertArrayNotHasKey('image_url', $order->items->first()->getAttributes());
        }
        $data['errors'] = new ViewErrorBag;
        $source = file_get_contents(resource_path('views/portals/fashion-logistics/'.$page.'/index.blade.php'));
        $source = str_replace("@extends('portal.layout')", '', $source)."\n@yield('content')";
        $html = Blade::render($source, $data, true);
        $this->assertStringNotContainsString('data-fashion-order-payload', $html);
        $this->assertStringNotContainsString('raw-detail-marker', $html);
        $this->assertStringNotContainsString('detail-address-marker', $html);
        $this->assertStringNotContainsString('detail-image-', $html);

        $filtered = $controller->index(Request::create($path, 'GET', ['keyword' => 'first-3']))->getData();
        $this->assertSame([$match->id], $filtered['orders']->pluck('id')->all());
    }

    public function test_xshoppy_detail_route_only_serves_visible_orders_to_permitted_users(): void
    {
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $own = $this->makeOrder('xshoppy', 'own');
        $other = $this->makeOrder('xshoppy', 'other', 2);
        $shopline = $this->makeOrder('shopline', 'shopline');

        $response = $this->getJson('/fashion-logistics/orders/'.$own->id)
            ->assertOk()
            ->assertJsonPath('order.order_created_at', '2026-11-19 07:34:14')
            ->assertJsonPath('order.shipping_address', 'detail-address-marker')
            ->assertJsonPath('order.source_payload.marker', 'raw-detail-marker')
            ->assertJsonCount(3, 'items')
            ->assertJsonPath('items.2.image_url', 'https://example.test/detail-image-3.jpg');
        $this->assertStringNotContainsString('store-credential-marker', $response->getContent());

        $this->getJson('/fashion-logistics/orders/'.$other->id)->assertForbidden();
        $this->getJson('/fashion-logistics/orders/'.$shopline->id)->assertNotFound();
        $this->mayViewOrders = false;
        $this->getJson('/fashion-logistics/orders/'.$own->id)->assertForbidden();
    }
}
