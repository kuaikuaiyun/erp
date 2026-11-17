<?php

namespace App\Portals\Payment;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
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
        $text = static fn (string $key): string => __('payment.renewal_two_sections.'.$key);
        $channels = ['total'=>$text('total'),'paypal'=>__('payment.renewal_analysis.channels.paypal'),'credit_card'=>__('payment.renewal_analysis.channels.credit_card')];
        $first = $book->getActiveSheet();
        $first->setTitle($text('first_sheet'));
        $first->fromArray([
            [__('payment.renewal_analysis.export.timezone'), $data['calculation']['timezone']],
            [__('payment.renewal_analysis.export.period'), $data['calculation']['period_from'].' ~ '.$data['calculation']['period_to']],
            [__('payment.renewal_analysis.export.comparison_period'), $data['calculation']['comparison_from'].' ~ '.$data['calculation']['comparison_to']],
            [__('payment.renewal_analysis.export.utc_period'), $data['calculation']['utc_period_from'].' ~ '.$data['calculation']['utc_period_to']],
            [__('payment.renewal_analysis.export.utc_comparison'), $data['calculation']['utc_comparison_from'].' ~ '.$data['calculation']['utc_comparison_to']],
            [$text('first_scope')],
        ]);
        $firstKeys = ['channel','subscription_orders','qualified_orders','subscription_rate','refund_base','revenue','refund_orders','refund_amount','refund_count_rate','refund_amount_rate'];
        $first->fromArray([array_map($text, $firstKeys)], null, 'A8');
        $rowNumber = 9;
        foreach ($channels as $channel=>$label) {
            $rate = $data['summary']['subscription_rate'][$channel];
            $refund = $data['initial_refunds'][$channel];
            $first->fromArray([[
                $label, $rate['subscription_orders'], $rate['total_orders'], $rate['rate'].'%',
                $refund['orders'], $refund['revenue'], $refund['refund_order_count'], $refund['refund_amount'],
                $refund['refund_count_rate'].'%', $refund['refund_amount_rate'].'%',
            ]], null, 'A'.$rowNumber++);
        }

        $renewals = $book->createSheet();
        $renewals->setTitle($text('renewal_sheet'));
        $renewals->fromArray([[
            $text('renewal_stage'), __('payment.renewal_analysis.funnel.eligible'), __('payment.renewal_analysis.funnel.completed'),
            __('payment.renewal_analysis.funnel.renewal_rate'), $text('orders'), $text('revenue'),
            $text('refund_orders'), $text('refund_amount'), $text('refund_count_rate'), $text('refund_amount_rate'),
        ]], null, 'A1');
        $rowNumber = 2;
        foreach ($data['stages'] as $stage) {
            $renewals->fromArray([[
                __('payment.renewal_analysis.funnel.stage_name',['number'=>$stage['number']]),
                $stage['eligible_chains'], $stage['completed_chains'], $stage['rate'].'%',
                $stage['orders'], $stage['revenue'], $stage['refund_order_count'], $stage['refund_amount'],
                $stage['refund_count_rate'].'%', $stage['refund_amount_rate'].'%',
            ]], null, 'A'.$rowNumber++);
        }

        // Keep the existing export-only payment trace for reconciliation.
        $detail = $book->createSheet();
        $detail->setTitle(__('payment.renewal_analysis.export.detail_sheet'));
        $detail->fromArray([array_values(__('payment.renewal_analysis.export.columns'))], null, 'A1');
        $rowNumber = 2;
        foreach (array_merge($data['initial_rows'], $data['rows']) as $row) {
            $detail->fromArray([[
                $row['stage_number'] === 0 ? __('payment.renewal_analysis.funnel.initial_stage') : __('payment.renewal_analysis.funnel.stage_name',['number'=>$row['stage_number']]),
                __('payment.renewal_analysis.channels.'.$row['initial_channel']), __('payment.renewal_analysis.channels.'.$row['actual_channel']),
                $row['initial_order'], $row['renewal_order'], $row['store_name'], $row['transaction_id'],
                $row['paid_at'], $row['amount'], $row['refund_count'], $row['refund_amount'],
                $row['dispute_count'], $row['dispute_amount'],
            ]], null, 'A'.$rowNumber++);
        }
        foreach ([[$first,8],[$renewals,1],[$detail,1]] as [$sheet,$headerRow]) {
            $range = 'A'.$headerRow.':'.$sheet->getHighestColumn().$headerRow;
            $sheet->getStyle($range)->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
            $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF287FBD');
            $sheet->freezePane('A'.($headerRow+1));
            foreach (range('A',$sheet->getHighestColumn()) as $column) $sheet->getColumnDimension($column)->setAutoSize(true);
        }
        $filename = __('payment.renewal_analysis.export.filename',['time'=>now()->format('Ymd_His')]);
        return response()->streamDownload(function () use ($book): void {
            (new Xlsx($book))->save('php://output');
            $book->disconnectWorksheets();
        }, $filename, ['Content-Type'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }
}
