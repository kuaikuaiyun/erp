<?php

namespace App\Portals\Logistics;

use App\Models\LogisticsOrder;
use App\Models\LogisticsOrderItem;
use App\Models\LogisticsOrderPaymentReference;
use App\Models\LogisticsPayPalAccount;
use App\Models\LogisticsPayPalDispute;
use App\Models\LogisticsPayPalRefund;
use App\Models\LogisticsPayPalTransaction;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * PayPal risk statistics are derived from the PayPal payment ledger.
 *
 * ERP orders are supplementary information only.  An unlinked payment is
 * still a real PayPal payment and must remain in financial totals and detail
 * results until a later Shopify sync can safely match it.
 */
class PayPalRiskAnalyticsService
{
    /**
     * PayPal reporting day used by every currently configured merchant.
     *
     * Keep this as an IANA timezone rather than a fixed UTC offset so that
     * daylight-saving transitions are handled correctly.
     */
    private const REPORT_TIMEZONE = 'America/Los_Angeles';

    /**
     * 退款率与纠纷率的统计口径不同：纠纷风险仅纳入实际与商品/服务
     * 履约相关的索偿、撤销付款，避免预仲裁等后续流程重复放大指标。
     *
     * @var list<string>
     */
    private const QUALIFYING_DISPUTE_REASONS = [
        'MERCHANDISE_OR_SERVICE_NOT_RECEIVED',
        'MERCHANDISE_OR_SERVICE_NOT_AS_DESCRIBED',
    ];

    /** @var list<string> */
    private const QUALIFYING_DISPUTE_TYPES = [
        'INQUIRY',
        'CHARGEBACK',
    ];

    /** @return array<string,mixed> */
    public function summary(LogisticsPayPalAccount $account, array $filters): array
    {
        $current = $this->paymentMetrics($account, $filters);
        $history = $this->paymentMetrics($account, [], false);

        return [
            'id' => (int) $account->getKey(),
            'account_name' => (string) $account->account_name,
            'merchant_id' => (string) ($account->merchant_id ?: '-'),
            'account_email' => (string) ($account->account_email ?: '-'),
            'masked_email' => $this->maskEmail($account->account_email),
            'environment' => (string) $account->environment,
            'is_enabled' => (bool) $account->is_enabled,
            ...$current,
            'cumulative_refund_rate' => $history['refund_rate'],
            'cumulative_dispute_rate' => $history['dispute_rate'],
            'last_imported_at' => $this->dateTime($account->last_imported_at),
            'last_import_status' => (string) ($account->last_import_status ?: 'never'),
            'last_import_message' => (string) ($account->last_import_message ?: __('logistics.paypal_risk.messages.no_import_yet')),
            'history' => $history,
        ];
    }

    /**
     * Return only the metrics for the requested reporting period.
     *
     * The payment portal account board does not display cumulative metrics, so
     * avoiding the second all-history scan keeps its server-side filters fast.
     *
     * @return array<string,mixed>
     */
    public function currentSummary(LogisticsPayPalAccount $account, array $filters): array
    {
        return [
            'id' => (int) $account->getKey(),
            'account_name' => (string) $account->account_name,
            'merchant_id' => (string) ($account->merchant_id ?: '-'),
            'account_email' => (string) ($account->account_email ?: '-'),
            'masked_email' => $this->maskEmail($account->account_email),
            'environment' => (string) $account->environment,
            'is_enabled' => (bool) $account->is_enabled,
            ...$this->paymentMetrics($account, $filters),
            'last_imported_at' => $this->dateTime($account->last_imported_at),
            'last_import_status' => (string) ($account->last_import_status ?: 'never'),
            'last_import_message' => (string) ($account->last_import_message ?: __('logistics.paypal_risk.messages.no_import_yet')),
        ];
    }

    /** @param Collection<int,LogisticsPayPalAccount> $accounts
     *  @return array<string,float|int>
     */
    public function portfolio(Collection $accounts, array $filters): array
    {
        $summaries = $accounts->map(fn (LogisticsPayPalAccount $account): array => $this->summary($account, $filters));
        $orders = (int) $summaries->sum('order_count');
        $refunds = (int) $summaries->sum('refund_order_count');
        $disputes = (int) $summaries->sum('dispute_order_count');

        return [
            'accounts' => $accounts->count(),
            'orders' => $orders,
            'paid_amount' => round((float) $summaries->sum('paid_amount'), 2),
            'refund_rate' => $orders > 0 ? round($refunds / $orders * 100, 2) : 0.0,
            'dispute_rate' => $orders > 0 ? round($disputes / $orders * 100, 2) : 0.0,
        ];
    }

    /** @return array<string,mixed> */
    public function detail(
        LogisticsPayPalAccount $account,
        array $filters,
        string $tab,
        int $page = 1,
        string $sort = '',
        string $direction = '',
        array $tabFilters = [],
        bool $includeHistory = true,
        int $perPage = 10,
    ): array {
        $perPage = in_array($perPage, [10, 20, 50, 100], true) ? $perPage : 50;
        $payload = [
            'summary' => $includeHistory ? $this->summary($account, $filters) : $this->currentSummary($account, $filters),
            'tab' => $tab,
        ];

        return match ($tab) {
            'orders' => $payload + ['rows' => $this->orderRows($account, $filters, $page, $sort, $direction, $perPage, $tabFilters)],
            'products' => $payload + ['rows' => $this->productRows($account, $filters, $page, $sort, $direction, $perPage, $tabFilters)],
            'refunds' => $payload + ['rows' => $this->paginate($this->sortRows(
                $this->filterRows($this->refundRows($account, $filters), $tabFilters, ['refund_id', 'order_number', 'bill_numbers', 'store_name', 'source_reason', 'manual_reason', 'linked_disputes']),
                $sort,
                $direction,
                ['refund_id', 'order_number', 'store_name', 'source', 'amount', 'source_reason', 'manual_reason', 'status', 'linked_disputes', 'happened_at', 'match_status'],
            ), $page, $perPage)],
            'disputes' => $payload + ['rows' => $this->disputeRows($account, $filters, $page, $sort, $direction, $perPage, $tabFilters)],
            default => $payload + $this->overview($account, $filters),
        };
    }

    /** @return list<array<string,mixed>> */
    public function exportRows(LogisticsPayPalAccount $account, array $filters, string $tab, array $tabFilters = []): array
    {
        return match ($tab) {
            'orders' => $this->orderRows($account, $filters, 1, '', '', PHP_INT_MAX, $tabFilters)['data'],
            'products' => $this->productRows($account, $filters, 1, '', '', PHP_INT_MAX, $tabFilters)['data'],
            'refunds' => $this->filterRows($this->refundRows($account, $filters), $tabFilters, ['refund_id', 'order_number', 'bill_numbers', 'store_name', 'source_reason', 'manual_reason', 'linked_disputes']),
            'disputes' => $this->disputeRows($account, $filters, 1, '', '', PHP_INT_MAX, $tabFilters)['data'],
            default => [],
        };
    }

    /** @return array<string,mixed> */
    private function paymentMetrics(LogisticsPayPalAccount $account, array $filters, bool $applyRange = true): array
    {
        $payments = $this->paymentQuery($account, $filters, $applyRange)
            ->with('order:id,store_name,logistics_status')
            ->get();
        $groups = $payments->groupBy(fn (LogisticsPayPalTransaction $payment): string => (string) $payment->payment_transaction_id);
        $paymentIds = $payments->pluck('payment_transaction_id')->filter()->unique()->values()->all();
        $refunds = $this->refundQueryForPayments($account, $filters, $paymentIds, $applyRange)->get();
        $allDisputes = $this->disputeQueryForPayments($account, $filters, $paymentIds, $applyRange)->get();
        $disputes = $this->qualifyingDisputeQueryForPayments($account, $filters, $paymentIds, $applyRange)->get();
        $matchedGroups = $groups->filter(fn (Collection $group): bool => $group->contains(fn (LogisticsPayPalTransaction $payment): bool => $payment->logistics_order_id !== null));
        $matchedOrders = $matchedGroups
            ->map(fn (Collection $group) => $group->first(fn (LogisticsPayPalTransaction $payment): bool => $payment->order instanceof LogisticsOrder)?->order)
            ->filter(fn ($order): bool => $order instanceof LogisticsOrder)
            ->unique(fn (LogisticsOrder $order): int => (int) $order->getKey())
            ->values();
        $refundKeys = $refunds->map(fn (LogisticsPayPalRefund $refund): string => trim((string) ($refund->original_transaction_id ?: $refund->refund_transaction_id)))->filter()->unique();
        $disputeKeys = $disputes->map(fn (LogisticsPayPalDispute $dispute): string => trim((string) ($dispute->disputed_transaction_id ?: $dispute->dispute_id)))->filter()->unique();
        $overallDisputeKeys = $allDisputes->map(fn (LogisticsPayPalDispute $dispute): string => trim((string) ($dispute->disputed_transaction_id ?: $dispute->dispute_id)))->filter()->unique();
        $paidAmount = round((float) $payments->sum(fn (LogisticsPayPalTransaction $payment): float => (float) ($payment->payment_amount_usd ?? 0)), 2);
        $refundAmount = round((float) $refunds->sum(fn (LogisticsPayPalRefund $refund): float => (float) ($refund->refund_amount_usd ?? $refund->original_amount ?? 0)), 2);
        $disputeAmount = round((float) $disputes->sum(fn (LogisticsPayPalDispute $dispute): float => (float) ($dispute->dispute_amount_usd ?? $dispute->original_amount ?? 0)), 2);
        $shipped = $matchedOrders->filter(fn (LogisticsOrder $order): bool => $this->isActuallyShipped($order));
        $delivered = $shipped->filter(fn (LogisticsOrder $order): bool => $this->isDelivered($order));
        $unmatchedPayments = $groups->filter(fn (Collection $group): bool => $group->every(fn (LogisticsPayPalTransaction $payment): bool => $payment->logistics_order_id === null))->count();

        return [
            'order_count' => $groups->count(),
            'paid_amount' => $paidAmount,
            'refund_order_count' => $refundKeys->count(),
            'dispute_order_count' => $disputeKeys->count(),
            'overall_dispute_order_count' => $overallDisputeKeys->count(),
            'refund_rate' => $groups->isEmpty() ? 0.0 : round($refundKeys->count() / $groups->count() * 100, 2),
            'dispute_rate' => $groups->isEmpty() ? 0.0 : round($disputeKeys->count() / $groups->count() * 100, 2),
            'overall_dispute_rate' => $groups->isEmpty() ? 0.0 : round($overallDisputeKeys->count() / $groups->count() * 100, 2),
            'refund_amount' => $refundAmount,
            'refund_amount_rate' => $paidAmount <= 0
                ? 0.0
                : round($refundAmount / $paidAmount * 100, 2),
            'dispute_amount' => $disputeAmount,
            'dispute_amount_rate' => $paidAmount <= 0
                ? 0.0
                : round($disputeAmount / $paidAmount * 100, 2),
            'store_count' => $matchedOrders->pluck('store_name')->filter()->unique()->count(),
            'shipped_order_count' => $shipped->count(),
            'delivered_order_count' => $delivered->count(),
            // Delivery status exists only after an ERP order is safely matched.
            'shipping_rate' => $matchedGroups->isEmpty() ? 0.0 : round($shipped->count() / $matchedGroups->count() * 100, 2),
            'delivery_rate' => $matchedGroups->isEmpty() ? 0.0 : round($delivered->count() / $matchedGroups->count() * 100, 2),
            'unmatched_count' => $unmatchedPayments + $refunds->whereNull('logistics_order_id')->count() + $allDisputes->whereNull('logistics_order_id')->count(),
        ];
    }

    /** @return array<string,mixed> */
    private function overview(LogisticsPayPalAccount $account, array $filters): array
    {
        $payments = $this->paymentQuery($account, $filters)->get();
        $from = $this->rangeFrom($filters);
        $to = $this->rangeTo($filters);
        $end = $to !== ''
            ? Carbon::parse($to, self::REPORT_TIMEZONE)->endOfDay()
            : now(self::REPORT_TIMEZONE)->endOfDay();
        $start = $from !== ''
            ? Carbon::parse($from, self::REPORT_TIMEZONE)->startOfDay()
            : $end->copy()->subDays(29)->startOfDay();
        if ($start->diffInDays($end) > 30) {
            $start = $end->copy()->subDays(30)->startOfDay();
        }
        $trend = [];
        for ($cursor = $start->copy(); $cursor->lessThanOrEqualTo($end); $cursor->addDay()) {
            $trend[$cursor->format('Y-m-d')] = ['label' => $cursor->format('m-d'), 'orders' => 0, 'refunds' => 0, 'disputes' => 0];
        }
        // A payment transaction is the financial grain.  Keep the trend
        // consistent with the KPI denominator even if a historical import
        // happens to contain repeated source events for the same payment.
        foreach ($payments->unique('payment_transaction_id') as $payment) {
            $key = $payment->paid_at?->timezone(self::REPORT_TIMEZONE)->format('Y-m-d');
            if ($key && isset($trend[$key])) {
                $trend[$key]['orders']++;
            }
        }
        $seenDailyRefunds = [];
        foreach ($this->refundRows($account, $filters) as $refund) {
            $key = $this->dateKey($refund['happened_at'] ?? null);
            $identity = trim((string) ($refund['payment_transaction_id'] ?? '')) ?: (string) ($refund['refund_id'] ?? '');
            $dailyIdentity = $key.'|'.$identity;
            if ($key && isset($trend[$key]) && !isset($seenDailyRefunds[$dailyIdentity])) {
                $trend[$key]['refunds']++;
                $seenDailyRefunds[$dailyIdentity] = true;
            }
        }
        $seenDailyDisputes = [];
        foreach ($this->qualifyingDisputeQueryForPayments($account, $filters, $payments->pluck('payment_transaction_id')->all())->get() as $dispute) {
            $key = $dispute->dispute_created_at?->timezone(self::REPORT_TIMEZONE)->format('Y-m-d');
            $identity = trim((string) ($dispute->disputed_transaction_id ?? '')) ?: (string) $dispute->getKey();
            $dailyIdentity = $key.'|'.$identity;
            if ($key && isset($trend[$key]) && !isset($seenDailyDisputes[$dailyIdentity])) {
                $trend[$key]['disputes']++;
                $seenDailyDisputes[$dailyIdentity] = true;
            }
        }

        $exceptions = array_merge(
            array_map(fn (array $refund): array => [
                'order_id' => $refund['order_id'], 'order_detail_url' => $refund['order_detail_url'], 'order_number' => $refund['order_number'],
                'store_name' => $refund['store_name'], 'type' => __('logistics.paypal_risk.detail.refund'), 'amount' => $refund['amount'],
                'currency' => $refund['currency'], 'reason' => $refund['reason'], 'happened_at' => $refund['happened_at'], 'status' => $refund['status'], 'sort_time' => $refund['sort_time'],
            ], $this->refundRows($account, $filters)),
            array_map(fn (array $dispute): array => [
                'order_id' => $dispute['order_id'], 'order_detail_url' => $dispute['order_detail_url'], 'order_number' => $dispute['order_number'],
                'store_name' => $dispute['store_name'], 'type' => __('logistics.paypal_risk.detail.dispute'), 'amount' => $dispute['amount'],
                'currency' => $dispute['currency'], 'reason' => $dispute['reason'], 'happened_at' => $dispute['happened_at'], 'status' => $dispute['status'], 'sort_time' => $dispute['sort_time'],
            ], $this->disputeRows($account, $filters, 1, '', '', PHP_INT_MAX)['data']),
        );
        usort($exceptions, static fn (array $left, array $right): int => $right['sort_time'] <=> $left['sort_time']);

        return [
            'trend' => array_values($trend),
            'exceptions' => array_slice($exceptions, 0, 5),
        ];
    }

    /** @return array<string,mixed> */
    private function orderRows(LogisticsPayPalAccount $account, array $filters, int $page, string $sort, string $direction, int $perPage, array $tabFilters): array
    {
        $payments = $this->paymentQuery($account, $filters)
            ->with([
                'order:id,shopify_order_number,tongtu_order_number,store_name,bill_number,tracking_number,logistics_status',
                'order.items:id,logistics_order_id,product_title,variant_title',
                'order.paymentReferences:id,logistics_order_id,reference_number,reference_type,gateway_order_id',
            ])
            ->orderByDesc('paid_at')->orderByDesc('id')->get();
        $transactionIds = $payments->pluck('payment_transaction_id')->filter()->all();
        $refundIds = LogisticsPayPalRefund::query()->where('logistics_paypal_account_id', $account->getKey())->whereIn('original_transaction_id', $transactionIds)->pluck('original_transaction_id')->filter()->flip()->all();
        $disputeIds = LogisticsPayPalDispute::query()->where('logistics_paypal_account_id', $account->getKey())->whereIn('disputed_transaction_id', $transactionIds)->pluck('disputed_transaction_id')->filter()->flip()->all();

        $rows = $payments->map(function (LogisticsPayPalTransaction $payment) use ($refundIds, $disputeIds): array {
            $order = $payment->order;
            $products = $order instanceof LogisticsOrder
                ? $order->items->map(static fn (LogisticsOrderItem $item): string => trim($item->product_title.' '.$item->variant_title))->filter()->unique()->take(3)->implode(', ')
                : '';
            $matched = $order instanceof LogisticsOrder;

            return [
                'payment_transaction_id' => (string) $payment->payment_transaction_id,
                'match_status' => $matched ? __('logistics.paypal_risk.detail.matched') : __('logistics.paypal_risk.detail.unmatched'),
                'order_id' => $order?->getKey(),
                'order_detail_url' => $order ? route('logistics.orders.detail', $order) : null,
                'order_number' => (string) ($order?->shopify_order_number ?: '-'),
                'tongtu_order_number' => (string) ($order?->tongtu_order_number ?: '-'),
                'bill_numbers' => $this->orderBillNumbers($order),
                'store_name' => (string) ($order?->store_name ?: '-'),
                'products' => $products ?: '-',
                'paid_amount' => round((float) ($payment->payment_amount_usd ?? $payment->original_amount), 2),
                'tracking_number' => (string) ($order?->tracking_number ?: '-'),
                'logistics_status' => (string) ($order?->logistics_status ?: '-'),
                'refund_status' => isset($refundIds[$payment->payment_transaction_id]) ? __('logistics.paypal_risk.detail.refunded') : __('logistics.paypal_risk.detail.not_refunded'),
                'dispute_status' => isset($disputeIds[$payment->payment_transaction_id]) ? __('logistics.paypal_risk.detail.has_dispute') : __('logistics.paypal_risk.detail.no_dispute'),
                'paid_at' => $this->dateTime($payment->paid_at),
            ];
        })->all();

        $rows = $this->filterRows($rows, $tabFilters, ['payment_transaction_id', 'order_number', 'tongtu_order_number', 'bill_numbers', 'store_name', 'products', 'tracking_number', 'logistics_status', 'match_status']);

        return $this->paginate($this->sortRows($rows, $sort, $direction, [
            'payment_transaction_id', 'match_status', 'order_number', 'tongtu_order_number', 'bill_numbers', 'store_name', 'products', 'paid_amount', 'refund_status', 'dispute_status', 'tracking_number', 'logistics_status', 'paid_at',
        ]), $page, $perPage);
    }

    /** @return array<string,mixed> */
    private function productRows(LogisticsPayPalAccount $account, array $filters, int $page, string $sort, string $direction, int $perPage, array $tabFilters): array
    {
        $orders = $this->matchedOrdersForPayments($account, $filters);
        $orderIds = $orders->pluck('id')->all();
        if ($orderIds === []) {
            return $this->paginate([], $page, $perPage);
        }
        $refundSet = LogisticsPayPalRefund::query()->where('logistics_paypal_account_id', $account->getKey())->whereIn('logistics_order_id', $orderIds)->pluck('logistics_order_id')->filter()->flip()->all();
        $disputeSet = LogisticsPayPalDispute::query()->where('logistics_paypal_account_id', $account->getKey())->whereIn('logistics_order_id', $orderIds)->pluck('logistics_order_id')->filter()->flip()->all();
        $items = LogisticsOrderItem::query()->whereIn('logistics_order_id', $orderIds)->orderBy('id')->get();
        $groups = [];
        foreach ($items as $item) {
            $productCode = trim((string) $item->tongtu_product_code);
            $key = $productCode !== ''
                ? 'pcl:'.mb_strtolower($productCode)
                : mb_strtolower(trim((string) $item->product_title)).'|'.mb_strtolower(trim((string) ($item->tongtu_sku ?: $item->shopify_sku)));
            $groups[$key][] = $item;
        }
        $rows = [];
        foreach ($groups as $group) {
            /** @var LogisticsOrderItem $first */
            $first = $group[0];
            $groupOrderIds = collect($group)->pluck('logistics_order_id')->map(static fn ($id): int => (int) $id)->unique()->values();
            $orderCount = $groupOrderIds->count();
            $refundCount = $groupOrderIds->filter(static fn (int $id): bool => isset($refundSet[$id]))->count();
            $disputeCount = $groupOrderIds->filter(static fn (int $id): bool => isset($disputeSet[$id]))->count();
            $rows[] = [
                'image_url' => $first->product_image_url,
                'product_name' => trim((string) $first->product_title) ?: '-',
                'product_code' => trim((string) ($first->tongtu_product_code ?: $first->tongtu_sku ?: $first->shopify_sku)) ?: '-',
                // Keep the legacy key for the existing table contract; it now represents the product-level PCL.
                'sku' => trim((string) ($first->tongtu_product_code ?: $first->tongtu_sku ?: $first->shopify_sku)) ?: '-',
                'quantity' => round((float) collect($group)->sum('quantity'), 2),
                'paid_amount' => round((float) collect($group)->sum('line_revenue_usd'), 2),
                'refund_orders' => $refundCount,
                'dispute_orders' => $disputeCount,
                'refund_rate' => $orderCount > 0 ? round($refundCount / $orderCount * 100, 2) : 0.0,
                'dispute_rate' => $orderCount > 0 ? round($disputeCount / $orderCount * 100, 2) : 0.0,
            ];
        }
        usort($rows, static fn (array $left, array $right): int => $right['paid_amount'] <=> $left['paid_amount']);
        $rows = $this->filterRows($rows, $tabFilters, ['product_name', 'sku']);

        return $this->paginate($this->sortRows($rows, $sort, $direction, ['product_name', 'sku', 'quantity', 'paid_amount', 'refund_orders', 'refund_rate', 'dispute_orders', 'dispute_rate']), $page, $perPage);
    }

    /** @return list<array<string,mixed>> */
    private function refundRows(LogisticsPayPalAccount $account, array $filters): array
    {
        $paymentIds = $this->paymentQuery($account, $filters)->pluck('payment_transaction_id')->filter()->all();

        return $this->refundQueryForPayments($account, $filters, $paymentIds)
            ->with([
                'order:id,shopify_order_number,store_name,bill_number',
                'order.paymentReferences:id,logistics_order_id,reference_number,reference_type,gateway_order_id',
                'disputeLinks.dispute',
            ])
            ->orderByDesc('refunded_at')->orderByDesc('id')->get()
            ->map(fn (LogisticsPayPalRefund $refund): array => $this->paypalRefundRow($refund))
            ->all();
    }

    /** @return array<string,mixed> */
    private function paypalRefundRow(LogisticsPayPalRefund $refund): array
    {
        $order = $refund->order;
        $matched = $order instanceof LogisticsOrder;

        return [
            'order_id' => $order?->getKey(),
            'order_detail_url' => $order ? route('logistics.orders.detail', $order) : null,
            'refund_id' => (string) $refund->refund_transaction_id,
            'payment_transaction_id' => (string) $refund->original_transaction_id,
            'match_status' => $matched ? __('logistics.paypal_risk.detail.matched') : __('logistics.paypal_risk.detail.unmatched'),
            'order_number' => (string) ($order?->shopify_order_number ?: '-'),
            'bill_numbers' => $this->orderBillNumbers($order),
            'store_name' => (string) ($order?->store_name ?: '-'),
            'source' => 'PayPal',
            'amount' => round((float) ($refund->refund_amount_usd ?? $refund->original_amount), 2),
            'currency' => (string) ($refund->currency_code ?: 'USD'),
            'reason' => (string) ($refund->manual_reason ?: $refund->refund_reason ?: $refund->eventLabel()),
            'source_reason' => (string) ($refund->refund_reason ?: $refund->eventLabel()),
            'manual_reason' => (string) ($refund->manual_reason ?: ''),
            'status' => $refund->statusLabel(),
            'linked_disputes' => $refund->disputeLinks->map(fn ($link): string => (string) ($link->dispute?->dispute_id ?? ''))->filter()->unique()->values()->all(),
            'happened_at' => $this->dateTime($refund->refunded_at),
            'sort_time' => $refund->refunded_at?->timestamp ?? 0,
        ];
    }

    /** @return array<string,mixed> */
    private function disputeRows(LogisticsPayPalAccount $account, array $filters, int $page, string $sort, string $direction, int $perPage, array $tabFilters = []): array
    {
        $paymentIds = $this->paymentQuery($account, $filters)->pluck('payment_transaction_id')->filter()->all();
        $rows = $this->disputeQueryForPayments($account, $filters, $paymentIds)
            ->with([
                'order:id,shopify_order_number,store_name,bill_number',
                'order.paymentReferences:id,logistics_order_id,reference_number,reference_type,gateway_order_id',
                'refundLinks.refund',
            ])
            ->orderByDesc('dispute_created_at')->orderByDesc('id')->get()
            ->map(function (LogisticsPayPalDispute $dispute): array {
                $order = $dispute->order;
                return [
                    'order_id' => $order?->getKey(),
                    'order_detail_url' => $order ? route('logistics.orders.detail', $order) : null,
                    'dispute_id' => (string) $dispute->dispute_id,
                    'payment_transaction_id' => (string) ($dispute->disputed_transaction_id ?: '-'),
                    'match_status' => $order instanceof LogisticsOrder ? __('logistics.paypal_risk.detail.matched') : __('logistics.paypal_risk.detail.unmatched'),
                    'order_number' => (string) ($order?->shopify_order_number ?: '-'),
                    'bill_numbers' => $this->orderBillNumbers($order),
                    'store_name' => (string) ($order?->store_name ?: '-'),
                    'amount' => round((float) ($dispute->dispute_amount_usd ?? $dispute->original_amount), 2),
                    'currency' => (string) ($dispute->currency_code ?: 'USD'),
                    'reason' => $dispute->reasonLabel(),
                    'stage' => $dispute->typeLabel(),
                    'status' => $dispute->statusLabel(),
                    'happened_at' => $this->dateTime($dispute->dispute_created_at),
                    'updated_at' => $this->dateTime($dispute->dispute_updated_at),
                    'seller_response_due_at' => $this->dateTime($dispute->seller_response_due_at),
                    'outcome' => $dispute->outcomeLabel(),
                    'linked_refunds' => $dispute->refundLinks->map(fn ($link): array => [
                        'refund_id' => (string) ($link->refund?->refund_transaction_id ?: '-'),
                        'amount' => round((float) ($link->refund?->refund_amount_usd ?? $link->refund?->original_amount ?? 0), 2),
                    ])->values()->all(),
                    'sort_time' => $dispute->dispute_created_at?->timestamp ?? 0,
                ];
            })->all();
        $rows = $this->filterRows($rows, $tabFilters, ['dispute_id', 'payment_transaction_id', 'order_number', 'bill_numbers', 'store_name', 'reason', 'stage', 'status', 'outcome', 'linked_refunds', 'match_status']);

        return $this->paginate($this->sortRows($rows, $sort, $direction, ['dispute_id', 'payment_transaction_id', 'match_status', 'order_number', 'bill_numbers', 'store_name', 'amount', 'reason', 'stage', 'status', 'happened_at', 'outcome', 'seller_response_due_at', 'updated_at', 'linked_refunds']), $page, $perPage);
    }

    private function orderBillNumbers(?LogisticsOrder $order): string
    {
        if (!$order instanceof LogisticsOrder) {
            return '-';
        }

        $references = $order->relationLoaded('paymentReferences')
            ? $order->paymentReferences
            : collect();
        $primary = $references
            ->where('reference_type', LogisticsOrderPaymentReference::TYPE_PRIMARY)
            ->pluck('reference_number');
        $upsells = $references
            ->where('reference_type', LogisticsOrderPaymentReference::TYPE_UPSELL)
            ->pluck('reference_number');
        $legacy = trim((string) $order->bill_number);

        $numbers = collect($legacy !== '' ? [$legacy] : [])
            ->concat($primary)
            ->concat($upsells)
            ->map(static fn ($number): string => trim((string) $number))
            ->filter()
            ->unique()
            ->values();

        return $numbers->isEmpty() ? '-' : $numbers->implode("\n");
    }

    private function paymentQuery(LogisticsPayPalAccount $account, array $filters, bool $applyRange = true): Builder
    {
        $query = LogisticsPayPalTransaction::query()
            ->where('logistics_paypal_account_id', $account->getKey())
            ->where('event_code', 'like', 'T00%')
            ->where('status', 'S');
        if ($applyRange) {
            $this->applyRange($query, 'paid_at', $filters);
        }

        return $query;
    }

    /** @param list<string> $paymentIds */
    private function refundQueryForPayments(LogisticsPayPalAccount $account, array $filters, array $paymentIds, bool $applyRange = true): Builder
    {
        $query = LogisticsPayPalRefund::query()->where('logistics_paypal_account_id', $account->getKey());
        if (!$applyRange) {
            return $query;
        }
        if ($this->usesEventDates($filters)) {
            $this->applyRange($query, 'refunded_at', $filters);
        } elseif ($paymentIds === []) {
            $query->whereRaw('1 = 0');
        } else {
            $query->whereIn('original_transaction_id', $paymentIds);
        }

        return $query;
    }

    /** @param list<string> $paymentIds */
    private function disputeQueryForPayments(LogisticsPayPalAccount $account, array $filters, array $paymentIds, bool $applyRange = true): Builder
    {
        $query = LogisticsPayPalDispute::query()->where('logistics_paypal_account_id', $account->getKey());
        if (!$applyRange) {
            return $query;
        }
        if ($this->usesEventDates($filters)) {
            $this->applyRange($query, 'dispute_created_at', $filters);
        } elseif ($paymentIds === []) {
            $query->whereRaw('1 = 0');
        } else {
            $query->whereIn('disputed_transaction_id', $paymentIds);
        }

        return $query;
    }

    /** @param list<string> $paymentIds */
    private function qualifyingDisputeQueryForPayments(LogisticsPayPalAccount $account, array $filters, array $paymentIds, bool $applyRange = true): Builder
    {
        return $this->disputeQueryForPayments($account, $filters, $paymentIds, $applyRange)
            ->whereIn('reason', self::QUALIFYING_DISPUTE_REASONS)
            ->whereIn('life_cycle_stage', self::QUALIFYING_DISPUTE_TYPES);
    }

    /** @return Collection<int,LogisticsOrder> */
    private function matchedOrdersForPayments(LogisticsPayPalAccount $account, array $filters): Collection
    {
        $orderIds = $this->paymentQuery($account, $filters)
            ->whereNotNull('logistics_order_id')->pluck('logistics_order_id')->filter()->unique()->values()->all();

        return $orderIds === [] ? collect() : LogisticsOrder::query()->whereIn('id', $orderIds)->get();
    }

    private function applyRange(Builder $query, string $column, array $filters): void
    {
        $from = $this->rangeFrom($filters);
        $to = $this->rangeTo($filters);
        if ($from !== '') {
            $query->where(
                $column,
                '>=',
                Carbon::parse($from, self::REPORT_TIMEZONE)->startOfDay()->utc()->toDateTimeString(),
            );
        }
        if ($to !== '') {
            $query->where(
                $column,
                '<',
                Carbon::parse($to, self::REPORT_TIMEZONE)->addDay()->startOfDay()->utc()->toDateTimeString(),
            );
        }
    }

    private function usesEventDates(array $filters): bool
    {
        return ($filters['date_mode'] ?? '') === 'event';
    }

    private function rangeFrom(array $filters): string
    {
        return trim((string) ($this->usesEventDates($filters) ? ($filters['event_date_from'] ?? '') : ($filters['date_from'] ?? '')));
    }

    private function rangeTo(array $filters): string
    {
        return trim((string) ($this->usesEventDates($filters) ? ($filters['event_date_to'] ?? '') : ($filters['date_to'] ?? '')));
    }

    private function dateKey(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return Carbon::parse($value)->timezone(self::REPORT_TIMEZONE)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    private function isActuallyShipped(LogisticsOrder $order): bool
    {
        return in_array((string) $order->logistics_status, [
            LogisticsOrder::LOGISTICS_IN_TRANSIT,
            LogisticsOrder::LOGISTICS_EXCEPTION,
            LogisticsOrder::LOGISTICS_DELIVERED,
        ], true);
    }

    private function isDelivered(LogisticsOrder $order): bool
    {
        return (string) $order->logistics_status === LogisticsOrder::LOGISTICS_DELIVERED;
    }

    /** @param list<array<string,mixed>> $rows
     *  @param list<string> $keywordColumns
     *  @return list<array<string,mixed>>
     */
    private function filterRows(array $rows, array $filters, array $keywordColumns): array
    {
        $keyword = mb_strtolower(trim((string) ($filters['keyword'] ?? '')));
        $status = trim((string) ($filters['status'] ?? ''));
        $type = trim((string) ($filters['type'] ?? ''));
        $outcome = trim((string) ($filters['outcome'] ?? ''));
        $matchStatus = match (trim((string) ($filters['match_status'] ?? ''))) {
            'matched' => __('logistics.paypal_risk.detail.matched'),
            'unmatched' => __('logistics.paypal_risk.detail.unmatched'),
            default => '',
        };

        return array_values(array_filter($rows, static function (array $row) use ($keyword, $status, $type, $outcome, $matchStatus, $keywordColumns): bool {
            if ($keyword !== '') {
                $found = false;
                foreach ($keywordColumns as $column) {
                    $value = $row[$column] ?? '';
                    $text = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : (string) $value;
                    if (str_contains(mb_strtolower((string) $text), $keyword)) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    return false;
                }
            }

            return ($status === '' || (string) ($row['status'] ?? '') === $status)
                && ($type === '' || (string) ($row['stage'] ?? '') === $type)
                && ($outcome === '' || (string) ($row['outcome'] ?? '') === $outcome)
                && ($matchStatus === '' || (string) ($row['match_status'] ?? '') === $matchStatus);
        }));
    }

    /** @param list<array<string,mixed>> $rows
     *  @param list<string> $allowed
     *  @return list<array<string,mixed>>
     */
    private function sortRows(array $rows, string $sort, string $direction, array $allowed): array
    {
        if ($sort === '' || !in_array($sort, $allowed, true) || !in_array($direction, ['asc', 'desc'], true)) {
            return $rows;
        }
        foreach ($rows as $index => &$row) {
            $row['_index'] = $index;
        }
        unset($row);
        usort($rows, static function (array $left, array $right) use ($sort, $direction): int {
            $a = $left[$sort] ?? null;
            $b = $right[$sort] ?? null;
            if (is_array($a)) {
                $a = json_encode($a, JSON_UNESCAPED_UNICODE);
            }
            if (is_array($b)) {
                $b = json_encode($b, JSON_UNESCAPED_UNICODE);
            }
            $blankA = $a === null || $a === '' || $a === '-';
            $blankB = $b === null || $b === '' || $b === '-';
            if ($blankA !== $blankB) {
                return $blankA ? 1 : -1;
            }
            $comparison = is_numeric($a) && is_numeric($b) ? ((float) $a <=> (float) $b) : strnatcasecmp((string) $a, (string) $b);
            if ($comparison === 0) {
                return $left['_index'] <=> $right['_index'];
            }
            return $direction === 'asc' ? $comparison : -$comparison;
        });
        foreach ($rows as &$row) {
            unset($row['_index']);
        }
        unset($row);
        return $rows;
    }

    /** @param list<array<string,mixed>> $rows
     *  @return array{data:list<array<string,mixed>>,meta:array<string,int>}
     */
    private function paginate(array $rows, int $page, int $perPage = 10): array
    {
        $total = count($rows);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($lastPage, $page));
        return [
            'data' => array_slice($rows, ($page - 1) * $perPage, $perPage),
            'meta' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'total' => $total,
                'per_page' => $perPage,
                'from' => $total === 0 ? 0 : (($page - 1) * $perPage + 1),
                'to' => min($total, $page * $perPage),
            ],
        ];
    }

    private function maskEmail(mixed $email): string
    {
        $email = trim((string) ($email ?? ''));
        if (!str_contains($email, '@')) {
            return $email ?: '-';
        }
        [$local, $domain] = explode('@', $email, 2);
        return mb_substr($local, 0, min(2, mb_strlen($local))).'***@'.$domain;
    }

    private function dateTime(mixed $value): string
    {
        if (!$value) {
            return '-';
        }
        try {
            return Carbon::parse($value)->timezone(self::REPORT_TIMEZONE)->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return '-';
        }
    }
}
