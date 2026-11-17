<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\AssistantDomainStore;
use App\Models\FashionLogisticsOrder;
use App\Models\Portal;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\FashionLogistics\FashionLogisticsPortalService;
use App\Portals\FashionLogistics\Http\Controllers\NbcOrderController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ViewErrorBag;
use Illuminate\View\Compilers\BladeCompiler;
use Mockery;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class NbcFashionOrderAccessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        NbcFashionOrderSyncTest::createNbcSchema();
        Schema::create('admin_users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('username');
        });
        DB::table('admin_users')->insert([
            ['id' => 1, 'name' => 'Owner One', 'username' => 'owner1'],
            ['id' => 2, 'name' => 'Owner Two', 'username' => 'owner2'],
        ]);
    }

    private function controller(bool $admin = false, bool $allowed = true): NbcOrderController
    {
        $user = Mockery::mock(AdminUser::class)->makePartial();
        $user->setRawAttributes(['id' => 1, 'name' => 'Owner One', 'username' => 'owner1']);
        $user->shouldReceive('isAdministrator')->andReturn($admin);
        $user->shouldReceive('can')->with('portal.fuzhuangwuliu.orders')->andReturn($allowed);
        $entry = Mockery::mock(PortalEntryController::class);
        $entry->shouldReceive('portalContext')->andReturn(['portal' => new Portal(['code' => 'fuzhuangwuliu']), 'user' => $user]);

        return new NbcOrderController($entry, new FashionLogisticsPortalService);
    }

    private function order(array $storeAttributes = [], array $orderAttributes = []): FashionLogisticsOrder
    {
        $store = AssistantDomainStore::query()->create(array_merge(['site_builder' => 'nbc', 'shop_status' => 'in_use', 'shop_name' => 'NBC Test', 'nbc_app_token' => 'do-not-leak-token', 'owner_user_id' => 1], $storeAttributes));

        return FashionLogisticsOrder::query()->create(array_merge(['assistant_domain_store_id' => $store->id, 'source_platform' => 'nbc', 'external_order_id' => (string) $store->id, 'payment_status' => '230', 'order_created_at' => '2026-11-16 08:00:00', 'paid_at_utc' => '2026-11-16 08:01:00'], $orderAttributes));
    }

    public function test_list_enforces_platform_payment_store_status_and_owner_scope(): void
    {
        $own = $this->order();
        $this->order(['owner_user_id' => 2]);
        $this->order([], ['source_platform' => 'shopline']);
        $this->order([], ['payment_status' => '260']);
        $this->order(['shop_status' => 'abandoned']);
        $this->order(['site_builder' => 'xshoppy']);
        $data = $this->controller()->index(Request::create('/fashion-logistics/nbc-orders'))->getData();
        $this->assertSame([$own->id], $data['orders']->pluck('id')->all());
        $this->assertFalse($data['canSelectOperator']);
        $this->assertSame(2, $this->controller(true)->index(Request::create('/fashion-logistics/nbc-orders'))->getData()['orders']->total());
        foreach ($data['stores'] as $store) {
            $this->assertArrayNotHasKey('nbc_app_token', $store->getAttributes());
        }
    }

    public function test_name_fallback_and_filter_cannot_expand_user_visibility(): void
    {
        $own = $this->order(['owner_user_id' => null, 'user_name' => 'owner1']);
        $other = $this->order(['owner_user_id' => 2]);
        $controller = $this->controller();
        $this->assertSame([$own->id], $controller->index(Request::create('/fashion-logistics/nbc-orders'))->getData()['orders']->pluck('id')->all());
        $filtered = $controller->index(Request::create('/fashion-logistics/nbc-orders', 'GET', ['store_id' => $other->assistant_domain_store_id, 'operator_id' => 2]))->getData();
        $this->assertSame(0, $filtered['orders']->total());
    }

    public function test_detail_excludes_store_credentials_and_formats_china_wall_clock(): void
    {
        $order = $this->order();
        $json = $this->controller()->show(Request::create('/fashion-logistics/nbc-orders/'.$order->id), $order);
        $this->assertStringNotContainsString('do-not-leak-token', $json->getContent());
        $data = $json->getData(true);
        $this->assertSame('2026-11-16 08:01:00', $data['order']['paid_at_utc']);
        $this->assertArrayNotHasKey('store', $data['order']);
        $this->assertSame(['id', 'shop_name', 'user_name'], array_keys($data['store']));
    }

    public function test_other_owners_detail_is_forbidden(): void
    {
        $order = $this->order(['owner_user_id' => 2]);
        try {
            $this->controller()->show(Request::create('/'), $order);
            $this->fail('Expected 403');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_other_platform_or_refunded_detail_is_not_found(): void
    {
        foreach ([['source_platform' => 'xshoppy'], ['payment_status' => '260']] as $attributes) {
            $order = $this->order([], $attributes);
            try {
                $this->controller(true)->show(Request::create('/'), $order);
                $this->fail('Expected 404');
            } catch (HttpException $e) {
                $this->assertSame(404, $e->getStatusCode());
            }
        }
    }

    public function test_sync_status_uses_same_visibility_and_does_not_expose_token(): void
    {
        $this->order();
        $this->order(['nbc_app_token' => '   ']);
        $this->order(['owner_user_id' => 2]);
        $controller = $this->controller();
        $response = $controller->syncStatus(Request::create('/fashion-logistics/nbc-orders/sync-status'));
        $data = $response->getData(true);
        $this->assertSame(2, $data['summary']['total']);
        $this->assertSame(1, $data['summary']['ready']);
        $this->assertSame(1, $data['summary']['missing']);
        $this->assertStringNotContainsString('do-not-leak-token', $response->getContent());
        $ready = $controller->syncStatus(Request::create('/', 'GET', ['configuration' => 'ready']))->getData(true);
        $this->assertCount(1, $ready['rows']);
        $this->assertTrue($ready['rows'][0]['ready']);
    }

    public function test_menu_migration_is_idempotent_preserves_existing_paths_and_has_translation(): void
    {
        Schema::create('portals', function (Blueprint $t) {
            $t->id();
            $t->string('code');
        });
        Schema::create('portal_menus', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('portal_id');
            $t->unsignedBigInteger('parent_id')->default(0);
            foreach (['code', 'title', 'title_key', 'path', 'permission_slug', 'icon'] as $field) {
                $t->string($field)->nullable();
            }
            $t->integer('sort')->default(0);
            $t->boolean('is_enabled')->default(true);
            $t->timestamps();
        });
        Schema::create('admin_permissions', function (Blueprint $t) {
            $t->id();
            $t->string('slug');
            $t->text('http_path');
            $t->timestamps();
        });
        DB::table('portals')->insert(['id' => 1, 'code' => 'fuzhuangwuliu']);
        DB::table('portal_menus')->insert(['portal_id' => 1, 'code' => 'fashion_logistics_orders', 'title' => 'Existing']);
        DB::table('admin_permissions')->insert(['slug' => 'portal.fuzhuangwuliu.orders', 'http_path' => '/existing/custom-path']);
        $migration = require database_path('migrations/2026_11_16_000400_add_nbc_orders_to_fashion_logistics.php');
        $migration->up();
        $migration->up();
        $this->assertSame(1, DB::table('portal_menus')->where('code', 'fashion_logistics_nbc_orders')->count());
        $paths = DB::table('admin_permissions')->value('http_path');
        $this->assertStringContainsString('/existing/custom-path', $paths);
        $this->assertSame(1, substr_count($paths, '/fashion-logistics/nbc-orders/*'));
        $menu = DB::table('portal_menus')->where('code', 'fashion_logistics_nbc_orders')->first();
        $this->assertSame('NBC订单', __($menu->title_key, [], 'zh-CN'));
        $migration->down();
        $this->assertSame(0, DB::table('portal_menus')->where('code', 'fashion_logistics_nbc_orders')->value('is_enabled'));
    }

    public function test_page_renders_filters_translations_and_escaped_order_fields(): void
    {
        $this->order([], ['customer_name' => '<script>alert(1)</script>']);
        $this->order(['site_builder' => 'shopyy', 'shop_name' => 'Shopyy Test'], ['source_platform' => 'shopyy', 'customer_name' => 'Shopyy customer']);
        $data = $this->controller()->index(Request::create('/fashion-logistics/nbc-orders'))->getData();
        $data['errors'] = new ViewErrorBag;
        $source = file_get_contents(resource_path('views/portals/fashion-logistics/nbc-orders/index.blade.php'));
        $source = str_replace("@extends('portal.layout')", '', $source)."\n@yield('content')\n@stack('scripts')";
        $cache = sys_get_temp_dir().'/nbc-view-tests-'.bin2hex(random_bytes(6));
        mkdir($cache, 0700, true);
        config(['view.compiled' => $cache]);
        app()->instance('blade.compiler', new BladeCompiler(new Filesystem, $cache));
        Blade::clearResolvedInstance('blade.compiler');
        $html = Blade::render($source, $data, true);
        $this->assertStringContainsString('NBC订单', $html);
        $this->assertStringContainsString('nbc-sync-form', $html);
        $this->assertStringContainsString('Shopyy Test', $html);
        $this->assertStringContainsString('Shopyy customer', $html);
        $this->assertStringContainsString('建站系统', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('nbc_orders.', $html);
        $this->assertStringNotContainsString('do-not-leak-token', $html);
    }

    public function test_order_permission_is_required_even_without_a_menu_match(): void
    {
        $controller = $this->controller(false, false);
        foreach (['index', 'syncStatus'] as $action) {
            try {
                $controller->{$action}(Request::create('/'));
                $this->fail('Expected 403');
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }
        $order = $this->order();
        try {
            $controller->show(Request::create('/'), $order);
            $this->fail('Expected 403');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_shopyy_orders_share_the_page_but_keep_store_and_payment_visibility(): void
    {
        $nbc = $this->order();
        $shopyy = $this->order(['site_builder' => ' ShOpYy ', 'shop_name' => 'Shopyy Test'], ['source_platform' => 'shopyy']);
        $other = $this->order(['site_builder' => 'shopyy', 'owner_user_id' => 2], ['source_platform' => 'shopyy']);
        $this->order(['site_builder' => 'shopyy'], ['source_platform' => 'shopyy', 'payment_status' => '260']);
        $this->order(['site_builder' => 'shopyy', 'shop_status' => 'abandoned'], ['source_platform' => 'shopyy']);
        $controller = $this->controller();
        $data = $controller->index(Request::create('/fashion-logistics/nbc-orders'))->getData();
        $this->assertEqualsCanonicalizing([$nbc->id, $shopyy->id], $data['orders']->pluck('id')->all());
        $selected = $controller->index(Request::create('/', 'GET', ['store_id' => $shopyy->assistant_domain_store_id]))->getData();
        $this->assertSame([$shopyy->id], $selected['orders']->pluck('id')->all());
        $filtered = $controller->index(Request::create('/', 'GET', ['store_id' => $other->assistant_domain_store_id]))->getData();
        $this->assertSame(0, $filtered['orders']->total());
        $detail = $controller->show(Request::create('/'), $shopyy)->getData(true);
        $this->assertSame('shopyy', $detail['order']['source_platform']);
        $this->assertStringNotContainsString('do-not-leak-token', json_encode($detail));
        try {
            $controller->show(Request::create('/'), $other);
            $this->fail('Expected 403');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_sync_status_includes_shopyy_with_platform_and_only_its_paid_orders(): void
    {
        $nbc = $this->order();
        $shopyy = $this->order(['site_builder' => 'shopyy', 'shop_name' => 'Shopyy Test'], ['source_platform' => 'shopyy']);
        FashionLogisticsOrder::query()->create(['assistant_domain_store_id' => $shopyy->assistant_domain_store_id, 'source_platform' => 'shopline', 'external_order_id' => 'shopline-other', 'payment_status' => 'paid']);
        $this->order(['site_builder' => 'shopyy', 'owner_user_id' => 2], ['source_platform' => 'shopyy']);
        $response = $this->controller()->syncStatus(Request::create('/'));
        $data = $response->getData(true);
        $this->assertSame(2, $data['summary']['total']);
        $this->assertSame(2, $data['summary']['ready']);
        $rows = collect($data['rows'])->keyBy('platform');
        $this->assertSame(1, $rows['nbc']['total_count']);
        $this->assertSame(1, $rows['shopyy']['total_count']);
        $this->assertSame('Shopyy Test', $rows['shopyy']['handle']);
        $this->assertStringNotContainsString('do-not-leak-token', $response->getContent());
    }
}
