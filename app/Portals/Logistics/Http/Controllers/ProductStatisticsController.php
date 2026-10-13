<?php

namespace App\Portals\Logistics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\LogisticsOrderItem;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\Logistics\LogisticsPortalService;
use App\Portals\Logistics\LogisticsProductStatisticsSpreadsheetService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class ProductStatisticsController extends Controller
{
    public function __construct(
        private readonly PortalEntryController $portalEntries,
        private readonly LogisticsPortalService $logistics,
        private readonly LogisticsProductStatisticsSpreadsheetService $spreadsheets,
    ) {
    }

    public function index(Request $request): Response|View
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }

        $filters = $this->filters($request);
        $products = $this->filteredProducts($filters);
        $stores = $this->storeOptions($filters);

        return view('portals.logistics.product-statistics.index', [
            ...$this->viewContext($context),
            'products' => $products,
            'stores' => $stores,
            'filters' => $filters,
            'decisionOptions' => $this->decisionOptions(),
            'labels' => $this->labels(),
        ]);
    }

    public function export(Request $request): Response
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }

        $validated = $request->validate([
            'scope' => ['required', Rule::in(['selected', 'filtered'])],
            'selected_ids' => ['required_if:scope,selected', 'array', 'min:1'],
            'selected_ids.*' => ['string', 'distinct'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'name' => ['nullable', 'string', 'max:191'],
            'keyword' => ['nullable', 'string', 'max:191'],
            'store' => ['nullable', 'string', 'max:191'],
            'decision' => ['nullable', Rule::in(['', 'continue', 'optimize', 'watch', 'stop'])],
            'sort' => ['nullable', Rule::in(['', 'name', 'sku', 'shop_count', 'sales', 'revenue', 'cost', 'fees', 'refunds', 'profit', 'profit_rate', 'refund_rate', 'decision'])],
            'direction' => ['nullable', Rule::in(['', 'asc', 'desc'])],
        ]);

        $filters = $this->normaliseFilters($validated);
        $products = $this->sortProducts(
            $this->filteredProducts($filters),
            trim((string) ($validated['sort'] ?? '')),
            trim((string) ($validated['direction'] ?? '')),
        );

        if ($validated['scope'] === 'selected') {
            $selected = array_fill_keys($validated['selected_ids'], true);
            $products = array_values(array_filter(
                $products,
                static fn (array $product): bool => isset($selected[$product['id']]),
            ));
        }

        if ($products === []) {
            throw ValidationException::withMessages([
                'scope' => __('logistics.product_statistics.export_empty'),
            ]);
        }

        return $this->spreadsheets->exportResponse(collect($products));
    }

    /** @return array{portal: \App\Models\Portal, user: \App\Models\AdminUser}|Response */
    private function portalContext(Request $request): array|Response
    {
        $context = $this->portalEntries->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }

        $this->logistics->assertLogisticsPortal($context['portal']);

        return $context;
    }

    /** @param array{portal: \App\Models\Portal, user: \App\Models\AdminUser} $context
     * @return array{portal: \App\Models\Portal, user: \App\Models\AdminUser}
     */
    private function viewContext(array $context): array
    {
        $context['portal']->load('menus');

        return $context;
    }

    /** @return array{date_from:string,date_to:string,name:string,keyword:string,store:string,decision:string} */
    private function filters(Request $request): array
    {
        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'name' => ['nullable', 'string', 'max:191'],
            'keyword' => ['nullable', 'string', 'max:191'],
            'store' => ['nullable', 'string', 'max:191'],
            'decision' => ['nullable', Rule::in(['', 'continue', 'optimize', 'watch', 'stop'])],
        ]);

        return $this->normaliseFilters($validated);
    }

    /** @param array<string, mixed> $input
     * @return array{date_from:string,date_to:string,name:string,keyword:string,store:string,decision:string}
     */
    private function normaliseFilters(array $input): array
    {
        return [
            'date_from' => trim((string) ($input['date_from'] ?? '')),
            'date_to' => trim((string) ($input['date_to'] ?? '')),
            'name' => trim((string) ($input['name'] ?? '')),
            'keyword' => trim((string) ($input['keyword'] ?? '')),
            'store' => trim((string) ($input['store'] ?? '')),
            'decision' => trim((string) ($input['decision'] ?? '')),
        ];
    }

    /** @param array{date_from:string,date_to:string,name:string,keyword:string,store:string,decision:string} $filters
     * @return list<array<string, mixed>>
     */
    private function filteredProducts(array $filters): array
    {
        return $this->filterProducts($this->products($filters), $filters);
    }

    /** @param array{date_from:string,date_to:string,name:string,keyword:string,store:string,decision:string} $filters
     * @return list<array<string, mixed>>
     */
    private function products(array $filters): array
    {
        $query = LogisticsOrderItem::query()
            ->with(['order.activeRefundDetails'])
            ->selectRaw('logistics_order_items.*, logistics_orders.store_name as statistics_store_name, logistics_orders.order_created_at as statistics_order_created_at, logistics_orders.shopify_order_number as statistics_shopify_order_number, logistics_orders.bill_number as statistics_bill_number')
            ->join('logistics_orders', 'logistics_orders.id', '=', 'logistics_order_items.logistics_order_id')
            ->whereNotNull('logistics_order_items.line_revenue_usd');

        if ($filters['date_from'] !== '') {
            $query->whereDate('logistics_orders.order_created_at', '>=', $filters['date_from']);
        }
        if ($filters['date_to'] !== '') {
            $query->whereDate('logistics_orders.order_created_at', '<=', $filters['date_to']);
        }
        if ($filters['store'] !== '') {
            $query->where('logistics_orders.store_name', $filters['store']);
        }

        $items = $query->orderBy('logistics_order_items.id')->get();
        $this->attachActualRefunds($items);
        $groups = [];
        foreach ($items as $item) {
            $key = $this->productKey($item);
            $groups[$key] ??= [];
            $groups[$key][] = $item;
        }

        $products = [];
        foreach ($groups as $key => $productItems) {
            $first = $productItems[0];
            $products[] = [
                'id' => 'product-'.sha1($key),
                'name' => $this->productTitle($first),
                'sku' => $this->sku($first),
                'category' => __('logistics.product_statistics.sold_product'),
                'image_url' => $this->firstImage($productItems),
                ...$this->summary($productItems),
                'variants' => $this->variants($productItems, $key),
                'store_metrics' => $this->storeMetrics($productItems),
                'trend' => $this->trend($productItems),
            ];
        }

        return $products;
    }

    /** @param list<LogisticsOrderItem> $items
     * @return array<string, mixed>
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

        return [
            'stores' => $stores,
            'shop_count' => count($stores),
            'sales' => $this->sum($items, 'quantity'),
            'revenue' => $revenue,
            'cost' => $cost,
            'shipping' => $this->sum($items, 'total_shipping_usd'),
            'handling' => $this->sum($items, 'handling_fee_usd'),
            'refunds' => $this->sum($items, 'proportional_refund_usd'),
            'actual_refunds' => $actualRefund['amount'],
            'actual_refund_count' => count($actualRefund['details']),
            'refund_details' => $actualRefund['details'],
            'profit' => $profit,
            'profit_rate' => $profit !== null && $revenue > 0 ? round($profit / $revenue * 100, 2) : null,
            'refund_rate' => $revenue > 0 ? round($this->sum($items, 'proportional_refund_usd') / $revenue * 100, 2) : 0.0,
            'decision' => $this->decision($profit, $revenue, $this->sum($items, 'proportional_refund_usd')),
        ];
    }

    /** @param list<LogisticsOrderItem> $items
     * @return list<array<string, mixed>>
     */
    private function variants(array $items, string $parentKey): array
    {
        $groups = [];
        foreach ($items as $item) {
            $name = trim((string) ($item->variant_title ?: $item->tongtu_product_name ?: $item->shopify_sku ?: $item->product_title));
            $key = mb_strtolower($name).'|'.mb_strtolower((string) $item->shopify_sku);
            $groups[$key] ??= [];
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
     * @return list<array<string, mixed>>
     */
    private function storeMetrics(array $items): array
    {
        $groups = [];
        foreach ($items as $item) {
            $store = trim((string) $item->getAttribute('statistics_store_name')) ?: __('logistics.common.unassigned_store');
            $groups[$store] ??= [];
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
            $groups[$label] ??= [];
            $groups[$label][] = $item;
        }
        ksort($groups);

        return array_map(function (array $dayItems, string $label): array {
            $summary = $this->summary($dayItems);

            return ['label' => $label, 'sales' => $summary['sales'], 'profit' => $summary['profit']];
        }, array_values($groups), array_keys($groups));
    }

    /** @param array{date_from:string,date_to:string,name:string,keyword:string,store:string,decision:string} $filters
     * @param list<array<string, mixed>> $products
     * @return list<array<string, mixed>>
     */
    private function filterProducts(array $products, array $filters): array
    {
        $name = mb_strtolower($filters['name']);
        $keyword = mb_strtolower($filters['keyword']);

        return array_values(array_filter($products, static function (array $product) use ($filters, $name, $keyword): bool {
            $variantText = implode(' ', array_map(static fn (array $variant): string => implode(' ', [
                (string) ($variant['name'] ?? ''), (string) ($variant['sku'] ?? ''),
            ]), $product['variants']));
            $haystack = mb_strtolower(implode(' ', [
                (string) $product['name'], (string) $product['sku'], $variantText,
            ]));

            return ($name === '' || str_contains(mb_strtolower((string) $product['name']), $name))
                && ($keyword === '' || str_contains($haystack, $keyword))
                && ($filters['decision'] === '' || $filters['decision'] === $product['decision']);
        }));
    }

    /** @param list<array<string, mixed>> $products
     * @return list<array<string, mixed>>
     */
    private function sortProducts(array $products, string $sort, string $direction): array
    {
        if ($sort === '' || $direction === '') {
            return $products;
        }

        usort($products, static function (array $left, array $right) use ($sort, $direction): int {
            $leftValue = $sort === 'fees' ? (float) $left['shipping'] + (float) $left['handling'] : $left[$sort];
            $rightValue = $sort === 'fees' ? (float) $right['shipping'] + (float) $right['handling'] : $right[$sort];
            if ($sort === 'decision') {
                $sequence = ['continue', 'optimize', 'watch', 'stop'];
                $comparison = array_search($leftValue, $sequence, true) <=> array_search($rightValue, $sequence, true);
            } elseif (is_string($leftValue)) {
                $comparison = strnatcasecmp($leftValue, (string) $rightValue);
            } else {
                $comparison = ($leftValue ?? -PHP_FLOAT_MAX) <=> ($rightValue ?? -PHP_FLOAT_MAX);
            }

            return $direction === 'asc' ? $comparison : -$comparison;
        });

        return $products;
    }

    /** @param array{date_from:string,date_to:string,name:string,keyword:string,store:string,decision:string} $filters
     * @return list<string>
     */
    private function storeOptions(array $filters): array
    {
        $query = LogisticsOrderItem::query()
            ->join('logistics_orders', 'logistics_orders.id', '=', 'logistics_order_items.logistics_order_id')
            ->whereNotNull('logistics_order_items.line_revenue_usd')
            ->whereNotNull('logistics_orders.store_name');
        if ($filters['date_from'] !== '') {
            $query->whereDate('logistics_orders.order_created_at', '>=', $filters['date_from']);
        }
        if ($filters['date_to'] !== '') {
            $query->whereDate('logistics_orders.order_created_at', '<=', $filters['date_to']);
        }

        return $query->orderBy('logistics_orders.store_name')
            ->distinct()
            ->pluck('logistics_orders.store_name')
            ->filter()
            ->values()
            ->all();
    }

    private function productKey(LogisticsOrderItem $item): string
    {
        $title = mb_strtolower(trim((string) $item->product_title));
        if ($title !== '') {
            return 'title:'.$title;
        }

        return 'sku:'.mb_strtolower($this->sku($item));
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

    /** @param \Illuminate\Support\Collection<int, LogisticsOrderItem> $items */
    private function attachActualRefunds($items): void
    {
        foreach ($items->groupBy('logistics_order_id') as $orderItems) {
            $order = $orderItems->first()?->order;
            $refunds = $order?->activeRefundDetails ?? collect();
            $weights = $orderItems->map(static fn (LogisticsOrderItem $item): float => max(0.0, (float) $item->line_revenue_usd));
            $total = (float) $weights->sum();
            if ($total <= 0) {
                $weights = $orderItems->map(static fn (LogisticsOrderItem $item): float => max(0.0, (float) $item->quantity));
                $total = (float) $weights->sum();
            }
            if ($total <= 0) {
                $weights = $orderItems->map(static fn (): float => 1.0);
                $total = (float) $weights->sum();
            }

            $orderItems = $orderItems->values();
            $weights = $weights->values();
            $lastIndex = $orderItems->count() - 1;
            $allocatedByRefund = [];
            foreach ($orderItems as $index => $item) {
                $details = [];
                foreach ($refunds as $refund) {
                    $refundId = (int) $refund->getKey();
                    $refundAmount = (float) $refund->refund_amount_usd;
                    $allocated = $index === $lastIndex
                        ? round($refundAmount - ($allocatedByRefund[$refundId] ?? 0.0), 4)
                        : round($refundAmount * ((float) $weights[$index] / $total), 4);
                    $allocatedByRefund[$refundId] = round(($allocatedByRefund[$refundId] ?? 0.0) + $allocated, 4);
                    $details[] = [
                        'id' => $refundId,
                        'store' => (string) $item->getAttribute('statistics_store_name'),
                        'shopify_order_number' => (string) $item->getAttribute('statistics_shopify_order_number'),
                        'bill_number' => (string) $item->getAttribute('statistics_bill_number'),
                        'refund_number' => (string) $refund->refund_number,
                        'amount' => $allocated,
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

    /** @return array<string, string> */
    private function decisionOptions(): array
    {
        return [
            'continue' => __('logistics.product_statistics.decisions.continue'),
            'optimize' => __('logistics.product_statistics.decisions.optimize'),
            'watch' => __('logistics.product_statistics.decisions.watch'),
            'stop' => __('logistics.product_statistics.decisions.stop'),
        ];
    }

    /** @return array<string, string> */
    private function labels(): array
    {
        return [
            'notAvailable' => __('logistics.common.not_available'),
            'view' => __('logistics.product_statistics.ui.view'),
            'detail' => __('logistics.product_statistics.ui.detail'),
            'variant' => __('logistics.product_statistics.ui.variant'),
            'orderedVariant' => __('logistics.product_statistics.ui.ordered_variant'),
            'pageEmpty' => __('logistics.product_statistics.ui.page_empty'),
            'pageSummary' => __('logistics.product_statistics.ui.page_summary'),
            'totalProducts' => __('logistics.product_statistics.ui.total_products'),
            'selectedCount' => __('logistics.product_statistics.ui.selected_count'),
            'filteredCount' => __('logistics.product_statistics.ui.filtered_count'),
            'selectExportWarning' => __('logistics.product_statistics.ui.select_export_warning'),
            'detailTitle' => __('logistics.product_statistics.ui.detail_title'),
            'variantDetailTitle' => __('logistics.product_statistics.ui.variant_detail_title'),
            'profitBreakdown' => __('logistics.product_statistics.ui.profit_breakdown'),
            'storeName' => __('logistics.product_statistics.ui.store_name'),
            'period' => __('logistics.product_statistics.ui.period'),
            'sales' => __('logistics.product_statistics.headers.sales'),
            'revenue' => __('logistics.product_statistics.headers.revenue'),
            'cost' => __('logistics.product_statistics.headers.cost'),
            'fees' => __('logistics.product_statistics.headers.fees'),
            'refunds' => __('logistics.product_statistics.headers.refunds'),
            'profit' => __('logistics.product_statistics.headers.profit'),
            'profitRate' => __('logistics.product_statistics.headers.profit_rate'),
            'refundRate' => __('logistics.product_statistics.headers.refund_rate'),
            'actualRefunds' => __('logistics.product_statistics.ui.actual_refunds'),
            'actualRefundCount' => __('logistics.product_statistics.ui.actual_refund_count'),
            'refundDetails' => __('logistics.product_statistics.ui.refund_details'),
            'refundAllocationNote' => __('logistics.product_statistics.ui.refund_allocation_note'),
            'salesChange' => __('logistics.product_statistics.ui.sales_change'),
        ];
    }
}
