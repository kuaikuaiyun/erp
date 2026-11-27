<?php

namespace Tests\Feature;

use App\Portals\Logistics\LogisticsProductStatisticsService;
use App\Portals\Payment\PaymentProductAlertExportService;
use App\Portals\Payment\PaymentProductAlertService;
use App\Portals\Payment\PaymentProductRiskService;
use Illuminate\Support\Facades\Log;
use Mockery;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class PaymentProductRiskExportTest extends TestCase
{
    public function test_undated_export_writes_every_batch_with_the_original_seventeen_columns(): void
    {
        app()->setLocale('zh-CN');
        $filters = ['date_from' => '', 'date_to' => '', 'date_mode' => 'placed', 'sort' => 'revenue', 'direction' => 'desc'];
        $statistics = Mockery::mock(LogisticsProductStatisticsService::class);
        $statistics->shouldReceive('paymentExportBatches')->once()
            ->with([...$filters, 'use_payment_type' => true, 'skip_product_store_aggregates' => true], [], [], null)
            ->andReturnUsing(function (): \Generator {
                foreach ([[1, 100], [101, 200], [201, 205]] as [$start, $end]) {
                    $batch = [];
                    for ($id = $start; $id <= $end; $id++) {
                        $batch[] = $this->product($id);
                    }
                    yield $batch;
                }
            });

        Log::spy();
        $products = (new PaymentProductRiskService($statistics, new PaymentProductAlertService()))->exportList($filters, null);
        $response = app(PaymentProductAlertExportService::class)->downloadRiskList($products);
        $path = tempnam(sys_get_temp_dir(), 'erp-risk-test-');

        try {
            ob_start();
            try {
                $response->sendContent();
            } finally {
                $bytes = ob_get_clean();
            }
            file_put_contents($path, $bytes);
            $workbook = IOFactory::createReader('Xlsx')->load($path);
            try {
                $sheet = $workbook->getActiveSheet();
                self::assertSame(1, $workbook->getSheetCount());
                self::assertSame('Q', $sheet->getHighestDataColumn());
                self::assertSame(207, $sheet->getHighestDataRow());
                self::assertSame(['A1:G1', 'H1:M1', 'N1:Q1'], array_values($sheet->getMergeCells()));
                self::assertSame('A3', $sheet->getFreezePane());
                self::assertSame(['产品名称', '产品PCL', '末次付款时间'], $sheet->rangeToArray('A2:C2')[0]);
                self::assertSame(['商品 1', '00001', '2026-11-01 10:30'], $sheet->rangeToArray('A3:C3')[0]);
                self::assertSame('商品 101', (string) $sheet->getCell('A103')->getValue());
                self::assertSame('商品 205', (string) $sheet->getCell('A207')->getValue());
                self::assertEqualsWithDelta(0.0235, (float) $sheet->getCell('Q207')->getValue(), 0.000001);
                self::assertSame('2.35%', $sheet->getCell('Q207')->getFormattedValue());
            } finally {
                $workbook->disconnectWorksheets();
            }
        } finally {
            unlink($path);
        }

        Log::shouldHaveReceived('log')->once()->with('info', 'Payment product risk export.', Mockery::on(
            static fn (array $stats): bool => $stats['completed'] && $stats['rows'] === 205
                && $stats['data_ms'] >= 0 && $stats['xlsx_ms'] >= 0 && $stats['total_ms'] >= $stats['data_ms'],
        ));
    }

    private function product(int $id): array
    {
        return [
            'id' => 'fixture-'.$id,
            'name' => '商品 '.$id,
            'tongtu_product_name' => '',
            'source_product_name' => '',
            'product_code' => str_pad((string) $id, 5, '0', STR_PAD_LEFT),
            'latest_paid_at' => '2026-11-01 10:30',
            'order_count' => 12,
            'revenue' => 152.48,
            ...array_fill_keys(PaymentProductRiskService::RATE_METRICS, 2.35),
            'paypal_description_mismatch_rate' => 2.35,
            'paypal_initial_dispute_rate' => 2.35,
            'paypal_initial_refund_amount_rate' => 2.35,
            'credit_card_initial_refund_amount_rate' => 2.35,
        ];
    }
}
