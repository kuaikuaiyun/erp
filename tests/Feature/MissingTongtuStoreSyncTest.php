<?php

namespace Tests\Feature;

use App\Jobs\SyncShopifyStoreJob;
use App\Models\{AdminUser,AssistantDomainStore,LogisticsShopifySyncBatch,LogisticsShopifySyncTask,Portal};
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\Logistics\Http\Controllers\{LogisticsOrderController,ShopifyStoreController};
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\{Blade,Bus,DB,Http,Schema};
use Tests\TestCase;

class MissingTongtuStoreSyncTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default'=>'sqlite','database.connections.sqlite.database'=>':memory:','admin.database.connection'=>null,'cache.default'=>'array','session.driver'=>'array']);
        DB::purge('sqlite'); app()->setLocale('zh-CN'); Bus::fake(); Http::preventStrayRequests();
        Schema::create((new AssistantDomainStore())->getTable(),function (Blueprint $table): void {
            $table->increments('id'); foreach (['domain','shop_name','site_builder','shop_status'] as $field) $table->string($field);
        });
        foreach ([new LogisticsShopifySyncBatch(),new LogisticsShopifySyncTask()] as $model) {
            Schema::create($model->getTable(),function (Blueprint $table) use ($model): void {
                $table->increments('id'); foreach ($model->getFillable() as $field) $table->text($field)->nullable(); $table->timestamps();
            });
        }
        foreach ([[1,'https://A.COM/path','shopify',AssistantDomainStore::SHOP_STATUS_IN_USE], [2,'dup.com','shopify',AssistantDomainStore::SHOP_STATUS_IN_USE],
            [3,'https://DUP.com','shopify',AssistantDomainStore::SHOP_STATUS_IN_USE],[4,'paused.com','shopify','停用'],
            [5,'other.com','shopline',AssistantDomainStore::SHOP_STATUS_IN_USE]] as [$id,$domain,$platform,$status]) {
            DB::table((new AssistantDomainStore())->getTable())->insert(['id'=>$id,'domain'=>$domain,'shop_name'=>'店铺'.$id,'site_builder'=>$platform,'shop_status'=>$status]);
        }
        $portal=(new Portal())->forceFill(['code'=>'wuliu']); $portal->setRelation('menus',collect());
        $this->mock(PortalEntryController::class)->shouldReceive('portalContext')->andReturn(['portal'=>$portal,'user'=>(new AdminUser())->forceFill(['id'=>7,'name'=>'测试运营'])]);
    }

    private function matching(array $domains): array
    {
        $controller=(new \ReflectionClass(LogisticsOrderController::class))->newInstanceWithoutConstructor();
        return (new \ReflectionMethod($controller,'missingTongtuSyncStores'))->invoke($controller,collect($domains)->map(fn ($domain)=>['domain'=>$domain,'numbers'=>['A-1001']]));
    }

    private function submit(int $storeId): mixed
    {
        $request=Request::create('/shopify-stores/'.$storeId.'/sync','POST');
        $request->setLaravelSession(app('session')->driver());
        return app(ShopifyStoreController::class)->sync($request,AssistantDomainStore::query()->findOrFail($storeId));
    }

    public function test_domains_resolve_only_unique_active_shopify_stores_and_busy_stores_are_marked(): void
    {
        $map=$this->matching(['a.com','dup.com','paused.com','other.com','missing.com',null]);
        self::assertSame(1,$map['a.com']['store']->id); self::assertFalse($map['a.com']['busy']);
        self::assertNull($map['dup.com']['store']); self::assertSame('ambiguous',$map['dup.com']['reason']);
        foreach (['paused.com','other.com','missing.com',''] as $key) self::assertNull($map[$key]['store']);
        LogisticsShopifySyncTask::query()->create(['assistant_domain_store_id'=>1,'status'=>'queued']);
        self::assertTrue($this->matching(['a.com'])['a.com']['busy']);
        self::assertSame([],$this->matching([]));
    }

    public function test_existing_single_store_endpoint_queues_one_job_and_rejects_duplicate_submissions(): void
    {
        $response=$this->submit(1);
        self::assertSame(route('logistics.shopify-stores.index'),$response->getTargetUrl());
        $batch=LogisticsShopifySyncBatch::query()->sole(); $task=LogisticsShopifySyncTask::query()->sole();
        self::assertEquals(180,$batch->sync_days); self::assertEquals(1,$batch->total_count); self::assertEquals(1,$task->assistant_domain_store_id);
        Bus::assertDispatched(SyncShopifyStoreJob::class,fn ($job)=>$job->taskId===$task->id && $job->connection==='database' && $job->queue==='logistics-shopify-high');
        $this->submit(1); self::assertSame(1,LogisticsShopifySyncBatch::query()->count()); Bus::assertDispatchedTimes(SyncShopifyStoreJob::class,1);
        Http::assertNothingSent();
    }

    public function test_existing_endpoint_rejects_paused_and_other_platform_stores_and_denied_access(): void
    {
        foreach ([4,5] as $id) {
            try { $this->submit($id); self::fail('Invalid store accepted'); }
            catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { self::assertSame(404,$e->getStatusCode()); }
        }
        $entry=\Mockery::mock(PortalEntryController::class); $entry->shouldReceive('portalContext')->andReturn(response('Forbidden',403));
        $this->app->instance(PortalEntryController::class,$entry);
        try { $this->submit(1); self::fail('Denied request accepted'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { self::assertSame(403,$e->getStatusCode()); }
        Bus::assertNothingDispatched();
    }

    public function test_store_already_queued_by_another_user_is_not_queued_again(): void
    {
        $batch=LogisticsShopifySyncBatch::query()->create(['actor_id'=>8,'status'=>'processing','total_count'=>1]);
        LogisticsShopifySyncTask::query()->create(['logistics_shopify_sync_batch_id'=>$batch->id,'assistant_domain_store_id'=>1,'status'=>'processing']);
        $this->submit(1);
        self::assertSame(1,LogisticsShopifySyncBatch::query()->count());
        self::assertSame(1,LogisticsShopifySyncTask::query()->count());
        Bus::assertNothingDispatched();
    }

    public function test_missing_page_posts_to_existing_endpoint_and_disables_ambiguous_rows(): void
    {
        $groups=collect(['a.com','dup.com'])->map(fn ($domain)=>['domain'=>$domain,'numbers'=>['A-1001']]);
        $source=str_replace("@extends('portal.layout')",'',file_get_contents(resource_path('views/portals/logistics/orders/missing-tongtu.blade.php')));
        $data=['orders'=>new LengthAwarePaginator($groups,2,20),'checkUrl'=>'/missing-tongtu-orders','exportUrl'=>'/missing-tongtu-orders?export=1',
            'paidFrom'=>'','paidTo'=>'','storeDomain'=>'','tongtuOrderNumber'=>'','lastSync'=>null,'syncedDays'=>0,'totalDays'=>90,
            'coverageFrom'=>'2026-08-27','coverageTo'=>'2026-11-24','paginationFilters'=>['per_page'=>20],
            'canSyncMissingTongtuStores'=>true,'missingTongtuSyncStores'=>$this->matching(['a.com','dup.com']),'errors'=>new \Illuminate\Support\ViewErrorBag()];
        $html=Blade::render($source.'@yield("content")',$data);
        self::assertStringContainsString('action="'.route('logistics.shopify-stores.sync',1).'"',$html);
        self::assertStringContainsString('name="_token"',$html); self::assertStringContainsString('同步该店铺',$html);
        self::assertStringContainsString('对应多个在用Shopify店铺',$html);
        self::assertSame(1,substr_count($html,'data-loading="page"'));
        $data['canSyncMissingTongtuStores']=false;
        $hidden=Blade::render($source.'@yield("content")',$data);
        self::assertStringNotContainsString('同步该店铺',$hidden);
        self::assertStringNotContainsString('logistics.missing_tongtu_sync.',$html);
    }
}
