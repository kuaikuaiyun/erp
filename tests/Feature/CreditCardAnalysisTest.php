<?php

namespace Tests\Feature;

use App\Models\{AdminUser,AssistantDomainStore,LogisticsOrder,LogisticsOrderItem,LogisticsCreditCardRefund,LogisticsCreditCardRefundOperation,Portal,PortalMenu};
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\Logistics\{CreditCardAnalysisService,CreditCardAnalysisExportService};
use App\Portals\Logistics\Http\Controllers\CreditCardAnalysisController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Blade,DB,Http,Schema};
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CreditCardAnalysisTest extends TestCase
{
    private array $context;
    private mixed $migration;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default'=>'sqlite','database.connections.sqlite.database'=>':memory:','admin.database.connection'=>null,'cache.default'=>'array']);
        DB::purge('sqlite');
        app()->setLocale('zh-CN');
        Http::preventStrayRequests();
        foreach ([new LogisticsOrder(), new LogisticsOrderItem(), new LogisticsCreditCardRefund()] as $model) {
            Schema::create($model->getTable(), function (Blueprint $table) use ($model): void {
                $table->increments('id');
                foreach ($model->getFillable() as $field) {
                    if (str_ends_with($field, 'amount_usd')) $table->decimal($field, 16, 4)->nullable();
                    else $table->text($field)->nullable();
                }
                $table->timestamps();
            });
        }
        Schema::create((new AssistantDomainStore())->getTable(),function (Blueprint $table): void {
            $table->increments('id'); $table->string('shop_name'); $table->string('store_prefix')->nullable();
        });
        Schema::create('portals',function (Blueprint $table): void { $table->increments('id'); $table->string('code'); });
        Schema::create('portal_menus',function (Blueprint $table): void {
            $table->increments('id'); $table->integer('portal_id');
            foreach (['parent_id','code','title','title_key','path','permission_slug','icon','sort','is_enabled'] as $field) $table->text($field)->nullable();
            $table->timestamps();
        });
        DB::table('portals')->insert(['id'=>1,'code'=>'wuliu']);
        DB::table('portal_menus')->insert([
            ['id'=>1,'portal_id'=>1,'code'=>'logistics_reports','permission_slug'=>null],
            ['id'=>2,'portal_id'=>1,'code'=>'logistics_paypal_dispute_analysis','permission_slug'=>'existing.dashboard'],
        ]);
        $this->migration = require database_path('migrations/2026_11_23_000001_install_logistics_credit_card_analysis.php');
        $this->migration->up();
        DB::table((new AssistantDomainStore())->getTable())->insert([['id'=>1,'shop_name'=>'测试甲店','store_prefix'=>'A'],['id'=>2,'shop_name'=>'测试乙店','store_prefix'=>'B']]);
        LogisticsOrder::query()->create(['id'=>1,'assistant_domain_store_id'=>1,'store_name'=>'测试甲店','collection_account'=>'收款A','buyer_email'=>'a@example.com',
            'shopify_order_number'=>'1001','paid_amount_usd'=>'100','order_created_at'=>'2026-11-11 16:00:00','subscription_type'=>'订阅首次购买','is_add_on'=>'已加购',
            'subscription_origin_order_id'=>'','logistics_status'=>'已签收','tracking_number'=>'TRACK-A','tongtu_order_number'=>'A-1001']);
        LogisticsOrder::query()->create(['id'=>2,'assistant_domain_store_id'=>2,'store_name'=>'测试乙店','collection_account'=>'收款B','buyer_email'=>'b@example.com',
            'paid_amount_usd'=>'20','order_created_at'=>'2026-11-12 16:00:00','subscription_type'=>'订阅复购2次','subscription_origin_order_id'=>'123','is_add_on'=>'未加购','logistics_status'=>'待发货']);
        LogisticsOrderItem::query()->create(['logistics_order_id'=>1,'tongtu_sku'=>'SKU%_!','tongtu_product_name'=>'测试商品','product_title'=>'商品A']);
        $portal = (new Portal())->forceFill(['id'=>1,'code'=>'wuliu','name'=>'Shopify订阅数据系统']);
        $portal->setRelation('menus',collect());
        $this->context = ['portal'=>$portal,'user'=>(new AdminUser())->forceFill(['id'=>7,'name'=>'测试操作人'])];
        $this->mock(PortalEntryController::class)->shouldReceive('portalContext')->andReturnUsing(fn ()=>$this->context);
    }

    private function record(?string $reason = '部分补偿', array $extra = []): LogisticsCreditCardRefund
    {
        return LogisticsCreditCardRefund::query()->create(array_merge([
            'logistics_order_id'=>1,'bill_number'=>'BILL-1','refund_reference'=>'REF-'.LogisticsCreditCardRefund::query()->count(),
            'refund_amount_usd'=>'5.25','refund_reason'=>$reason,'occurred_at'=>'2026-11-12 00:00:00',
        ],$extra));
    }

    private function ids(array $params = []): array
    {
        $service = app(CreditCardAnalysisService::class);
        return $service->query($service->filters(Request::create('/credit-card-analysis','GET',$params)))->orderBy('id')->pluck('id')->all();
    }

    private function handle(LogisticsCreditCardRefund $record, array $data = []): mixed
    {
        return app(CreditCardAnalysisController::class)->handle(Request::create('/credit-card-analysis/'.$record->id.'/handle','POST',$data),$record);
    }

    public function test_all_categories_same_order_multiple_records_and_legacy_values_remain_visible(): void
    {
        $a=$this->record(' Ethoca '); $b=$this->record(); $c=$this->record('取消部分商品');
        $d=$this->record('争议(欺诈)'); $e=$this->record('历史原因'); $f=$this->record(null);
        self::assertSame([$a->id,$b->id,$c->id,$d->id,$e->id,$f->id],$this->ids());
        self::assertSame([$a->id],$this->ids(['category'=>'alert']));
        self::assertSame([$b->id,$c->id],$this->ids(['category'=>'customer_service']));
        self::assertSame([$d->id],$this->ids(['category'=>'dispute','reason'=>'争议（欺诈）']));
        self::assertSame([$e->id,$f->id],$this->ids(['category'=>'uncategorized']));
        foreach ([$a,$b,$c,$d,$e,$f] as $row) {
            self::assertContains($row->id,$this->ids(['category'=>app(CreditCardAnalysisService::class)->category($row->refund_reason)]));
        }
    }

    public function test_beijing_date_boundaries_apply_to_refund_order_and_handling_dates(): void
    {
        $before=$this->record(null,['occurred_at'=>'2026-11-11 15:59:59']);
        $start=$this->record(null,['occurred_at'=>'2026-11-11 16:00:00']);
        $end=$this->record(null,['occurred_at'=>'2026-11-12 15:59:59']);
        $after=$this->record(null,['occurred_at'=>'2026-11-12 16:00:00']);
        self::assertSame([$start->id,$end->id],$this->ids(['date_from'=>'2026-11-12','date_to'=>'2026-11-12']));
        $start->forceFill(['first_handled_at'=>'2026-11-11 16:00:00'])->save();
        $end->forceFill(['first_handled_at'=>'2026-11-12 16:00:00'])->save();
        self::assertSame([$start->id],$this->ids(['handled_from'=>'2026-11-12','handled_to'=>'2026-11-12']));
        self::assertSame([$before->id,$start->id,$end->id,$after->id],$this->ids(['order_from'=>'2026-11-12','order_to'=>'2026-11-12']));
    }

    public function test_filters_are_combined_and_search_wildcards_are_literal(): void
    {
        $a=$this->record('部分补偿',['refund_reference'=>'REF%_!']);
        $this->record('部分补偿',['logistics_order_id'=>2]);
        $orphan=$this->record('Ethoca',['logistics_order_id'=>999]);
        self::assertSame([$a->id],$this->ids(['store_id'=>1,'collection_account'=>'收款A','customer_email'=>'a@','category'=>'customer_service',
            'refund_amount_min'=>'5','refund_amount_max'=>'6','order_amount_min'=>'99','order_amount_max'=>'101','subscription_type'=>'订阅首次购买',
            'post_upsell'=>'yes','repurchase'=>'no','shipped'=>'yes','logistics_status'=>'已签收','tracking_number'=>'TRACK','tongtu_order_number'=>'A-',
            'item_keyword'=>'%_!','keyword'=>'%_!']));
        self::assertSame([$orphan->id],$this->ids(['matched'=>'no']));
        self::assertSame([],$this->ids(['category'=>'dispute','reason'=>'部分补偿']));
        self::assertSame([],$this->ids(['keyword'=>'REF%_!x']));
    }

    public function test_invalid_date_and_amount_ranges_are_rejected(): void
    {
        foreach ([['date_from'=>'2026-11-13','date_to'=>'2026-11-12'],['refund_amount_min'=>'10','refund_amount_max'=>'5'],['category'=>'invented'],['per_page'=>999],['date_from'=>'2026-02-30']] as $params) {
            try { $this->ids($params); self::fail('Invalid filter accepted'); }
            catch (ValidationException $e) { self::assertNotEmpty($e->errors()); }
        }
    }

    public function test_manual_handling_retains_first_operator_and_only_latest_nonempty_note_matches(): void
    {
        $r=$this->record();
        $returnTo=route('logistics.credit-card-analysis.index',['category'=>'customer_service']);
        $response=$this->handle($r,['subscription_cancelled'=>'yes','note'=>'旧备注','return_to'=>$returnTo]);
        self::assertSame($returnTo,$response->getTargetUrl());
        $first=$r->fresh()->first_handled_at;
        $this->context['user']->name='第二操作人'; $this->context['user']->id=8;
        $this->handle($r,['add_on_cancelled'=>'no','note'=>'最新%_!备注','return_to'=>'https://example.org/']);
        $this->handle($r,['note'=>'']);
        $r->refresh();
        self::assertTrue($r->subscription_cancelled); self::assertFalse($r->add_on_cancelled);
        self::assertEquals($first,$r->first_handled_at); self::assertSame('测试操作人',$r->first_handled_by_name);
        self::assertEquals(7,$r->first_handled_by); self::assertCount(3,$r->operations);
        self::assertSame([$r->id],$this->ids(['note'=>'%_!','handled'=>'yes','subscription_cancelled'=>'yes','add_on_cancelled'=>'no']));
        self::assertSame([],$this->ids(['note'=>'旧备注']));
        self::assertSame([],$this->ids(['note'=>'最新xx备注']));
        self::assertSame('最新%_!备注',app(CreditCardAnalysisService::class)->latestNote($r));
        self::assertSame(route('logistics.credit-card-analysis.index'),$this->handle($r,['return_to'=>'https://example.org/'])->getTargetUrl());
        Http::assertNothingSent();
    }

    public function test_failed_operation_write_rolls_back_handling_and_validation_does_not_modify_record(): void
    {
        $r=$this->record();
        try { $this->handle($r,['note'=>str_repeat('字',1001)]); self::fail('Long note accepted'); }
        catch (ValidationException) { self::assertNull($r->fresh()->first_handled_at); }
        Schema::drop('logistics_credit_card_refund_operations');
        try { $this->handle($r,['subscription_cancelled'=>'yes']); self::fail('Audit failure ignored'); }
        catch (\Illuminate\Database\QueryException) { self::assertNull($r->fresh()->first_handled_at); self::assertNull($r->fresh()->subscription_cancelled); }
    }

    public function test_reimport_updates_refund_without_overwriting_manual_decisions_or_history(): void
    {
        $r=$this->record();
        $this->handle($r,['subscription_cancelled'=>'yes','note'=>'已经处理']);
        $first=$r->fresh()->first_handled_at;
        Schema::create('logistics_paypal_transactions',function (Blueprint $table): void { $table->increments('id'); $table->string('payment_transaction_id'); });
        $this->mock(\App\Portals\Logistics\LogisticsOrderPaymentReferenceService::class)->shouldReceive('uniqueOrderForReference')->with('BILL-1')->andReturn(LogisticsOrder::query()->findOrFail(1));
        $service=app(\App\Portals\Logistics\LogisticsRefundImportService::class);
        $created=(new \ReflectionMethod($service,'upsertCreditCardRefund'))->invoke($service,[
            'reference_number'=>'BILL-1','refund_reference'=>$r->refund_reference,'refund_amount_usd'=>'8.50',
            'refund_reason'=>'取消部分商品','occurred_at'=>\Illuminate\Support\Carbon::parse('2026-11-12 01:00:00'),
        ],$this->context['user']);
        self::assertFalse($created);
        $r->refresh();
        self::assertSame('8.5000',$r->refund_amount_usd); self::assertSame('取消部分商品',$r->refund_reason);
        self::assertTrue($r->subscription_cancelled); self::assertEquals($first,$r->first_handled_at);
        self::assertCount(1,$r->operations); self::assertSame('已经处理',app(CreditCardAnalysisService::class)->latestNote($r));
    }

    public function test_all_new_routes_use_the_same_menu_authorization_boundary(): void
    {
        $portal=(new Portal())->forceFill(['id'=>1,'code'=>'wuliu']);
        $portal->setRelation('menus',collect([(new PortalMenu())->forceFill([
            'id'=>3,'portal_id'=>1,'code'=>'logistics_credit_card_analysis','path'=>'/credit-card-analysis','is_enabled'=>true,'permission_slug'=>'existing.dashboard',
        ])]));
        $authorization=app(\App\Portal\Services\PortalMenuAuthorizationService::class);
        foreach (['/credit-card-analysis','/credit-card-analysis/export','/credit-card-analysis/123/handle'] as $path) {
            self::assertSame('existing.dashboard',$authorization->menuForRequest($portal,$path)?->permission_slug);
        }
        self::assertNull($authorization->menuForRequest($portal,'/credit-card-analysis-other'));
    }

    public function test_menu_migration_is_repeatable_preserves_access_and_keeps_audit_on_rollback(): void
    {
        $menu=DB::table('portal_menus')->where('code','logistics_credit_card_analysis')->first();
        self::assertSame('existing.dashboard',$menu->permission_slug);
        self::assertSame('/credit-card-analysis',$menu->path);
        $this->migration->up(); self::assertSame(1,DB::table('portal_menus')->where('code','logistics_credit_card_analysis')->count());
        $r=$this->record(); $this->handle($r,['note'=>'留存']);
        $this->migration->down();
        self::assertSame(1,LogisticsCreditCardRefundOperation::query()->count());
        self::assertNotNull($r->fresh()->first_handled_at);
        self::assertEquals(0,DB::table('portal_menus')->where('code','logistics_credit_card_analysis')->value('is_enabled'));
        $this->migration->up();
        self::assertEquals(1,DB::table('portal_menus')->where('code','logistics_credit_card_analysis')->value('is_enabled'));
        self::assertSame(1,LogisticsCreditCardRefundOperation::query()->count());
    }

    public function test_export_scope_intersects_filters_and_csv_contains_notes_and_safe_text(): void
    {
        $a=$this->record('部分补偿',['refund_reference'=>'=HYPERLINK(1)']);
        $b=$this->record('Ethoca');
        $this->handle($a,['note'=>'+危险公式']);
        $controller=app(CreditCardAnalysisController::class);
        $response=$controller->export(Request::create('/credit-card-analysis/export','POST',['scope'=>'selected','selected_ids'=>[$a->id,$b->id],'category'=>'customer_service']));
        ob_start(); $response->sendContent(); $csv=ob_get_clean();
        self::assertStringStartsWith("\xEF\xBB\xBF",$csv);
        $stream=fopen('php://temp','r+'); fwrite($stream,substr($csv,3)); rewind($stream);
        $headers=fgetcsv($stream); $row=fgetcsv($stream); self::assertFalse(fgetcsv($stream)); fclose($stream);
        self::assertCount(count($headers),$row);
        self::assertSame("'=HYPERLINK(1)",$row[array_search('退款流水号',$headers,true)]);
        self::assertSame("'+危险公式",$row[array_search('最新处理备注',$headers,true)]);
        self::assertSame('客服退款',$row[array_search('分类',$headers,true)]);
        self::assertSame('5.25 USD',$row[array_search('退款金额（单笔）',$headers,true)]);
        self::assertSame('2026-11-12 08:00:00',$row[array_search('退款发生日期',$headers,true)]);
        try { $controller->export(Request::create('/credit-card-analysis/export','POST',['scope'=>'selected','selected_ids'=>[$b->id],'category'=>'customer_service'])); self::fail('Empty scope exported'); }
        catch (ValidationException $e) { self::assertArrayHasKey('scope',$e->errors()); }
    }

    public function test_controller_actions_stop_when_portal_access_is_denied(): void
    {
        $r=$this->record();
        $entry=\Mockery::mock(PortalEntryController::class);
        $entry->shouldReceive('portalContext')->andReturn(response('Forbidden',403));
        $this->app->instance(PortalEntryController::class,$entry);
        $controller=app(CreditCardAnalysisController::class);
        self::assertSame(403,$controller->index(Request::create('/credit-card-analysis'))->getStatusCode());
        self::assertSame(403,$controller->handle(Request::create('/credit-card-analysis/'.$r->id.'/handle','POST',['note'=>'不能保存']),$r)->getStatusCode());
        self::assertSame(403,$controller->export(Request::create('/credit-card-analysis/export','POST',['scope'=>'filtered']))->getStatusCode());
        self::assertSame(0,LogisticsCreditCardRefundOperation::query()->count());
    }

    public function test_pagination_preserves_filters_and_filtered_export_includes_all_pages(): void
    {
        for ($i=0;$i<22;$i++) $this->record();
        $this->record('Ethoca');
        $request=Request::create('http://localhost/credit-card-analysis?category=customer_service&per_page=20&page=2');
        $this->app->instance('request',$request);
        $view=app(CreditCardAnalysisController::class)->index($request);
        $records=$view->getData()['records'];
        self::assertSame(22,$records->total()); self::assertSame(2,$records->currentPage()); self::assertCount(2,$records->items());
        self::assertStringContainsString('category=customer_service',$records->url(1));
        $response=app(CreditCardAnalysisController::class)->export(Request::create('/credit-card-analysis/export','POST',['scope'=>'filtered','category'=>'customer_service']));
        ob_start(); $response->sendContent(); $csv=ob_get_clean();
        $stream=fopen('php://temp','r+'); fwrite($stream,$csv); rewind($stream); $rows=[];
        while (($row=fgetcsv($stream)) !== false) $rows[]=$row;
        fclose($stream);
        self::assertCount(23,$rows);
    }

    public function test_empty_page_renders_and_error_attributes_are_translated(): void
    {
        $view=app(CreditCardAnalysisController::class)->index(Request::create('/credit-card-analysis'));
        $source=str_replace("@extends('portal.layout')",'',file_get_contents(resource_path('views/portals/logistics/credit-card-analysis/index.blade.php')));
        $html=Blade::render($source.'@yield("content")',[...$view->getData(),'errors'=>new ViewErrorBag()]);
        self::assertStringContainsString('当前条件下没有信用卡记录',$html);
        try { $this->ids(['date_from'=>'invalid']); self::fail('Invalid date accepted'); }
        catch (ValidationException $e) {
            self::assertStringContainsString('退款发生日期',$e->errors()['date_from'][0]);
            self::assertStringNotContainsString('credit_card_analysis.',$e->errors()['date_from'][0]);
        }
    }

    public function test_page_renders_filters_rows_dialogs_escaping_and_pagination_without_missing_translations(): void
    {
        $a=$this->record('部分补偿',['refund_reference'=>'<script>alert(1)</script>']);
        $this->handle($a,['note'=>'<b>处理备注</b>']);
        $this->record('未知原因',['logistics_order_id'=>999]);
        $view=app(CreditCardAnalysisController::class)->index(Request::create('/credit-card-analysis'));
        $source=str_replace("@extends('portal.layout')",'',file_get_contents(resource_path('views/portals/logistics/credit-card-analysis/index.blade.php')));
        $html=Blade::render($source.'@yield("content") @stack("styles") @stack("scripts")',[...$view->getData(),'errors'=>new ViewErrorBag()]);
        foreach (['信用卡分析','客服退款','未分类','退款发生日期','北京时间','ca-check-all','ca-export-form','ca-handle-'.$a->id,'ca-history-'.$a->id,'credit-card-analysis.css','credit-card-analysis.js','&lt;b&gt;处理备注&lt;/b&gt;'] as $text) self::assertStringContainsString($text,$html);
        self::assertStringNotContainsString('<script>alert(1)</script>',$html);
        self::assertStringNotContainsString('credit_card_analysis.',$html);
        self::assertStringNotContainsString('发生时物流',$html);
        Http::assertNothingSent();
    }
}
