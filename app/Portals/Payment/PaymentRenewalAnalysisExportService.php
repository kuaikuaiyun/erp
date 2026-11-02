<?php

namespace App\Portals\Payment;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PaymentRenewalAnalysisExportService
{
    public function __construct(private readonly PaymentRenewalAnalysisService $analysis)
    {
    }

    /** @param array<string,mixed> $filters */
    public function download(array $filters): StreamedResponse
    {
        $data = $this->analysis->exportData($filters);
        $book = new Spreadsheet();
        $summary = $book->getActiveSheet();
        $summary->setTitle(__('payment.renewal_analysis.export.summary_sheet'));
        $summary->fromArray([
            [__('payment.renewal_analysis.export.metric'), __('payment.renewal_analysis.export.value')],
            [__('payment.renewal_analysis.kpis.total_rate'), $data['summary']['rate'].'%'],
            [__('payment.renewal_analysis.kpis.eligible'), $data['summary']['eligible']],
            [__('payment.renewal_analysis.kpis.renewed'), $data['summary']['renewed']],
            [__('payment.renewal_analysis.kpis.paypal_rate'), $data['summary']['paypal']['rate'].'%'],
            [__('payment.renewal_analysis.kpis.card_rate'), $data['summary']['credit_card']['rate'].'%'],
            [__('payment.renewal_analysis.kpis.renewal_revenue'), $data['summary']['renewal_revenue']],
        ]);
        $summary->getStyle('A1:B1')->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $summary->getStyle('A1:B1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF287FBD');
        $summary->getColumnDimension('A')->setWidth(30);
        $summary->getColumnDimension('B')->setWidth(20);

        $detail = $book->createSheet();
        $detail->setTitle(__('payment.renewal_analysis.export.detail_sheet'));
        $headers = array_values(__('payment.renewal_analysis.export.columns'));
        $detail->fromArray([$headers], null, 'A1');
        $rowNumber = 2;
        foreach ($data['rows'] as $row) {
            $detail->fromArray([[
                $row['stage_number'],
                __('payment.renewal_analysis.channels.'.$row['initial_channel']),
                __('payment.renewal_analysis.channels.'.$row['actual_channel']),
                $row['initial_order'], $row['renewal_order'], $row['store_name'], $row['transaction_id'],
                $row['paid_at'], $row['amount'], $row['refund_count'], $row['refund_amount'],
                $row['dispute_count'], $row['dispute_amount'],
            ]], null, 'A'.$rowNumber++);
        }
        $lastColumn = $detail->getHighestColumn();
        $detail->getStyle('A1:'.$lastColumn.'1')->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $detail->getStyle('A1:'.$lastColumn.'1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF287FBD');
        $detail->getStyle('A1:'.$lastColumn.max(2, $rowNumber - 1))->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        foreach (range('A', $lastColumn) as $column) $detail->getColumnDimension($column)->setAutoSize(true);
        $detail->freezePane('A2');

        $filename = __('payment.renewal_analysis.export.filename', ['time' => now()->format('Ymd_His')]);
        return response()->streamDownload(function () use ($book): void {
            (new Xlsx($book))->save('php://output');
            $book->disconnectWorksheets();
        }, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }
}
