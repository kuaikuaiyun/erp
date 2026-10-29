<?php

namespace App\Portals\Payment;

use App\Models\LogisticsOrder;
use App\Models\LogisticsPayPalAccount;
use App\Models\LogisticsPayPalDispute;
use App\Models\LogisticsPayPalRefund;
use App\Models\LogisticsPayPalTransaction;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class PaymentOrderSearchService
{
    /** @param array<string,mixed> $filters @return array<string,mixed> */
    public function page(array $filters): array
    {
        $query = $this->filteredQuery($filters);
        $summary = $this->summary($filters);
        $total = $summary['payment_count'];
        $perPage = in_array((int) ($filters['per_page'] ?? 50), [50, 100], true) ? (int) $filters['per_page'] : 50;
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, (int) ($filters['page'] ?? 1)), $lastPage);
        $sortColumns = [
            'paid_at' => 'paid_at', 'amount' => 'amount_usd', 'transaction_id' => 'transaction_id',
            'account' => 'account_name', 'channel' => 'channel',
        ];
        $sort = $sortColumns[(string) ($filters['sort'] ?? '')] ?? 'paid_at';
        if ($sort === 'paid_at' && ($filters['date_mode'] ?? 'paid') === 'order') {
            $sort = 'order_created_at';
        }
        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $records = (clone $query)->orderBy($sort, $direction)->orderBy('source_id', $direction)
            ->forPage($page, $perPage)->get();

        return [
            'filters' => $filters,
            'payment_gateways' => $this->paymentGatewayOptions(),
            'summary' => $summary,
            'rows' => $this->hydrateRows($records),
            'pagination' => ['current_page' => $page, 'last_page' => $lastPage, 'per_page' => $perPage, 'total' => $total, 'from' => $total === 0 ? 0 : (($page - 1) * $perPage + 1), 'to' => min($page * $perPage, $total)],
        ];
    }

    /** @param array<string,mixed> $filters @return array<string,int|float> */
    public function summary(array $filters): array
    {
        $cacheKey = 'payment:order-search-summary:v2:'.sha1((string) json_encode(
            $filters,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));

        return Cache::remember(
            $cacheKey,
            now()->addSeconds(30),
            fn (): array => $this->summarize($this->filteredQuery($filters)),
        );
    }

    /** @return array{payment_count:int,received_amount:float,risk_payment_count:int,unmatched_count:int} */
    private function summarize(Builder $query): array
    {
        $row = (clone $query)->selectRaw('COUNT(*) as payment_count')
            ->selectRaw('COALESCE(SUM(amount_usd), 0) as received_amount')
            ->selectRaw('COALESCE(SUM(CASE WHEN risk_count > 0 THEN 1 ELSE 0 END), 0) as risk_payment_count')
            ->selectRaw('COALESCE(SUM(CASE WHEN order_id IS NULL THEN 1 ELSE 0 END), 0) as unmatched_count')
            ->first();

        return [
            'payment_count' => (int) ($row->payment_count ?? 0),
            'received_amount' => round((float) ($row->received_amount ?? 0), 2),
            'risk_payment_count' => (int) ($row->risk_payment_count ?? 0),
            'unmatched_count' => (int) ($row->unmatched_count ?? 0),
        ];
    }

    /** @return array<string,mixed>|null */
    public function detail(string $recordKey): ?array
    {
        if (!preg_match('/^(paypal|card):(\d+)$/', $recordKey, $match)) {
            return null;
        }

        if ($match[1] === 'paypal') {
            $payment = LogisticsPayPalTransaction::query()->with([
                'account', 'order.items', 'order.paymentReferences', 'order.paypalRefunds.disputeLinks.dispute',
                'order.paypalDisputes.refundLinks.refund', 'order.creditCardRefunds',
            ])->find((int) $match[2]);

            return $payment ? $this->paypalDetail($payment) : null;
        }

        $order = LogisticsOrder::query()->with([
            'items', 'paymentReferences', 'paypalRefunds.disputeLinks.dispute',
            'paypalDisputes.refundLinks.refund', 'creditCardRefunds', 'paypalAccount',
        ])->find((int) $match[2]);

        return $order ? $this->cardDetail($order) : null;
    }

    /** @param array<string,mixed> $filters */
    private function filteredQuery(array $filters): Builder
    {
        $query = $this->database()->query()->fromSub($this->sourceQuery(), 'payments');
        $dateColumn = ($filters['date_mode'] ?? 'paid') === 'order' ? 'order_created_at' : 'paid_at';
        if ($filters['date_from'] ?? '') $query->whereDate($dateColumn, '>=', $filters['date_from']);
        if ($filters['date_to'] ?? '') $query->whereDate($dateColumn, '<=', $filters['date_to']);
        if ($filters['payment_gateway'] ?? '') {
            $query->where('payment_gateway', (string) $filters['payment_gateway']);
        }
        if ($filters['deduction_type'] ?? '') $query->where('deduction_type', $filters['deduction_type']);
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $keyword).'%';
            $query->where(function (Builder $nested) use ($like): void {
                foreach (['transaction_id', 'invoice_id', 'account_name', 'merchant_id', 'shopify_order_number', 'tongtu_order_number', 'bill_number', 'product_text'] as $index => $column) {
                    $index === 0 ? $nested->where($column, 'like', $like) : $nested->orWhere($column, 'like', $like);
                }
                $nested->orWhereExists(fn (Builder $refund) => $refund->selectRaw('1')->from('logistics_paypal_refunds as search_refund')->whereColumn('search_refund.original_transaction_id', 'payments.transaction_id')->where('search_refund.refund_transaction_id', 'like', $like))
                    ->orWhereExists(fn (Builder $dispute) => $dispute->selectRaw('1')->from('logistics_paypal_disputes as search_dispute')->whereColumn('search_dispute.disputed_transaction_id', 'payments.transaction_id')->where('search_dispute.dispute_id', 'like', $like));
            });
        }

        return $query;
    }

    private function sourceQuery(): Builder
    {
        $database = $this->database();
        $paypal = $database->table('logistics_paypal_transactions as payment')
            ->leftJoin('logistics_paypal_accounts as account', 'account.id', '=', 'payment.logistics_paypal_account_id')
            ->leftJoin('logistics_orders as orders', 'orders.id', '=', 'payment.logistics_order_id')
            ->selectRaw("CONCAT('paypal:', payment.id) as record_key, 'paypal' as channel, payment.id as source_id")
            ->selectRaw("'PayPal' as payment_gateway")
            ->selectRaw('payment.payment_transaction_id as transaction_id, payment.invoice_id as invoice_id')
            ->selectRaw("CONCAT('paypal:', payment.logistics_paypal_account_id) as account_key")
            ->selectRaw("COALESCE(account.account_name, account.account_email, account.merchant_id, '-') as account_name")
            ->selectRaw("COALESCE(account.merchant_id, '') as merchant_id")
            ->selectRaw('payment.logistics_order_id as order_id, payment.paid_at as paid_at, orders.order_created_at as order_created_at, payment.payment_amount_usd as amount_usd')
            ->selectRaw('payment.original_amount as original_amount, payment.currency_code as currency_code, payment.status as payment_status')
            ->selectRaw("CASE WHEN orders.subscription_origin_order_id IS NOT NULL AND orders.subscription_origin_order_id <> '' THEN 'renewal' WHEN orders.subscription_type IS NOT NULL AND orders.subscription_type <> '' AND orders.subscription_type NOT LIKE '%无订阅%' THEN 'subscription' WHEN payment.logistics_order_id IS NULL THEN 'unmatched' ELSE 'one_time' END as deduction_type")
            ->selectRaw("COALESCE(orders.shopify_order_number, '') as shopify_order_number, COALESCE(orders.tongtu_order_number, '') as tongtu_order_number, COALESCE(orders.bill_number, '') as bill_number")
            ->selectRaw("COALESCE(orders.store_name, '') as store_name, COALESCE(orders.tongtu_product_name, orders.tongtu_product_code, orders.tongtu_sku, payment.payment_subject, '') as product_text")
            ->selectRaw('(SELECT COUNT(*) FROM logistics_paypal_refunds pr WHERE pr.original_transaction_id = payment.payment_transaction_id) + (SELECT COUNT(*) FROM logistics_paypal_disputes pd WHERE pd.disputed_transaction_id = payment.payment_transaction_id) as risk_count');

        $card = $database->table('logistics_orders as orders')
            ->where(function (Builder $operator): void {
                $operator->whereNull('orders.operator_name')
                    ->orWhere('orders.operator_name', 'not like', '%刷单%');
            })
            ->whereNotNull('orders.payment_gateway')
            ->whereRaw("TRIM(orders.payment_gateway) <> ''")
            ->where('orders.payment_gateway', 'not like', '%PayPal%')
            ->selectRaw("CONCAT('card:', orders.id) as record_key, 'card' as channel, orders.id as source_id")
            ->selectRaw('orders.payment_gateway as payment_gateway')
            ->selectRaw("COALESCE(orders.transaction_id, orders.payment_gateway_order_id, orders.bill_number, CONCAT('ORDER-', orders.id)) as transaction_id")
            ->selectRaw("COALESCE(orders.bill_number, '') as invoice_id")
            ->selectRaw("CONCAT('card:', COALESCE(NULLIF(orders.collection_account_email, ''), NULLIF(orders.collection_account, ''), 'unknown')) as account_key")
            ->selectRaw("COALESCE(NULLIF(orders.collection_account, ''), NULLIF(orders.collection_account_email, ''), '-') as account_name")
            ->selectRaw("COALESCE(orders.collection_account_email, '') as merchant_id")
            ->selectRaw('orders.id as order_id, orders.paid_at as paid_at, orders.order_created_at as order_created_at, orders.paid_amount_usd as amount_usd')
            ->selectRaw('orders.paid_amount_usd as original_amount, \'USD\' as currency_code, orders.payment_status as payment_status')
            ->selectRaw("CASE WHEN orders.subscription_origin_order_id IS NOT NULL AND orders.subscription_origin_order_id <> '' THEN 'renewal' WHEN orders.subscription_type IS NOT NULL AND orders.subscription_type <> '' AND orders.subscription_type NOT LIKE '%无订阅%' THEN 'subscription' ELSE 'one_time' END as deduction_type")
            ->selectRaw("COALESCE(orders.shopify_order_number, '') as shopify_order_number, COALESCE(orders.tongtu_order_number, '') as tongtu_order_number, COALESCE(orders.bill_number, '') as bill_number")
            ->selectRaw("COALESCE(orders.store_name, '') as store_name, COALESCE(orders.tongtu_product_name, orders.tongtu_product_code, orders.tongtu_sku, '') as product_text")
            ->selectRaw('(SELECT COUNT(*) FROM logistics_credit_card_refunds cr WHERE cr.logistics_order_id = orders.id) as risk_count');

        return $paypal->unionAll($card);
    }

    /** @return list<string> */
    private function paymentGatewayOptions(): array
    {
        $gateways = LogisticsOrder::query()
            ->where(function ($operator): void {
                $operator->whereNull('operator_name')
                    ->orWhere('operator_name', 'not like', '%刷单%');
            })
            ->whereNotNull('payment_gateway')
            ->whereRaw("TRIM(payment_gateway) <> ''")
            ->distinct()->orderBy('payment_gateway')->pluck('payment_gateway')
            ->map(static fn ($gateway): string => trim((string) $gateway))
            ->filter()->values();

        return $gateways->contains('PayPal')
            ? $gateways->all()
            : $gateways->push('PayPal')->sort()->values()->all();
    }

    /** @return list<array<string,mixed>> */
    private function hydrateRows(object $records): array
    {
        return $records->map(fn (object $row): array => [
            'record_key' => (string) $row->record_key, 'transaction_id' => (string) $row->transaction_id,
            'channel' => (string) $row->channel, 'account_name' => (string) $row->account_name,
            'payment_gateway' => (string) $row->payment_gateway,
            'merchant_id' => (string) $row->merchant_id, 'order_id' => $row->order_id ? (int) $row->order_id : null,
            'shopify_order_number' => (string) $row->shopify_order_number, 'store_name' => (string) $row->store_name,
            'product_text' => (string) $row->product_text, 'deduction_type' => (string) $row->deduction_type,
            'amount_usd' => round((float) $row->amount_usd, 2), 'original_amount' => round((float) $row->original_amount, 2),
            'currency_code' => (string) ($row->currency_code ?: 'USD'), 'payment_status' => (string) $row->payment_status,
            'risk_count' => (int) $row->risk_count, 'paid_at' => $row->paid_at ? (string) $row->paid_at : '',
            'order_created_at' => $row->order_created_at ? (string) $row->order_created_at : '',
            'match_status' => $row->order_id ? 'matched' : 'unmatched',
        ])->all();
    }

    /** @return array<string,mixed> */
    private function paypalDetail(LogisticsPayPalTransaction $payment): array
    {
        $detail = $this->detailPayload('paypal:'.$payment->getKey(), $payment->order, [
            'channel' => 'paypal', 'transaction_id' => (string) $payment->payment_transaction_id,
            'account_name' => (string) ($payment->account?->account_name ?: $payment->account?->account_email ?: $payment->account?->merchant_id ?: '-'),
            'merchant_id' => (string) ($payment->account?->merchant_id ?: ''), 'status' => (string) $payment->status,
            'amount_usd' => (float) $payment->payment_amount_usd, 'original_amount' => (float) $payment->original_amount,
            'currency_code' => (string) ($payment->currency_code ?: 'USD'), 'paid_at' => $payment->paid_at?->format('Y-m-d H:i:s') ?: '',
        ]);

        $refunds = LogisticsPayPalRefund::query()
            ->where('original_transaction_id', $payment->payment_transaction_id)
            ->get()->map(fn (LogisticsPayPalRefund $refund): array => $this->paypalRefundRow($refund))->all();
        $disputes = LogisticsPayPalDispute::query()
            ->where('disputed_transaction_id', $payment->payment_transaction_id)
            ->get()->map(fn (LogisticsPayPalDispute $dispute): array => $this->paypalDisputeRow($dispute))->all();

        $detail['refunds'] = $refunds;
        $detail['disputes'] = $disputes;
        $detail['summary'] = $this->eventSummary($refunds, $disputes);

        return $detail;
    }

    /** @return array<string,mixed> */
    private function cardDetail(LogisticsOrder $order): array
    {
        return $this->detailPayload('card:'.$order->getKey(), $order, [
            'channel' => 'card', 'transaction_id' => (string) ($order->transaction_id ?: $order->payment_gateway_order_id ?: $order->bill_number ?: 'ORDER-'.$order->getKey()),
            'account_name' => (string) ($order->collection_account ?: $order->collection_account_email ?: '-'),
            'merchant_id' => (string) ($order->collection_account_email ?: ''), 'status' => (string) $order->payment_status,
            'amount_usd' => (float) $order->paid_amount_usd, 'original_amount' => (float) $order->paid_amount_usd,
            'currency_code' => 'USD', 'paid_at' => $order->paid_at?->format('Y-m-d H:i:s') ?: '',
        ]);
    }

    /** @param array<string,mixed> $payment @return array<string,mixed> */
    private function detailPayload(string $recordKey, ?LogisticsOrder $order, array $payment): array
    {
        $refunds = [];
        $disputes = [];
        if ($order) {
            foreach ($order->paypalRefunds as $refund) $refunds[] = $this->paypalRefundRow($refund);
            foreach ($order->creditCardRefunds as $refund) $refunds[] = ['id' => (string) $refund->refund_reference, 'channel' => 'card', 'amount' => (float) $refund->refund_amount_usd, 'status' => __('payment.payment_orders.detail.refunded'), 'reason' => (string) $refund->refund_reason, 'happened_at' => $refund->last_imported_at?->format('Y-m-d H:i:s') ?: ''];
            foreach ($order->paypalDisputes as $dispute) $disputes[] = $this->paypalDisputeRow($dispute);
        }
        $deductionType = !$order ? 'unmatched' : (filled($order->subscription_origin_order_id) ? 'renewal' : (filled($order->subscription_type) && !str_contains((string) $order->subscription_type, '无订阅') ? 'subscription' : 'one_time'));
        $items = $order?->items->map(fn ($item): array => ['name' => (string) ($item->tongtu_product_name ?: $item->product_title ?: $item->tongtu_product_code ?: $item->shopify_sku), 'sku' => (string) ($item->tongtu_sku ?: $item->shopify_sku), 'quantity' => (float) $item->quantity])->values()->all() ?? [];

        return [
            'record_key' => $recordKey, 'payment' => $payment,
            'order' => $order ? ['id' => (int) $order->getKey(), 'detail_url' => route('logistics.orders.detail', ['order' => $order->getKey()]), 'shopify_order_number' => (string) $order->shopify_order_number, 'tongtu_order_number' => (string) $order->tongtu_order_number, 'bill_number' => (string) $order->bill_number, 'store_name' => (string) $order->store_name, 'deduction_type' => $deductionType, 'subscription_origin_order_id' => (string) $order->subscription_origin_order_id, 'logistics_status' => (string) $order->logistics_status, 'tracking_number' => (string) $order->tracking_number, 'items' => $items] : null,
            'refunds' => $refunds, 'disputes' => $disputes,
            'summary' => $this->eventSummary($refunds, $disputes),
        ];
    }

    /** @return array<string,mixed> */
    private function paypalRefundRow(LogisticsPayPalRefund $refund): array
    {
        return ['id' => (string) $refund->refund_transaction_id, 'channel' => 'paypal', 'amount' => (float) $refund->refund_amount_usd, 'status' => $refund->statusLabel(), 'reason' => (string) ($refund->manual_reason ?: $refund->refund_reason), 'happened_at' => $refund->refunded_at?->format('Y-m-d H:i:s') ?: ''];
    }

    /** @return array<string,mixed> */
    private function paypalDisputeRow(LogisticsPayPalDispute $dispute): array
    {
        return ['id' => (string) $dispute->dispute_id, 'amount' => (float) $dispute->dispute_amount_usd, 'status' => $dispute->statusLabel(), 'reason' => $dispute->reasonLabel(), 'outcome' => $dispute->outcomeLabel(), 'happened_at' => $dispute->dispute_created_at?->format('Y-m-d H:i:s') ?: '', 'deadline' => $dispute->seller_response_due_at?->format('Y-m-d H:i:s') ?: ''];
    }

    /** @param list<array<string,mixed>> $refunds @param list<array<string,mixed>> $disputes @return array<string,mixed> */
    private function eventSummary(array $refunds, array $disputes): array
    {
        return ['refund_amount' => round(array_sum(array_column($refunds, 'amount')), 2), 'dispute_amount' => round(array_sum(array_column($disputes, 'amount')), 2), 'risk_event_count' => count($refunds) + count($disputes)];
    }

    private function database(): \Illuminate\Database\Connection
    {
        return DB::connection(config('admin.database.connection') ?: config('database.default'));
    }
}
