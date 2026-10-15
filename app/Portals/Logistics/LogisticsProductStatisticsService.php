<?php

namespace App\Portals\Logistics;

use App\Models\LogisticsOrder;
use App\Models\LogisticsOrderItem;
use App\Models\LogisticsPayPalDispute;
use App\Models\LogisticsPayPalRefund;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Product reporting is deliberately based on real source records only.
 *
 * Shopify channel refunds and the former proportional-refund fields are never
 * used here. PayPal refunds come from the imported PayPal source records and
 * independent credit-card refunds come from logistics_credit_card_refunds.
 */
class LogisticsProductStatisticsService
{
    /**
     * @param array<string, mixed> $filters
     * @return array{products:list<array<string,mixed>>,summary:array<string,mixed>,pagination:array<string,int>}
     */
    public function page(array $filters): array
    {
        $rows = $this->filteredProducts($filters);
        $summary = $this->overallSummary($rows);
        $perPage = (int) ($filters['per_page'] ?? 10);
        $page = max(1, (int) ($filters['page'] ?? 1));
        $total = count($rows);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);
        $offset = ($page - 1) * $perPage;

        return [
            'products' => array_values(array_slice($rows, $offset, $perPage)),
            'summary' => $summary,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
                'from' => $total === 0 ? 0 : $offset + 1,
                'to' => min($offset + $perPage, $total),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @param list<string>|null $selectedIds
     * @return list<array<string,mixed>>
     */
    public function exportRows(array $filters, ?array $selectedIds = null): array
    {
        $rows = $this->filteredProducts($filters);
        if ($selectedIds === null) {
            return $rows;
        }

        $selected = array_fill_keys($selectedIds, true);

        return array_values(array_filter($rows, static fn (array $row): bool => isset($selected[$row['id']])));
    }

    /**
     * Kept as a generator for the spreadsheet export contract. Rows supplied by
     * exportRows are already fully calculated and must not be recalculated.
     *
     * @param list<array<string,mixed>> $rows
     * @param array<string,mixed> $filters
     * @return \Generator<int,array<string,mixed>>
     */
    public function productsForRows(array $rows, array $filters): \Generator
    {
        foreach ($rows as $row) {
            yield $row;
        }
    }

    /**
     * @param array<string,mixed> $filters
     * @return list<string>
     */
    public function storeOptions(array $filters): array
    {
        $query = LogisticsOrderItem::query()
            ->join('logistics_orders', 'logistics_orders.id', '=', 'logistics_order_items.logistics_order_id')
            ->whereNotNull('logistics_order_items.line_revenue_usd')
            ->whereNotNull('logistics_orders.store_name');
        $this->applyDateFilters($query, $filters);

        return $query->orderBy('logistics_orders.store_name')
            ->distinct()
            ->pluck('logistics_orders.store_name')
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    private function filteredProducts(array $filters): array
    {
        $items = $this->detailItems($filters);
        $groups = [];
        foreach ($items as $item) {
            $groups[$this->productKey($item)][] = $item;
        }

        $rows = [];
        foreach ($groups as $key => $productItems) {
            $rows[] = $this->product($productItems, $key);
        }

        $name = mb_strtolower(trim((string) ($filters['name'] ?? '')));
        $keyword = mb_strtolower(trim((string) ($filters['keyword'] ?? '')));
        $decision = trim((string) ($filters['decision'] ?? ''));

        $rows = array_values(array_filter($rows, static function (array $row) use ($name, $keyword, $decision): bool {
            return ($name === '' || str_contains(mb_strtolower((string) $row['name']), $name))
                && ($keyword === '' || str_contains(mb_strtolower((string) $row['_search']), $keyword))
                && ($decision === '' || $decision === $row['decision']);
        }));

        $this->sortRows($rows, trim((string) ($filters['sort'] ?? '')), trim((string) ($filters['direction'] ?? '')));

        return $rows;
    }

    /**
     * @param array<string,mixed> $filters
     * @return Collection<int,LogisticsOrderItem>
     */
    private function detailItems(array $filters): Collection
    {
        $query = LogisticsOrderItem::query()
            ->with([
                'order',
                'order.paypalRefunds.disputeLinks.dispute',
                'order.paypalDisputes.refundLinks.refund',
                'order.creditCardRefunds',
            ])
            ->select('logistics_order_items.*')
            ->selectRaw('logistics_orders.store_name as statistics_store_name')
            ->selectRaw('logistics_orders.order_created_at as statistics_order_created_at')
            ->selectRaw('logistics_orders.shopify_order_number as statistics_shopify_order_number')
            ->selectRaw('logistics_orders.bill_number as statistics_bill_number')
            ->join('logistics_orders', 'logistics_orders.id', '=', 'logistics_order_items.logistics_order_id')
            ->whereNotNull('logistics_order_items.line_revenue_usd');
        $this->applyBaseFilters($query, $filters);

        return $query->orderBy('logistics_order_items.id')->get();
    }

    /**
     * @param list<LogisticsOrderItem> $items
     * @return array<string,mixed>
     */
    private function product(array $items, string $key): array
    {
        $first = $items[0];
        $summary = $this->summary($items);

        return [
            'id' => 'product-'.sha1($key),
            '_key' => $key,
            '_first_item_id' => (int) $first->getKey(),
            '_search' => $this->searchText($items),
            'name' => $this->productTitle($first),
            'sku' => $this->sku($first),
            'category' => __('logistics.product_statistics.sold_product'),
            'image_url' => $this->firstImage($items),
            ...$summary,
            // Variants are intentionally not independently allocated or added
            // to the parent calculation. The product is the reporting unit.
            'variants' => [],
            'store_metrics' => $this->storeMetrics($items),
            'trend' => $this->trend($items),
        ];
    }

    /**
     * @param list<LogisticsOrderItem> $items
     * @return array<string,mixed>
     */
    private function summary(array $items): array
    {
        $stores = collect($items)
            ->map(fn (LogisticsOrderItem $item): string => trim((string) $item->getAttribute('statistics_store_name')) ?: __('logistics.common.unassigned_store'))
            ->unique()->sort()->values()->all();
        $orders = $this->ordersForItems($items);
        $hasUnknownCost = collect($items)->contains(fn (LogisticsOrderItem $item): bool => $this->number($item->product_cost_usd) === null);
        $revenue = $this->sum($items, 'line_revenue_usd');
        $cost = $hasUnknownCost ? null : $this->sum($items, 'product_cost_usd');
        $shipping = $this->sum($items, 'total_shipping_usd');
        $handling = $this->sum($items, 'handling_fee_usd');
        $payment = $this->realPaymentSummary($orders);
        $refunds = round($payment['paypal_refund_amount'] + $payment['credit_card_refund_amount'], 4);
        $profit = $cost === null ? null : round($revenue - $cost - $shipping - $handling - $refunds, 4);
        $paidOrders = $orders->filter(fn (LogisticsOrder $order): bool => $this->isPaidOrder($order));
        $paypalOrders = $paidOrders->filter(fn (LogisticsOrder $order): bool => $this->isActualPayPalOrder($order));
        $creditCardOrders = $paidOrders->filter(fn (LogisticsOrder $order): bool => $this->isCreditCardOrder($order));
        $paypalRefundOrderIds = array_fill_keys($payment['paypal_refund_order_ids'], true);
        $creditCardRefundOrderIds = array_fill_keys($payment['credit_card_refund_order_ids'], true);
        $disputeOrderIds = array_fill_keys($payment['paypal_dispute_order_ids'], true);
        $paypalRefundRate = $paypalOrders->isEmpty() ? 0.0 : round($paypalOrders->filter(fn (LogisticsOrder $order): bool => isset($paypalRefundOrderIds[(int) $order->getKey()]))->count() / $paypalOrders->count() * 100, 2);
        $paypalDisputeRate = $paypalOrders->isEmpty() ? 0.0 : round($paypalOrders->filter(fn (LogisticsOrder $order): bool => isset($disputeOrderIds[(int) $order->getKey()]))->count() / $paypalOrders->count() * 100, 2);
        $creditCardRefundRate = $creditCardOrders->isEmpty() ? 0.0 : round($creditCardOrders->filter(fn (LogisticsOrder $order): bool => isset($creditCardRefundOrderIds[(int) $order->getKey()]))->count() / $creditCardOrders->count() * 100, 2);
        $combinedRefundOrderIds = $paypalRefundOrderIds + $creditCardRefundOrderIds;
        $combinedRefundRate = $paidOrders->isEmpty() ? 0.0 : round($paidOrders->filter(fn (LogisticsOrder $order): bool => isset($combinedRefundOrderIds[(int) $order->getKey()]))->count() / $paidOrders->count() * 100, 2);

        return [
            'stores' => $stores,
            'shop_count' => count($stores),
            'sales' => $this->sum($items, 'quantity'),
            'revenue' => $revenue,
            'cost' => $cost,
            'shipping' => $shipping,
            'handling' => $handling,
            // Compatibility aliases now both represent actual, source-backed refunds.
            'refunds' => $refunds,
            'actual_refunds' => $refunds,
            'actual_refund_count' => count($payment['refund_details']),
            'refund_details' => $payment['refund_details'],
            'paypal_refund_amount' => $payment['paypal_refund_amount'],
            'paypal_refund_count' => count($payment['paypal_refund_details']),
            'paypal_refund_rate' => $paypalRefundRate,
            'paypal_dispute_count' => count($payment['dispute_details']),
            'paypal_dispute_rate' => $paypalDisputeRate,
            'paypal_dispute_details' => $payment['dispute_details'],
            'credit_card_refund_amount' => $payment['credit_card_refund_amount'],
            'credit_card_refund_count' => count($payment['credit_card_refund_details']),
            'credit_card_refund_rate' => $creditCardRefundRate,
            'combined_refund_rate' => $combinedRefundRate,
            'related_orders' => $this->relatedOrders($items, $payment),
            'profit' => $profit,
            'actual_profit' => $profit,
            'profit_rate' => $profit !== null && $revenue > 0 ? round($profit / $revenue * 100, 2) : null,
            'refund_rate' => $combinedRefundRate,
            'decision' => $this->decision($profit, $revenue, $combinedRefundRate),
        ];
    }

    /**
     * @param Collection<int,LogisticsOrder> $orders
     * @return array{paypal_refund_amount:float,credit_card_refund_amount:float,paypal_refund_order_ids:list<int>,credit_card_refund_order_ids:list<int>,paypal_dispute_order_ids:list<int>,refund_details:list<array<string,mixed>>,paypal_refund_details:list<array<string,mixed>>,credit_card_refund_details:list<array<string,mixed>>,dispute_details:list<array<string,mixed>>}
     */
    private function realPaymentSummary(Collection $orders): array
    {
        $paypalRefunds = [];
        $creditCardRefunds = [];
        $disputes = [];
        foreach ($orders as $order) {
            $orderId = (int) $order->getKey();
            if ($this->isActualPayPalOrder($order)) {
                foreach ($order->paypalRefunds as $refund) {
                    if (!$refund instanceof LogisticsPayPalRefund) {
                        continue;
                    }
                    if ((int) $refund->logistics_paypal_account_id !== (int) $order->paypal_account_id) {
                        continue;
                    }
                    $key = 'paypal:'.(int) $refund->getKey();
                    $paypalRefunds[$key] ??= $this->paypalRefundDetail($refund, $order);
                }
                foreach ($order->paypalDisputes as $dispute) {
                    if (!$dispute instanceof LogisticsPayPalDispute) {
                        continue;
                    }
                    if ((int) $dispute->logistics_paypal_account_id !== (int) $order->paypal_account_id) {
                        continue;
                    }
                    $key = 'dispute:'.(int) $dispute->getKey();
                    $disputes[$key] ??= $this->paypalDisputeDetail($dispute, $order);
                }
            }
            foreach ($order->creditCardRefunds as $refund) {
                $key = 'credit_card:'.(int) $refund->getKey();
                $creditCardRefunds[$key] ??= $this->creditCardRefundDetail($refund, $order);
            }
        }

        $paypalDetails = array_values($paypalRefunds);
        $creditCardDetails = array_values($creditCardRefunds);

        return [
            'paypal_refund_amount' => round(array_sum(array_column($paypalDetails, 'amount')), 4),
            'credit_card_refund_amount' => round(array_sum(array_column($creditCardDetails, 'amount')), 4),
            'paypal_refund_order_ids' => array_values(array_unique(array_map(static fn (array $detail): int => (int) $detail['order_id'], $paypalDetails))),
            'credit_card_refund_order_ids' => array_values(array_unique(array_map(static fn (array $detail): int => (int) $detail['order_id'], $creditCardDetails))),
            'paypal_dispute_order_ids' => array_values(array_unique(array_map(static fn (array $detail): int => (int) $detail['order_id'], array_values($disputes)))),
            'refund_details' => array_values(array_merge($paypalDetails, $creditCardDetails)),
            'paypal_refund_details' => $paypalDetails,
            'credit_card_refund_details' => $creditCardDetails,
            'dispute_details' => array_values($disputes),
        ];
    }

    /** @return array<string,mixed> */
    private function paypalRefundDetail(LogisticsPayPalRefund $refund, LogisticsOrder $order): array
    {
        $linkedDisputes = $refund->disputeLinks
            ->map(fn ($link): string => (string) ($link->dispute?->dispute_id ?? ''))
            ->filter()->unique()->values()->all();

        return [
            'id' => 'paypal:'.(int) $refund->getKey(),
            'payment_method' => 'paypal',
            'payment_method_label' => __('logistics.product_statistics.payment_methods.paypal'),
            'order_id' => (int) $order->getKey(),
            'order_detail_url' => route('logistics.orders.detail', ['order' => (int) $order->getKey()]),
            'store' => (string) ($order->store_name ?: __('logistics.common.unassigned_store')),
            'shopify_order_number' => (string) ($order->shopify_order_number ?: __('logistics.common.not_available')),
            'bill_number' => (string) ($order->bill_number ?: __('logistics.common.not_available')),
            'refund_number' => (string) $refund->refund_transaction_id,
            'amount' => $this->paypalRefundAmount($refund),
            'refund_reason' => (string) ($refund->manual_reason ?: $refund->refund_reason ?: $refund->eventLabel()),
            'source_reason' => (string) ($refund->refund_reason ?: $refund->eventLabel()),
            'manual_reason' => (string) ($refund->manual_reason ?: ''),
            'status' => $refund->statusLabel(),
            'linked_disputes' => $linkedDisputes,
            'imported_at' => $this->dateTime($refund->refunded_at ?: $refund->created_at),
            'sort_time' => ($refund->refunded_at ?: $refund->created_at)?->timestamp ?? 0,
        ];
    }

    /** @return array<string,mixed> */
    private function creditCardRefundDetail(object $refund, LogisticsOrder $order): array
    {
        return [
            'id' => 'credit_card:'.(int) $refund->getKey(),
            'payment_method' => 'credit_card',
            'payment_method_label' => __('logistics.product_statistics.payment_methods.credit_card'),
            'order_id' => (int) $order->getKey(),
            'order_detail_url' => route('logistics.orders.detail', ['order' => (int) $order->getKey()]),
            'store' => (string) ($order->store_name ?: __('logistics.common.unassigned_store')),
            'shopify_order_number' => (string) ($order->shopify_order_number ?: __('logistics.common.not_available')),
            'bill_number' => (string) ($order->bill_number ?: __('logistics.common.not_available')),
            'refund_number' => (string) $refund->refund_reference,
            'amount' => round((float) $refund->refund_amount_usd, 4),
            'refund_reason' => (string) ($refund->refund_reason ?: __('logistics.common.not_available')),
            'source_reason' => '',
            'manual_reason' => (string) ($refund->refund_reason ?: ''),
            'status' => __('logistics.product_statistics.credit_card_refund_status'),
            'linked_disputes' => [],
            'imported_at' => $this->dateTime($refund->last_imported_at ?: $refund->updated_at ?: $refund->created_at),
            'sort_time' => ($refund->last_imported_at ?: $refund->updated_at ?: $refund->created_at)?->timestamp ?? 0,
        ];
    }

    /** @return array<string,mixed> */
    private function paypalDisputeDetail(LogisticsPayPalDispute $dispute, LogisticsOrder $order): array
    {
        $refunds = $dispute->refundLinks
            ->map(function ($link): array {
                $refund = $link->refund;

                return [
                    'refund_number' => (string) ($refund?->refund_transaction_id ?: __('logistics.common.not_available')),
                    'amount' => $refund instanceof LogisticsPayPalRefund ? $this->paypalRefundAmount($refund) : 0.0,
                    'reason' => (string) ($refund?->manual_reason ?: $refund?->refund_reason ?: $refund?->eventLabel() ?: ''),
                ];
            })
            ->values()->all();

        return [
            'id' => 'dispute:'.(int) $dispute->getKey(),
            'order_id' => (int) $order->getKey(),
            'order_detail_url' => route('logistics.orders.detail', ['order' => (int) $order->getKey()]),
            'store' => (string) ($order->store_name ?: __('logistics.common.unassigned_store')),
            'shopify_order_number' => (string) ($order->shopify_order_number ?: __('logistics.common.not_available')),
            'bill_number' => (string) ($order->bill_number ?: __('logistics.common.not_available')),
            'dispute_id' => (string) $dispute->dispute_id,
            'disputed_transaction_id' => (string) ($dispute->disputed_transaction_id ?: __('logistics.common.not_available')),
            'amount' => round((float) ($dispute->dispute_amount_usd ?? $dispute->original_amount ?? 0), 4),
            'currency' => (string) ($dispute->currency_code ?: 'USD'),
            'reason' => (string) ($dispute->reason ?: __('logistics.common.not_available')),
            'stage' => (string) ($dispute->life_cycle_stage ?: __('logistics.common.not_available')),
            'status' => (string) ($dispute->status ?: __('logistics.common.not_available')),
            'outcome' => (string) ($dispute->outcome_code ?: __('logistics.common.not_available')),
            'seller_response_due_at' => $this->dateTime($dispute->seller_response_due_at),
            'updated_at' => $this->dateTime($dispute->dispute_updated_at),
            'happened_at' => $this->dateTime($dispute->dispute_created_at),
            'linked_refunds' => $refunds,
            'sort_time' => ($dispute->dispute_updated_at ?: $dispute->dispute_created_at)?->timestamp ?? 0,
        ];
    }

    /**
     * Full source refund is shown against every product included in the same
     * order, as explicitly required. It is never revenue-ratio allocated.
     *
     * @param list<LogisticsOrderItem> $items
     * @param array<string,mixed> $payment
     * @return list<array<string,mixed>>
     */
    private function relatedOrders(array $items, array $payment): array
    {
        $orders = [];
        foreach ($items as $item) {
            $order = $item->order;
            if (!$order instanceof LogisticsOrder) {
                continue;
            }
            $id = (int) $order->getKey();
            $orders[$id] ??= [
                'order_id' => $id,
                'detail_url' => route('logistics.orders.detail', ['order' => $id]),
                'store' => (string) ($order->store_name ?: __('logistics.common.unassigned_store')),
                'shopify_order_number' => (string) ($order->shopify_order_number ?: __('logistics.common.not_available')),
                'bill_number' => (string) ($order->bill_number ?: __('logistics.common.not_available')),
                'quantity' => 0.0,
                'revenue' => 0.0,
                'paypal_refund' => 0.0,
                'credit_card_refund' => 0.0,
                'actual_refund' => 0.0,
                'paypal_dispute_count' => 0,
                'payment_method' => $this->isActualPayPalOrder($order)
                    ? __('logistics.product_statistics.payment_methods.paypal')
                    : ($this->isCreditCardOrder($order)
                        ? __('logistics.product_statistics.payment_methods.credit_card')
                        : __('logistics.common.not_available')),
                'payment_kind' => $this->isActualPayPalOrder($order)
                    ? 'paypal'
                    : ($this->isCreditCardOrder($order) ? 'credit_card' : 'unknown'),
                'order_created_at' => $this->dateTime($order->order_created_at),
                '_sort_time' => $order->order_created_at?->timestamp ?? 0,
            ];
            $orders[$id]['quantity'] += (float) $item->quantity;
            $orders[$id]['revenue'] += (float) $item->line_revenue_usd;
        }

        foreach ($payment['paypal_refund_details'] as $refund) {
            if (isset($orders[$refund['order_id']])) {
                $orders[$refund['order_id']]['paypal_refund'] += (float) $refund['amount'];
            }
        }
        foreach ($payment['credit_card_refund_details'] as $refund) {
            if (isset($orders[$refund['order_id']])) {
                $orders[$refund['order_id']]['credit_card_refund'] += (float) $refund['amount'];
            }
        }
        foreach ($payment['dispute_details'] as $dispute) {
            if (isset($orders[$dispute['order_id']])) {
                $orders[$dispute['order_id']]['paypal_dispute_count']++;
            }
        }
        foreach ($orders as &$order) {
            $order['quantity'] = round((float) $order['quantity'], 4);
            $order['revenue'] = round((float) $order['revenue'], 4);
            $order['paypal_refund'] = round((float) $order['paypal_refund'], 4);
            $order['credit_card_refund'] = round((float) $order['credit_card_refund'], 4);
            $order['actual_refund'] = round($order['paypal_refund'] + $order['credit_card_refund'], 4);
        }
        unset($order);
        uasort($orders, static fn (array $left, array $right): int => $right['_sort_time'] <=> $left['_sort_time']);

        return array_values(array_map(static function (array $order): array {
            unset($order['_sort_time']);

            return $order;
        }, $orders));
    }

    /** @param list<LogisticsOrderItem> $items
     * @return list<array<string,mixed>>
     */
    private function storeMetrics(array $items): array
    {
        $groups = [];
        foreach ($items as $item) {
            $key = trim((string) $item->getAttribute('statistics_store_name')) ?: __('logistics.common.unassigned_store');
            $groups[$key][] = $item;
        }
        ksort($groups);

        return array_map(function (array $group, string $store): array {
            $summary = $this->summary($group);

            return ['name' => $store, ...$summary];
        }, $groups, array_keys($groups));
    }

    /** @param list<LogisticsOrderItem> $items
     * @return list<array<string,mixed>>
     */
    private function trend(array $items): array
    {
        $groups = [];
        foreach ($items as $item) {
            $date = $item->getAttribute('statistics_order_created_at')
                ? Carbon::parse($item->getAttribute('statistics_order_created_at'))->timezone('Asia/Shanghai')->format('Y-m-d')
                : __('logistics.common.not_available');
            $groups[$date][] = $item;
        }
        ksort($groups);

        return array_map(function (array $group, string $date): array {
            $summary = $this->summary($group);

            return ['label' => $date, ...$summary];
        }, $groups, array_keys($groups));
    }

    /** @param list<array<string,mixed>> $rows
     * @return array<string,mixed>
     */
    private function overallSummary(array $rows): array
    {
        $refunds = [];
        foreach ($rows as $row) {
            foreach ($row['refund_details'] as $refund) {
                $refunds[$refund['id']] ??= $refund;
            }
        }
        $revenue = round(array_sum(array_map(static fn (array $row): float => (float) $row['revenue'], $rows)), 4);
        $costComplete = !collect($rows)->contains(static fn (array $row): bool => $row['cost'] === null);
        $cost = $costComplete ? round(array_sum(array_map(static fn (array $row): float => (float) $row['cost'], $rows)), 4) : null;
        $shipping = round(array_sum(array_map(static fn (array $row): float => (float) $row['shipping'], $rows)), 4);
        $handling = round(array_sum(array_map(static fn (array $row): float => (float) $row['handling'], $rows)), 4);
        $actualRefunds = round(array_sum(array_map(static fn (array $refund): float => (float) $refund['amount'], $refunds)), 4);
        $profit = $cost === null ? null : round($revenue - $cost - $shipping - $handling - $actualRefunds, 4);

        return [
            'product_count' => count($rows),
            'variant_count' => 0,
            'sales' => round(array_sum(array_map(static fn (array $row): float => (float) $row['sales'], $rows)), 4),
            'revenue' => $revenue,
            'actual_refunds' => $actualRefunds,
            'profit' => $profit,
            'profit_rate' => $profit !== null && $revenue > 0 ? round($profit / $revenue * 100, 2) : null,
        ];
    }

    /** @param list<array<string,mixed>> $rows */
    private function sortRows(array &$rows, string $sort, string $direction): void
    {
        if ($sort === '' || $direction === '') {
            usort($rows, static fn (array $left, array $right): int => $left['_first_item_id'] <=> $right['_first_item_id']);

            return;
        }
        usort($rows, function (array $left, array $right) use ($sort, $direction): int {
            $leftValue = $sort === 'fees' ? (float) $left['shipping'] + (float) $left['handling'] : ($left[$sort] ?? null);
            $rightValue = $sort === 'fees' ? (float) $right['shipping'] + (float) $right['handling'] : ($right[$sort] ?? null);
            if ($sort === 'decision') {
                $sequence = ['continue', 'optimize', 'watch', 'stop'];
                $comparison = array_search($leftValue, $sequence, true) <=> array_search($rightValue, $sequence, true);
            } elseif ($leftValue === null && $rightValue === null) {
                $comparison = 0;
            } elseif ($leftValue === null) {
                $comparison = 1;
            } elseif ($rightValue === null) {
                $comparison = -1;
            } elseif (is_numeric($leftValue) && is_numeric($rightValue)) {
                $comparison = (float) $leftValue <=> (float) $rightValue;
            } else {
                $comparison = strnatcasecmp((string) $leftValue, (string) $rightValue);
            }

            return $direction === 'asc' ? $comparison : -$comparison;
        });
    }

    /** @param array<string,mixed> $filters */
    private function applyBaseFilters(Builder $query, array $filters): void
    {
        $this->applyDateFilters($query, $filters);
        if (trim((string) ($filters['store'] ?? '')) !== '') {
            $query->where('logistics_orders.store_name', $filters['store']);
        }
    }

    /** @param array<string,mixed> $filters */
    private function applyDateFilters(Builder $query, array $filters): void
    {
        if (trim((string) ($filters['date_from'] ?? '')) !== '') {
            $query->where('logistics_orders.order_created_at', '>=', $filters['date_from'].' 00:00:00');
        }
        if (trim((string) ($filters['date_to'] ?? '')) !== '') {
            $query->where('logistics_orders.order_created_at', '<=', $filters['date_to'].' 23:59:59');
        }
    }

    /** @param list<LogisticsOrderItem> $items
     * @return Collection<int,LogisticsOrder>
     */
    private function ordersForItems(array $items): Collection
    {
        return collect($items)->map(fn (LogisticsOrderItem $item) => $item->order)
            ->filter(fn ($order): bool => $order instanceof LogisticsOrder)
            ->keyBy(fn (LogisticsOrder $order): int => (int) $order->getKey())
            ->values();
    }

    private function isPaidOrder(LogisticsOrder $order): bool
    {
        return $order->paid_at !== null || (float) $order->paid_amount_usd > 0;
    }

    private function isActualPayPalOrder(LogisticsOrder $order): bool
    {
        return $order->paypal_account_id !== null && $order->paypal_association_source === 'actual_transaction';
    }

    private function isCreditCardOrder(LogisticsOrder $order): bool
    {
        // An order confirmed by a PayPal transaction is always PayPal, even if
        // its gateway label happens to include the word "card".  Independent
        // credit-card refunds must never share the PayPal denominator.
        if ($this->isActualPayPalOrder($order)) {
            return false;
        }

        $value = mb_strtolower(trim(implode(' ', array_filter([
            (string) $order->payment_method,
            (string) $order->payment_gateway,
        ]))));

        return str_contains($value, 'credit_card')
            || str_contains($value, 'credit card')
            || str_contains($value, 'creditcard')
            || str_contains($value, '信用卡');
    }

    private function paypalRefundAmount(LogisticsPayPalRefund $refund): float
    {
        if ($refund->refund_amount_usd !== null) {
            return round((float) $refund->refund_amount_usd, 4);
        }

        return strtoupper((string) $refund->currency_code) === 'USD'
            ? round((float) $refund->original_amount, 4)
            : 0.0;
    }

    private function productKey(LogisticsOrderItem $item): string
    {
        $shopifyProductId = trim((string) $item->shopify_product_id);
        if ($shopifyProductId !== '') {
            return 'shopify-product:'.mb_strtolower($shopifyProductId);
        }

        // Historic rows can lack a Shopify product ID. In that case the product
        // title is intentionally the fallback key, so variants are still kept
        // under one product rather than split by their individual SKU.
        $productName = mb_strtolower(trim((string) ($item->tongtu_product_name ?: $item->product_title)));
        if ($productName !== '') {
            return 'product-name:'.$productName;
        }

        return 'sku:'.(mb_strtolower(trim((string) ($item->tongtu_sku ?: $item->shopify_sku))) ?: __('logistics.common.not_available'));
    }

    private function productTitle(LogisticsOrderItem $item): string
    {
        return trim((string) $item->product_title) ?: trim((string) $item->tongtu_product_name) ?: __('logistics.product_statistics.unnamed_product');
    }

    private function sku(LogisticsOrderItem $item): string
    {
        return trim((string) ($item->tongtu_sku ?: $item->shopify_sku)) ?: __('logistics.common.not_available');
    }

    /** @param list<LogisticsOrderItem> $items */
    private function searchText(array $items): string
    {
        return implode(' ', array_map(static fn (LogisticsOrderItem $item): string => implode(' ', [
            (string) $item->product_title,
            (string) $item->variant_title,
            (string) $item->shopify_sku,
            (string) $item->tongtu_sku,
            (string) $item->tongtu_product_name,
        ]), $items));
    }

    /** @param list<LogisticsOrderItem> $items */
    private function firstImage(array $items): ?string
    {
        foreach ($items as $item) {
            if (filled($item->product_image_url)) {
                return (string) $item->product_image_url;
            }
        }

        return null;
    }

    /** @param list<LogisticsOrderItem> $items */
    private function sum(array $items, string $field): float
    {
        return round(array_sum(array_map(fn (LogisticsOrderItem $item): float => $this->number($item->{$field}) ?? 0.0, $items)), 4);
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function decision(?float $profit, float $revenue, float $refundRate): string
    {
        if ($profit === null || $revenue <= 0.0) {
            return 'watch';
        }
        $profitRate = $profit / $revenue * 100;

        return match (true) {
            $profitRate < 0 || $refundRate >= 25 => 'stop',
            $profitRate < 15 || $refundRate >= 12 => 'optimize',
            $profitRate >= 30 && $refundRate < 8 => 'continue',
            default => 'watch',
        };
    }

    private function dateTime(mixed $value): string
    {
        if (!$value) {
            return __('logistics.common.not_available');
        }
        try {
            return Carbon::parse($value)->timezone('Asia/Shanghai')->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return __('logistics.common.not_available');
        }
    }
}
