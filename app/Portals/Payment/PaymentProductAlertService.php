<?php

namespace App\Portals\Payment;

use App\Models\PaymentProductAlertAssignment;
use App\Models\PaymentProductAlertAssignmentLog;
use App\Models\AdminUser;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PaymentProductAlertService
{
    public const MINIMUM_ORDER_COUNT = 1;

    public const DISPUTE_RATE_THRESHOLD = 3.0;

    /**
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    public function dashboard(array $filters, int $page = 1, int $perPage = 50): array
    {
        $allAlerts = $this->withStatuses($this->metricRows());
        $filtered = $this->filterRows($allAlerts, $filters);
        $total = $filtered->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $lastPage);
        $offset = ($page - 1) * $perPage;

        return [
            'filters' => $filters,
            'kpis' => [
                ['key' => 'in_progress', 'value' => (string) $allAlerts->where('status_key', 'in_progress')->count(), 'note_key' => 'in_progress_note', 'tone' => 'warning'],
                ['key' => 'completed', 'value' => (string) $allAlerts->where('status_key', 'completed')->count(), 'note_key' => 'completed_note', 'tone' => 'primary'],
            ],
            'alerts' => $filtered->slice($offset, $perPage)->values()->all(),
            'pagination' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
                'from' => $total === 0 ? 0 : $offset + 1,
                'to' => min($offset + $perPage, $total),
            ],
        ];
    }

    /** @return array<string,mixed>|null */
    public function find(string $alertCode): ?array
    {
        $alert = $this->withStatuses($this->metricRows())->firstWhere('code', $alertCode);

        return $alert === null ? null : [...$alert, ...$this->detailData($alert)];
    }

    /** @return list<array<string,mixed>> */
    public function exportRows(): array
    {
        return $this->metricRows(false)->values()->all();
    }

    public function updateStatus(string $alertCode, string $status, string $operation, string $remark, AdminUser $actor): void
    {
        abort_unless(in_array($status, ['in_progress', 'completed'], true), 422);

        $record = PaymentProductAlertAssignment::query()->firstOrNew(['alert_code' => $alertCode]);
        $previousStatus = $record->exists && $record->status === 'completed' ? 'completed' : 'in_progress';
        $record->status = $status;
        $record->save();

        PaymentProductAlertAssignmentLog::query()->create([
            'alert_code' => $alertCode,
            'event' => 'status_changed',
            'actor_id' => $actor->getKey(),
            'actor_name_snapshot' => $actor->name,
            'note' => json_encode([
                'from_status' => $previousStatus,
                'to_status' => $status,
                'operation' => $operation,
                'remark' => $remark,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    /** @param array<string,mixed> $alert @return array<string,mixed> */
    private function detailData(array $alert): array
    {
        $database = $this->database();
        $productKey = $this->productKeyExpression('detail_items');
        $orderIds = $database->table('logistics_order_items as detail_items')
            ->join('logistics_orders as detail_orders', 'detail_orders.id', '=', 'detail_items.logistics_order_id')
            ->whereRaw("{$productKey} = ?", [$alert['product_key']])
            ->where(function ($query): void {
                $query->whereNull('detail_orders.subscription_origin_order_id')
                    ->orWhereRaw("TRIM(detail_orders.subscription_origin_order_id) = ''");
            })
            ->where(function ($query): void {
                $query->whereNotNull('detail_orders.paid_at')->orWhere('detail_orders.paid_amount_usd', '>', 0);
            })
            ->whereNotNull('detail_orders.paypal_account_id')
            ->where('detail_orders.paypal_association_source', 'actual_transaction')
            ->select('detail_orders.id')->distinct();

        $disputes = $database->table('logistics_paypal_disputes')
            ->whereIn('logistics_order_id', clone $orderIds)
            ->selectRaw('logistics_order_id, COUNT(DISTINCT id) as dispute_count')
            ->selectRaw('GROUP_CONCAT(DISTINCT NULLIF(reason, \'\') SEPARATOR \' / \') as dispute_reasons')
            ->selectRaw('GROUP_CONCAT(DISTINCT NULLIF(status, \'\') SEPARATOR \' / \') as dispute_statuses')
            ->groupBy('logistics_order_id');

        $payments = $database->table('logistics_orders as orders')
            ->whereIn('orders.id', clone $orderIds)
            ->leftJoinSub($disputes, 'risk', 'risk.logistics_order_id', '=', 'orders.id')
            ->leftJoin('logistics_paypal_transactions as transaction', 'transaction.logistics_order_id', '=', 'orders.id')
            ->select(['orders.id', 'orders.shopify_order_number', 'orders.tongtu_order_number', 'orders.store_name', 'orders.paid_at', 'orders.paid_amount_usd'])
            ->selectRaw('MAX(transaction.payment_transaction_id) as transaction_id')
            ->selectRaw('MAX(transaction.status) as payment_status')
            ->selectRaw('MAX(COALESCE(risk.dispute_count, 0)) as dispute_count')
            ->selectRaw('MAX(risk.dispute_reasons) as dispute_reasons')
            ->selectRaw('MAX(risk.dispute_statuses) as dispute_statuses')
            ->groupBy('orders.id', 'orders.shopify_order_number', 'orders.tongtu_order_number', 'orders.store_name', 'orders.paid_at', 'orders.paid_amount_usd')
            ->orderByDesc('orders.paid_at')->limit(100)->get()
            ->map(static fn (object $row): array => [
                'order' => (string) ($row->shopify_order_number ?: $row->tongtu_order_number ?: $row->id),
                'store' => (string) ($row->store_name ?: '-'),
                'transaction_id' => (string) ($row->transaction_id ?: '-'),
                'amount' => (float) $row->paid_amount_usd,
                'payment_status' => (string) ($row->payment_status ?: '-'),
                'dispute_count' => (int) $row->dispute_count,
                'dispute_reasons' => (string) ($row->dispute_reasons ?: '-'),
                'dispute_statuses' => (string) ($row->dispute_statuses ?: '-'),
                'time' => $row->paid_at ? date('Y-m-d H:i', strtotime((string) $row->paid_at)) : '-',
            ])->all();

        $paymentEvents = $database->table('logistics_paypal_transactions')
            ->whereIn('logistics_order_id', clone $orderIds)
            ->orderByDesc('paid_at')->limit(100)->get()
            ->map(static fn (object $row): array => [
                'type' => 'payment', 'reference' => (string) ($row->payment_transaction_id ?: '-'),
                'related_reference' => (string) ($row->invoice_id ?: '-'), 'status' => (string) ($row->status ?: '-'),
                'amount' => (float) ($row->payment_amount_usd ?? $row->original_amount ?? 0),
                'reason' => (string) ($row->payment_subject ?: '-'),
                'time' => $row->paid_at ? date('Y-m-d H:i', strtotime((string) $row->paid_at)) : '-',
                'sort_time' => (string) ($row->paid_at ?: ''),
            ]);
        $refundEvents = $database->table('logistics_paypal_refunds')
            ->whereIn('logistics_order_id', clone $orderIds)
            ->orderByDesc('refunded_at')->limit(100)->get()
            ->map(static fn (object $row): array => [
                'type' => 'refund', 'reference' => (string) ($row->refund_transaction_id ?: '-'),
                'related_reference' => (string) ($row->original_transaction_id ?: '-'), 'status' => (string) ($row->status ?: '-'),
                'amount' => (float) ($row->refund_amount_usd ?? $row->original_amount ?? 0),
                'reason' => (string) ($row->manual_reason ?: $row->refund_reason ?: '-'),
                'time' => $row->refunded_at ? date('Y-m-d H:i', strtotime((string) $row->refunded_at)) : '-',
                'sort_time' => (string) ($row->refunded_at ?: ''),
            ]);

        $logs = PaymentProductAlertAssignmentLog::query()->where('alert_code', $alert['code'])
            ->where('event', 'status_changed')->latest('created_at')->limit(100)->get()
            ->map(static function (PaymentProductAlertAssignmentLog $log): array {
                $payload = json_decode((string) $log->note, true);
                return [
                    'from_status' => is_array($payload) ? ($payload['from_status'] ?? 'in_progress') : 'in_progress',
                    'to_status' => is_array($payload) ? ($payload['to_status'] ?? 'in_progress') : 'in_progress',
                    'operation' => is_array($payload) ? ($payload['operation'] ?? 'process') : 'process',
                    'remark' => is_array($payload) ? ($payload['remark'] ?? (string) $log->note) : (string) $log->note,
                    'actor' => (string) ($log->actor_name_snapshot ?: '-'),
                    'time' => $log->created_at ? $log->created_at->timezone('Asia/Shanghai')->format('Y-m-d H:i:s') : '-',
                ];
            })->all();

        return [
            'payments' => $payments,
            'payment_events' => $paymentEvents->merge($refundEvents)->sortByDesc('sort_time')->take(100)->values()->all(),
            'processing_logs' => $logs,
        ];
    }

    public static function disputeRate(int $eligibleOrders, int $disputeOrders): float
    {
        return $eligibleOrders > 0 ? round($disputeOrders / $eligibleOrders * 100, 2) : 0.0;
    }

    public static function qualifies(int $eligibleOrders, int $disputeOrders): bool
    {
        return $eligibleOrders >= self::MINIMUM_ORDER_COUNT
            && self::disputeRate($eligibleOrders, $disputeOrders) > self::DISPUTE_RATE_THRESHOLD;
    }

    /** @return Collection<int,array<string,mixed>> */
    private function metricRows(bool $onlyQualified = true): Collection
    {
        $database = $this->database();
        $productKey = $this->productKeyExpression('items');
        $paidOrder = '(orders.paid_at IS NOT NULL OR COALESCE(orders.paid_amount_usd, 0) > 0)';
        $initialOrder = "(orders.subscription_origin_order_id IS NULL OR TRIM(orders.subscription_origin_order_id) = '')";

        // One row represents one product in one paid order. This is the key
        // de-duplication boundary: quantity does not increase the order count,
        // while an order containing several products belongs once to each one.
        $productOrders = $database->table('logistics_order_items as items')
            ->join('logistics_orders as orders', 'orders.id', '=', 'items.logistics_order_id')
            ->whereRaw($paidOrder)
            ->whereRaw($initialOrder)
            ->selectRaw("{$productKey} as product_key")
            ->addSelect('orders.id as order_id')
            ->selectRaw('MIN(items.id) as first_item_id')
            ->selectRaw('SUM(COALESCE(items.quantity, 0)) as unit_count')
            ->selectRaw('MAX(COALESCE(orders.paid_amount_usd, 0)) as paid_amount_usd')
            ->selectRaw('MAX(orders.paypal_account_id) as paypal_account_id')
            ->selectRaw('MAX(orders.paypal_association_source) as paypal_association_source')
            ->selectRaw('MAX(orders.shopify_payload) as shopify_payload')
            ->groupByRaw("{$productKey}, orders.id");

        $disputes = $database->table('logistics_paypal_disputes as dispute')
            ->whereNotNull('dispute.logistics_order_id')
            ->select(['dispute.logistics_order_id', 'dispute.logistics_paypal_account_id'])
            ->selectRaw('COUNT(DISTINCT dispute.id) as dispute_count')
            ->selectRaw("SUM(COALESCE(dispute.dispute_amount_usd, CASE WHEN UPPER(dispute.currency_code) = 'USD' THEN dispute.original_amount ELSE 0 END)) as dispute_amount")
            ->selectRaw("SUM(CASE WHEN UPPER(COALESCE(dispute.reason, '')) LIKE '%NOT_AS_DESCRIBED%' OR UPPER(COALESCE(dispute.reason, '')) LIKE '%MERCHANDISE_OR_SERVICE_NOT_AS_DESCRIBED%' THEN COALESCE(dispute.dispute_amount_usd, CASE WHEN UPPER(dispute.currency_code) = 'USD' THEN dispute.original_amount ELSE 0 END) ELSE 0 END) as description_mismatch_amount")
            ->groupBy('dispute.logistics_order_id', 'dispute.logistics_paypal_account_id');

        $cardComplaints = $database->table('logistics_credit_card_refunds as refund')
            ->where(function ($query): void {
                $query->whereRaw("LOWER(COALESCE(refund.refund_reason, '')) LIKE '%ethoca%'")
                    ->orWhereRaw("LOWER(COALESCE(refund.refund_reason, '')) LIKE '%cdrn%'")
                    ->orWhereRaw("LOWER(COALESCE(refund.refund_reason, '')) LIKE '%rdr%'");
            })
            ->select('refund.logistics_order_id')
            ->selectRaw('COUNT(DISTINCT refund.id) as complaint_count')
            ->groupBy('refund.logistics_order_id');

        $actualPayPal = "(product_orders.paypal_account_id IS NOT NULL AND product_orders.paypal_association_source = 'actual_transaction')";
        $creditCard = "(CASE WHEN JSON_VALID(COALESCE(product_orders.shopify_payload, '')) THEN JSON_SEARCH(JSON_EXTRACT(product_orders.shopify_payload, '$.tags'), 'one', '%CreditCardBy%') ELSE NULL END IS NOT NULL)";
        $payload = "LOWER(COALESCE(product_orders.shopify_payload, ''))";
        $yunhui = "({$creditCard} AND ({$payload} LIKE '%yunhui%' OR {$payload} LIKE '%云汇%'))";
        $qianhai = "({$creditCard} AND ({$payload} LIKE '%qianhai%' OR {$payload} LIKE '%oceanpayment%' OR {$payload} LIKE '%钱海%'))";

        $rows = $database->query()->fromSub($productOrders, 'product_orders')
            ->join('logistics_order_items as first_item', 'first_item.id', '=', 'product_orders.first_item_id')
            ->leftJoinSub($disputes, 'paypal_disputes', function ($join): void {
                $join->on('paypal_disputes.logistics_order_id', '=', 'product_orders.order_id')
                    ->on('paypal_disputes.logistics_paypal_account_id', '=', 'product_orders.paypal_account_id');
            })
            ->leftJoinSub($cardComplaints, 'card_complaints', 'card_complaints.logistics_order_id', '=', 'product_orders.order_id')
            ->select('product_orders.product_key')
            ->selectRaw('MIN(product_orders.first_item_id) as first_item_id')
            ->selectRaw('MIN(first_item.product_title) as product_title')
            ->selectRaw('MIN(first_item.tongtu_product_name) as tongtu_product_name')
            ->selectRaw('MIN(first_item.tongtu_product_code) as tongtu_product_code')
            ->selectRaw('MIN(first_item.shopify_sku) as shopify_sku')
            ->selectRaw('MIN(first_item.tongtu_sku) as tongtu_sku')
            ->selectRaw('SUM(product_orders.unit_count) as total_sales')
            ->selectRaw('COUNT(*) as total_order_count')
            ->selectRaw("SUM(CASE WHEN {$actualPayPal} THEN 1 ELSE 0 END) as paypal_order_count")
            ->selectRaw("SUM(CASE WHEN {$actualPayPal} THEN product_orders.paid_amount_usd ELSE 0 END) as paypal_received_amount")
            ->selectRaw("SUM(CASE WHEN {$actualPayPal} THEN COALESCE(paypal_disputes.dispute_count, 0) ELSE 0 END) as paypal_dispute_count")
            ->selectRaw("SUM(CASE WHEN {$actualPayPal} AND COALESCE(paypal_disputes.dispute_count, 0) > 0 THEN 1 ELSE 0 END) as paypal_dispute_order_count")
            ->selectRaw("SUM(CASE WHEN {$actualPayPal} THEN COALESCE(paypal_disputes.dispute_amount, 0) ELSE 0 END) as paypal_dispute_amount")
            ->selectRaw("SUM(CASE WHEN {$actualPayPal} THEN COALESCE(paypal_disputes.description_mismatch_amount, 0) ELSE 0 END) as description_mismatch_amount")
            ->selectRaw("SUM(CASE WHEN {$yunhui} THEN 1 ELSE 0 END) as yunhui_payment_count")
            ->selectRaw("SUM(CASE WHEN {$yunhui} THEN COALESCE(card_complaints.complaint_count, 0) ELSE 0 END) as yunhui_complaint_count")
            ->selectRaw("SUM(CASE WHEN {$qianhai} THEN 1 ELSE 0 END) as qianhai_payment_count")
            ->selectRaw("SUM(CASE WHEN {$qianhai} THEN COALESCE(card_complaints.complaint_count, 0) ELSE 0 END) as qianhai_complaint_count")
            ->groupBy('product_orders.product_key')
            ->get();

        $globalDisputeAmount = $this->globalInitialPayPalDisputeAmount($disputes);

        return $rows->map(function (object $row) use ($globalDisputeAmount, $onlyQualified): ?array {
            $eligibleOrders = (int) $row->paypal_order_count;
            $disputeOrders = (int) $row->paypal_dispute_order_count;
            $isAlert = self::qualifies($eligibleOrders, $disputeOrders);
            if ($onlyQualified && !$isAlert) {
                return null;
            }

            $received = round((float) $row->paypal_received_amount, 4);
            $disputeAmount = round((float) $row->paypal_dispute_amount, 4);
            $mismatchAmount = round((float) $row->description_mismatch_amount, 4);
            $productTitle = trim((string) $row->product_title);
            $matchedName = trim((string) $row->tongtu_product_name);
            $productCode = trim((string) $row->tongtu_product_code);
            $fallbackSku = trim((string) ($row->shopify_sku ?: $row->tongtu_sku));
            $yunhuiPayments = (int) $row->yunhui_payment_count;
            $qianhaiPayments = (int) $row->qianhai_payment_count;

            return [
                'code' => 'PW-'.(int) $row->first_item_id,
                'product_key' => (string) $row->product_key,
                'product_name' => $matchedName !== '' ? $matchedName : ($productTitle !== '' ? $productTitle : __('payment.product_alerts.unnamed_product')),
                'source_product_name' => $productTitle,
                'sku' => $productCode !== '' ? $productCode : ($fallbackSku !== '' ? $fallbackSku : __('payment.product_alerts.missing_sku')),
                'severity_key' => 'critical',
                'channel_key' => 'paypal_initial',
                'threshold_key' => 'dispute_order_rate',
                'minimum_sample' => self::MINIMUM_ORDER_COUNT,
                'threshold' => self::DISPUTE_RATE_THRESHOLD,
                'is_alert' => $isAlert,
                'total_sales' => round((float) $row->total_sales, 4),
                'total_order_count' => (int) $row->total_order_count,
                'eligible_order_count' => $eligibleOrders,
                'dispute_order_count' => $disputeOrders,
                'dispute_count' => (int) $row->paypal_dispute_count,
                'dispute_rate' => self::disputeRate($eligibleOrders, $disputeOrders),
                'received_amount' => $received,
                'dispute_amount' => $disputeAmount,
                'dispute_amount_rate' => $received > 0 ? round($disputeAmount / $received * 100, 2) : 0.0,
                'description_mismatch_amount' => $mismatchAmount,
                'description_mismatch_rate' => $disputeAmount > 0 ? round($mismatchAmount / $disputeAmount * 100, 2) : 0.0,
                'sku_dispute_amount_share' => $globalDisputeAmount > 0 ? round($disputeAmount / $globalDisputeAmount * 100, 2) : 0.0,
                'yunhui_complaint_count' => (int) $row->yunhui_complaint_count,
                'yunhui_payment_count' => $yunhuiPayments,
                'yunhui_dispute_rate' => $yunhuiPayments > 0 ? round((int) $row->yunhui_complaint_count / $yunhuiPayments * 100, 2) : 0.0,
                'qianhai_complaint_count' => (int) $row->qianhai_complaint_count,
                'qianhai_payment_count' => $qianhaiPayments,
                'qianhai_dispute_rate' => $qianhaiPayments > 0 ? round((int) $row->qianhai_complaint_count / $qianhaiPayments * 100, 2) : 0.0,
                'sample' => $eligibleOrders.' / '.$disputeOrders,
                'status_key' => 'in_progress',
                'action_key' => 'process',
            ];
        })->filter()->sortByDesc('dispute_rate')->values();
    }

    /** @param \Illuminate\Database\Query\Builder $disputes */
    private function globalInitialPayPalDisputeAmount($disputes): float
    {
        $database = $this->database();

        return round((float) $database->table('logistics_orders as orders')
            ->leftJoinSub(clone $disputes, 'paypal_disputes', function ($join): void {
                $join->on('paypal_disputes.logistics_order_id', '=', 'orders.id')
                    ->on('paypal_disputes.logistics_paypal_account_id', '=', 'orders.paypal_account_id');
            })
            ->where(function ($query): void {
                $query->whereNull('orders.subscription_origin_order_id')
                    ->orWhereRaw("TRIM(orders.subscription_origin_order_id) = ''");
            })
            ->where(function ($query): void {
                $query->whereNotNull('orders.paid_at')->orWhere('orders.paid_amount_usd', '>', 0);
            })
            ->whereNotNull('orders.paypal_account_id')
            ->where('orders.paypal_association_source', 'actual_transaction')
            ->sum(DB::raw('COALESCE(paypal_disputes.dispute_amount, 0)')), 4);
    }

    /** @param Collection<int,array<string,mixed>> $alerts @return Collection<int,array<string,mixed>> */
    private function withStatuses(Collection $alerts): Collection
    {
        if ($alerts->isEmpty()) {
            return $alerts;
        }

        $statuses = PaymentProductAlertAssignment::query()
            ->whereIn('alert_code', $alerts->pluck('code')->all())
            ->get()->keyBy('alert_code');

        return $alerts->map(function (array $alert) use ($statuses): array {
            $record = $statuses->get($alert['code']);
            if (!$record) {
                return $alert;
            }

            $alert['status_key'] = $record->status === 'completed' ? 'completed' : 'in_progress';
            $alert['action_key'] = $alert['status_key'] === 'completed' ? 'view_completed' : 'process';

            return $alert;
        });
    }

    /**
     * @param Collection<int,array<string,mixed>> $alerts
     * @param array<string,mixed> $filters
     * @return Collection<int,array<string,mixed>>
     */
    private function filterRows(Collection $alerts, array $filters): Collection
    {
        $status = trim((string) ($filters['status'] ?? ''));
        $product = mb_strtolower(trim((string) ($filters['product'] ?? '')));

        return $alerts->filter(function (array $row) use ($status, $product): bool {
            if ($status !== '' && $row['status_key'] !== $status) {
                return false;
            }
            if ($product !== '') {
                $search = mb_strtolower(implode(' ', [$row['product_name'], $row['source_product_name'], $row['sku']]));
                if (!str_contains($search, $product)) {
                    return false;
                }
            }
            return true;
        })->values();
    }

    private function productKeyExpression(string $alias): string
    {
        return "CASE "
            ."WHEN NULLIF(TRIM({$alias}.tongtu_product_code), '') IS NOT NULL THEN CONCAT('tongtu-product-code:', LOWER(TRIM({$alias}.tongtu_product_code))) "
            ."WHEN NULLIF(TRIM({$alias}.shopify_sku), '') IS NOT NULL THEN CONCAT('shopify-sku:', LOWER(TRIM({$alias}.shopify_sku))) "
            ."WHEN NULLIF(TRIM({$alias}.tongtu_sku), '') IS NOT NULL THEN CONCAT('tongtu-sku:', LOWER(TRIM({$alias}.tongtu_sku))) "
            ."WHEN NULLIF(TRIM({$alias}.shopify_product_id), '') IS NOT NULL THEN CONCAT('shopify-product:', LOWER(TRIM({$alias}.shopify_product_id))) "
            ."WHEN NULLIF(TRIM(COALESCE({$alias}.tongtu_product_name, {$alias}.product_title)), '') IS NOT NULL THEN CONCAT('product-name:', LOWER(TRIM(COALESCE({$alias}.tongtu_product_name, {$alias}.product_title)))) "
            ."ELSE CONCAT('sku:', COALESCE(NULLIF(LOWER(TRIM({$alias}.tongtu_product_code)), ''), NULLIF(LOWER(TRIM({$alias}.tongtu_sku)), ''), NULLIF(LOWER(TRIM({$alias}.shopify_sku)), ''), '__missing__')) END";
    }

    private function database(): Connection
    {
        return DB::connection(config('admin.database.connection') ?: config('database.default'));
    }
}
