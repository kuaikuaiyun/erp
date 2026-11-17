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
            [__('payment.renewal_analysis.export.timezone'), $data['calculation']['timezone']],
            [__('payment.renewal_analysis.export.utc_period'), $data['calculation']['utc_period_from'].' ~ '.$data['calculation']['utc_period_to']],
            [__('payment.renewal_analysis.export.utc_comparison'), $data['calculation']['utc_comparison_from'].' ~ '.$data['calculation']['utc_comparison_to']],
            [__('payment.renewal_analysis.export.period'), $data['calculation']['period_from'].' ~ '.$data['calculation']['period_to']],
            [__('payment.renewal_analysis.export.comparison_period'), $data['calculation']['comparison_from'].' ~ '.$data['calculation']['comparison_to']],
            [__('payment.renewal_analysis.kpis.period_orders'), $data['summary']['orders']],
            [__('payment.renewal_analysis.kpis.period_revenue'), $data['summary']['revenue']],
            [__('payment.renewal_analysis.kpis.refund_orders'), $data['summary']['refund_order_count']],
            [__('payment.renewal_analysis.kpis.refund_amount'), $data['summary']['refund_amount']],
            [__('payment.renewal_analysis.kpis.refund_count_rate'), $data['summary']['refund_count_rate'].'%'],
            [__('payment.renewal_analysis.kpis.refund_amount_rate'), $data['summary']['refund_amount_rate'].'%'],
        ]);
        $summary->getStyle('A1:B1')->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $summary->getStyle('A1:B1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF287FBD');
        $summary->getColumnDimension('A')->setWidth(30);
        $summary->getColumnDimension('B')->setWidth(20);

        $stageSheet = $book->createSheet();
        $stageSheet->setTitle(__('payment.renewal_analysis.export.stages_sheet'));
        $stageKeys = ['stage','eligible','completed','renewal_rate','loss','orders_revenue','refund','refund_orders','refund_count_rate','refund_amount_rate','dispute'];
        $stageSheet->fromArray([array_map(fn ($key) => __('payment.renewal_analysis.funnel.'.$key), $stageKeys)], null, 'A1');
        $stageNumber = 2;
        foreach ($data['stages'] as $stage) {
            $stageSheet->fromArray([[
                $stage['number'] === 0 ? __('payment.renewal_analysis.funnel.initial_stage') : __('payment.renewal_analysis.funnel.stage_name', ['number' => $stage['number']]),
                $stage['eligible_chains'] ?? __('payment.renewal_analysis.funnel.not_applicable'),
                $stage['completed_chains'] ?? __('payment.renewal_analysis.funnel.not_applicable'),
                $stage['rate'] === null ? __('payment.renewal_analysis.funnel.not_applicable') : $stage['rate'].'%',
                $stage['loss_count'] === null ? __('payment.renewal_analysis.funnel.not_applicable') : $stage['loss_count'].' / '.$stage['loss_rate'].'%',
                $stage['orders'].' / '.number_format($stage['revenue'], 2, '.', ''),
                $stage['refund_count'].' / '.number_format($stage['refund_amount'], 2, '.', ''),
                $stage['refund_order_count'], $stage['refund_count_rate'].'%', $stage['refund_amount_rate'].'%',
                $stage['dispute_count'].' / '.number_format($stage['dispute_amount'], 2, '.', ''),
            ]], null, 'A'.$stageNumber++);
        }
        $riskSheet = $book->createSheet();
        $riskSheet->setTitle(__('payment.renewal_analysis.export.risk_sheet'));
        $riskKeys = ['channel','orders','revenue','refunds','refund_count_rate','refund_amount_rate','disputes'];
        $riskSheet->fromArray([array_map(fn ($key) => __('payment.renewal_analysis.risk.'.$key), $riskKeys)], null, 'A1');
        $riskNumber = 2;
        foreach ($data['risk_by_channel'] as $channel => $risk) {
            $riskSheet->fromArray([[
                __('payment.renewal_analysis.channels.'.$channel), $risk['orders'], $risk['revenue'],
                $risk['refund_count'].' / '.number_format($risk['refund_amount'], 2, '.', ''),
                $risk['refund_count_rate'].'%', $risk['refund_amount_rate'].'%',
                $risk['dispute_count'].' / '.number_format($risk['dispute_amount'], 2, '.', ''),
            ]], null, 'A'.$riskNumber++);
        }
        foreach ([$stageSheet, $riskSheet] as $sheet) {
            $sheet->getStyle('A1:'.$sheet->getHighestColumn().'1')->getFont()->setBold(true);
            $sheet->freezePane('A2');
            foreach (range('A', $sheet->getHighestColumn()) as $column) $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        $detail = $book->createSheet();
        $detail->setTitle(__('payment.renewal_analysis.export.detail_sheet'));
        $headers = array_values(__('payment.renewal_analysis.export.columns'));
        $detail->fromArray([$headers], null, 'A1');
        $rowNumber = 2;
        foreach ($data['rows'] as $row) {
            $detail->fromArray([[
                $row['stage_number'] === 0 ? __('payment.renewal_analysis.funnel.initial_stage') : __('payment.renewal_analysis.funnel.stage_name', ['number' => $row['stage_number']]),
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
