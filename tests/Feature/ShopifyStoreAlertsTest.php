<?php

namespace Tests\Feature;

use App\Models\{AdminUser,AssistantDomainStore,LogisticsShopifyConnection,Portal,PortalMenu};
use App\Portals\Logistics\ShopifyStoreAlertService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
use Tests\TestCase;

class ShopifyStoreAlertsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default'=>'sqlite','database.connections.sqlite.database'=>':memory:','admin.database.connection'=>null]);
        DB::purge('sqlite'); app()->setLocale('zh-CN');
        Schema::create((new AssistantDomainStore())->getTable(),function (Blueprint $table): void {
            $table->increments('id'); $table->string('site_builder'); $table->string('shop_status');
        });
        Schema::create((new LogisticsShopifyConnection())->getTable(),function (Blueprint $table): void {
            $table->increments('id'); $table->integer('assistant_domain_store_id')->unique();
            foreach (['status','webhook_status','last_sync_status'] as $field) $table->string($field)->nullable();
        });
    }

    private function store(int $id, ?array $connection = null, string $platform = 'shopify', ?string $status = null): void
    {
        DB::table((new AssistantDomainStore())->getTable())->insert(['id'=>$id,'site_builder'=>$platform,'shop_status'=>$status ?? AssistantDomainStore::SHOP_STATUS_IN_USE]);
        if ($connection !== null) DB::table((new LogisticsShopifyConnection())->getTable())->insert(['assistant_domain_store_id'=>$id,...$connection]);
    }

    public function test_counts_follow_filters_exclude_inactive_and_deduplicate_failures(): void
    {
        $this->store(1); $this->store(2,[]); $this->store(3,['webhook_status'=>'']);
        $this->store(4,['webhook_status'=>'active','last_sync_status'=>'success']);
        $this->store(5,['webhook_status'=>'failed']);
        $this->store(6,['webhook_status'=>'active','last_sync_status'=>'failed']);
        $this->store(7,['webhook_status'=>'pending','status'=>'failed']);
        $this->store(8,['webhook_status'=>'failed','status'=>'failed','last_sync_status'=>'failed']);
        $this->store(9,['webhook_status'=>'failed'],'shopify','停用');
        $this->store(10,['webhook_status'=>'failed'],'shopline');
        DB::enableQueryLog(); DB::flushQueryLog();
        self::assertSame(['not_registered'=>4,'abnormal'=>4],app(ShopifyStoreAlertService::class)->counts());
        self::assertCount(2,DB::getQueryLog()); DB::disableQueryLog();
        DB::table((new LogisticsShopifyConnection())->getTable())->where('assistant_domain_store_id',7)->update(['status'=>'token_ready','webhook_status'=>'active']);
        self::assertSame(['not_registered'=>3,'abnormal'=>3],app(ShopifyStoreAlertService::class)->counts());
    }

    private function renderMenu(string $portalCode, array $counts): string
    {
        $this->mock(\App\Portal\Services\PortalDomainService::class)->shouldReceive('portalPath')->andReturnUsing(fn ($portal,$path='')=>$path);
        $menu=(new PortalMenu())->forceFill(['id'=>1,'parent_id'=>0,'code'=>'logistics_shopify_stores','title_key'=>'logistics.shopify_stores.menu','path'=>'/shopify-stores','is_enabled'=>true]);
        return view('portal.partials.menu',[
            'portal'=>(new Portal())->forceFill(['code'=>$portalCode]),'user'=>(new AdminUser())->forceFill(['id'=>7]),
            'menu'=>$menu,'menus'=>collect([$menu]),'portalOrigin'=>'http://localhost','canManagePortalMenus'=>false,'canManageOperationTasks'=>false,
            'shopifyStoreAlertCounts'=>$counts,
        ])->render();
    }

    public function test_menu_badges_are_scoped_to_logistics_and_hide_zero_counts(): void
    {
        $html=$this->renderMenu('wuliu',['not_registered'=>4,'abnormal'=>3]);
        self::assertStringContainsString('未登记 4',$html); self::assertStringContainsString('异常 3',$html);
        self::assertStringContainsString('href="http://localhost/shopify-stores"',$html);
        self::assertStringNotContainsString('shopify-store-menu-alerts',$this->renderMenu('wuliu',['not_registered'=>0,'abnormal'=>0]));
        self::assertStringNotContainsString('shopify-store-menu-alerts',$this->renderMenu('fuzhuang',['not_registered'=>4,'abnormal'=>3]));
        self::assertStringNotContainsString('logistics.shopify_store_alerts.',$html);
    }
}
