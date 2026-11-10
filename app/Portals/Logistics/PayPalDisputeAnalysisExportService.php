<?php

namespace App\Portals\Logistics;

use App\Models\LogisticsOrder;
use App\Models\LogisticsPayPalDispute;
use App\Models\LogisticsPayPalDisputeRefund;
use App\Models\LogisticsPayPalTransaction;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PayPalDisputeAnalysisExportService
{
    /** @param Builder<LogisticsPayPalDispute> $disputes */
    public function download(Builder $disputes): StreamedResponse
    {
        $filename = 'paypal-dispute-analysis-'.now('Asia/Shanghai')->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($disputes): void {
            set_time_limit(0);
            $output = fopen('php://output', 'wb');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, [
                'PP账户', '事件ID', '交易ID', '客户邮箱', '发起日期', '首次处理日期', '下单日期',
                '纠纷申诉金额', '申诉币种', '订单金额', '订单币种', '退款金额', '退款币种', '纠纷费',
                '订阅/定期', 'PostUpsell', '是否复购单', '是否取消订阅', '是否取消加购',
                '类型', '纠纷理由', '事件时物流状态', '事件时是否发货', '事件时物流单号', '当前物流状态', '当前是否发货', '当前物流单号', '通途订单号', '通途SKU', '中文品名',
                '解决方案', '状态', '首次操作人',
            ]);

            $disputes->with([
                'account:id,account_name',
                'order:id,buyer_email,subscription_type,subscription_origin_order_id,is_add_on,logistics_status,tracking_number,order_created_at,paid_amount_usd,tongtu_order_number',
                'order.items:id,logistics_order_id,tongtu_sku,tongtu_product_name,product_title',
                'refundLinks.refund:id,refund_amount_usd,original_amount,currency_code',
            ])->orderBy('id')->chunkById(300, function ($rows) use ($output): void {
                $transactions = LogisticsPayPalTransaction::query()
                    ->whereIn('payment_transaction_id', $rows->pluck('disputed_transaction_id')->filter()->unique())
                    ->get(['logistics_paypal_account_id', 'payment_transaction_id', 'original_amount', 'currency_code'])
                    ->keyBy(fn (LogisticsPayPalTransaction $item): string => $this->transactionKey($item->logistics_paypal_account_id, $item->payment_transaction_id));

                foreach ($rows as $dispute) {
                    $order = $dispute->order;
                    $transaction = $transactions->get($this->transactionKey($dispute->logistics_paypal_account_id, $dispute->disputed_transaction_id));
                    $refunds = $dispute->refundLinks->map(fn (LogisticsPayPalDisputeRefund $link) => $link->refund)->filter();
                    $refundCurrencies = $refunds->pluck('currency_code')->filter()->unique();
                    $items = $order?->items ?? collect();
                    $orderAmount = $transaction?->original_amount ?? $order?->paid_amount_usd;
                    $orderCurrency = $transaction?->currency_code ?? ($orderAmount !== null ? 'USD' : '');
                    $shipped = $order && in_array($order->logistics_status, [LogisticsOrder::LOGISTICS_SHIPPED, LogisticsOrder::LOGISTICS_IN_TRANSIT, LogisticsOrder::LOGISTICS_DELIVERED], true);

                    fputcsv($output, [
                        $this->text($dispute->account?->account_name), $this->text($dispute->dispute_id), $this->text($dispute->disputed_transaction_id),
                        $this->text($order?->buyer_email), $this->date($dispute->dispute_created_at), $this->date($dispute->first_handled_at), $this->date($order?->order_created_at),
                        $this->number($dispute->original_amount ?? $dispute->dispute_amount_usd), $this->text($dispute->currency_code), $this->number($orderAmount), $this->text($orderCurrency),
                        $this->number($refunds->sum(fn ($refund): float => (float) ($refund->original_amount ?? $refund->refund_amount_usd ?? 0))), $refundCurrencies->count() === 1 ? $this->text($refundCurrencies->first()) : '', '',
                        $this->text($order?->subscription_type), $order ? ($order->is_add_on === '已加购' ? '是' : '否') : '', $order ? (filled($order->subscription_origin_order_id) ? '是' : '否') : '',
                        $this->flag($dispute->subscription_cancelled), $this->flag($dispute->add_on_cancelled), $this->text($dispute->typeLabel()), $this->text($dispute->reasonLabel()),
                        $this->text($dispute->event_logistics_status), $dispute->event_logistics_captured_at ? ($dispute->event_shipped ? '是' : '否') : '', $this->text($dispute->event_tracking_number),
                        $this->text($order?->logistics_status), $order ? ($shipped ? '是' : '否') : '', $this->text($order?->tracking_number), $this->text($order?->tongtu_order_number),
                        $this->text($items->pluck('tongtu_sku')->filter()->unique()->implode(' | ')), $this->text($items->map(fn ($item) => $item->tongtu_product_name ?: $item->product_title)->filter()->unique()->implode(' | ')),
                        $this->text($dispute->outcomeLabel()), $this->text($dispute->statusLabel()), $this->text($dispute->first_handled_by_name),
                    ]);
                }
            });
            fclose($output);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function transactionKey(mixed $accountId, mixed $transactionId): string
    {
        $transactionId = trim((string) $transactionId);
        return $transactionId === '' ? '' : (int) $accountId.'|'.$transactionId;
    }

    private function date(mixed $value): string { return $value ? $value->timezone('Asia/Shanghai')->format('Y-m-d H:i:s') : ''; }
    private function number(mixed $value): string { return $value === null || $value === '' ? '' : number_format((float) $value, 2, '.', ''); }
    private function flag(?bool $value): string { return $value === null ? '未处理' : ($value ? '是' : '否'); }
    private function text(mixed $value): string { $value = trim((string) $value); return preg_match('/^[=+\-@]/', $value) ? "'".$value : $value; }
}
