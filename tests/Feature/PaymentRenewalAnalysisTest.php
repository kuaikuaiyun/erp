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
            foreach (['paid_at', 'subscription_origin_order_id', 'subscription_type', 'is_add_on', 'operator_name', 'store_name', 'transaction_id', 'shopify_order_id', 'shopify_order_number', 'payment_gateway_order_id', 'bill_number', 'payment_gateway', 'payment_method', 'paypal_association_source', 'tongtu_order_number', 'buyer_email', 'collection_account', 'collection_account_email'] as $field) $table->string($field)->nullable();
            $table->decimal('paid_amount_usd', 16, 4)->default(0);
            $table->unsignedInteger('paypal_account_id')->nullable();
        });
        Schema::create('logistics_paypal_transactions', function (Blueprint $table): void {
            $table->increments('id'); $table->unsignedInteger('logistics_order_id')->nullable();
            $table->string('event_code')->nullable(); $table->string('status')->nullable(); $table->string('payment_transaction_id')->nullable();
        });
        Schema::create('logistics_order_payment_references', function (Blueprint $table): void {
            $table->increments('id'); $table->unsignedInteger('logistics_order_id'); $table->string('reference_type'); $table->string('gateway_order_id')->nullable(); $table->string('reference_number')->nullable();
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
        // An ap first purchase without a matched PayPal transaction is included.
        $this->order(152, '2026-11-01 12:00:00', '订阅首次购买', 'credit_card', '', 'Methods', ['payment_method'=>'ap']);
        \Illuminate\Support\Facades\Cache::flush();
        $updated = app(PaymentRenewalAnalysisService::class)->dashboard($this->filters(['initial_store'=>'Methods','renewal_store'=>'Methods']));
        self::assertEquals(50, $updated['stages'][0]['by_channel']['method_ap']['rate']);
        self::assertEquals(200, $updated['stages'][0]['by_channel']['method_gp']['rate']);
        self::assertSame(7, $updated['summary']['subscription_rate']['total']['total_orders']);
        self::assertSame(7, $updated['initial_refunds']['total']['orders']);
    }

    public function test_first_purchase_rate_does_not_require_tongtu_or_paypal_transaction_matching(): void
    {
        foreach (['pp','ap','gp','cc'] as $i=>$method) {
            foreach (['订阅首次购买','无订阅'] as $j=>$type) {
                $id=500+$i*2+$j;
                $this->order($id,'2026-11-01 12:00:00',$type,'credit_card','','NoMatching',[
                    'payment_method'=>$method, 'tongtu_order_number'=>$j===0 ? null : '',
                    'paypal_account_id'=>null, 'paypal_association_source'=>null, 'is_add_on'=>'已加购',
                ]);
            }
        }
        $this->order(520,'2026-11-01 12:00:00','订阅首次购买','credit_card','','NoMatching',['operator_name'=>'刷单']);
        $this->order(521,'2026-11-02 12:00:00','订阅首次购买','credit_card','','NoMatching');
        $this->order(522,'2026-11-01 12:00:00','订阅复购','credit_card','','NoMatching');
        $this->order(523,'2026-11-01 12:00:00','订阅首次购买','credit_card','','Other');
        DB::table('logistics_orders')->insert(['id'=>524,'subscription_type'=>'订阅首次购买','store_name'=>'NoMatching','payment_method'=>'pp']);
        $filters=$this->filters(['initial_store'=>'NoMatching']);
        $service=app(PaymentRenewalAnalysisService::class);
        $data=$service->dashboard($filters);
        $rates=$data['summary']['subscription_rate'];
        self::assertSame(8,$rates['total']['total_orders']);
        self::assertSame(4,$rates['total']['subscription_orders']);
        self::assertEquals(50,$rates['total']['rate']);
        foreach (['pp','ap','gp','cc'] as $method) {
            self::assertSame(2,$rates['method_'.$method]['total_orders']);
            self::assertSame(1,$rates['method_'.$method]['subscription_orders']);
            self::assertEquals(50,$rates['method_'.$method]['rate']);
        }
        self::assertSame($rates,$service->exportData($filters)['summary']['subscription_rate']);
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

    public function test_card_refunds_include_every_positive_record_once_regardless_of_reason(): void
    {
        $this->order(600,'2026-11-01 12:00:00','无订阅','credit_card','','CardAmount');
        $this->order(601,'2026-11-01 12:00:00','订阅首次购买','credit_card','','CardAmount');
        $this->order(602,'2026-11-01 12:00:00','无订阅','credit_card','','CardAmount');
        $this->order(603,'2026-11-01 12:00:00','无订阅','credit_card','','CardAmount');
        $this->order(610,'2026-10-02 12:00:00','订阅首次购买','credit_card','','CardAmount');
        $this->order(611,'2026-11-01 12:00:00','订阅复购','credit_card','610','CardAmount');
        $reasons=['Ethoca','CDRN','RDR','取消复购订单','取消加购','取消订单','质量问题','地址无法发货','描述不符','商品无法发货','取消加购,取消订单','争议（欺诈）','争议（其他）','未定义原因',''];
        foreach ($reasons as $reason) DB::table('logistics_credit_card_refunds')->insert([
            'logistics_order_id'=>600,'refund_amount_usd'=>1,'refund_reason'=>$reason,'occurred_at'=>'2026-12-01 12:00:00',
        ]);
        foreach ([null,0,-10] as $amount) DB::table('logistics_credit_card_refunds')->insert([
            'logistics_order_id'=>601,'refund_amount_usd'=>$amount,'refund_reason'=>'质量问题',
        ]);
        DB::table('logistics_credit_card_refunds')->insert([
            ['logistics_order_id'=>602,'refund_amount_usd'=>0.0001,'refund_reason'=>null],
            ['logistics_order_id'=>611,'refund_amount_usd'=>3,'refund_reason'=>'争议（欺诈）'],
        ]);
        $filters=$this->filters(['initial_store'=>'CardAmount','renewal_store'=>'CardAmount']);
        $service=app(PaymentRenewalAnalysisService::class);
        $data=$service->dashboard($filters);
        $total=$data['initial_refunds']['total'];
        self::assertSame(4,$total['orders']);
        self::assertSame(2,$total['refund_order_count']);
        self::assertSame(count($reasons)+1,$total['refund_count']);
        self::assertEqualsWithDelta(count($reasons)+0.0001,$total['refund_amount'],0.0000001);
        self::assertEquals(50,$total['refund_count_rate']);
        self::assertEquals(0,$total['dispute_amount']);
        $detail=$service->initialOrderDetails($filters,$this->detailFilters(['detail_kind'=>'refunds']));
        self::assertSame([602,600],array_column($detail['rows'],'erp_order_id'));
        self::assertCount(count($reasons),$detail['rows'][1]['refund_details']);
        self::assertEquals($total['refund_amount'],$detail['summary']['refund_amount']);
        $stage=$data['stages'][0];
        self::assertSame(1,$stage['refund_order_count']);
        self::assertSame(1,$stage['refund_count']);
        self::assertEquals(3,$stage['refund_amount']);
        self::assertEquals(0,$stage['dispute_amount']);
        self::assertEquals(0,$stage['dispute_count']);
        self::assertSame($total,$service->exportData($filters)['initial_refunds']['total']);
    }

    public function test_multiple_payment_identifiers_are_displayed_searched_and_exported_without_changing_totals(): void
    {
        $filters=$this->filters();
        $service=app(PaymentRenewalAnalysisService::class);
        $before=$service->dashboard($filters);
        DB::table('logistics_orders')->where('id',5)->update(['bill_number'=>'00001234567890123456789','transaction_id'=>'ERP-YX-5','collection_account'=>'收款账户A','collection_account_email'=>'collect@example.com']);
        foreach ([
            ['primary','00001234567890123456789','NOT-A-TRANSACTION'],
            ['upsell','ADDON-BILL-5','NOT-A-TRANSACTION'],
            ['upsell','ADDON-BILL-5',null],
            ['upsell','<script>alert(1)</script>',null],
            ['upsell','   ',null],
        ] as [$type,$bill,$gateway]) DB::table('logistics_order_payment_references')->insert([
            'logistics_order_id'=>5,'reference_type'=>$type,'reference_number'=>$bill,'gateway_order_id'=>$gateway,
        ]);
        DB::table('logistics_order_payment_references')->insert(['logistics_order_id'=>6,'reference_type'=>'upsell','reference_number'=>'OTHER-ORDER-BILL']);
        foreach ([
            ['TX-5','T0006','S',5], ['EXTRA-TRANSACTION-5','T0006','S',5],
            ['EXTRA-TRANSACTION-5','T0006','V',5], ['FAILED-TRANSACTION','T0006','D',5],
            ['REFUND-TRANSACTION','T1107','S',5], ['UNLINKED-TRANSACTION','T0006','S',null],
        ] as [$id,$event,$status,$order]) DB::table('logistics_paypal_transactions')->insert([
            'logistics_order_id'=>$order,'payment_transaction_id'=>$id,'event_code'=>$event,'status'=>$status,
        ]);
        \Illuminate\Support\Facades\Cache::flush();
        $after=$service->dashboard($filters);
        self::assertSame($before['summary'],$after['summary']);
        self::assertSame($before['stages'],$after['stages']);
        self::assertSame($before['initial_refunds_by_type'],$after['initial_refunds_by_type']);
        $detail=$service->initialOrderDetails($filters,$this->detailFilters(['detail_group'=>'method_pp']));
        self::assertCount(1,$detail['rows']);
        $row=$detail['rows'][0];
        self::assertSame(['TX-5','EXTRA-TRANSACTION-5'],$row['transaction_ids']);
        self::assertSame(['00001234567890123456789'],$row['primary_bill_numbers']);
        self::assertSame(['ADDON-BILL-5','<script>alert(1)</script>'],$row['upsell_bill_numbers']);
        self::assertSame(['00001234567890123456789','ADDON-BILL-5','<script>alert(1)</script>'],$row['bill_numbers']);
        self::assertSame('ERP-YX-5',$row['transaction_id']);
        self::assertSame(['收款账户A','collect@example.com'],$row['collection_accounts']);
        self::assertSame('00001234567890123456789',$row['bill_number']);
        foreach (['EXTRA-TRANSACTION-5','ADDON-BILL-5','ERP-YX-5','collect@example.com','收款账户A'] as $search) {
            $filtered=$service->initialOrderDetails($filters,$this->detailFilters(['detail_keyword'=>$search]));
            self::assertSame([5],array_column($filtered['rows'],'erp_order_id'));
        }
        foreach (['NOT-A-TRANSACTION','FAILED-TRANSACTION','REFUND-TRANSACTION','UNLINKED-TRANSACTION'] as $search) {
            self::assertSame(0,$service->initialOrderDetails($filters,$this->detailFilters(['detail_keyword'=>$search]))['pagination']['total']);
        }
        $html=view('portals.payment.renewal-order-details',compact('detail'))->render();
        self::assertStringContainsString('EXTRA-TRANSACTION-5',$html);
        self::assertStringContainsString('ADDON-BILL-5',$html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;',$html);
        self::assertStringNotContainsString('<script>alert(1)</script>',$html);
        foreach (['modal','main'] as $kind) {
            $export=app(PaymentRenewalAnalysisExportService::class);
            $response=$kind==='main' ? $export->download($filters) : $export->downloadOrderDetails($filters,$this->detailFilters(['detail_keyword'=>'ADDON-BILL-5']));
            ob_start();$response->sendContent();$bytes=ob_get_clean();
            $path=tempnam(sys_get_temp_dir(),'renewal-multiple-');
            try {
                file_put_contents($path,$bytes);$book=IOFactory::load($path);
                $sheet=$book->getSheet($kind==='main' ? 3 : 0);
                $target=null;
                for($r=2;$r<=$sheet->getHighestRow();$r++) {
                    if((string) $this->exportedValue($sheet,($kind==='main'?'E':'A').$r)==='5') {$target=$r;break;}
                }
                self::assertNotNull($target);
                $txn=$kind==='main'?'J':'F';$bill=$kind==='main'?'H':'D';
                $upsell=$kind==='main'?'I':'E';$account=$kind==='main'?'K':'G';
                self::assertSame("TX-5\nEXTRA-TRANSACTION-5", $this->exportedValue($sheet,$txn.$target));
                self::assertSame('00001234567890123456789', $this->exportedValue($sheet,$bill.$target));
                self::assertSame("ADDON-BILL-5\n<script>alert(1)</script>", $this->exportedValue($sheet,$upsell.$target));
                self::assertSame("收款账户A\ncollect@example.com", $this->exportedValue($sheet,$account.$target));
                self::assertContains($sheet->getCell($bill.$target)->getDataType(),['s','inlineStr']);
                self::assertTrue($sheet->getStyle($bill.$target)->getAlignment()->getWrapText());
                if($kind==='modal') self::assertSame(2,$sheet->getHighestRow());
                $book->disconnectWorksheets();
            } finally {unlink($path);}
        }
    }

    public function test_explicit_bill_types_and_order_collection_account_are_not_inferred_from_other_sources(): void
    {
        DB::table('logistics_orders')->where('id',6)->update(['bill_number'=>'UPSELL-ONLY','transaction_id'=>'ERP-CARD-6',
            'collection_account'=>'merchant@example.com','collection_account_email'=>'merchant@example.com']);
        DB::table('logistics_order_payment_references')->insert([
            ['logistics_order_id'=>6,'reference_type'=>'upsell','reference_number'=>'UPSELL-ONLY'],
            ['logistics_order_id'=>6,'reference_type'=>'primary','reference_number'=>'MAIN-BILL'],
            ['logistics_order_id'=>6,'reference_type'=>'upsell','reference_number'=>'MAIN-BILL'],
        ]);
        $detail=app(PaymentRenewalAnalysisService::class)->initialOrderDetails($this->filters(),$this->detailFilters(['detail_keyword'=>'ERP-CARD-6']));
        self::assertCount(1,$detail['rows']);
        $row=$detail['rows'][0];
        self::assertSame('ERP-CARD-6',$row['transaction_id']);
        self::assertSame([],$row['transaction_ids']);
        self::assertSame(['MAIN-BILL'],$row['primary_bill_numbers']);
        self::assertSame(['UPSELL-ONLY'],$row['upsell_bill_numbers']);
        self::assertSame(['merchant@example.com'],$row['collection_accounts']);
        $html=view('portals.payment.renewal-order-details',compact('detail'))->render();
        foreach(['订单交易号','主账单号','加购账单号','PayPal付款流水','订单收款账号','merchant@example.com'] as $label) self::assertStringContainsString($label,$html);
        self::assertStringNotContainsString('payment.renewal_details.',$html);
    }

    public function test_large_exports_write_all_rows_under_a_bounded_memory_limit(): void
    {
        $service=app(PaymentRenewalAnalysisService::class);
        $fixture=tempnam(sys_get_temp_dir(),'renewal-stream-fixture-');
        $script=tempnam(sys_get_temp_dir(),'renewal-stream-check-');
        $payload=['main'=>$service->exportData($this->filters()),
            'detail'=>$service->initialOrderDetails($this->filters(),$this->detailFilters(),false)];
        file_put_contents($fixture,json_encode($payload,JSON_THROW_ON_ERROR));
        $program=<<<'PHP'
<?php
require getcwd().'/vendor/autoload.php';
$app=new Illuminate\Foundation\Application(getcwd());
$loader=new Illuminate\Translation\FileLoader(new Illuminate\Filesystem\Filesystem,getcwd().'/lang');
$app->instance('translator',new Illuminate\Translation\Translator($loader,'zh-CN'));
$factory=Mockery::mock(Illuminate\Contracts\Routing\ResponseFactory::class);
$factory->shouldReceive('streamDownload')->andReturnUsing(
    fn($callback,$filename,$headers)=>new Symfony\Component\HttpFoundation\StreamedResponse($callback,200,$headers)
);
$app->instance(Illuminate\Contracts\Routing\ResponseFactory::class,$factory);
$data=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);
$kind=$argv[2];
$count=20000;
if($kind==='main'){
    $data['main']['initial_rows']=array_fill(0,$count,$data['main']['initial_rows'][0]);
    $data['main']['rows']=array_fill(0,10000,$data['main']['rows'][0]);
}else{
    $data['detail']['rows']=array_fill(0,$count,$data['detail']['rows'][0]);
}
$analysis=new class($data) extends App\Portals\Payment\PaymentRenewalAnalysisService{
    public function __construct(private array $fixture){}
    public function exportData(array $filters):array{return $this->fixture['main'];}
    public function initialOrderDetails(array $filters,array $detail,bool $paginate=true):array{return $this->fixture['detail'];}
};
$export=new App\Portals\Payment\PaymentRenewalAnalysisExportService($analysis);
$before=memory_get_usage(true);
$response=$kind==='main'?$export->download([]):$export->downloadOrderDetails([],[]);
$output=tempnam(sys_get_temp_dir(),'renewal-stream-result-');
$handle=fopen($output,'wb');
try{
    ob_start(static function($bytes)use($handle){fwrite($handle,$bytes);return '';},65536);
    try{$response->sendContent();}finally{ob_end_flush();fclose($handle);}
    $peak=memory_get_peak_usage(true);
    $reader=new XMLReader;
    $sheet=$kind==='main'?4:1;
    if(!$reader->open('zip://'.$output.'#xl/worksheets/sheet'.$sheet.'.xml'))throw new RuntimeException('Invalid workbook');
    $rows=0;
    while($reader->read())if($reader->nodeType===XMLReader::ELEMENT&&$reader->localName==='row')$rows++;
    $reader->close();
    echo json_encode(['rows'=>$rows,'expected'=>$kind==='main'?30001:20001,'peak'=>$peak,'growth'=>$peak-$before],JSON_THROW_ON_ERROR);
}finally{unlink($output);}
PHP;
        file_put_contents($script,$program);
        try {
            foreach (['main','detail'] as $kind) {
                $process=new \Symfony\Component\Process\Process([PHP_BINARY,'-d','memory_limit=96M',$script,$fixture,$kind],base_path());
                $process->setTimeout(60);
                $process->mustRun();
                $result=json_decode($process->getOutput(),true,512,JSON_THROW_ON_ERROR);
                self::assertSame($result['expected'],$result['rows']);
                self::assertLessThan(96*1024*1024,$result['peak']);
                self::assertLessThan(16*1024*1024,$result['growth']);
            }
        } finally {
            unlink($fixture);
            unlink($script);
        }
    }

    /** Streaming XLSX stores literal text as inline strings; the reader may return RichText. */
    private function exportedValue($sheet, string $coordinate): ?string
    {
        $value=$sheet->getCell($coordinate)->getValue();
        if ($value===null) return null;
        $text=(string)$value;
        return $text==='' ? null : $text;
    }

    public function test_dashboard_cache_contains_only_totals_and_detail_hydration_is_limited_to_the_page(): void
    {
        for($i=0;$i<35;$i++) {
            $this->order(800+$i,'2026-11-01 13:00:00','无订阅','credit_card','','PageMemory');
            DB::table('logistics_paypal_refunds')->insert(['logistics_order_id'=>800+$i,
                'refund_amount_usd'=>1,'manual_reason'=>str_repeat('退款说明',500)]);
        }
        $queries=[];
        DB::listen(function($event)use(&$queries):void{
            if(str_contains($event->sql,'manual_reason'))$queries[]=$event;
        });
        $service=app(PaymentRenewalAnalysisService::class);
        $filters=$this->filters(['initial_store'=>'PageMemory','renewal_store'=>'PageMemory']);
        $dashboard=$service->dashboard($filters);
        self::assertArrayNotHasKey('initial_rows',$dashboard);
        self::assertArrayNotHasKey('rows',$dashboard);
        self::assertSame(35,$dashboard['initial_refunds']['total']['orders']);
        self::assertSame([],$queries,'Summary queries must not select verbose refund explanations');
        $detail=$service->initialOrderDetails($filters,$this->detailFilters(['detail_per_page'=>20,'detail_page'=>2]));
        self::assertCount(15,$detail['rows']);
        self::assertSame(35,$detail['summary']['refund_order_count']);
        self::assertNotEmpty($queries);
        foreach($queries as$query)self::assertLessThanOrEqual(15,count($query->bindings),'Only the visible page may hydrate refund explanations');
        $store=\Illuminate\Support\Facades\Cache::store()->getStore();
        $storage=(new \ReflectionProperty($store,'storage'))->getValue($store);
        $serialized=serialize($storage);
        self::assertStringNotContainsString('manual_reason',$serialized);
        self::assertStringNotContainsString('refund_details',$serialized);
        self::assertStringNotContainsString('transaction_ids',$serialized);
        self::assertLessThan(200000,strlen($serialized));
        self::assertInstanceOf(\Illuminate\Support\LazyCollection::class,$service->exportData($filters)['initial_rows']);
        self::assertInstanceOf(\Illuminate\Support\LazyCollection::class,$service->initialOrderDetails($filters,$this->detailFilters(),false)['rows']);
    }

    public function test_large_statistics_and_pages_complete_under_128mb_with_compact_serialized_cache(): void
    {
        $database=tempnam(sys_get_temp_dir(),'renewal-statistics-db-');
        $script=tempnam(sys_get_temp_dir(),'renewal-statistics-process-');
        $pdo=DB::connection()->getPdo();
        $pdo->exec('VACUUM INTO '.$pdo->quote($database));
        $program=<<<'PHP'
<?php
require getcwd().'/vendor/autoload.php';
$app=new Illuminate\Foundation\Application(getcwd());
$app->instance('config',new Illuminate\Config\Repository([
    'database'=>['default'=>'sqlite'],'admin'=>['database'=>['connection'=>null]],
    'cache'=>['default'=>'array','stores'=>['array'=>['driver'=>'array','serialize'=>true]]],
]));
$capsule=new Illuminate\Database\Capsule\Manager($app);
$capsule->addConnection(['driver'=>'sqlite','database'=>$argv[1]]);
$capsule->setAsGlobal();$capsule->bootEloquent();
$app->instance('db',$capsule->getDatabaseManager());
$app->instance('cache',new Illuminate\Cache\CacheManager($app));
Illuminate\Support\Facades\Facade::setFacadeApplication($app);
$loader=new Illuminate\Translation\FileLoader(new Illuminate\Filesystem\Filesystem,getcwd().'/lang');
$app->instance('translator',new Illuminate\Translation\Translator($loader,'zh-CN'));
$db=$capsule->getConnection();
$db->statement('CREATE INDEX memory_paid ON logistics_orders(paid_at,id)');
$db->statement('CREATE INDEX memory_origins ON logistics_orders(subscription_origin_order_id,paid_at,id)');
$db->statement('CREATE INDEX memory_card_refunds ON logistics_credit_card_refunds(logistics_order_id)');
$reason=str_repeat('refund-note-',180);
$db->beginTransaction();
for($batch=0;$batch<50;$batch++){
    $orders=$refunds=[];
    for($j=0;$j<400;$j++){
        $i=$batch*400+$j;$id=100000+$i;
        $orders[]=['id'=>$id,'paid_at'=>'2026-09-15 12:00:00','subscription_type'=>$i%2?'无订阅':'订阅首次购买',
            'store_name'=>'StatisticsMemory','payment_method'=>'cc','paid_amount_usd'=>20,'transaction_id'=>'ERP-'.$id];
        $refunds[]=['logistics_order_id'=>$id,'refund_amount_usd'=>1,'refund_reason'=>$reason];
    }
    $db->table('logistics_orders')->insert($orders);$db->table('logistics_credit_card_refunds')->insert($refunds);
}
$db->table('logistics_orders')->insert(['id'=>900000,'paid_at'=>'2026-01-01 12:00:00',
    'subscription_type'=>'订阅首次购买','store_name'=>'StatisticsMemory','payment_method'=>'cc','paid_amount_usd'=>20]);
for($batch=0;$batch<4;$batch++){
    $history=[];
    for($j=0;$j<300;$j++)$history[]=['id'=>910000+$batch*300+$j,'paid_at'=>'2026-02-01 12:00:00',
        'subscription_origin_order_id'=>'900000','subscription_type'=>'订阅复购','store_name'=>'StatisticsMemory','payment_method'=>'cc','paid_amount_usd'=>20];
    $db->table('logistics_orders')->insert($history);
}
$db->table('logistics_orders')->insert(['id'=>920000,'paid_at'=>'2026-11-01 12:00:00',
    'subscription_origin_order_id'=>'900000','subscription_type'=>'订阅复购','store_name'=>'StatisticsMemory','payment_method'=>'cc','paid_amount_usd'=>20]);
$db->commit();
unset($orders,$refunds,$history,$reason);
if(function_exists('memory_reset_peak_usage'))memory_reset_peak_usage();
$before=memory_get_usage(true);
$f=['initial_date_from'=>'2026-09-01','initial_date_to'=>'2026-11-18','initial_timezone'=>'+00:00','initial_store'=>'StatisticsMemory',
    'renewal_date_from'=>'2026-09-01','renewal_date_to'=>'2026-11-18','renewal_timezone'=>'+00:00','renewal_store'=>'StatisticsMemory','stage'=>'','stage_page'=>1];
$detail=['detail_group'=>'total','detail_purchase'=>'all','detail_kind'=>'orders',
    'detail_keyword'=>'','detail_store'=>'','detail_method'=>'','detail_type'=>'','detail_refund'=>'','detail_from'=>'','detail_to'=>'','detail_per_page'=>20,'detail_page'=>2];
$service=new App\Portals\Payment\PaymentRenewalAnalysisService;
$data=$service->dashboard($f);
$page=$service->initialOrderDetails($f,$detail);
$export=$service->exportData($f);
$first=$export['initial_rows']->take(1)->all();
$renewals=$export['rows']->take(1)->all();
$store=Illuminate\Support\Facades\Cache::store()->getStore();
$storage=(new ReflectionProperty($store,'storage'))->getValue($store);
echo json_encode(['orders'=>$data['initial_refunds']['total']['orders'],'refund_orders'=>$data['initial_refunds']['total']['refund_order_count'],
    'page'=>count($page['rows']),'page_total'=>$page['pagination']['total'],'renewal_number'=>reset($renewals)['stage_number'],
    'export_first'=>count($first),'cache_bytes'=>strlen(serialize($storage)),
    'peak'=>memory_get_peak_usage(true),'growth'=>memory_get_peak_usage(true)-$before],JSON_THROW_ON_ERROR);
PHP;
        file_put_contents($script,$program);
        try{
            $process=new \Symfony\Component\Process\Process([PHP_BINARY,'-d','memory_limit=128M',$script,$database],base_path());
            $process->setTimeout(60);
            $process->mustRun();
            $result=json_decode($process->getOutput(),true,512,JSON_THROW_ON_ERROR);
            self::assertSame(20000,$result['orders']);
            self::assertSame(20000,$result['refund_orders']);
            self::assertSame(20,$result['page']);
            self::assertSame(20000,$result['page_total']);
            self::assertSame(1201,$result['renewal_number']);
            self::assertSame(1,$result['export_first']);
            self::assertLessThan(1024*1024,$result['cache_bytes']);
            self::assertLessThan(128*1024*1024,$result['peak']);
            self::assertLessThan(64*1024*1024,$result['growth']);
        }finally{
            unlink($script);unlink($database);
        }
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

    public function test_default_dates_cover_seven_days_in_each_timezone_and_explicit_dates_are_preserved(): void
    {
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-11-18 02:00:00','UTC'));
        try {
            $method = new \ReflectionMethod(PaymentPortalController::class,'renewalAnalysisFilters');
            $controller = (new \ReflectionClass(PaymentPortalController::class))->newInstanceWithoutConstructor();
            $filters = $method->invoke($controller, Request::create('/','GET',[
                'initial_timezone'=>'+08:00','renewal_timezone'=>'-05:00',
            ]));
            self::assertSame('2026-11-12',$filters['initial_date_from']);
            self::assertSame('2026-11-18',$filters['initial_date_to']);
            self::assertSame('2026-11-11',$filters['renewal_date_from']);
            self::assertSame('2026-11-17',$filters['renewal_date_to']);
            $moduleMethod = new \ReflectionMethod(PaymentRenewalAnalysisService::class,'moduleFilters');
            foreach (['initial'=>'+08:00','renewal'=>'-05:00'] as $module=>$timezone) {
                $moduleFilters = $moduleMethod->invoke(app(PaymentRenewalAnalysisService::class),[$module.'_timezone'=>$timezone],$module);
                self::assertSame($filters[$module.'_date_from'],$moduleFilters['date_from']);
                self::assertSame($filters[$module.'_date_to'],$moduleFilters['date_to']);
            }
            $explicit = $method->invoke($controller, Request::create('/','GET',$this->filters(['stage_page'=>2])));
            self::assertSame('2026-11-01',$explicit['initial_date_from']);
            self::assertSame('2026-11-01',$explicit['renewal_date_to']);
            self::assertSame(2,$explicit['stage_page']);
            // Reset links omit only their own module dates; the other module remains intact.
            $resetInitial = $method->invoke($controller, Request::create('/','GET',[
                'renewal_date_from'=>'2026-09-01','renewal_date_to'=>'2026-09-30',
            ]));
            self::assertSame('2026-11-12',$resetInitial['initial_date_from']);
            self::assertSame('2026-09-01',$resetInitial['renewal_date_from']);
            self::assertSame('2026-09-30',$resetInitial['renewal_date_to']);
        } finally {
            \Carbon\Carbon::setTestNow();
        }
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
            self::assertStringContainsString('主账单号',$html);
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
                self::assertSame('主账单号', $this->exportedValue($sheet,'D1'));
                self::assertSame('客户邮箱', $this->exportedValue($sheet,'H1'));
                if ($index === 0) {
                    self::assertSame(48,$sheet->getHighestRow());
                    self::assertSame($longBill, $this->exportedValue($sheet,'D2'));
                    self::assertContains($sheet->getCell('D2')->getDataType(),['s','inlineStr']);
                    self::assertSame('=1+1', $this->exportedValue($sheet,'D3'));
                    self::assertContains($sheet->getCell('D3')->getDataType(),['s','inlineStr']);
                    self::assertSame('buyer44@example.com', $this->exportedValue($sheet,'H2'));
                    self::assertSame('UTC+08:00',$this->exportedValue($book->getSheet(1),'B3'));
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
        DB::table('logistics_orders')->where('id',5)->update(['bill_number'=>'00009549574570768548249622','buyer_email'=>'buyer@example.com']);
        DB::table('logistics_orders')->where('id',3)->update(['bill_number'=>'=1+1','buyer_email'=>null]);
        $response = app(PaymentRenewalAnalysisExportService::class)->download($this->filters(['initial_timezone'=>'-05:00']));
        ob_start(); $response->sendContent(); $bytes=ob_get_clean();
        $path=tempnam(sys_get_temp_dir(),'renewal-event-times-');
        try {
            file_put_contents($path,$bytes);
            $book=IOFactory::load($path);
            $sheet=$book->getSheet(3);
            self::assertSame('退款时间', $this->exportedValue($sheet,'Q1'));
            self::assertSame('争议时间', $this->exportedValue($sheet,'T1'));
            self::assertSame('时间所属时区', $this->exportedValue($sheet,'V1'));
            self::assertSame('主账单号', $this->exportedValue($sheet,'H1'));
            self::assertSame('客户邮箱', $this->exportedValue($sheet,'L1'));
            $matched=[];
            for ($r=2;$r<=$sheet->getHighestRow();$r++) {
                $id=(string) $this->exportedValue($sheet,'E'.$r);
                if ($id==='5') {
                    self::assertSame('00009549574570768548249622', $this->exportedValue($sheet,'H'.$r));
                    self::assertContains($sheet->getCell('H'.$r)->getDataType(),['s','inlineStr']);
                    self::assertSame('buyer@example.com', $this->exportedValue($sheet,'L'.$r));
                    self::assertSame("2026-11-01 22:00:00\n2026-11-03 18:00:00", $this->exportedValue($sheet,'Q'.$r));
                    self::assertNull( $this->exportedValue($sheet,'T'.$r));
                    self::assertSame('UTC-05:00', $this->exportedValue($sheet,'V'.$r));
                    self::assertTrue($sheet->getStyle('Q'.$r)->getAlignment()->getWrapText());
                    $matched[]=$id;
                } elseif ($id==='3') {
                    self::assertSame('=1+1', $this->exportedValue($sheet,'H'.$r));
                    self::assertContains($sheet->getCell('H'.$r)->getDataType(),['s','inlineStr']);
                    self::assertNull( $this->exportedValue($sheet,'L'.$r));
                    self::assertSame("2026-11-04 12:00:00\n2026-11-06 04:00:00", $this->exportedValue($sheet,'T'.$r));
                    self::assertNull( $this->exportedValue($sheet,'Q'.$r));
                    self::assertSame('UTC+08:00', $this->exportedValue($sheet,'V'.$r));
                    $matched[]=$id;
                } elseif ($id==='6') {
                    // A refund with no event timestamp remains blank; never invent a payment-time substitute.
                    self::assertNull( $this->exportedValue($sheet,'Q'.$r));
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
            self::assertSame('UTC-05:00', $this->exportedValue($book->getSheet(0),'B1'));
            self::assertSame(20, $book->getSheet(1)->getHighestRow());
            self::assertSame('支付方式', $this->exportedValue($book->getSheet(1),'A5'));
            self::assertSame('PayPal支付', $this->exportedValue($book->getSheet(1),'A9'));
            self::assertSame('UTC+08:00', $this->exportedValue($book->getSheet(2),'B1'));
            self::assertSame('总体', $this->exportedValue($book->getSheet(2),'B7'));
            self::assertSame('PayPal支付', $this->exportedValue($book->getSheet(2),'B8'));
            self::assertSame('信用卡支付', $this->exportedValue($book->getSheet(2),'B9'));
            self::assertSame('谷歌支付', $this->exportedValue($book->getSheet(2),'B10'));
            self::assertSame('苹果支付', $this->exportedValue($book->getSheet(2),'B11'));
            $book->disconnectWorksheets();
        } finally { @unlink($path); }
    }
}
