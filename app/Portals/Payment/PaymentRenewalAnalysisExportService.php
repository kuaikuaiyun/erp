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
        $channels = ['total'=>$text('total'),'paypal'=>__('payment.renewal_analysis.channels.paypal'),'credit_card'=>__('payment.renewal_analysis.channels.credit_card'),'unknown'=>$text('unknown')];
        $allGroups = PaymentRenewalAnalysisService::breakdownGroups();
        $groups = array_replace(array_flip(['total','method_pp','method_cc','method_gp','method_ap']), array_intersect_key($allGroups, array_flip(['total','method_pp','method_cc','method_gp','method_ap'])));
        $methodLabel = static fn (array $group) => $group['method'] === 'all' ? $text('total') : ($group['method'] === 'unknown' ? $text('unknown') : $text('method_names.'.$group['method']));
        $first = $book->getActiveSheet();
        $first->setTitle($text('first_sheet'));
        $first->fromArray([
            [__('payment.renewal_analysis.export.timezone'), $data['initial_calculation']['timezone']],
            [__('payment.renewal_analysis.export.period'), $data['initial_calculation']['period_from'].' ~ '.$data['initial_calculation']['period_to']],
            [__('payment.renewal_analysis.export.comparison_period'), $data['initial_calculation']['comparison_from'].' ~ '.$data['initial_calculation']['comparison_to']],
            [__('payment.renewal_analysis.export.utc_period'), $data['initial_calculation']['utc_period_from'].' ~ '.$data['initial_calculation']['utc_period_to']],
            [__('payment.renewal_analysis.export.utc_comparison'), $data['initial_calculation']['utc_comparison_from'].' ~ '.$data['initial_calculation']['utc_comparison_to']],
            [$text('first_scope')],
            [__('payment.renewal_analysis.filters.store'), $filters['initial_store'] ?? $filters['store'] ?? ''],
        ]);
        $firstKeys = ['payment_method','subscription_orders','qualified_orders','subscription_rate'];
        $first->fromArray([array_map($text, $firstKeys)], null, 'A8');
        $rowNumber = 9;
        foreach ($groups as $channel=>$group) {
            $rate = $data['summary']['subscription_rate'][$channel];
            $first->fromArray([[
                $methodLabel($group), $rate['subscription_orders'], $rate['total_orders'], $rate['rate'].'%',
            ]], null, 'A'.$rowNumber++);
        }

        $refunds = $book->createSheet();
        $refunds->setTitle($text('refund_sheet'));
        $refunds->fromArray([
            [__('payment.renewal_analysis.export.timezone'), $data['initial_calculation']['timezone']],
            [__('payment.renewal_analysis.export.period'), $data['initial_calculation']['period_from'].' ~ '.$data['initial_calculation']['period_to']],
            [__('payment.renewal_analysis.filters.store'), $filters['initial_store'] ?? $filters['store'] ?? ''],
        ]);
        $refunds->fromArray([array_map($text, ['payment_method','purchase_type','refund_base','revenue','refund_orders','refund_amount','refund_count_rate','refund_amount_rate'])], null, 'A5');
        $refundRow = 6;
        foreach ($groups as $channel => $group) {
            foreach (['all', 'subscription', 'non_subscription'] as $type) {
                $refund = $data['initial_refunds_by_type'][$channel][$type];
                $refunds->fromArray([[$methodLabel($group), $text('purchase_'.$type), $refund['orders'], $refund['revenue'],
                    $refund['refund_order_count'], $refund['refund_amount'], $refund['refund_count_rate'].'%', $refund['refund_amount_rate'].'%']], null, 'A'.$refundRow++);
            }
        }

        $renewals = $book->createSheet();
        $renewals->setTitle($text('renewal_sheet'));
        $renewals->fromArray([
            [__('payment.renewal_analysis.export.timezone'), $data['calculation']['timezone']],
            [__('payment.renewal_analysis.export.period'), $data['calculation']['period_from'].' ~ '.$data['calculation']['period_to']],
            [__('payment.renewal_analysis.export.comparison_period'), $data['calculation']['comparison_from'].' ~ '.$data['calculation']['comparison_to']],
            [__('payment.renewal_analysis.filters.store'), $filters['renewal_store'] ?? $filters['store'] ?? ''],
        ]);
        $renewals->fromArray([[
            $text('renewal_stage'), $text('payment_method'), $text('previous_orders'), $text('current_renewal_orders'),
            __('payment.renewal_analysis.funnel.renewal_rate'), $text('revenue'),
            $text('refund_orders'), $text('refund_amount'), $text('refund_count_rate'), $text('refund_amount_rate'),
        ]], null, 'A6');
        $rowNumber = 7;
        foreach ($data['stages'] as $stage) {
            foreach ($groups as $channel => $group) {
                $values = $channel === 'total' ? $stage : $stage['by_channel'][$channel];
                $renewals->fromArray([[
                    __('payment.renewal_analysis.funnel.stage_name',['number'=>$stage['number']]), $methodLabel($group),
                    $values['previous_orders'], $values['orders'],
                    $values['rate'] === null ? __('payment.renewal_analysis.funnel.not_applicable') : $values['rate'].'%',
                    $values['revenue'], $values['refund_order_count'], $values['refund_amount'],
                    $values['refund_count_rate'].'%', $values['refund_amount_rate'].'%',
                ]], null, 'A'.$rowNumber++);
            }
        }

        // Keep the existing export-only payment trace for reconciliation.
        $detail = $book->createSheet();
        $detail->setTitle(__('payment.renewal_analysis.export.detail_sheet'));
        $detail->fromArray([[...array_values(__('payment.renewal_analysis.export.columns')), $text('payment_method')]], null, 'A1');
        $rowNumber = 2;
        foreach (array_merge($data['initial_rows'], $data['rows']) as $row) {
            $detail->fromArray([[
                $row['stage_number'] === 0 ? $text('purchase_'.$row['purchase_type']) : __('payment.renewal_analysis.funnel.stage_name',['number'=>$row['stage_number']]),
                $channels[$row['initial_channel']], $channels[$row['actual_channel']],
                $row['initial_order'], $row['renewal_order'], $row['store_name'], $row['transaction_id'],
                $row['paid_at'], $row['amount'], $row['refund_count'], $row['refund_amount'],
                $row['dispute_count'], $row['dispute_amount'], $row['payment_method'] === 'unknown' ? $text('unknown') : $text('method_names.'.$row['payment_method']),
            ]], null, 'A'.$rowNumber++);
        }
        foreach ([[$first,8],[$refunds,5],[$renewals,6],[$detail,1]] as [$sheet,$headerRow]) {
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
