<?php

namespace App\Portals\Logistics;

use App\Models\LogisticsOrder;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CreditCardAnalysisExportService
{
    public function __construct(private readonly CreditCardAnalysisService $analysis) {}

    public function download(Builder $query): StreamedResponse
    {
        $fields = ['store_id','collection_account','refund_reference','bill_number','customer_email','shopify_order_number',
            'date','order_date','refund_amount','order_amount','category','reason','subscription_type','post_upsell','repurchase',
            'subscription_cancelled','add_on_cancelled','logistics_status','shipped','tracking_number','tongtu_order_number',
            'sku','product_name','handled','first_handled_at','first_handled_by','latest_note','imported_at','imported_by'];
        return response()->streamDownload(function () use ($query,$fields): void {
            set_time_limit(0);
            $output = fopen('php://output','wb');
            try {
                fwrite($output,"\xEF\xBB\xBF");
                fputcsv($output,array_map(fn ($key) => __('credit_card_analysis.fields.'.$key),$fields));
                $query->with(CreditCardAnalysisService::RELATIONS)->reorder()->chunkById(300,function ($records) use ($output): void {
                    foreach ($records as $record) {
                        $order = $record->order;
                        $items = $order?->items ?? collect();
                        $shipped = $order && in_array($order->logistics_status,[LogisticsOrder::LOGISTICS_SHIPPED,LogisticsOrder::LOGISTICS_IN_TRANSIT,LogisticsOrder::LOGISTICS_DELIVERED],true);
                        $row = [
                            $order?->store_name,$order?->collection_account,$record->refund_reference,$record->bill_number,$order?->buyer_email,$order?->shopify_order_number,
                            $this->date($record->occurred_at),$this->date($order?->order_created_at),$this->money($record->refund_amount_usd),$this->money($order?->paid_amount_usd),
                            __('credit_card_analysis.categories.'.$this->analysis->category($record->refund_reason)),$record->refund_reason,$order?->subscription_type,
                            $order ? $this->flag($order->is_add_on === '已加购') : '',$order ? $this->flag(filled($order->subscription_origin_order_id)) : '',
                            $this->flag($record->subscription_cancelled),$this->flag($record->add_on_cancelled),$order?->logistics_status,$order ? $this->flag($shipped) : '',
                            $order?->tracking_number,$order?->tongtu_order_number,$items->pluck('tongtu_sku')->filter()->unique()->implode(' | '),
                            $items->map(fn ($item) => $item->tongtu_product_name ?: $item->product_title)->filter()->unique()->implode(' | '),
                            __($record->first_handled_at ? 'credit_card_analysis.handled' : 'credit_card_analysis.unhandled'),
                            $this->date($record->first_handled_at),$record->first_handled_by_name,$this->analysis->latestNote($record),
                            $this->date($record->last_imported_at),$record->imported_by_name,
                        ];
                        fputcsv($output,array_map([$this,'safeText'],$row));
                    }
                });
            } finally { fclose($output); }
        },'credit-card-analysis-'.now('Asia/Shanghai')->format('Ymd-His').'.csv',['Content-Type'=>'text/csv; charset=UTF-8']);
    }

    private function date(mixed $value): string { return $value ? $value->copy()->timezone('Asia/Shanghai')->format('Y-m-d H:i:s') : ''; }
    private function money(mixed $value): string { return $value === null ? '' : number_format((float) $value,2,'.','').' USD'; }
    private function flag(?bool $value): string { return __($value === null ? 'credit_card_analysis.unhandled' : ($value ? 'credit_card_analysis.yes' : 'credit_card_analysis.no')); }
    private function safeText(mixed $value): string
    {
        $value = (string) ($value ?? '');
        return preg_match('/^[\s\x00-\x20]*[=+\-@]/u',$value) || preg_match('/^[\t\r\n]/',$value) ? "'".$value : $value;
    }
}
