<?php

namespace App\Portals\Payment;

use App\Portals\Logistics\StreamedXlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PaymentRenewalAnalysisExportService
{
    public function __construct(
        private readonly PaymentRenewalAnalysisService $analysis,
        private readonly StreamedXlsxWriter $writer = new StreamedXlsxWriter(),
    ) {
    }

    public function downloadOrderDetails(array $filters, array $detailFilters): StreamedResponse
    {
        $data = $this->analysis->initialOrderDetails($filters, $detailFilters, false);
        $dt = static fn (string $key) => __('payment.renewal_details.'.$key);
        $pt = static fn (string $key) => __('payment.renewal_two_sections.'.$key);
        $methodName = static fn (string $method) => $method === 'unknown' ? $dt('unknown') : $pt('method_names.'.$method);
        $f = $data['filters'];
        $columns = ['erp_id','order','tongtu_order_number','transaction','bill_number','upsell_bill_number','paypal_transactions','collection_account','buyer_email','store','method','type','paid_at','revenue','refund_count','refund_amount','delivery_state'];
        $rows = function () use ($data, $dt, $pt, $methodName): \Generator {
            foreach ($data['rows'] as $row) {
                yield $this->row([
                    (string)$row['erp_order_id'], $row['initial_order'], $row['tongtu_order_number'], $row['transaction_id'], implode("\n", $row['primary_bill_numbers']),
                    implode("\n", $row['upsell_bill_numbers']), implode("\n", $row['transaction_ids']), implode("\n", $row['collection_accounts']),
                    $row['buyer_email'], $row['store_name'], $methodName($row['payment_method']),
                    $pt('purchase_'.$row['purchase_type']), $row['paid_at'], $row['raw_amount'], $row['refund_count'], $row['raw_refund_amount'], $dt('delivery_'.$row['delivery_status']),
                ], [13,15], [2,4,5,6,7]);
            }
        };
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
        foreach (['detail_keyword','detail_store','detail_method','detail_type','detail_refund','detail_from','detail_to','detail_delivery'] as $key) {
            $value = $f[$key] ?? '';
            if ($key === 'detail_method' && $value !== '') $value = $methodName($value);
            if ($key === 'detail_type' && $value !== '') $value = $pt('purchase_'.$value);
            if ($key === 'detail_delivery' && $value !== '') $value = $dt('delivery_'.$value);
            if ($key === 'detail_refund' && $value !== '') $value = $dt($value === 'yes' ? 'refund_yes' : 'refund_no');
            $metadata[] = [__('payment.renewal_details.fields.'.$key), $value === '' ? $dt('all') : $value];
        }
        foreach (['scope','summary'] as $section) {
            foreach (['orders'=>'order_count','revenue'=>'revenue','refund_order_count'=>'refund_orders','refund_amount'=>'refund_amount'] as $key=>$label) {
                $metadata[] = [($section === 'scope' ? $dt('original_scope') : $dt('filtered')).' / '.$dt($label), (string)$data[$section][$key]];
            }
        }
        $scopeRows = function () use ($metadata): \Generator {
            foreach (array_slice($metadata, 1) as $values) yield $this->row($values, [], [0,1]);
        };
        return $this->response(__('payment.renewal_details.export_filename',['time'=>now()->format('Ymd_His')]), [
            [$dt('export_sheet'), $this->columns(array_map($dt,$columns), [12,22,26,30,32,32,32,32,30,24,18,22,24,18,16,18,18]), $rows],
            [$dt('export_scope_sheet'), $this->columns([$metadata[0][0],''], [40,70]), $scopeRows],
        ]);
    }

    /** @param array<string,mixed> $filters */
    public function download(array $filters): StreamedResponse
    {
        $data = $this->analysis->exportData($filters);
        $text = static fn (string $key) => __('payment.renewal_two_sections.'.$key);
        $channels = ['total'=>$text('total'),'paypal'=>__('payment.renewal_analysis.channels.paypal'),'credit_card'=>__('payment.renewal_analysis.channels.credit_card'),'unknown'=>$text('unknown')];
        $allGroups = PaymentRenewalAnalysisService::breakdownGroups();
        $groups = array_replace(array_flip(['total','method_pp','method_cc','method_gp','method_ap']), array_intersect_key($allGroups, array_flip(['total','method_pp','method_cc','method_gp','method_ap'])));
        $methodLabel = static fn (array $group) => $group['method'] === 'all' ? $text('total') : ($group['method'] === 'unknown' ? $text('unknown') : $text('method_names.'.$group['method']));
        $firstMetadata = [
            [__('payment.renewal_analysis.export.timezone'), $data['initial_calculation']['timezone']],
            [__('payment.renewal_analysis.export.period'), $data['initial_calculation']['period_from'].' ~ '.$data['initial_calculation']['period_to']],
            [__('payment.renewal_analysis.export.comparison_period'), $data['initial_calculation']['comparison_from'].' ~ '.$data['initial_calculation']['comparison_to']],
            [__('payment.renewal_analysis.export.utc_period'), $data['initial_calculation']['utc_period_from'].' ~ '.$data['initial_calculation']['utc_period_to']],
            [__('payment.renewal_analysis.export.utc_comparison'), $data['initial_calculation']['utc_comparison_from'].' ~ '.$data['initial_calculation']['utc_comparison_to']],
            [$text('first_scope')],
            [__('payment.renewal_analysis.filters.store'), $filters['initial_store'] ?? $filters['store'] ?? __('payment.renewal_analysis.filters.all_stores')],
        ];
        $firstRows = function () use ($data,$text,$groups,$methodLabel,$firstMetadata): \Generator {
            foreach (array_slice($firstMetadata,1) as $values) yield $this->row($values, [], [1]);
            yield $this->row(array_map($text,['payment_method','subscription_orders','qualified_orders','subscription_rate']), [], [], true);
            foreach ($groups as $key=>$group) {
                $rate=$data['summary']['subscription_rate'][$key];
                yield $this->row([$methodLabel($group),$rate['subscription_orders'],$rate['total_orders'],$rate['rate'].'%']);
            }
        };
        $refundRows = function () use ($data,$text,$groups,$methodLabel,$filters): \Generator {
            yield $this->row([__('payment.renewal_analysis.export.period'),$data['initial_calculation']['period_from'].' ~ '.$data['initial_calculation']['period_to']], [], [1]);
            yield $this->row([__('payment.renewal_analysis.filters.store'),$filters['initial_store'] ?? $filters['store'] ?? __('payment.renewal_analysis.filters.all_stores')]);
            $note=$this->row([$text('delivery_note')],[],[0]);
            $note['mergeAcross']=[0=>17];
            $note['height']=34;
            yield $note;
            yield $this->row(array_map($text,['payment_method','purchase_type','refund_base','revenue','refund_orders','refund_amount','refund_count_rate','refund_amount_rate','delivered_orders','delivered_share','undelivered_orders','undelivered_share','delivered_revenue','delivered_refund_orders','delivered_refund_amount','delivered_refund_count_rate','delivered_refund_amount_rate']), [], [], true);
            foreach ($groups as $key=>$group) {
                foreach (['all','subscription','non_subscription'] as $type) {
                    $refund=$data['initial_refunds_by_type'][$key][$type];
                    $delivered=$data['initial_delivery_by_type'][$key][$type]['delivered'];
                    $undelivered=$data['initial_delivery_by_type'][$key][$type]['undelivered'];
                    yield $this->row([$methodLabel($group),$text('purchase_'.$type),$refund['orders'],$refund['revenue'],
                        $refund['refund_order_count'],$refund['refund_amount'],$refund['refund_count_rate'].'%',$refund['refund_amount_rate'].'%',
                        $delivered['orders'],$delivered['order_share'].'%',$undelivered['orders'],$undelivered['order_share'].'%',
                        $delivered['revenue'],$delivered['refund_order_count'],$delivered['refund_amount'],
                        $delivered['refund_count_rate'].'%',$delivered['refund_amount_rate'].'%'], [3,5,12,14]);
                }
            }
        };
        $renewalRows = function () use ($data,$text,$groups,$methodLabel,$filters): \Generator {
            yield $this->row([__('payment.renewal_analysis.export.period'),$data['calculation']['period_from'].' ~ '.$data['calculation']['period_to']], [], [1]);
            yield $this->row([__('payment.renewal_analysis.export.comparison_period'),$data['calculation']['comparison_from'].' ~ '.$data['calculation']['comparison_to']], [], [1]);
            yield $this->row([__('payment.renewal_analysis.filters.store'),$filters['renewal_store'] ?? $filters['store'] ?? __('payment.renewal_analysis.filters.all_stores')]);
            yield $this->row([]);
            yield $this->row([$text('renewal_stage'),$text('payment_method'),$text('previous_orders'),$text('current_renewal_orders'),
                __('payment.renewal_analysis.funnel.renewal_rate'),$text('revenue'),$text('refund_orders'),$text('refund_amount'),$text('refund_count_rate'),$text('refund_amount_rate')], [], [], true);
            foreach ($data['stages'] as $stage) {
                foreach ($groups as $key=>$group) {
                    $values=$key==='total' ? $stage : $stage['by_channel'][$key];
                    yield $this->row([__('payment.renewal_analysis.funnel.stage_name',['number'=>$stage['number']]),$methodLabel($group),
                        $values['previous_orders'],$values['orders'],$values['rate']===null ? __('payment.renewal_analysis.funnel.not_applicable') : $values['rate'].'%',
                        $values['revenue'],$values['refund_order_count'],$values['refund_amount'],$values['refund_count_rate'].'%',$values['refund_amount_rate'].'%'], [5,7]);
                }
            }
        };
        $detailRows = function () use ($data,$text,$channels): \Generator {
            // Iterate the two existing populations without copying them into a merged array.
            foreach (['initial_rows','rows'] as $population) {
                foreach ($data[$population] as $row) {
                    yield $this->row([
                        $row['stage_number']===0 ? $text('purchase_'.$row['purchase_type']) : __('payment.renewal_analysis.funnel.stage_name',['number'=>$row['stage_number']]),
                        $channels[$row['initial_channel']],$channels[$row['actual_channel']],(string)$row['initial_order'],(string)$row['renewal_order'],
                        $row['tongtu_order_number'],$row['store_name'],$row['transaction_id'],implode("\n",$row['primary_bill_numbers']),
                        implode("\n",$row['upsell_bill_numbers']),implode("\n",$row['transaction_ids']),implode("\n",$row['collection_accounts']),$row['buyer_email'],
                        $row['paid_at'],$row['amount'],$row['refund_count'],$row['refund_amount'],$this->eventTimes($row['refund_details']),
                        $row['dispute_count'],$row['dispute_amount'],$this->eventTimes($row['dispute_details']),
                        $row['payment_method']==='unknown' ? $text('unknown') : $text('method_names.'.$row['payment_method']),
                        $data[$row['stage_number']===0 ? 'initial_calculation' : 'calculation']['timezone'],
                        __('payment.renewal_details.delivery_'.$row['delivery_status']),
                    ], [14,16,19], [5,8,9,10,11,17,20]);
                }
            }
        };
        return $this->response(__('payment.renewal_analysis.export.filename',['time'=>now()->format('Ymd_His')]), [
            [$text('first_sheet'),$this->columns([...$firstMetadata[0],'',''],[32,48,24,24]),$firstRows],
            [$text('refund_sheet'),$this->columns(array_pad([__('payment.renewal_analysis.export.timezone'),$data['initial_calculation']['timezone']],17,''),[22,48,24,20,18,20,20,20,18,18,18,18,22,22,22,22,22]),$refundRows],
            [$text('renewal_sheet'),$this->columns([__('payment.renewal_analysis.export.timezone'),$data['calculation']['timezone'],'','','','','','','',''],[22,48,28,28,22,20,20,20,20,20]),$renewalRows],
            [__('payment.renewal_analysis.export.detail_sheet'),$this->columns([...array_values(__('payment.renewal_analysis.export.columns')),$text('payment_method'),__('payment.renewal_analysis.export.detail_timezone'),__('payment.renewal_details.delivery_state')],[24,18,18,24,24,26,24,30,32,32,32,32,30,24,18,16,18,24,16,18,24,20,20,18]),$detailRows],
        ]);
    }

    private function columns(array $labels, array $widths): array
    {
        $columns=[];
        foreach ($labels as $index=>$label) $columns[]=['label'=>(string)$label,'width'=>$widths[$index] ?? 22];
        return $columns;
    }

    /** Strings, including numeric-looking identifiers and formula-like input, always remain literal text. */
    private function row(array $values, array $moneyColumns = [], array $wrapColumns = [], bool $header = false): array
    {
        $cells=[];
        $lines=1;
        foreach ($values as $index=>$value) {
            $wrap=in_array($index,$wrapColumns,true) || (is_string($value) && str_contains($value,"\n"));
            if ($wrap && is_string($value)) $lines=max($lines,substr_count($value,"\n")+1);
            $numeric=is_int($value) || is_float($value);
            $cells[]=['value'=>$value ?? '', 'type'=>$numeric ? (in_array($index,$moneyColumns,true) ? 'money' : 'quantity') : 'text',
                'align'=>$numeric ? 'right' : 'left','wrap'=>$wrap];
        }
        return ['cells'=>$cells,'kind'=>$header ? 'parent' : 'normal','height'=>min(409,max($header ? 28 : 22,$lines*15+8))];
    }

    private function eventTimes(array $records): string
    {
        $times=array_values(array_filter(array_column($records,'time'),static fn ($time): bool => $time!==null && $time!=='' && $time!=='-'));
        sort($times,SORT_STRING);
        return implode("\n",$times);
    }

    private function response(string $filename, array $sheets): StreamedResponse
    {
        [$name,$columns,$rows]=$sheets[0];
        return $this->writer->response($filename,$name,$columns,$rows,
            additionalSheets: array_slice($sheets,1));
    }
}
