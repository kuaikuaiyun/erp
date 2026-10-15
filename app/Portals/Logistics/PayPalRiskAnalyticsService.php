<?php

namespace App\Portals\Logistics;

use App\Models\LogisticsOrder;
use App\Models\LogisticsOrderItem;
use App\Models\LogisticsPayPalAccount;
use App\Models\LogisticsPayPalDispute;
use App\Models\LogisticsPayPalRefund;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class PayPalRiskAnalyticsService
{
    /** @param array{date_from:string,date_to:string} $filters
     *  @return array<string,mixed>
     */
    public function summary(LogisticsPayPalAccount $account, array $filters): array
    {
        $orders = $this->orderQuery($account, $filters)
            ->get(['id', 'store_name', 'paid_amount_usd', 'logistics_status']);
        $orderIds = $orders->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $refundIds = $this->refundOrderIds($orderIds, $account, $filters);
        $disputeIds = $this->disputeOrderIds($orderIds, $account, $filters);
        $orderCount = count($orderIds);
        $refundCount = count($refundIds);
        $disputeCount = count($disputeIds);
        $refundRate = $orderCount > 0 ? round($refundCount / $orderCount * 100, 2) : 0.0;
        $disputeRate = $orderCount > 0 ? round($disputeCount / $orderCount * 100, 2) : 0.0;
        $score = max(0.0, min(100.0, round(100 - $refundRate * 2 - $disputeRate * 10, 1)));
        $shippedOrders = $orders->filter(fn (LogisticsOrder $order): bool => $this->isActuallyShipped($order));
        $deliveredOrders = $shippedOrders->filter(fn (LogisticsOrder $order): bool => $this->isDelivered($order));

        return [
            'id' => (int) $account->getKey(),
            'account_name' => (string) $account->account_name,
            'merchant_id' => (string) ($account->merchant_id ?: '—'),
            'account_email' => (string) ($account->account_email ?: '—'),
            'masked_email' => $this->maskEmail($account->account_email),
            'environment' => (string) $account->environment,
            'is_enabled' => (bool) $account->is_enabled,
            'order_count' => $orderCount,
            'paid_amount' => round((float) $orders->sum('paid_amount_usd'), 2),
            'refund_order_count' => $refundCount,
            'dispute_order_count' => $disputeCount,
            'refund_rate' => $refundRate,
            'dispute_rate' => $disputeRate,
            'refund_amount' => $this->canonicalRefundAmount($orderIds, $account, $filters),
            'dispute_amount' => $this->canonicalDisputeAmount($orderIds, $account, $filters),
            'store_count' => $orders->pluck('store_name')->filter()->unique()->count(),
            'shipped_order_count' => $shippedOrders->count(),
            'delivered_order_count' => $deliveredOrders->count(),
            'shipping_rate' => $orderCount > 0 ? round($shippedOrders->count() / $orderCount * 100, 2) : 0.0,
            // 发货率、签收率使用当前筛选范围内的已付款订单数作为统一分母。
            'delivery_rate' => $orders->isEmpty() ? 0.0 : round($deliveredOrders->count() / $orders->count() * 100, 2),
            'score' => $score,
            'risk' => $this->riskLevel($score),
            'last_imported_at' => $this->dateTime($account->last_imported_at),
            'last_import_status' => (string) ($account->last_import_status ?: 'never'),
            'last_import_message' => (string) ($account->last_import_message ?: '尚未接收 Windows VPS 上传的 PayPal 数据。'),
            'unmatched_count' => LogisticsPayPalRefund::query()
                ->where('logistics_paypal_account_id', $account->getKey())
                ->whereNull('logistics_order_id')
                ->count()
                + LogisticsPayPalDispute::query()
                    ->where('logistics_paypal_account_id', $account->getKey())
                    ->whereNull('logistics_order_id')
                    ->count(),
        ];
    }

    /** @param Collection<int,LogisticsPayPalAccount> $accounts
     *  @param array{date_from:string,date_to:string} $filters
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

    /** @param array{date_from:string,date_to:string} $filters
     *  @return array<string,mixed>
     */
    public function detail(
        LogisticsPayPalAccount $account,
        array $filters,
        string $tab,
        int $page = 1,
        string $sort = '',
        string $direction = '',
    ): array {
        $summary = $this->summary($account, $filters);
        $payload = ['summary' => $summary, 'tab' => $tab];

        return match ($tab) {
            'orders' => $payload + ['rows' => $this->orderRows($account, $filters, $page, $sort, $direction)],
            'products' => $payload + ['rows' => $this->productRows($account, $filters, $page, $sort, $direction)],
            'refunds' => $payload + ['rows' => $this->paginate($this->sortRows($this->refundRows($account, $filters), $sort, $direction, [
                'refund_id', 'order_number', 'store_name', 'source', 'amount', 'reason', 'source_reason', 'manual_reason',
                'status', 'linked_disputes', 'happened_at',
            ]), $page)],
            'disputes' => $payload + ['rows' => $this->disputeRows($account, $filters, $page, $sort, $direction)],
            default => $payload + $this->overview($account, $filters),
        };
    }

    /**
     * Return all rows for one detail tab.  Exports intentionally use the
     * same filter and calculation path as the modal so the two views cannot
     * disagree merely because of different query logic.
     *
     * @return list<array<string,mixed>>
     */
    public function exportRows(LogisticsPayPalAccount $account, array $filters, string $tab): array
    {
        return match ($tab) {
            'orders' => $this->orderRows($account, $filters, 1, '', '', PHP_INT_MAX)['data'],
            'products' => $this->productRows($account, $filters, 1, '', '', PHP_INT_MAX)['data'],
            'refunds' => $this->refundRows($account, $filters),
            'disputes' => $this->disputeRows($account, $filters, 1, '', '', PHP_INT_MAX)['data'],
            default => [],
        };
    }

    /** @param array{date_from:string,date_to:string} $filters
     *  @return array<string,mixed>
     */
    private function overview(LogisticsPayPalAccount $account, array $filters): array
    {
        $dateColumn = $this->usesEventDates($filters) ? 'paid_at' : 'order_created_at';
        $orders = $this->orderQuery($account, $filters)
            ->orderBy($dateColumn)
            ->get(['id', 'shopify_order_number', 'store_name', 'paid_amount_usd', 'order_created_at', 'paid_at']);
        $orderIds = $orders->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $refundSet = array_fill_keys($this->refundOrderIds($orderIds, $account, $filters), true);
        $disputeSet = array_fill_keys($this->disputeOrderIds($orderIds, $account, $filters), true);
        $rangeTo = $this->rangeTo($filters);
        $rangeFrom = $this->rangeFrom($filters);
        $end = $rangeTo !== '' ? Carbon::parse($rangeTo)->endOfDay() : now();
        $start = $rangeFrom !== '' ? Carbon::parse($rangeFrom)->startOfDay() : $end->copy()->subDays(29)->startOfDay();
        if ($start->diffInDays($end) > 29) {
            $start = $end->copy()->subDays(29)->startOfDay();
        }
        $trend = [];
        for ($cursor = $start->copy(); $cursor->lessThanOrEqualTo($end); $cursor->addDay()) {
            $trend[$cursor->format('Y-m-d')] = [
                'label' => $cursor->format('m-d'),
                'orders' => 0,
                'refunds' => 0,
                'disputes' => 0,
            ];
        }
        foreach ($orders as $order) {
            $date = ($this->usesEventDates($filters) ? $order->paid_at : $order->order_created_at)
                ?->timezone('Asia/Shanghai')
                ->format('Y-m-d');
            if (!$date || !isset($trend[$date])) {
                continue;
            }
            $trend[$date]['orders']++;
            if (!$this->usesEventDates($filters)) {
                $trend[$date]['refunds'] += isset($refundSet[$order->getKey()]) ? 1 : 0;
                $trend[$date]['disputes'] += isset($disputeSet[$order->getKey()]) ? 1 : 0;
            }
        }
        if ($this->usesEventDates($filters)) {
            foreach ($this->refundRows($account, $filters) as $refund) {
                $date = $this->dateKey($refund['happened_at'] ?? null);
                if ($date && isset($trend[$date])) {
                    $trend[$date]['refunds']++;
                }
            }
            $this->disputesForFilter($account, $filters)
                ->get(['dispute_created_at'])
                ->each(function (LogisticsPayPalDispute $dispute) use (&$trend): void {
                    $date = $dispute->dispute_created_at?->timezone('Asia/Shanghai')->format('Y-m-d');
                    if ($date && isset($trend[$date])) {
                        $trend[$date]['disputes']++;
                    }
                });
        }

        $summary = $this->summary($account, $filters);
        $riskParts = [
            ['label' => '退款订单风险', 'value' => min(100, round((float) $summary['refund_rate'] * 2, 1)), 'tone' => 'refund'],
            ['label' => 'PayPal纠纷风险', 'value' => min(100, round((float) $summary['dispute_rate'] * 10, 1)), 'tone' => 'dispute'],
            ['label' => '未匹配交易风险', 'value' => min(100, (int) $summary['unmatched_count'] * 5), 'tone' => 'unmatched'],
        ];
        usort($riskParts, static fn (array $left, array $right): int => $right['value'] <=> $left['value']);

        $exceptions = array_merge(
            array_map(static fn (array $row): array => [
                'order_id' => $row['order_id'],
                'order_detail_url' => $row['order_detail_url'],
                'order_number' => $row['order_number'],
                'store_name' => $row['store_name'],
                'type' => '退款',
                'amount' => $row['amount'],
                'currency' => $row['currency'],
                'reason' => $row['reason'],
                'happened_at' => $row['happened_at'],
                'status' => $row['status'],
                'sort_time' => $row['sort_time'],
            ], $this->refundRows($account, $filters)),
            $this->disputesForFilter($account, $filters)
                ->with('order:id,shopify_order_number,store_name')
                ->get()
                ->map(fn (LogisticsPayPalDispute $dispute): array => [
                    'order_id' => $dispute->order?->getKey(),
                    'order_detail_url' => $dispute->order ? route('logistics.orders.detail', $dispute->order) : null,
                    'order_number' => $dispute->order?->shopify_order_number ?: '未匹配',
                    'store_name' => $dispute->order?->store_name ?: '—',
                    'type' => '纠纷',
                    'amount' => $dispute->dispute_amount_usd !== null ? (float) $dispute->dispute_amount_usd : (float) $dispute->original_amount,
                    'currency' => (string) ($dispute->currency_code ?: 'USD'),
                    'reason' => $this->disputeReason($dispute->reason),
                    'happened_at' => $this->dateTime($dispute->dispute_created_at),
                    'status' => $this->disputeStatus($dispute->status),
                    'sort_time' => $dispute->dispute_created_at?->timestamp ?? 0,
                ])->all(),
        );
        usort($exceptions, static fn (array $left, array $right): int => $right['sort_time'] <=> $left['sort_time']);

        return [
            'trend' => array_values($trend),
            'risk_parts' => $riskParts,
            'exceptions' => array_slice($exceptions, 0, 5),
        ];
    }

    /** @param array{date_from:string,date_to:string} $filters */
    private function orderRows(LogisticsPayPalAccount $account, array $filters, int $page, string $sort, string $direction, int $perPage = 10): array
    {
        $orders = $this->orderQuery($account, $filters)
            ->with([
                'items:id,logistics_order_id,product_title,variant_title',
                'paypalRefunds' => static function ($query) use ($account): void {
                    $query->where('logistics_paypal_account_id', $account->getKey());
                },
                'paypalDisputes' => static function ($query) use ($account): void {
                    $query->where('logistics_paypal_account_id', $account->getKey());
                },
            ])
            ->orderByDesc('order_created_at')
            ->orderByDesc('id')
            ->get();

        $rows = $orders->map(static function (LogisticsOrder $order): array {
                $products = $order->items->map(static fn (LogisticsOrderItem $item): string => trim($item->product_title.' '.$item->variant_title))->filter()->unique()->take(3)->implode('；');
                $hasRefund = $order->paypalRefunds->isNotEmpty();

                return [
                    'order_id' => (int) $order->getKey(),
                    'order_detail_url' => route('logistics.orders.detail', $order),
                    'order_number' => (string) ($order->shopify_order_number ?: '—'),
                    'tongtu_order_number' => (string) ($order->tongtu_order_number ?: '—'),
                    'store_name' => (string) ($order->store_name ?: '—'),
                    'products' => $products ?: '—',
                    'paid_amount' => round((float) $order->paid_amount_usd, 2),
                    'tracking_number' => (string) ($order->tracking_number ?: '—'),
                    'logistics_status' => (string) ($order->logistics_status ?: '—'),
                    'refund_status' => $hasRefund ? '已退款' : '未退款',
                    'dispute_status' => $order->paypalDisputes->isNotEmpty() ? '有纠纷' : '无纠纷',
                    'order_created_at' => $order->order_created_at?->timezone('Asia/Shanghai')->format('Y-m-d H:i:s') ?: '—',
                ];
            })->all();

        return $this->paginate($this->sortRows($rows, $sort, $direction, [
            'order_number', 'tongtu_order_number', 'store_name', 'products', 'paid_amount', 'refund_status',
            'dispute_status', 'tracking_number', 'logistics_status', 'order_created_at',
        ]), $page, $perPage);
    }

    /** @param array{date_from:string,date_to:string} $filters */
    private function productRows(LogisticsPayPalAccount $account, array $filters, int $page, string $sort, string $direction, int $perPage = 10): array
    {
        $itemsQuery = LogisticsOrderItem::query()
            ->selectRaw('logistics_order_items.*, logistics_orders.id as risk_order_id, logistics_orders.paid_amount_usd as risk_order_paid_amount')
            ->join('logistics_orders', 'logistics_orders.id', '=', 'logistics_order_items.logistics_order_id')
            ->where('logistics_orders.paypal_account_id', $account->getKey())
            ->where('logistics_orders.paypal_association_source', 'actual_transaction')
            ->orderBy('logistics_order_items.id');
        $dateColumn = $this->usesEventDates($filters)
            ? 'logistics_orders.paid_at'
            : 'logistics_orders.order_created_at';
        if ($this->rangeFrom($filters) !== '') {
            $itemsQuery->whereDate($dateColumn, '>=', $this->rangeFrom($filters));
        }
        if ($this->rangeTo($filters) !== '') {
            $itemsQuery->whereDate($dateColumn, '<=', $this->rangeTo($filters));
        }
        $items = $itemsQuery->get();
        $orderIds = $items->pluck('risk_order_id')->map(static fn ($id): int => (int) $id)->unique()->values()->all();
        $refundSet = array_fill_keys($this->refundOrderIds($orderIds, $account, $filters), true);
        $disputeSet = array_fill_keys($this->disputeOrderIds($orderIds, $account, $filters), true);
        $groups = [];
        foreach ($items as $item) {
            $key = mb_strtolower(trim((string) $item->product_title)).'|'.mb_strtolower(trim((string) ($item->tongtu_sku ?: $item->shopify_sku)));
            $groups[$key] ??= [];
            $groups[$key][] = $item;
        }
        $rows = [];
        foreach ($groups as $group) {
            /** @var LogisticsOrderItem $first */
            $first = $group[0];
            $groupOrderIds = collect($group)->pluck('risk_order_id')->map(static fn ($id): int => (int) $id)->unique()->values();
            $refundCount = $groupOrderIds->filter(static fn (int $id): bool => isset($refundSet[$id]))->count();
            $disputeCount = $groupOrderIds->filter(static fn (int $id): bool => isset($disputeSet[$id]))->count();
            $orderCount = $groupOrderIds->count();
            $rows[] = [
                'image_url' => $first->product_image_url,
                'product_name' => trim((string) $first->product_title) ?: '未命名商品',
                'sku' => trim((string) ($first->tongtu_sku ?: $first->shopify_sku)) ?: '—',
                'quantity' => round((float) collect($group)->sum('quantity'), 2),
                'paid_amount' => round((float) collect($group)->sum('line_revenue_usd'), 2),
                'refund_orders' => $refundCount,
                'dispute_orders' => $disputeCount,
                'refund_rate' => $orderCount > 0 ? round($refundCount / $orderCount * 100, 2) : 0.0,
                'dispute_rate' => $orderCount > 0 ? round($disputeCount / $orderCount * 100, 2) : 0.0,
            ];
        }
        usort($rows, static fn (array $left, array $right): int => $right['paid_amount'] <=> $left['paid_amount']);

        return $this->paginate($this->sortRows($rows, $sort, $direction, [
            'product_name', 'sku', 'quantity', 'paid_amount', 'refund_orders', 'refund_rate',
            'dispute_orders', 'dispute_rate',
        ]), $page, $perPage);
    }

    /** @param array{date_from:string,date_to:string} $filters
     *  @return list<array<string,mixed>>
     */
    private function refundRows(LogisticsPayPalAccount $account, array $filters): array
    {
        if ($this->usesEventDates($filters)) {
            $query = LogisticsPayPalRefund::query()
                ->with(['order:id,shopify_order_number,store_name,bill_number', 'disputeLinks.dispute'])
                ->where('logistics_paypal_account_id', $account->getKey());
            $this->applyEventDates($query, 'refunded_at', $filters);

            return $query->orderByDesc('refunded_at')->orderByDesc('id')
                ->get()
                ->map(fn (LogisticsPayPalRefund $refund): array => $this->paypalRefundRow($refund, $refund->order))
                ->all();
        }

        $orders = $this->orderQuery($account, $filters)
            ->with(['paypalRefunds' => function ($query) use ($account): void {
                $query->where('logistics_paypal_account_id', $account->getKey())
                    ->with('disputeLinks.dispute');
            }])
            ->get(['id', 'shopify_order_number', 'store_name', 'bill_number']);
        $rows = [];
        foreach ($orders as $order) {
            foreach ($order->paypalRefunds as $refund) {
                $rows[] = $this->paypalRefundRow($refund, $order);
            }
        }

        $unmatched = LogisticsPayPalRefund::query()
            ->where('logistics_paypal_account_id', $account->getKey())
            ->whereNull('logistics_order_id');
        $this->applyEventDates($unmatched, 'refunded_at', $filters);
        foreach ($unmatched->get() as $refund) {
            $rows[] = $this->paypalRefundRow($refund, null);
        }
        usort($rows, static fn (array $left, array $right): int => $right['sort_time'] <=> $left['sort_time']);

        return $rows;
    }

    private function paypalRefundRow(LogisticsPayPalRefund $refund, ?LogisticsOrder $order): array
    {
        return [
            'order_id' => $order?->getKey(),
            'order_detail_url' => $order ? route('logistics.orders.detail', $order) : null,
            'refund_id' => (string) $refund->refund_transaction_id,
            'order_number' => (string) ($order?->shopify_order_number ?: '未匹配'),
            'store_name' => (string) ($order?->store_name ?: '—'),
            'source' => 'PayPal',
            'amount' => round((float) ($refund->refund_amount_usd ?? $refund->original_amount), 2),
            'currency' => (string) ($refund->currency_code ?: 'USD'),
            'reason' => (string) ($refund->manual_reason ?: $refund->refund_reason ?: $refund->eventLabel()),
            'source_reason' => (string) ($refund->refund_reason ?: $refund->eventLabel()),
            'manual_reason' => (string) ($refund->manual_reason ?: ''),
            'status' => $refund->statusLabel(),
            'linked_disputes' => $refund->disputeLinks
                ->map(fn ($link): string => (string) ($link->dispute?->dispute_id ?? ''))
                ->filter()->unique()->values()->all(),
            'happened_at' => $this->dateTime($refund->refunded_at),
            'sort_time' => $refund->refunded_at?->timestamp ?? 0,
        ];
    }

    /** @param array{date_from:string,date_to:string} $filters */
    private function disputeRows(LogisticsPayPalAccount $account, array $filters, int $page, string $sort, string $direction, int $perPage = 10): array
    {
        $query = $this->disputesForFilter($account, $filters)
            ->with(['order:id,shopify_order_number,store_name,bill_number', 'refundLinks.refund'])
            ->orderByDesc('dispute_created_at')
            ->orderByDesc('id');
        $rows = $query->get()->map(fn (LogisticsPayPalDispute $dispute): array => [
                'order_id' => $dispute->order?->getKey(),
                'order_detail_url' => $dispute->order ? route('logistics.orders.detail', $dispute->order) : null,
                'dispute_id' => (string) $dispute->dispute_id,
                'order_number' => (string) ($dispute->order?->shopify_order_number ?: '未匹配'),
                'store_name' => (string) ($dispute->order?->store_name ?: '—'),
                'amount' => round((float) ($dispute->dispute_amount_usd ?? $dispute->original_amount), 2),
                'currency' => (string) ($dispute->currency_code ?: 'USD'),
                'reason' => $this->disputeReason($dispute->reason),
                'stage' => $this->disputeStage($dispute->life_cycle_stage),
                'status' => $this->disputeStatus($dispute->status),
                'happened_at' => $this->dateTime($dispute->dispute_created_at),
                'updated_at' => $this->dateTime($dispute->dispute_updated_at),
                'seller_response_due_at' => $this->dateTime($dispute->seller_response_due_at),
                'outcome' => (string) ($dispute->outcome_code ?: '—'),
                'linked_refunds' => $dispute->refundLinks->map(fn ($link): array => [
                    'refund_id' => (string) ($link->refund?->refund_transaction_id ?: '—'),
                    'amount' => round((float) ($link->refund?->refund_amount_usd ?? $link->refund?->original_amount ?? 0), 2),
                ])->values()->all(),
            ])->all();

        return $this->paginate($this->sortRows($rows, $sort, $direction, [
            'dispute_id', 'order_number', 'store_name', 'amount', 'reason', 'stage', 'status', 'happened_at',
            'outcome', 'seller_response_due_at', 'updated_at', 'linked_refunds',
        ]), $page, $perPage);
    }

    private function disputesForFilter(LogisticsPayPalAccount $account, array $filters): Builder
    {
        $query = LogisticsPayPalDispute::query()
            ->where('logistics_paypal_account_id', $account->getKey());
        if ($this->usesEventDates($filters)) {
            $this->applyEventDates($query, 'dispute_created_at', $filters);

            return $query;
        }

        return $query->where(function (Builder $query) use ($filters): void {
            $query->whereHas('order', function (Builder $orders) use ($filters): void {
                $this->applyDates($orders, $filters);
            })->orWhere(function (Builder $unmatched) use ($filters): void {
                $unmatched->whereNull('logistics_order_id');
                $this->applyEventDates($unmatched, 'dispute_created_at', $filters);
            });
        });
    }

    /** @param list<int> $orderIds
     *  @return list<int>
     */
    private function refundOrderIds(array $orderIds, LogisticsPayPalAccount $account, array $filters): array
    {
        if ($orderIds === [] && !$this->usesEventDates($filters)) {
            return [];
        }
        $paypal = LogisticsPayPalRefund::query()
            ->where('logistics_paypal_account_id', $account->getKey());
        if ($this->usesEventDates($filters)) {
            $this->applyEventDates($paypal, 'refunded_at', $filters);
        } else {
            $paypal->whereIn('logistics_order_id', $orderIds);
        }
        return $paypal
            ->distinct()
            ->pluck('logistics_order_id')
            ->filter(static fn ($id): bool => $id !== null)
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /** @param list<int> $orderIds
     *  @return list<int>
     */
    private function disputeOrderIds(array $orderIds, LogisticsPayPalAccount $account, array $filters): array
    {
        if ($orderIds === [] && !$this->usesEventDates($filters)) {
            return [];
        }
        $query = LogisticsPayPalDispute::query()
            ->where('logistics_paypal_account_id', $account->getKey());
        if ($this->usesEventDates($filters)) {
            $this->applyEventDates($query, 'dispute_created_at', $filters);
        } else {
            $query->whereIn('logistics_order_id', $orderIds);
        }
        return $query
            ->distinct()
            ->pluck('logistics_order_id')
            ->map(static fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    /** @param list<int> $orderIds */
    private function canonicalRefundAmount(array $orderIds, LogisticsPayPalAccount $account, array $filters): float
    {
        if ($orderIds === [] && !$this->usesEventDates($filters)) {
            return 0.0;
        }
        $paypal = LogisticsPayPalRefund::query()
            ->where('logistics_paypal_account_id', $account->getKey())
            ->whereNotNull('refund_amount_usd');
        if ($this->usesEventDates($filters)) {
            $this->applyEventDates($paypal, 'refunded_at', $filters);
        } else {
            $paypal->whereIn('logistics_order_id', $orderIds);
        }
        $paypal = $paypal->selectRaw('logistics_order_id, SUM(refund_amount_usd) as amount')
            ->groupBy('logistics_order_id')
            ->pluck('amount', 'logistics_order_id');
        return round((float) $paypal->sum(), 2);
    }

    /** @param list<int> $orderIds */
    private function canonicalDisputeAmount(array $orderIds, LogisticsPayPalAccount $account, array $filters): float
    {
        if ($orderIds === [] && !$this->usesEventDates($filters)) {
            return 0.0;
        }
        $query = LogisticsPayPalDispute::query()
            ->where('logistics_paypal_account_id', $account->getKey());
        if ($this->usesEventDates($filters)) {
            $this->applyEventDates($query, 'dispute_created_at', $filters);
        } else {
            $query->whereIn('logistics_order_id', $orderIds);
        }

        return round((float) $query->sum('dispute_amount_usd'), 2);
    }

    /** @param array{date_from:string,date_to:string} $filters */
    private function orderQuery(LogisticsPayPalAccount $account, array $filters): Builder
    {
        $query = LogisticsOrder::query()
            ->where('paypal_account_id', $account->getKey())
            ->where('paypal_association_source', 'actual_transaction')
            ->where(function (Builder $paid): void {
                $paid->whereNotNull('paid_at')->orWhere('paid_amount_usd', '>', 0);
            });
        $this->applyDates($query, $filters);

        return $query;
    }

    /** @param array{date_from:string,date_to:string} $filters */
    private function applyDates(Builder $query, array $filters): void
    {
        $column = $this->usesEventDates($filters) ? 'paid_at' : 'order_created_at';
        if ($this->rangeFrom($filters) !== '') {
            $query->whereDate($column, '>=', $this->rangeFrom($filters));
        }
        if ($this->rangeTo($filters) !== '') {
            $query->whereDate($column, '<=', $this->rangeTo($filters));
        }
    }

    /** @param array{date_from:string,date_to:string} $filters */
    private function applyEventDates(Builder $query, string $column, array $filters): void
    {
        if ($this->rangeFrom($filters) !== '') {
            $query->whereDate($column, '>=', $this->rangeFrom($filters));
        }
        if ($this->rangeTo($filters) !== '') {
            $query->whereDate($column, '<=', $this->rangeTo($filters));
        }
    }

    private function usesEventDates(array $filters): bool
    {
        return ($filters['date_mode'] ?? '') === 'event'
            || filled($filters['event_date_from'] ?? null)
            || filled($filters['event_date_to'] ?? null);
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
            return Carbon::parse($value)->timezone('Asia/Shanghai')->format('Y-m-d');
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
     *  @param list<string> $allowed
     *  @return list<array<string,mixed>>
     */
    private function sortRows(array $rows, string $sort, string $direction, array $allowed): array
    {
        if ($sort === '' || !in_array($sort, $allowed, true) || !in_array($direction, ['asc', 'desc'], true)) {
            return $rows;
        }
        foreach ($rows as $index => &$row) {
            $row['_sort_index'] = $index;
        }
        unset($row);
        usort($rows, static function (array $left, array $right) use ($sort, $direction): int {
            $leftValue = $left[$sort] ?? null;
            $rightValue = $right[$sort] ?? null;
            if (is_array($leftValue)) {
                $leftValue = implode('、', array_map(static fn ($value): string => is_array($value) ? implode(' ', $value) : (string) $value, $leftValue));
            }
            if (is_array($rightValue)) {
                $rightValue = implode('、', array_map(static fn ($value): string => is_array($value) ? implode(' ', $value) : (string) $value, $rightValue));
            }
            $leftBlank = $leftValue === null || $leftValue === '' || $leftValue === '—';
            $rightBlank = $rightValue === null || $rightValue === '' || $rightValue === '—';
            if ($leftBlank !== $rightBlank) {
                return $leftBlank ? 1 : -1;
            }
            if (is_numeric($leftValue) && is_numeric($rightValue)) {
                $comparison = (float) $leftValue <=> (float) $rightValue;
            } else {
                $comparison = strnatcasecmp((string) $leftValue, (string) $rightValue);
            }
            if ($comparison === 0) {
                return $left['_sort_index'] <=> $right['_sort_index'];
            }

            return $direction === 'asc' ? $comparison : -$comparison;
        });
        foreach ($rows as &$row) {
            unset($row['_sort_index']);
        }
        unset($row);

        return $rows;
    }

    /** @param list<array<string,mixed>> $rows */
    private function paginate(array $rows, int $page, int $perPage = 10): array
    {
        $total = count($rows);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($lastPage, $page));

        return [
            'data' => array_slice($rows, ($page - 1) * $perPage, $perPage),
            'meta' => $this->paginatorMeta($page, $lastPage, $total, $perPage),
        ];
    }

    private function paginatorMeta(int $page, int $lastPage, int $total, int $perPage): array
    {
        return [
            'current_page' => $page,
            'last_page' => $lastPage,
            'total' => $total,
            'per_page' => $perPage,
            'from' => $total === 0 ? 0 : (($page - 1) * $perPage + 1),
            'to' => min($total, $page * $perPage),
        ];
    }

    private function riskLevel(float $score): string
    {
        return match (true) {
            $score >= 85 => 'low',
            $score >= 70 => 'medium',
            default => 'high',
        };
    }

    private function maskEmail(mixed $email): string
    {
        $email = trim((string) ($email ?? ''));
        if (!str_contains($email, '@')) {
            return $email ?: '—';
        }
        [$local, $domain] = explode('@', $email, 2);

        return mb_substr($local, 0, min(2, mb_strlen($local))).'***@'.$domain;
    }

    private function dateTime(mixed $value): string
    {
        if (!$value) {
            return '—';
        }
        try {
            return Carbon::parse($value)->timezone('Asia/Shanghai')->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return '—';
        }
    }

    private function disputeReason(mixed $reason): string
    {
        $reason = trim((string) ($reason ?? ''));

        return [
            'MERCHANDISE_OR_SERVICE_NOT_RECEIVED' => '未收到商品或服务',
            'MERCHANDISE_OR_SERVICE_NOT_AS_DESCRIBED' => '商品或服务与描述不符',
            'UNAUTHORISED' => '未经授权的交易',
            'UNAUTHORIZED_TRANSACTION' => '未经授权的交易',
            'CREDIT_NOT_PROCESSED' => '退款未处理',
            'DUPLICATE_TRANSACTION' => '重复交易',
            'INCORRECT_AMOUNT' => '金额不正确',
        ][$reason] ?? ($reason ?: '—');
    }

    private function disputeStatus(mixed $status): string
    {
        $status = trim((string) ($status ?? ''));

        return [
            'OPEN' => '处理中',
            'WAITING_FOR_BUYER_RESPONSE' => '等待买家回复',
            'WAITING_FOR_SELLER_RESPONSE' => '等待卖家回复',
            'UNDER_REVIEW' => 'PayPal审核中',
            'RESOLVED' => '已解决',
        ][$status] ?? ($status ?: '—');
    }

    private function disputeStage(mixed $stage): string
    {
        $stage = trim((string) ($stage ?? ''));

        return [
            'INQUIRY' => '争议',
            'CHARGEBACK' => '拒付',
            'PRE_ARBITRATION' => '预仲裁',
            'ARBITRATION' => '仲裁',
        ][$stage] ?? ($stage ?: '—');
    }
}
