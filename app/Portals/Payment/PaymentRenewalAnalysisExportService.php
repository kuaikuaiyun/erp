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

    public function downloadOrderDetails(array $filters, array $detailFilters): StreamedResponse
    {
        $data = $this->analysis->initialOrderDetails($filters, $detailFilters, false);
        $dt = static fn (string $key) => __('payment.renewal_details.'.$key);
        $pt = static fn (string $key) => __('payment.renewal_two_sections.'.$key);
        $methodName = static fn (string $method) => $method === 'unknown' ? $dt('unknown') : $pt('method_names.'.$method);
        $f = $data['filters'];
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setTitle($dt('export_sheet'));
        $columns = ['erp_id','order','transaction','bill_number','buyer_email','store','method','type','paid_at','revenue','refund_count','refund_amount'];
        $sheet->fromArray([array_map($dt, $columns)], null, 'A1');
        $rowNumber = 2;
        foreach ($data['rows'] as $row) {
            $values = [
                (string)$row['erp_order_id'], $row['initial_order'], implode("\n", $row['transaction_ids']), implode("\n", $row['bill_numbers']),
                $row['buyer_email'], $row['store_name'], $methodName($row['payment_method']),
                $pt('purchase_'.$row['purchase_type']), $row['paid_at'], $row['raw_amount'], $row['refund_count'], $row['raw_refund_amount'],
            ];
            foreach ($values as $index => $value) {
                $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 1).$rowNumber;
                // User-supplied text stays text: preserve long IDs/leading zeros and avoid formula interpretation.
                $sheet->setCellValueExplicit($cell, $value, $index < 9
                    ? \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING : \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
            }
            $rowNumber++;
        }
        $sheet->freezePane('A2');
        $sheet->setAutoFilter('A1:L'.max(1,$rowNumber - 1));
        $sheet->getStyle('A1:L1')->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle('A1:L1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF287FBD');
        if ($rowNumber > 2) {
            $sheet->getStyle('J2:J'.($rowNumber - 1))->getNumberFormat()->setFormatCode('0.00');
            $sheet->getStyle('L2:L'.($rowNumber - 1))->getNumberFormat()->setFormatCode('0.00');
        }
        foreach (range('A','L') as $column) $sheet->getColumnDimension($column)->setAutoSize(true);
        foreach (['C','D'] as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(false)->setWidth(32);
            if ($rowNumber > 2) $sheet->getStyle($column.'2:'.$column.($rowNumber-1))->getAlignment()->setWrapText(true);
        }

        $scope = $book->createSheet();
        $scope->setTitle($dt('export_scope_sheet'));
        $metadata = [
            [$dt('export_all_note')],
            [$dt('export_period'), $data['calculation']['period_from'].' ~ '.$data['calculation']['period_to']],
            [$dt('export_timezone'), $data['calculation']['timezone']],
            [$dt('export_store'), $data['parent_store'] ?: $dt('all')],
            [$dt('export_method'), $f['detail_group'] === 'total' ? $pt('total') : $methodName(substr($f['detail_group'],7))],
            [$dt('export_type'), $pt('purchase_'.$f['detail_purchase'])],
            [$dt('export_kind'), $dt($f['detail_kind'])],
            [$dt('export_generated'), $data['calculation']['generated_at']],
        ];
        foreach (['detail_keyword','detail_store','detail_method','detail_type','detail_refund','detail_from','detail_to'] as $key) {
            $value = $f[$key] ?? '';
            if ($key === 'detail_method' && $value !== '') $value = $methodName($value);
            if ($key === 'detail_type' && $value !== '') $value = $pt('purchase_'.$value);
            if ($key === 'detail_refund' && $value !== '') $value = $dt($value === 'yes' ? 'refund_yes' : 'refund_no');
            $metadata[] = [__('payment.renewal_details.fields.'.$key), $value === '' ? $dt('all') : $value];
        }
        foreach (['scope','summary'] as $section) {
            foreach (['orders'=>'order_count','revenue'=>'revenue','refund_order_count'=>'refund_orders','refund_amount'=>'refund_amount'] as $key=>$label) {
                $metadata[] = [($section === 'scope' ? $dt('original_scope') : $dt('filtered')).' / '.$dt($label), (string)$data[$section][$key]];
            }
        }
        foreach ($metadata as $i=>$values) {
            foreach ($values as $j=>$value) $scope->setCellValueExplicit(
                \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($j+1).($i+1),
                (string)$value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING,
            );
        }
        $scope->getColumnDimension('A')->setWidth(40);
        $scope->getColumnDimension('B')->setWidth(70);
        $scope->getStyle('A:B')->getAlignment()->setWrapText(true);
        $book->setActiveSheetIndex(0);
        $filename = __('payment.renewal_details.export_filename',['time'=>now()->format('Ymd_His')]);
        return response()->streamDownload(function () use ($book): void {
            try { (new Xlsx($book))->save('php://output'); }
            finally { $book->disconnectWorksheets(); }
        }, $filename, [
            'Content-Type'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control'=>'private, no-store',
        ]);
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
        $detail->fromArray([[...array_values(__('payment.renewal_analysis.export.columns')), $text('payment_method'), __('payment.renewal_analysis.export.detail_timezone')]], null, 'A1');
        // Event timestamps are already converted by each module's dataset; keep every known record time.
        $eventTimes = static function (array $records): string {
            $times = array_values(array_filter(array_column($records, 'time'), static fn ($time): bool => $time !== null && $time !== '' && $time !== '-'));
            sort($times, SORT_STRING);
            return implode("\n", $times);
        };
        $rowNumber = 2;
        foreach (array_merge($data['initial_rows'], $data['rows']) as $row) {
            $detail->fromArray([[
                $row['stage_number'] === 0 ? $text('purchase_'.$row['purchase_type']) : __('payment.renewal_analysis.funnel.stage_name',['number'=>$row['stage_number']]),
                $channels[$row['initial_channel']], $channels[$row['actual_channel']],
                $row['initial_order'], $row['renewal_order'], $row['store_name'], implode("\n", $row['transaction_ids']), implode("\n", $row['bill_numbers']), $row['buyer_email'],
                $row['paid_at'], $row['amount'], $row['refund_count'], $row['refund_amount'], $eventTimes($row['refund_details']),
                $row['dispute_count'], $row['dispute_amount'], $eventTimes($row['dispute_details']),
                $row['payment_method'] === 'unknown' ? $text('unknown') : $text('method_names.'.$row['payment_method']),
                $data[$row['stage_number'] === 0 ? 'initial_calculation' : 'calculation']['timezone'],
            ]], null, 'A'.$rowNumber);
            foreach (['G'=>'transaction_ids','H'=>'bill_numbers','I'=>'buyer_email'] as $column=>$field) {
                $detail->setCellValueExplicit($column.$rowNumber, is_array($row[$field]) ? implode("\n", $row[$field]) : $row[$field], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            }
            $rowNumber++;
        }
        foreach ([[$first,8],[$refunds,5],[$renewals,6],[$detail,1]] as [$sheet,$headerRow]) {
            $range = 'A'.$headerRow.':'.$sheet->getHighestColumn().$headerRow;
            $sheet->getStyle($range)->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
            $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF287FBD');
            $sheet->freezePane('A'.($headerRow+1));
            foreach (range('A',$sheet->getHighestColumn()) as $column) $sheet->getColumnDimension($column)->setAutoSize(true);
        }
        foreach (['G','H'] as $column) {
            $detail->getColumnDimension($column)->setAutoSize(false)->setWidth(32);
            if ($rowNumber > 2) $detail->getStyle($column.'2:'.$column.($rowNumber-1))->getAlignment()->setWrapText(true);
        }
        foreach (['N','Q'] as $column) {
            $detail->getColumnDimension($column)->setAutoSize(false)->setWidth(24);
            if ($rowNumber > 2) $detail->getStyle($column.'2:'.$column.($rowNumber-1))->getAlignment()->setWrapText(true);
        }
        $filename = __('payment.renewal_analysis.export.filename',['time'=>now()->format('Ymd_His')]);
        return response()->streamDownload(function () use ($book): void {
            (new Xlsx($book))->save('php://output');
            $book->disconnectWorksheets();
        }, $filename, ['Content-Type'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }
}
