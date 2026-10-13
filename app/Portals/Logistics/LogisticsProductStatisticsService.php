<?php

namespace App\Portals\Logistics;

use App\Models\LogisticsOrderItem;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LogisticsProductStatisticsService
{
    /**
     * @param array<string, mixed> $filters
     * @return array{products:list<array<string,mixed>>,summary:array<string,mixed>,pagination:array<string,int>}
     */
    public function page(array $filters): array
    {
        $rows = $this->filteredAggregateRows($filters);
        $summary = $this->overallSummary($rows);
        $perPage = (int) ($filters['per_page'] ?? 10);
        $page = max(1, (int) ($filters['page'] ?? 1));
        $total = count($rows);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);
        $offset = ($page - 1) * $perPage;
        $pageRows = array_slice($rows, $offset, $perPage);

        return [
            'products' => iterator_to_array($this->productsForRows($pageRows, $filters), false),
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
        $rows = $this->filteredAggregateRows($filters);
        if ($selectedIds === null) {
            return $rows;
        }

        $selected = array_fill_keys($selectedIds, true);

        return array_values(array_filter(
            $rows,
            static fn (array $row): bool => isset($selected[$row['id']]),
        ));
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param array<string,mixed> $filters
     * @return \Generator<int,array<string,mixed>>
     */
    public function productsForRows(array $rows, array $filters): \Generator
    {
        foreach (array_chunk($rows, 20) as $chunk) {
            $keys = array_column($chunk, '_key');
            $items = $this->detailItems($keys, $filters);
            $this->attachActualRefunds($items);
            $groups = [];
            foreach ($items as $item) {
                $groups[$this->productKey($item)][] = $item;
            }

            foreach ($chunk as $row) {
                $productItems = $groups[$row['_key']] ?? [];
                if ($productItems === []) {
                    continue;
                }
                yield $this->product($productItems, $row['_key'], (string) $row['id']);
            }
            unset($items, $groups);
        }
    }

    /** @param array<string,mixed> $filters
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

    /** @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    private function filteredAggregateRows(array $filters): array
    {
        $rows = $this->aggregateRows($filters);
        $name = mb_strtolower(trim((string) ($filters['name'] ?? '')));
        $keyword = mb_strtolower(trim((string) ($filters['keyword'] ?? '')));
        $decision = trim((string) ($filters['decision'] ?? ''));

        $rows = array_values(array_filter($rows, static function (array $row) use ($name, $keyword, $decision): bool {
            return ($name === '' || str_contains(mb_strtolower((string) $row['name']), $name))
                && ($keyword === '' || str_contains(mb_strtolower((string) $row['_search']), $keyword))
                && ($decision === '' || $decision === $row['decision']);
        }));

        $sort = trim((string) ($filters['sort'] ?? ''));
        $direction = trim((string) ($filters['direction'] ?? ''));
        if ($sort === '' || $direction === '') {
            usort($rows, static fn (array $left, array $right): int => $left['_first_item_id'] <=> $right['_first_item_id']);

            return $rows;
        }

        usort($rows, static function (array $left, array $right) use ($sort, $direction): int {
            $leftValue = $sort === 'fees' ? (float) $left['shipping'] + (float) $left['handling'] : ($left[$sort] ?? null);
            $rightValue = $sort === 'fees' ? (float) $right['shipping'] + (float) $right['handling'] : ($right[$sort] ?? null);
            if ($sort === 'decision') {
                $sequence = ['continue', 'optimize', 'watch', 'stop'];
                $comparison = array_search($leftValue, $sequence, true) <=> array_search($rightValue, $sequence, true);
            } elseif (is_string($leftValue)) {
                $comparison = strnatcasecmp($leftValue, (string) $rightValue);
            } elseif ($leftValue === null && $rightValue === null) {
                $comparison = 0;
            } elseif ($leftValue === null) {
                return 1;
            } elseif ($rightValue === null) {
                return -1;
            } else {
                $comparison = (float) $leftValue <=> (float) $rightValue;
            }

            return $direction === 'asc' ? $comparison : -$comparison;
        });

        return $rows;
    }

    /** @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    private function aggregateRows(array $filters): array
    {
        $key = $this->productKeySql();
        $variant = "CONCAT(LOWER(TRIM(COALESCE(NULLIF(logistics_order_items.variant_title, ''), NULLIF(logistics_order_items.tongtu_product_name, ''), NULLIF(logistics_order_items.shopify_sku, ''), logistics_order_items.product_title, ''))), '|', LOWER(TRIM(COALESCE(logistics_order_items.shopify_sku, ''))))";
        $query = LogisticsOrderItem::query()
            ->join('logistics_orders', 'logistics_orders.id', '=', 'logistics_order_items.logistics_order_id')
            ->whereNotNull('logistics_order_items.line_revenue_usd');
        $this->applyBaseFilters($query, $filters);

        $records = $query
            ->selectRaw($key.' AS product_key')
            ->selectRaw('MIN(logistics_order_items.id) AS first_item_id')
            ->selectRaw("COALESCE(NULLIF(MAX(logistics_order_items.product_title), ''), NULLIF(MAX(logistics_order_items.tongtu_product_name), ''), '未命名产品') AS product_name")
            ->selectRaw("COALESCE(NULLIF(MAX(logistics_order_items.tongtu_sku), ''), NULLIF(MAX(logistics_order_items.shopify_sku), ''), '—') AS product_sku")
            ->selectRaw("GROUP_CONCAT(DISTINCT NULLIF(TRIM(logistics_orders.store_name), '') ORDER BY NULLIF(TRIM(logistics_orders.store_name), '') SEPARATOR '|||') AS store_names")
            ->selectRaw("GROUP_CONCAT(DISTINCT CONCAT_WS(' ', COALESCE(logistics_order_items.product_title, ''), COALESCE(logistics_order_items.variant_title, ''), COALESCE(logistics_order_items.shopify_sku, ''), COALESCE(logistics_order_items.tongtu_sku, ''), COALESCE(logistics_order_items.tongtu_product_name, '')) SEPARATOR ' ') AS search_text")
            ->selectRaw('COUNT(DISTINCT logistics_orders.store_name) AS shop_count')
            ->selectRaw('COUNT(DISTINCT '.$variant.') AS variant_count')
            ->selectRaw('SUM(logistics_order_items.quantity) AS sales')
            ->selectRaw('SUM(logistics_order_items.line_revenue_usd) AS revenue')
            ->selectRaw('SUM(CASE WHEN logistics_order_items.product_cost_usd IS NULL THEN 1 ELSE 0 END) AS missing_cost_count')
            ->selectRaw('SUM(logistics_order_items.product_cost_usd) AS cost')
            ->selectRaw('SUM(logistics_order_items.total_shipping_usd) AS shipping')
            ->selectRaw('SUM(logistics_order_items.handling_fee_usd) AS handling')
            ->selectRaw('SUM(logistics_order_items.proportional_refund_usd) AS refunds')
            ->selectRaw('SUM(logistics_order_items.profit_usd) AS profit')
            ->groupByRaw($key)
            ->get();

        return $records->map(function ($record): array {
            $revenue = round((float) $record->revenue, 4);
            $refunds = round((float) $record->refunds, 4);
            $profit = (int) $record->missing_cost_count > 0 ? null : round((float) $record->profit, 4);
            $stores = array_values(array_filter(explode('|||', (string) $record->store_names)));
            $productKey = (string) $record->product_key;

            return [
                'id' => 'product-'.sha1($productKey),
                '_key' => $productKey,
                '_search' => (string) $record->search_text,
                '_first_item_id' => (int) $record->first_item_id,
                'name' => (string) $record->product_name,
                'sku' => (string) $record->product_sku,
                'stores' => $stores,
                'shop_count' => (int) $record->shop_count,
                'variant_count' => (int) $record->variant_count,
                'sales' => round((float) $record->sales, 4),
                'revenue' => $revenue,
                'cost' => (int) $record->missing_cost_count > 0 ? null : round((float) $record->cost, 4),
                'shipping' => round((float) $record->shipping, 4),
                'handling' => round((float) $record->handling, 4),
                'refunds' => $refunds,
                'profit' => $profit,
                'profit_rate' => $profit !== null && $revenue > 0 ? round($profit / $revenue * 100, 2) : null,
                'refund_rate' => $revenue > 0 ? round($refunds / $revenue * 100, 2) : 0.0,
                'decision' => $this->decision($profit, $revenue, $refunds),
            ];
        })->all();
    }

    /** @param list<string> $keys
     * @param array<string,mixed> $filters
     * @return Collection<int,LogisticsOrderItem>
     */
    private function detailItems(array $keys, array $filters): Collection
    {
        if ($keys === []) {
            return collect();
        }
        $query = LogisticsOrderItem::query()
            ->with([
                'order' => static fn ($orders) => $orders->select('id'),
                'order.activeRefundDetails' => static fn ($refunds) => $refunds->orderBy('id'),
            ])
            ->selectRaw('logistics_order_items.*, logistics_orders.store_name as statistics_store_name, logistics_orders.order_created_at as statistics_order_created_at, logistics_orders.shopify_order_number as statistics_shopify_order_number, logistics_orders.bill_number as statistics_bill_number')
            ->join('logistics_orders', 'logistics_orders.id', '=', 'logistics_order_items.logistics_order_id')
            ->whereNotNull('logistics_order_items.line_revenue_usd')
            ->whereIn(DB::raw($this->productKeySql()), $keys);
        $this->applyBaseFilters($query, $filters);

        return $query->orderBy('logistics_order_items.id')->get();
    }

    /** @param Collection<int,LogisticsOrderItem> $items */
    private function attachActualRefunds(Collection $items): void
    {
        if ($items->isEmpty()) {
            return;
        }
        $totals = LogisticsOrderItem::query()
            ->whereIn('logistics_order_id', $items->pluck('logistics_order_id')->unique()->values()->all())
            ->selectRaw('logistics_order_id, SUM(GREATEST(COALESCE(line_revenue_usd, 0), 0)) AS revenue_total, SUM(GREATEST(COALESCE(quantity, 0), 0)) AS quantity_total, COUNT(*) AS item_count')
            ->groupBy('logistics_order_id')
            ->get()
            ->keyBy('logistics_order_id');

        foreach ($items as $item) {
            $total = $totals->get($item->logistics_order_id);
            $revenueTotal = (float) ($total?->revenue_total ?? 0);
            $quantityTotal = (float) ($total?->quantity_total ?? 0);
            $itemCount = max(1, (int) ($total?->item_count ?? 1));
            $ratio = $revenueTotal > 0
                ? max(0.0, (float) $item->line_revenue_usd) / $revenueTotal
                : ($quantityTotal > 0
                    ? max(0.0, (float) $item->quantity) / $quantityTotal
                    : 1 / $itemCount);
            $details = [];
            foreach (($item->order?->activeRefundDetails ?? collect()) as $refund) {
                $details[] = [
                    'id' => (int) $refund->getKey(),
                    'store' => (string) $item->getAttribute('statistics_store_name'),
                    'shopify_order_number' => (string) $item->getAttribute('statistics_shopify_order_number'),
                    'bill_number' => (string) $item->getAttribute('statistics_bill_number'),
                    'refund_number' => (string) $refund->refund_number,
                    'amount' => round((float) $refund->refund_amount_usd * $ratio, 4),
                    'refund_type' => (string) ($refund->refund_type ?? ''),
                    'refund_reason' => (string) ($refund->refund_reason ?? ''),
                    'remark' => (string) ($refund->remark ?? ''),
                    'imported_at' => $refund->created_at?->timezone('Asia/Shanghai')->format('Y-m-d H:i:s'),
                ];
            }
            $item->setAttribute('statistics_actual_refund_details', $details);
            $item->setAttribute('statistics_actual_refund_amount', round(array_sum(array_column($details, 'amount')), 4));
        }
    }

    /** @param list<LogisticsOrderItem> $items
     * @return array<string,mixed>
     */
    private function product(array $items, string $key, string $id): array
    {
        $first = $items[0];

        return [
            'id' => $id,
            'name' => $this->productTitle($first),
            'sku' => $this->sku($first),
            'category' => __('logistics.product_statistics.sold_product'),
            'image_url' => $this->firstImage($items),
            ...$this->summary($items),
            'variants' => $this->variants($items, $key),
            'store_metrics' => $this->storeMetrics($items),
            'trend' => $this->trend($items),
        ];
    }

    /** @param list<LogisticsOrderItem> $items
     * @return array<string,mixed>
     */
    private function summary(array $items): array
    {
        $stores = collect($items)
            ->map(fn (LogisticsOrderItem $item): string => trim((string) $item->getAttribute('statistics_store_name')) ?: __('logistics.common.unassigned_store'))
            ->unique()->sort()->values()->all();
        $hasUnknownCost = collect($items)->contains(fn (LogisticsOrderItem $item): bool => $this->number($item->product_cost_usd) === null);
        $revenue = $this->sum($items, 'line_revenue_usd');
        $cost = $hasUnknownCost ? null : $this->sum($items, 'product_cost_usd');
        $profit = $hasUnknownCost ? null : $this->sum($items, 'profit_usd');
        $actualRefund = $this->actualRefundSummary($items);
        $refunds = $this->sum($items, 'proportional_refund_usd');

        return [
            'stores' => $stores,
            'shop_count' => count($stores),
            'sales' => $this->sum($items, 'quantity'),
            'revenue' => $revenue,
            'cost' => $cost,
            'shipping' => $this->sum($items, 'total_shipping_usd'),
            'handling' => $this->sum($items, 'handling_fee_usd'),
            'refunds' => $refunds,
            'actual_refunds' => $actualRefund['amount'],
            'actual_refund_count' => count($actualRefund['details']),
            'refund_details' => $actualRefund['details'],
            'profit' => $profit,
            'profit_rate' => $profit !== null && $revenue > 0 ? round($profit / $revenue * 100, 2) : null,
            'refund_rate' => $revenue > 0 ? round($refunds / $revenue * 100, 2) : 0.0,
            'decision' => $this->decision($profit, $revenue, $refunds),
        ];
    }

    /** @param list<LogisticsOrderItem> $items
     * @return list<array<string,mixed>>
     */
    private function variants(array $items, string $parentKey): array
    {
        $groups = [];
        foreach ($items as $item) {
            $name = trim((string) ($item->variant_title ?: $item->tongtu_product_name ?: $item->shopify_sku ?: $item->product_title));
            $key = mb_strtolower($name).'|'.mb_strtolower((string) $item->shopify_sku);
            $groups[$key][] = $item;
        }

        return array_map(function (array $variantItems, string $key) use ($parentKey): array {
            $first = $variantItems[0];

            return [
                'id' => 'variant-'.sha1($parentKey.'|'.$key),
                'name' => trim((string) ($first->variant_title ?: $first->tongtu_product_name ?: $first->product_title)),
                'sku' => $this->sku($first),
                'image_url' => $this->firstImage($variantItems),
                ...$this->summary($variantItems),
            ];
        }, array_values($groups), array_keys($groups));
    }

    /** @param list<LogisticsOrderItem> $items
     * @return list<array<string,mixed>>
     */
    private function storeMetrics(array $items): array
    {
        $groups = [];
        foreach ($items as $item) {
            $store = trim((string) $item->getAttribute('statistics_store_name')) ?: __('logistics.common.unassigned_store');
            $groups[$store][] = $item;
        }
        ksort($groups, SORT_NATURAL | SORT_FLAG_CASE);

        return array_map(function (array $storeItems, string $store): array {
            return ['name' => $store, ...$this->summary($storeItems)];
        }, array_values($groups), array_keys($groups));
    }

    /** @param list<LogisticsOrderItem> $items
     * @return list<array{label:string,sales:float,profit:float|null}>
     */
    private function trend(array $items): array
    {
        $groups = [];
        foreach ($items as $item) {
            $date = $item->getAttribute('statistics_order_created_at');
            if (!$date) {
                continue;
            }
            $label = Carbon::parse($date)->timezone('Asia/Shanghai')->format('m-d');
            $groups[$label][] = $item;
        }
        ksort($groups);

        return array_map(function (array $dayItems, string $label): array {
            $summary = $this->summary($dayItems);

            return ['label' => $label, 'sales' => $summary['sales'], 'profit' => $summary['profit']];
        }, array_values($groups), array_keys($groups));
    }

    /** @param list<array<string,mixed>> $rows
     * @return array<string,mixed>
     */
    private function overallSummary(array $rows): array
    {
        $revenue = round(array_sum(array_column($rows, 'revenue')), 4);
        $profitComplete = !collect($rows)->contains(static fn (array $row): bool => $row['profit'] === null);
        $profit = $profitComplete ? round(array_sum(array_column($rows, 'profit')), 4) : null;

        return [
            'product_count' => count($rows),
            'variant_count' => array_sum(array_column($rows, 'variant_count')),
            'sales' => round(array_sum(array_column($rows, 'sales')), 4),
            'revenue' => $revenue,
            'profit' => $profit,
            'profit_rate' => $profit !== null && $revenue > 0 ? round($profit / $revenue * 100, 2) : null,
        ];
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

    private function productKeySql(): string
    {
        return "CASE WHEN TRIM(COALESCE(logistics_order_items.product_title, '')) <> '' THEN CONCAT('title:', LOWER(TRIM(logistics_order_items.product_title))) ELSE CONCAT('sku:', LOWER(TRIM(COALESCE(NULLIF(logistics_order_items.tongtu_sku, ''), NULLIF(logistics_order_items.shopify_sku, ''), '—')))) END";
    }

    private function productKey(LogisticsOrderItem $item): string
    {
        $title = mb_strtolower(trim((string) $item->product_title));

        return $title !== '' ? 'title:'.$title : 'sku:'.mb_strtolower(trim((string) ($item->tongtu_sku ?: $item->shopify_sku)) ?: '—');
    }

    private function productTitle(LogisticsOrderItem $item): string
    {
        return trim((string) $item->product_title) ?: trim((string) $item->tongtu_product_name) ?: __('logistics.product_statistics.unnamed_product');
    }

    private function sku(LogisticsOrderItem $item): string
    {
        return trim((string) ($item->tongtu_sku ?: $item->shopify_sku)) ?: '—';
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

    private function decision(?float $profit, float $revenue, float $refunds): string
    {
        if ($profit === null || $revenue <= 0.0) {
            return 'watch';
        }
        $profitRate = $profit / $revenue * 100;
        $refundRate = $refunds / $revenue * 100;

        return match (true) {
            $profitRate < 0 || $refundRate >= 25 => 'stop',
            $profitRate < 15 => 'optimize',
            $profitRate >= 30 && $refundRate < 15 => 'continue',
            default => 'watch',
        };
    }

    /** @param list<LogisticsOrderItem> $items
     * @return array{amount:float,details:list<array<string,mixed>>}
     */
    private function actualRefundSummary(array $items): array
    {
        $details = [];
        foreach ($items as $item) {
            foreach (($item->getAttribute('statistics_actual_refund_details') ?: []) as $detail) {
                $key = (string) $detail['id'];
                if (!isset($details[$key])) {
                    $details[$key] = $detail;
                    $details[$key]['amount'] = 0.0;
                }
                $details[$key]['amount'] = round((float) $details[$key]['amount'] + (float) $detail['amount'], 4);
            }
        }

        return [
            'amount' => round(array_sum(array_column($details, 'amount')), 4),
            'details' => array_values($details),
        ];
    }
}
