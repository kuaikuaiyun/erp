<?php

namespace Tests\Feature;

use App\Models\LogisticsOrder;
use App\Models\LogisticsPayPalRefund;
use App\Models\LogisticsCreditCardRefund;
use App\Portals\Logistics\LogisticsOrderSpreadsheetService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class LogisticsOrderSpreadsheetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'admin.database.connection' => null]);
        app('db')->purge('sqlite');
        app()->setLocale('zh-CN');
    }

    private function value(LogisticsOrder $order, string $field): mixed
    {
        return (new \ReflectionMethod(LogisticsOrderSpreadsheetService::class, 'exportValue'))->invoke(app(LogisticsOrderSpreadsheetService::class), $order, $field);
    }

    public function test_collection_account_priority_and_exact_plural_attribute_key(): void
    {
        $order = new LogisticsOrder(['collection_account' => '已有账号', 'collection_account_email' => 'separate@example.com', 'shopify_payload' => json_encode(['customAttributes' => [['key' => 'yx_payments_alias', 'value' => '原始账号']]])]);
        self::assertSame('已有账号', $this->value($order, 'collection_account'));
        $order->collection_account = '  ';
        self::assertSame('原始账号', $this->value($order, 'collection_account'));
        self::assertSame('separate@example.com', $this->value($order, 'collection_account_email'));
        $order->shopify_payload = json_encode(['customAttributes' => [['key' => 'yx_payment_alias', 'value' => '不是指定属性']]]);
        self::assertSame('—', $this->value($order, 'collection_account'));
        $order->shopify_payload = json_encode(['note_attributes' => [['name' => 'yx_payments_alias', 'value' => '0']]]);
        self::assertSame('0', $this->value($order, 'collection_account'));
        foreach (['{bad json', 'null', '{}', json_encode(['customAttributes' => [['key' => 'yx_payments_alias', 'value' => []]]])] as $payload) {
            $order->shopify_payload = $payload;
            self::assertSame('—', $this->value($order, 'collection_account'));
        }
    }

    public function test_refund_sum_includes_zero_and_dispute_records_without_adding_order_refund_fields(): void
    {
        $order = new LogisticsOrder(['shopify_refund_amount_usd' => 100, 'channel_refund_amount_usd' => 200]);
        $order->setRelation('paypalRefunds', collect());
        $order->setRelation('creditCardRefunds', collect([
            new LogisticsCreditCardRefund(['refund_amount_usd' => 0, 'refund_reason' => '争议（其他）']),
            new LogisticsCreditCardRefund(['refund_amount_usd' => '30.9000', 'refund_reason' => 'Ethoca']),
        ]));
        self::assertEquals(30.9, $this->value($order, 'actual_refund_total_usd'));
        $order->setRelation('paypalRefunds', collect([new LogisticsPayPalRefund(['refund_amount_usd' => '5.1000'])]));
        self::assertEquals(36, $this->value($order, 'actual_refund_total_usd'));
        $order->setRelation('paypalRefunds', collect());
        $order->setRelation('creditCardRefunds', collect());
        self::assertEquals(0, $this->value($order, 'actual_refund_total_usd'));
    }

    public function test_real_export_contains_numeric_total_and_separate_account_email(): void
    {
        $service = app(LogisticsOrderSpreadsheetService::class);
        $columns = $service->requiredOrderColumns();
        self::assertContains('shopify_payload', $columns);
        self::assertNotContains('actual_refund_total_usd', $columns);
        Schema::create('logistics_orders', function (Blueprint $table) use ($columns): void {
            $table->increments('id');
            foreach (array_diff($columns, ['id']) as $column) $table->text($column)->nullable();
        });
        foreach (['logistics_paypal_refunds', 'logistics_credit_card_refunds'] as $tableName) {
            Schema::create($tableName, function (Blueprint $table): void {
                $table->increments('id'); $table->unsignedInteger('logistics_order_id');
                $table->decimal('refund_amount_usd', 16, 4);
                foreach (['refund_transaction_id', 'manual_reason', 'refund_reason', 'event_code', 'bill_number', 'refund_reference', 'refunded_at', 'occurred_at'] as $column) $table->string($column)->nullable();
            });
        }
        DB::table('logistics_orders')->insert(['id' => 1, 'collection_account_email' => 'only-email@example.com', 'shopify_payload' => json_encode(['customAttributes' => [['key' => 'yx_payments_alias', 'value' => '测试账号']]])]);
        DB::table('logistics_orders')->insert(['id' => 2, 'shopify_payload' => '{invalid']);
        DB::table('logistics_credit_card_refunds')->insert([
            ['logistics_order_id' => 1, 'refund_amount_usd' => 0, 'refund_reason' => '争议（其他）'],
            ['logistics_order_id' => 1, 'refund_amount_usd' => 30.9, 'refund_reason' => 'Ethoca'],
        ]);
        $response = $service->exportResponse(LogisticsOrder::query()->select($columns)->with(['paypalRefunds', 'creditCardRefunds'])->orderBy('id'));
        $bufferLevel = ob_get_level();
        ob_start();
        try { $response->sendContent(); $bytes = ob_get_contents(); }
        finally { while (ob_get_level() > $bufferLevel) ob_end_clean(); }
        $path = tempnam(sys_get_temp_dir(), 'logistics-export-');
        try {
            file_put_contents($path, $bytes);
            $book = IOFactory::load($path);
            $sheet = $book->getActiveSheet();
            $headers = $sheet->rangeToArray('A1:'.$sheet->getHighestColumn().'1')[0];
            self::assertSame(1, count(array_keys($headers, '收款账号', true)));
            $total = array_search('退款合计（USD）', $headers, true) + 1;
            $cell = $sheet->getCell([$total, 2]);
            self::assertSame(DataType::TYPE_NUMERIC, $cell->getDataType());
            self::assertEquals(30.9, $cell->getValue());
            self::assertSame(DataType::TYPE_NUMERIC, $sheet->getCell([$total, 3])->getDataType());
            self::assertEquals(0, $sheet->getCell([$total, 3])->getValue());
            self::assertSame('—', (string) $sheet->getCell([array_search('收款账号', $headers, true) + 1, 3])->getValue());
            self::assertSame('测试账号', (string) $sheet->getCell([array_search('收款账号', $headers, true) + 1, 2])->getValue());
            self::assertSame('only-email@example.com', (string) $sheet->getCell([array_search('收款账号邮箱', $headers, true) + 1, 2])->getValue());
            self::assertStringContainsString('30.90', (string) $sheet->getCell([array_search('信用卡实际退款（逐笔）', $headers, true) + 1, 2])->getValue());
            $book->disconnectWorksheets();
        } finally { @unlink($path); }
    }
}
