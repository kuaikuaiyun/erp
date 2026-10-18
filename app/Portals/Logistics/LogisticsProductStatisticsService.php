<?php

namespace App\Portals\Logistics;

use App\Models\LogisticsOrder;
use App\Models\LogisticsOrderItem;
use App\Models\LogisticsPayPalDispute;
use App\Models\LogisticsPayPalRefund;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
        $query = $this->productStatisticsQuery($filters);
        $summary = $this->overallSummaryFromQuery($query, $filters);
        $perPage = (int) ($filters['per_page'] ?? 10);
        $page = max(1, (int) ($filters['page'] ?? 1));
        $total = (clone $query)->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);
        $offset = ($page - 1) * $perPage;
        $this->applyDatabaseSort($query, trim((string) ($filters['sort'] ?? '')), trim((string) ($filters['direction'] ?? '')));
        $productRows = $query->forPage($page, $perPage)
            ->get();
        $this->hydrateMissingProductImages($productRows);
        $products = $productRows
            ->map(fn (object $row): array => $this->listProduct($row))
            ->all();

        return [
            'products' => $products,
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
     * @return \Generator<int,array<string,mixed>>
     */
    public function exportRows(array $filters, ?array $selectedIds = null): \Generator
    {
        $query = $this->queryForExport($filters, $selectedIds);
        $this->applyDatabaseSort($query, trim((string) ($filters['sort'] ?? '')), trim((string) ($filters['direction'] ?? '')));

        foreach ($query->cursor() as $row) {
            yield $this->listProduct($row);
        }
    }

    /** @param array<string,mixed> $filters
     * @param list<string>|null $selectedIds
     */
    public function hasExportRows(array $filters, ?array $selectedIds = null): bool
    {
        return $this->queryForExport($filters, $selectedIds)->exists();
    }

    /**
     * Kept as a generator for the spreadsheet export contract. Rows supplied by
     * exportRows are already fully calculated and must not be recalculated.
     *
     * @param iterable<array<string,mixed>> $rows
     * @param array<string,mixed> $filters
     * @return \Generator<int,array<string,mixed>>
     */
    public function productsForRows(iterable $rows, array $filters): \Generator
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
        $query = $this->baseItemQuery($filters)
            ->whereNotNull('orders.store_name');

        return $query->orderBy('orders.store_name')
            ->distinct()
            ->pluck('orders.store_name')
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param array<string,mixed> $filters
     * @return array<string,mixed>|null
     */
    public function detail(array $filters, string $productId): ?array
    {
        $key = $this->decodeProductId($productId);
        if ($key === null) {
            return null;
        }

        $items = $this->detailItemsForProduct($filters, $key);
        if ($items->isEmpty()) {
            return null;
        }

        $product = $this->product($items->all(), $key);
        $decision = trim((string) ($filters['decision'] ?? ''));

        return $decision === '' || $decision === $product['decision'] ? $product : null;
    }

    /**
     * @param array<string,mixed> $filters
     * @return Collection<int,LogisticsOrderItem>
     */
    private function detailItemsForProduct(array $filters, string $key): Collection
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
            ->whereNotNull('logistics_order_items.line_revenue_usd')
            ->whereRaw($this->productKeyExpression('logistics_order_items').' = ?', [$key]);
        $this->applyBaseFilters($query, $filters);

        return $query->orderBy('logistics_order_items.id')->get();
    }

    /** @param array<string,mixed> $filters */
    private function productStatisticsQuery(array $filters): Builder
    {
        $itemStatistics = $this->itemAggregateQuery($filters);
        $orderStatistics = $this->productOrderStatisticsQuery($filters);
        $query = $this->database()->query()
            ->fromSub($itemStatistics, 'item_statistics')
            ->join('logistics_order_items as first_item', 'first_item.id', '=', 'item_statistics.first_item_id')
            ->leftJoin('logistics_order_items as image_item', 'image_item.id', '=', 'item_statistics.image_item_id')
            ->leftJoinSub($orderStatistics, 'order_statistics', 'order_statistics.product_key', '=', 'item_statistics.product_key')
            ->select([
                'item_statistics.product_key',
                'item_statistics.first_item_id',
                'item_statistics.store_names',
                'item_statistics.shop_count',
                'item_statistics.tongtu_skus',
                'item_statistics.tongtu_variant_count',
                'item_statistics.sales',
                'item_statistics.revenue',
                'item_statistics.cost',
                'item_statistics.missing_cost_count',
                'item_statistics.shipping',
                'item_statistics.handling',
                'first_item.product_title',
                'first_item.tongtu_product_name',
                'first_item.tongtu_product_code',
                'first_item.tongtu_sku',
                'first_item.shopify_sku',
            ])
            ->selectRaw("COALESCE(NULLIF(TRIM(first_item.product_image_url), ''), NULLIF(TRIM(image_item.product_image_url), '')) as product_image_url")
            ->selectRaw('COALESCE(order_statistics.paypal_refund_amount, 0) as paypal_refund_amount')
            ->selectRaw('COALESCE(order_statistics.paypal_refund_count, 0) as paypal_refund_count')
            ->selectRaw('COALESCE(order_statistics.paypal_refund_order_count, 0) as paypal_refund_order_count')
            ->selectRaw('COALESCE(order_statistics.paypal_order_count, 0) as paypal_order_count')
            ->selectRaw('COALESCE(order_statistics.paypal_dispute_count, 0) as paypal_dispute_count')
            ->selectRaw('COALESCE(order_statistics.paypal_dispute_order_count, 0) as paypal_dispute_order_count')
            ->selectRaw('COALESCE(order_statistics.order_count, 0) as order_count')
            ->selectRaw('COALESCE(order_statistics.shipped_order_count, 0) as shipped_order_count')
            ->selectRaw('COALESCE(order_statistics.delivered_order_count, 0) as delivered_order_count')
            ->selectRaw('COALESCE(order_statistics.paypal_module_unit_count, 0) as paypal_module_unit_count')
            ->selectRaw('COALESCE(order_statistics.paypal_module_revenue, 0) as paypal_module_revenue')
            ->selectRaw('COALESCE(order_statistics.paypal_module_order_count, 0) as paypal_module_order_count')
            ->selectRaw('COALESCE(order_statistics.paypal_module_refund_amount, 0) as paypal_module_refund_amount')
            ->selectRaw('COALESCE(order_statistics.paypal_module_refund_count, 0) as paypal_module_refund_count')
            ->selectRaw('COALESCE(order_statistics.paypal_module_dispute_count, 0) as paypal_module_dispute_count')
            ->selectRaw('COALESCE(order_statistics.paypal_module_dispute_order_count, 0) as paypal_module_dispute_order_count')
            ->selectRaw('COALESCE(order_statistics.paypal_module_dispute_amount, 0) as paypal_module_dispute_amount')
            ->selectRaw('COALESCE(order_statistics.paypal_module_shipped_order_count, 0) as paypal_module_shipped_order_count')
            ->selectRaw('COALESCE(order_statistics.paypal_module_delivered_order_count, 0) as paypal_module_delivered_order_count')
            ->selectRaw('COALESCE(order_statistics.credit_card_module_unit_count, 0) as credit_card_module_unit_count')
            ->selectRaw('COALESCE(order_statistics.credit_card_module_revenue, 0) as credit_card_module_revenue')
            ->selectRaw('COALESCE(order_statistics.credit_card_module_order_count, 0) as credit_card_module_order_count')
            ->selectRaw('COALESCE(order_statistics.credit_card_module_refund_amount, 0) as credit_card_module_refund_amount')
            ->selectRaw('COALESCE(order_statistics.credit_card_module_refund_count, 0) as credit_card_module_refund_count')
            ->selectRaw('COALESCE(order_statistics.credit_card_module_shipped_order_count, 0) as credit_card_module_shipped_order_count')
            ->selectRaw('COALESCE(order_statistics.credit_card_module_delivered_order_count, 0) as credit_card_module_delivered_order_count')
            ->selectRaw('COALESCE(order_statistics.credit_card_refund_amount, 0) as credit_card_refund_amount')
            ->selectRaw('COALESCE(order_statistics.credit_card_refund_count, 0) as credit_card_refund_count')
            ->selectRaw('COALESCE(order_statistics.credit_card_refund_order_count, 0) as credit_card_refund_order_count')
            ->selectRaw('COALESCE(order_statistics.credit_card_order_count, 0) as credit_card_order_count')
            ->selectRaw('COALESCE(order_statistics.combined_refund_order_count, 0) as combined_refund_order_count')
            ->selectRaw('COALESCE(order_statistics.paid_order_count, 0) as paid_order_count');

        $cost = 'CASE WHEN product_data.missing_cost_count > 0 THEN NULL ELSE product_data.cost END';
        $refunds = '(product_data.paypal_refund_amount + product_data.credit_card_refund_amount)';
        $profit = "CASE WHEN product_data.missing_cost_count > 0 THEN NULL ELSE product_data.revenue - product_data.cost - product_data.shipping - product_data.handling - {$refunds} END";
        $calculated = $this->database()->query()
            ->fromSub($query, 'product_data')
            ->select('product_data.*')
            ->selectRaw("{$cost} as cost_complete")
            ->selectRaw("{$refunds} as actual_refunds")
            ->selectRaw("{$profit} as profit")
            ->selectRaw('CASE WHEN product_data.paypal_order_count > 0 THEN product_data.paypal_refund_order_count / product_data.paypal_order_count * 100 ELSE 0 END as paypal_refund_rate')
            ->selectRaw('CASE WHEN product_data.paypal_order_count > 0 THEN product_data.paypal_dispute_order_count / product_data.paypal_order_count * 100 ELSE 0 END as paypal_dispute_rate')
            ->selectRaw('CASE WHEN product_data.credit_card_order_count > 0 THEN product_data.credit_card_refund_order_count / product_data.credit_card_order_count * 100 ELSE 0 END as credit_card_refund_rate')
            ->selectRaw('CASE WHEN product_data.paid_order_count > 0 THEN product_data.combined_refund_order_count / product_data.paid_order_count * 100 ELSE 0 END as combined_refund_rate');

        $profitStatistics = $this->database()->query()
            ->fromSub($calculated, 'product_statistics')
            ->select('product_statistics.*')
            ->selectRaw('CASE WHEN product_statistics.cost_complete IS NULL OR product_statistics.revenue <= 0 THEN NULL ELSE product_statistics.profit / product_statistics.revenue * 100 END as profit_rate');

        $statistics = $this->database()->query()
            ->fromSub($profitStatistics, 'product_statistics')
            ->select('product_statistics.*')
            ->selectRaw($this->decisionExpression('product_statistics').' as decision');

        $result = $this->database()->query()->fromSub($statistics, 'product_statistics')->select('product_statistics.*');
        $decision = trim((string) ($filters['decision'] ?? ''));
        if ($decision !== '') {
            $result->where('product_statistics.decision', $decision);
        }

        return $result;
    }

    /** @param array<string,mixed> $filters */
    private function itemAggregateQuery(array $filters): Builder
    {
        $key = $this->productKeyExpression('items');

        return $this->baseItemQuery($filters)
            ->selectRaw("{$key} as product_key")
            ->selectRaw('MIN(items.id) as first_item_id')
            ->selectRaw("MIN(CASE WHEN NULLIF(TRIM(items.product_image_url), '') IS NOT NULL THEN items.id END) as image_item_id")
            ->selectRaw("GROUP_CONCAT(DISTINCT COALESCE(NULLIF(TRIM(orders.store_name), ''), '__unassigned__') ORDER BY COALESCE(NULLIF(TRIM(orders.store_name), ''), '__unassigned__') SEPARATOR 0x1F) as store_names")
            ->selectRaw("COUNT(DISTINCT COALESCE(NULLIF(TRIM(orders.store_name), ''), '__unassigned__')) as shop_count")
            ->selectRaw("GROUP_CONCAT(DISTINCT NULLIF(TRIM(items.tongtu_sku), '') ORDER BY NULLIF(TRIM(items.tongtu_sku), '') SEPARATOR 0x1F) as tongtu_skus")
            ->selectRaw("COUNT(DISTINCT NULLIF(TRIM(items.tongtu_sku), '')) as tongtu_variant_count")
            ->selectRaw('SUM(COALESCE(items.quantity, 0)) as sales')
            ->selectRaw('SUM(COALESCE(items.line_revenue_usd, 0)) as revenue')
            ->selectRaw('SUM(COALESCE(items.product_cost_usd, 0)) as cost')
            ->selectRaw('SUM(CASE WHEN items.product_cost_usd IS NULL THEN 1 ELSE 0 END) as missing_cost_count')
            ->selectRaw('SUM(COALESCE(items.total_shipping_usd, 0)) as shipping')
            ->selectRaw('SUM(COALESCE(items.handling_fee_usd, 0)) as handling')
            ->groupByRaw($key);
    }

    /** @param Collection<int,object> $rows */
    private function hydrateMissingProductImages(Collection $rows): void
    {
        $missingRows = $rows->filter(static fn (object $row): bool => !filled($row->product_image_url ?? null));
        if ($missingRows->isEmpty()) {
            return;
        }

        $skuKeys = $missingRows
            ->flatMap(fn (object $row): array => $this->imageSkuKeys($row))
            ->unique()
            ->values()
            ->all();
        if ($skuKeys === []) {
            return;
        }

        $imageRows = $this->database()
            ->table('logistics_order_items')
            ->select(['tongtu_product_code', 'tongtu_sku', 'shopify_sku', 'product_image_url'])
            ->whereRaw("NULLIF(TRIM(product_image_url), '') IS NOT NULL")
            ->where(function (Builder $query) use ($skuKeys): void {
                $query->whereIn(DB::raw("LOWER(NULLIF(TRIM(tongtu_product_code), ''))"), $skuKeys)
                    ->orWhereIn(DB::raw("LOWER(NULLIF(TRIM(tongtu_sku), ''))"), $skuKeys)
                    ->orWhereIn(DB::raw("LOWER(NULLIF(TRIM(shopify_sku), ''))"), $skuKeys);
            })
            ->orderBy('id')
            ->get();

        $imagesBySku = [];
        foreach ($imageRows as $imageRow) {
            $imageUrl = trim((string) ($imageRow->product_image_url ?? ''));
            if ($imageUrl === '') {
                continue;
            }

            foreach ($this->imageSkuKeys($imageRow) as $skuKey) {
                $imagesBySku[$skuKey] ??= $imageUrl;
            }
        }

        foreach ($missingRows as $row) {
            foreach ($this->imageSkuKeys($row) as $skuKey) {
                if (!isset($imagesBySku[$skuKey])) {
                    continue;
                }

                $row->product_image_url = $imagesBySku[$skuKey];
                break;
            }
        }
    }

    /** @return list<string> */
    private function imageSkuKeys(object $row): array
    {
        $keys = [];
        foreach (['tongtu_product_code', 'tongtu_sku', 'shopify_sku'] as $property) {
            $value = mb_strtolower(trim((string) ($row->{$property} ?? '')));
            if ($value !== '') {
                $keys[] = $value;
            }
        }

        return array_values(array_unique($keys));
    }

    /** @param array<string,mixed> $filters */
    private function productOrderStatisticsQuery(array $filters): Builder
    {
        $orders = $this->productOrderQuery($filters);
        $paypalRefunds = $this->paypalRefundAggregateQuery();
        $paypalDisputes = $this->paypalDisputeAggregateQuery();
        $creditCardRefunds = $this->creditCardRefundAggregateQuery();
        $paid = '(product_orders.paid_at IS NOT NULL OR COALESCE(product_orders.paid_amount_usd, 0) > 0)';
        $actualPaypal = "(product_orders.paypal_account_id IS NOT NULL AND product_orders.paypal_association_source = 'actual_transaction')";
        // Use the UTF-8 bytes for “信用卡” to prevent a collation conflict between
        // database fields (for example utf8mb4_0900_ai_ci) and the connection default.
        // The English gateway values remain normal case-insensitive LIKE comparisons.
        // The Shopify tag is the stable source of truth for the independent
        // credit-card platform. Presentation gateway fields are not reliable.
        $creditCardTag = "(CASE WHEN JSON_VALID(COALESCE(product_orders.shopify_payload, '')) THEN JSON_SEARCH(JSON_EXTRACT(product_orders.shopify_payload, '$.tags'), 'one', '%CreditCardBy%') ELSE NULL END IS NOT NULL)";
        $creditCard = $creditCardTag;
        $paypalModule = "({$actualPaypal} AND NOT {$creditCardTag})";
        $quote = fn (string $value): string => $this->database()->getPdo()->quote($value);
        $shipped = 'product_orders.logistics_status IN ('.implode(', ', array_map($quote, [
            LogisticsOrder::LOGISTICS_IN_TRANSIT,
            LogisticsOrder::LOGISTICS_EXCEPTION,
            LogisticsOrder::LOGISTICS_DELIVERED,
        ])).')';
        $delivered = 'product_orders.logistics_status = '.$quote(LogisticsOrder::LOGISTICS_DELIVERED);

        return $this->database()->query()
            ->fromSub($orders, 'product_orders')
            ->leftJoinSub($paypalRefunds, 'paypal_refunds', function ($join): void {
                $join->on('paypal_refunds.logistics_order_id', '=', 'product_orders.order_id')
                    ->on('paypal_refunds.logistics_paypal_account_id', '=', 'product_orders.paypal_account_id');
            })
            ->leftJoinSub($paypalDisputes, 'paypal_disputes', function ($join): void {
                $join->on('paypal_disputes.logistics_order_id', '=', 'product_orders.order_id')
                    ->on('paypal_disputes.logistics_paypal_account_id', '=', 'product_orders.paypal_account_id');
            })
            ->leftJoinSub($creditCardRefunds, 'credit_card_refunds', 'credit_card_refunds.logistics_order_id', '=', 'product_orders.order_id')
            ->select('product_orders.product_key')
            ->selectRaw('COUNT(*) as order_count')
            ->selectRaw("SUM(CASE WHEN {$shipped} THEN 1 ELSE 0 END) as shipped_order_count")
            ->selectRaw("SUM(CASE WHEN {$delivered} THEN 1 ELSE 0 END) as delivered_order_count")
            ->selectRaw("SUM(CASE WHEN {$paypalModule} THEN COALESCE(product_orders.unit_count, 0) ELSE 0 END) as paypal_module_unit_count")
            ->selectRaw("SUM(CASE WHEN {$paypalModule} THEN COALESCE(product_orders.revenue, 0) ELSE 0 END) as paypal_module_revenue")
            ->selectRaw("SUM(CASE WHEN {$paypalModule} THEN 1 ELSE 0 END) as paypal_module_order_count")
            ->selectRaw("SUM(CASE WHEN {$paypalModule} THEN COALESCE(paypal_refunds.refund_amount, 0) ELSE 0 END) as paypal_module_refund_amount")
            ->selectRaw("SUM(CASE WHEN {$paypalModule} THEN COALESCE(paypal_refunds.refund_count, 0) ELSE 0 END) as paypal_module_refund_count")
            ->selectRaw("SUM(CASE WHEN {$paypalModule} THEN COALESCE(paypal_disputes.dispute_count, 0) ELSE 0 END) as paypal_module_dispute_count")
            ->selectRaw("SUM(CASE WHEN {$paypalModule} AND COALESCE(paypal_disputes.dispute_count, 0) > 0 THEN 1 ELSE 0 END) as paypal_module_dispute_order_count")
            ->selectRaw("SUM(CASE WHEN {$paypalModule} THEN COALESCE(paypal_disputes.dispute_amount, 0) ELSE 0 END) as paypal_module_dispute_amount")
            ->selectRaw("SUM(CASE WHEN {$paypalModule} AND {$shipped} THEN 1 ELSE 0 END) as paypal_module_shipped_order_count")
            ->selectRaw("SUM(CASE WHEN {$paypalModule} AND {$delivered} THEN 1 ELSE 0 END) as paypal_module_delivered_order_count")
            ->selectRaw("SUM(CASE WHEN {$creditCard} THEN COALESCE(product_orders.unit_count, 0) ELSE 0 END) as credit_card_module_unit_count")
            ->selectRaw("SUM(CASE WHEN {$creditCard} THEN COALESCE(product_orders.revenue, 0) ELSE 0 END) as credit_card_module_revenue")
            ->selectRaw("SUM(CASE WHEN {$creditCard} THEN 1 ELSE 0 END) as credit_card_module_order_count")
            ->selectRaw("SUM(CASE WHEN {$creditCard} THEN COALESCE(credit_card_refunds.refund_amount, 0) ELSE 0 END) as credit_card_module_refund_amount")
            ->selectRaw("SUM(CASE WHEN {$creditCard} THEN COALESCE(credit_card_refunds.refund_count, 0) ELSE 0 END) as credit_card_module_refund_count")
            ->selectRaw("SUM(CASE WHEN {$creditCard} AND {$shipped} THEN 1 ELSE 0 END) as credit_card_module_shipped_order_count")
            ->selectRaw("SUM(CASE WHEN {$creditCard} AND {$delivered} THEN 1 ELSE 0 END) as credit_card_module_delivered_order_count")
            ->selectRaw("SUM(CASE WHEN {$paid} THEN 1 ELSE 0 END) as paid_order_count")
            ->selectRaw("SUM(CASE WHEN {$paid} AND {$actualPaypal} THEN 1 ELSE 0 END) as paypal_order_count")
            ->selectRaw("SUM(CASE WHEN {$actualPaypal} THEN COALESCE(paypal_refunds.refund_amount, 0) ELSE 0 END) as paypal_refund_amount")
            ->selectRaw("SUM(CASE WHEN {$actualPaypal} THEN COALESCE(paypal_refunds.refund_count, 0) ELSE 0 END) as paypal_refund_count")
            ->selectRaw("SUM(CASE WHEN {$paid} AND {$actualPaypal} AND COALESCE(paypal_refunds.refund_count, 0) > 0 THEN 1 ELSE 0 END) as paypal_refund_order_count")
            ->selectRaw("SUM(CASE WHEN {$actualPaypal} THEN COALESCE(paypal_disputes.dispute_count, 0) ELSE 0 END) as paypal_dispute_count")
            ->selectRaw("SUM(CASE WHEN {$paid} AND {$actualPaypal} AND COALESCE(paypal_disputes.dispute_count, 0) > 0 THEN 1 ELSE 0 END) as paypal_dispute_order_count")
            ->selectRaw("SUM(CASE WHEN {$paid} AND {$creditCard} THEN 1 ELSE 0 END) as credit_card_order_count")
            ->selectRaw('SUM(COALESCE(credit_card_refunds.refund_amount, 0)) as credit_card_refund_amount')
            ->selectRaw('SUM(COALESCE(credit_card_refunds.refund_count, 0)) as credit_card_refund_count')
            ->selectRaw("SUM(CASE WHEN {$paid} AND {$creditCard} AND COALESCE(credit_card_refunds.refund_count, 0) > 0 THEN 1 ELSE 0 END) as credit_card_refund_order_count")
            ->selectRaw("SUM(CASE WHEN {$paid} AND (COALESCE(paypal_refunds.refund_count, 0) > 0 OR COALESCE(credit_card_refunds.refund_count, 0) > 0) THEN 1 ELSE 0 END) as combined_refund_order_count")
            ->groupBy('product_orders.product_key');
    }

    /** @param array<string,mixed> $filters */
    private function productOrderQuery(array $filters): Builder
    {
        $key = $this->productKeyExpression('items');

        return $this->baseItemQuery($filters)
            ->selectRaw("{$key} as product_key")
            ->addSelect('orders.id as order_id')
            ->selectRaw('SUM(COALESCE(items.quantity, 0)) as unit_count')
            ->selectRaw('SUM(COALESCE(items.line_revenue_usd, 0)) as revenue')
            ->selectRaw('MAX(orders.paid_at) as paid_at')
            ->selectRaw('MAX(COALESCE(orders.paid_amount_usd, 0)) as paid_amount_usd')
            ->selectRaw('MAX(orders.paypal_account_id) as paypal_account_id')
            ->selectRaw('MAX(orders.paypal_association_source) as paypal_association_source')
            ->selectRaw('MAX(orders.payment_method) as payment_method')
            ->selectRaw('MAX(orders.payment_gateway) as payment_gateway')
            ->selectRaw('MAX(orders.logistics_status) as logistics_status')
            ->selectRaw('MAX(orders.shopify_payload) as shopify_payload')
            ->groupByRaw("{$key}, orders.id");
    }

    private function paypalRefundAggregateQuery(): Builder
    {
        return $this->database()->table('logistics_paypal_refunds as refund')
            ->whereNotNull('refund.logistics_order_id')
            ->select(['refund.logistics_order_id', 'refund.logistics_paypal_account_id'])
            ->selectRaw("SUM(COALESCE(refund.refund_amount_usd, CASE WHEN UPPER(refund.currency_code) = 'USD' THEN refund.original_amount ELSE 0 END)) as refund_amount")
            ->selectRaw('COUNT(*) as refund_count')
            ->groupBy('refund.logistics_order_id', 'refund.logistics_paypal_account_id');
    }

    private function paypalDisputeAggregateQuery(): Builder
    {
        return $this->database()->table('logistics_paypal_disputes as dispute')
            ->whereNotNull('dispute.logistics_order_id')
            ->select(['dispute.logistics_order_id', 'dispute.logistics_paypal_account_id'])
            ->selectRaw('COUNT(*) as dispute_count')
            ->selectRaw("SUM(COALESCE(dispute.dispute_amount_usd, CASE WHEN UPPER(dispute.currency_code) = 'USD' THEN dispute.original_amount ELSE 0 END)) as dispute_amount")
            ->groupBy('dispute.logistics_order_id', 'dispute.logistics_paypal_account_id');
    }

    private function creditCardRefundAggregateQuery(): Builder
    {
        return $this->database()->table('logistics_credit_card_refunds as refund')
            ->select('refund.logistics_order_id')
            ->selectRaw('SUM(refund.refund_amount_usd) as refund_amount')
            ->selectRaw('COUNT(*) as refund_count')
            ->groupBy('refund.logistics_order_id');
    }

    /** @param array<string,mixed> $filters */
    private function baseItemQuery(array $filters): Builder
    {
        $query = $this->database()->table('logistics_order_items as items')
            ->join('logistics_orders as orders', 'orders.id', '=', 'items.logistics_order_id')
            ->whereNotNull('items.line_revenue_usd');
        $this->applyBaseFilters($query, $filters, 'items', 'orders');

        return $query;
    }

    /** @param array<string,mixed> $filters */
    private function queryForExport(array $filters, ?array $selectedIds): Builder
    {
        $query = $this->productStatisticsQuery($filters);
        if ($selectedIds === null) {
            return $query;
        }

        $keys = array_values(array_filter(array_map(fn (string $id): ?string => $this->decodeProductId($id), $selectedIds)));
        if ($keys === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('product_statistics.product_key', $keys);
    }

    /** @param array<string,mixed> $filters */
    private function overallSummaryFromQuery(Builder $products, array $filters): array
    {
        $productStatistics = clone $products;
        $aggregate = $this->database()->query()->fromSub($productStatistics, 'product_statistics')
            ->selectRaw('COUNT(*) as product_count')
            ->selectRaw('COALESCE(SUM(product_statistics.sales), 0) as sales')
            ->selectRaw('COALESCE(SUM(product_statistics.revenue), 0) as revenue')
            ->selectRaw('COALESCE(SUM(product_statistics.shipping), 0) as shipping')
            ->selectRaw('COALESCE(SUM(product_statistics.handling), 0) as handling')
            ->selectRaw('COALESCE(SUM(product_statistics.cost_complete), 0) as cost')
            ->selectRaw('SUM(CASE WHEN product_statistics.cost_complete IS NULL THEN 1 ELSE 0 END) as has_missing_cost')
            ->first();
        $refunds = $this->uniqueRefundSummary($products, $filters);
        $revenue = round((float) ($aggregate->revenue ?? 0), 4);
        $cost = (int) ($aggregate->has_missing_cost ?? 0) > 0 ? null : round((float) ($aggregate->cost ?? 0), 4);
        $shipping = round((float) ($aggregate->shipping ?? 0), 4);
        $handling = round((float) ($aggregate->handling ?? 0), 4);
        $actualRefunds = round((float) $refunds['paypal'] + (float) $refunds['credit_card'], 4);
        $profit = $cost === null ? null : round($revenue - $cost - $shipping - $handling - $actualRefunds, 4);

        return [
            'product_count' => (int) ($aggregate->product_count ?? 0),
            'variant_count' => 0,
            'sales' => round((float) ($aggregate->sales ?? 0), 4),
            'revenue' => $revenue,
            'actual_refunds' => $actualRefunds,
            'profit' => $profit,
            'profit_rate' => $profit !== null && $revenue > 0 ? round($profit / $revenue * 100, 2) : null,
        ];
    }

    /**
     * Refunds are intentionally unique across all filtered products in the
     * summary. A refund is fully shown on every affected product row, but must
     * not be counted repeatedly in the page-level total.
     *
     * @param array<string,mixed> $filters
     * @return array{paypal:float,credit_card:float}
     */
    private function uniqueRefundSummary(Builder $products, array $filters): array
    {
        $productKeys = (clone $products)->select('product_statistics.product_key');
        $selectedOrders = $this->database()->query()
            ->fromSub($this->productOrderQuery($filters), 'product_orders')
            ->joinSub($productKeys, 'product_statistics', 'product_statistics.product_key', '=', 'product_orders.product_key')
            ->select('product_orders.order_id')
            ->selectRaw('MAX(product_orders.paypal_account_id) as paypal_account_id')
            ->selectRaw('MAX(product_orders.paypal_association_source) as paypal_association_source')
            ->groupBy('product_orders.order_id');
        $paypalRefunds = $this->paypalRefundAggregateQuery();
        $creditCardRefunds = $this->creditCardRefundAggregateQuery();
        $summary = $this->database()->query()
            ->fromSub($selectedOrders, 'selected_orders')
            ->leftJoinSub($paypalRefunds, 'paypal_refunds', function ($join): void {
                $join->on('paypal_refunds.logistics_order_id', '=', 'selected_orders.order_id')
                    ->on('paypal_refunds.logistics_paypal_account_id', '=', 'selected_orders.paypal_account_id');
            })
            ->leftJoinSub($creditCardRefunds, 'credit_card_refunds', 'credit_card_refunds.logistics_order_id', '=', 'selected_orders.order_id')
            ->selectRaw("COALESCE(SUM(CASE WHEN selected_orders.paypal_account_id IS NOT NULL AND selected_orders.paypal_association_source = 'actual_transaction' THEN COALESCE(paypal_refunds.refund_amount, 0) ELSE 0 END), 0) as paypal")
            ->selectRaw('COALESCE(SUM(COALESCE(credit_card_refunds.refund_amount, 0)), 0) as credit_card')
            ->first();

        return [
            'paypal' => round((float) ($summary->paypal ?? 0), 4),
            'credit_card' => round((float) ($summary->credit_card ?? 0), 4),
        ];
    }

    private function listProduct(object $row): array
    {
        $stores = array_values(array_filter(explode("\x1F", (string) ($row->store_names ?? ''))));
        $stores = array_map(static fn (string $store): string => $store === '__unassigned__' ? __('logistics.common.unassigned_store') : $store, $stores);
        $tongtuProductCode = trim((string) ($row->tongtu_product_code ?? ''));
        $shopifySku = trim((string) ($row->shopify_sku ?? ''));
        $tongtuSku = trim((string) ($row->tongtu_sku ?? ''));
        $fallbackSku = $shopifySku !== '' ? $shopifySku : $tongtuSku;
        $cost = $row->cost_complete === null ? null : round((float) $row->cost_complete, 4);
        $refunds = round((float) $row->paypal_refund_amount + (float) $row->credit_card_refund_amount, 4);
        $profit = $row->profit === null ? null : round((float) $row->profit, 4);

        return [
            'id' => $this->productId((string) $row->product_key),
            'name' => trim((string) ($row->product_title ?: $row->tongtu_product_name)) ?: __('logistics.product_statistics.unnamed_product'),
            'matched_name' => $this->matchedChineseProductName(
                (string) $row->product_title,
                (string) $row->tongtu_product_name,
            ),
            'has_tongtu_match' => $tongtuProductCode !== '',
            'product_code' => $tongtuProductCode !== '' ? $tongtuProductCode : null,
            'fallback_shopify_sku' => $fallbackSku !== '' ? $fallbackSku : null,
            // Keep this compatibility key for consumers which have not yet
            // switched from SKU to the product-level PCL identifier.
            'sku' => $tongtuProductCode !== '' ? $tongtuProductCode : ($fallbackSku ?: __('logistics.common.not_available')),
            'variant_count' => (int) ($row->tongtu_variant_count ?? 0),
            'variant_skus' => array_values(array_filter(explode("\x1F", (string) ($row->tongtu_skus ?? '')))),
            'category' => __('logistics.product_statistics.sold_product'),
            'image_url' => filled($row->product_image_url) ? (string) $row->product_image_url : null,
            'stores' => $stores,
            'shop_count' => (int) $row->shop_count,
            'sales' => round((float) $row->sales, 4),
            'revenue' => round((float) $row->revenue, 4),
            'cost' => $cost,
            'shipping' => round((float) $row->shipping, 4),
            'handling' => round((float) $row->handling, 4),
            'refunds' => $refunds,
            'actual_refunds' => $refunds,
            'paypal_refund_amount' => round((float) $row->paypal_refund_amount, 4),
            'paypal_refund_count' => (int) $row->paypal_refund_count,
            'paypal_refund_rate' => round((float) $row->paypal_refund_rate, 2),
            'paypal_dispute_count' => (int) $row->paypal_dispute_count,
            'paypal_dispute_rate' => round((float) $row->paypal_dispute_rate, 2),
            'credit_card_refund_amount' => round((float) $row->credit_card_refund_amount, 4),
            'credit_card_refund_count' => (int) $row->credit_card_refund_count,
            'credit_card_refund_rate' => round((float) $row->credit_card_refund_rate, 2),
            'combined_refund_rate' => round((float) $row->combined_refund_rate, 2),
            'profit' => $profit,
            'actual_profit' => $profit,
            'profit_rate' => $row->profit_rate === null ? null : round((float) $row->profit_rate, 2),
            'refund_rate' => round((float) $row->combined_refund_rate, 2),
            'decision' => (string) $row->decision,
            'overview_modules' => $this->overviewModulesFromRow($row),
        ];
    }

    /**
     * The product list already computes these values from the same source
     * records.  Keeping a compact overview here lets the modal open without
     * first loading every order, refund and dispute row for the product.
     *
     * @return array{overall:array<string,mixed>,paypal:array<string,mixed>,credit_card:array<string,mixed>}
     */
    private function overviewModulesFromRow(object $row): array
    {
        $paypal = $this->overviewModuleFromAggregate(
            (float) $row->paypal_module_unit_count,
            (int) $row->paypal_module_order_count,
            (float) $row->paypal_module_revenue,
            (float) $row->paypal_module_refund_amount,
            (int) $row->paypal_module_refund_count,
            (int) $row->paypal_module_shipped_order_count,
            (int) $row->paypal_module_delivered_order_count,
            (int) $row->paypal_module_dispute_count,
            (int) $row->paypal_module_dispute_order_count,
            (float) $row->paypal_module_dispute_amount,
            true,
        );
        $creditCard = $this->overviewModuleFromAggregate(
            (float) $row->credit_card_module_unit_count,
            (int) $row->credit_card_module_order_count,
            (float) $row->credit_card_module_revenue,
            (float) $row->credit_card_module_refund_amount,
            (int) $row->credit_card_module_refund_count,
            (int) $row->credit_card_module_shipped_order_count,
            (int) $row->credit_card_module_delivered_order_count,
            0,
            0,
            0.0,
            false,
        );

        return [
            'overall' => $this->overviewModuleFromAggregate(
                (float) $row->sales,
                (int) $row->order_count,
                (float) $row->revenue,
                $paypal['refund_amount'] + $creditCard['refund_amount'],
                $paypal['refund_count'] + $creditCard['refund_count'],
                (int) $row->shipped_order_count,
                (int) $row->delivered_order_count,
                (int) $row->paypal_module_dispute_count,
                (int) $row->paypal_module_dispute_order_count,
                (float) $row->paypal_module_dispute_amount,
                true,
            ),
            'paypal' => $paypal,
            'credit_card' => $creditCard,
        ];
    }

    /** @return array<string,mixed> */
    private function overviewModuleFromAggregate(
        float $unitCount,
        int $orderCount,
        float $receivedAmount,
        float $refundAmount,
        int $refundCount,
        int $shippingCount,
        int $deliveryCount,
        int $disputeCount,
        int $disputeOrderCount,
        float $disputeAmount,
        bool $disputesApplicable,
    ): array {
        return [
            'unit_count' => round($unitCount, 4),
            'order_count' => $orderCount,
            'received_amount' => round($receivedAmount, 4),
            'refund_amount' => round($refundAmount, 4),
            'refund_count' => $refundCount,
            'refund_rate' => $receivedAmount > 0 ? round($refundAmount / $receivedAmount * 100, 2) : 0.0,
            'shipping_count' => $shippingCount,
            'delivery_count' => $deliveryCount,
            'shipping_rate' => $orderCount > 0 ? round($shippingCount / $orderCount * 100, 2) : 0.0,
            'delivery_rate' => $shippingCount > 0 ? round($deliveryCount / $shippingCount * 100, 2) : 0.0,
            'dispute_applicable' => $disputesApplicable,
            'dispute_count' => $disputeCount,
            'dispute_order_count' => $disputeOrderCount,
            'dispute_amount' => round($disputeAmount, 4),
            'dispute_rate' => $orderCount > 0 ? round($disputeOrderCount / $orderCount * 100, 2) : 0.0,
            // Reason detail needs the explicit dispute records and is filled
            // after the user opens a detail tab.
            'dispute_reason_breakdown' => [],
        ];
    }

    private function decisionExpression(string $table): string
    {
        return "CASE WHEN {$table}.cost_complete IS NULL OR {$table}.revenue <= 0 THEN 'watch' "
            . "WHEN {$table}.profit_rate < 0 OR {$table}.combined_refund_rate >= 25 THEN 'stop' "
            . "WHEN {$table}.profit_rate < 15 OR {$table}.combined_refund_rate >= 12 THEN 'optimize' "
            . "WHEN {$table}.profit_rate >= 30 AND {$table}.combined_refund_rate < 8 THEN 'continue' "
            . "ELSE 'watch' END";
    }

    private function applyDatabaseSort(Builder $query, string $sort, string $direction): void
    {
        $direction = $direction === 'asc' ? 'asc' : 'desc';
        if ($sort === '') {
            $query->orderBy('product_statistics.first_item_id');

            return;
        }

        if ($sort === 'fees') {
            $query->orderByRaw('(product_statistics.shipping + product_statistics.handling) '.$direction);
        } elseif ($sort === 'decision') {
            $query->orderByRaw("FIELD(product_statistics.decision, 'continue', 'optimize', 'watch', 'stop') {$direction}");
        } elseif (in_array($sort, [
            'name', 'sku', 'product_code', 'variant_count', 'shop_count', 'sales', 'revenue', 'cost', 'paypal_refund_amount', 'paypal_refund_rate',
            'paypal_dispute_count', 'paypal_dispute_rate', 'credit_card_refund_amount', 'credit_card_refund_rate',
            'combined_refund_rate', 'profit', 'profit_rate',
        ], true)) {
            $column = match ($sort) {
                'name' => 'product_title',
                'sku', 'product_code' => 'tongtu_product_code',
                'variant_count' => 'tongtu_variant_count',
                default => $sort,
            };
            $query->orderBy('product_statistics.'.$column, $direction);
        }
        $query->orderBy('product_statistics.first_item_id');
    }

    private function productId(string $key): string
    {
        return 'product-'.rtrim(strtr(base64_encode($key), '+/', '-_'), '=');
    }

    private function decodeProductId(string $productId): ?string
    {
        if (!str_starts_with($productId, 'product-')) {
            return null;
        }
        $encoded = substr($productId, strlen('product-'));
        if ($encoded === '') {
            return null;
        }
        $encoded = strtr($encoded, '-_', '+/');
        $encoded .= str_repeat('=', (4 - strlen($encoded) % 4) % 4);
        $decoded = base64_decode($encoded, true);

        return $decoded === false || $decoded === '' ? null : $decoded;
    }

    private function productKeyExpression(string $alias): string
    {
        return "CASE "
            . "WHEN NULLIF(TRIM({$alias}.tongtu_product_code), '') IS NOT NULL THEN CONCAT('tongtu-product-code:', LOWER(TRIM({$alias}.tongtu_product_code))) "
            . "WHEN NULLIF(TRIM({$alias}.shopify_sku), '') IS NOT NULL THEN CONCAT('shopify-sku:', LOWER(TRIM({$alias}.shopify_sku))) "
            . "WHEN NULLIF(TRIM({$alias}.tongtu_sku), '') IS NOT NULL THEN CONCAT('tongtu-sku:', LOWER(TRIM({$alias}.tongtu_sku))) "
            . "WHEN NULLIF(TRIM({$alias}.shopify_product_id), '') IS NOT NULL THEN CONCAT('shopify-product:', LOWER(TRIM({$alias}.shopify_product_id))) "
            . "WHEN NULLIF(TRIM(COALESCE({$alias}.tongtu_product_name, {$alias}.product_title)), '') IS NOT NULL THEN CONCAT('product-name:', LOWER(TRIM(COALESCE({$alias}.tongtu_product_name, {$alias}.product_title)))) "
            . "ELSE CONCAT('sku:', COALESCE(NULLIF(LOWER(TRIM({$alias}.tongtu_product_code)), ''), NULLIF(LOWER(TRIM({$alias}.tongtu_sku)), ''), NULLIF(LOWER(TRIM({$alias}.shopify_sku)), ''), '__missing__')) END";
    }

    private function database(): \Illuminate\Database\Connection
    {
        return DB::connection(config('admin.database.connection') ?: config('database.default'));
    }

    /**
     * @param list<LogisticsOrderItem> $items
     * @return array<string,mixed>
     */
    private function product(array $items, string $key): array
    {
        $first = $items[0];
        $summary = $this->summary($items);
        $tongtuProductCode = trim((string) $first->tongtu_product_code);
        $shopifySku = trim((string) $first->shopify_sku);
        $tongtuSku = trim((string) $first->tongtu_sku);
        $fallbackSku = $shopifySku !== '' ? $shopifySku : $tongtuSku;

        return [
            'id' => $this->productId($key),
            '_key' => $key,
            '_first_item_id' => (int) $first->getKey(),
            '_search' => $this->searchText($items),
            'name' => $this->productTitle($first),
            'matched_name' => $this->matchedChineseProductName(
                (string) $first->product_title,
                (string) $first->tongtu_product_name,
            ),
            'has_tongtu_match' => $tongtuProductCode !== '',
            'product_code' => $tongtuProductCode !== '' ? $tongtuProductCode : null,
            'fallback_shopify_sku' => $fallbackSku !== '' ? $fallbackSku : null,
            'sku' => $tongtuProductCode !== '' ? $tongtuProductCode : ($fallbackSku ?: __('logistics.common.not_available')),
            'category' => __('logistics.product_statistics.sold_product'),
            'image_url' => $this->firstImage($items),
            ...$summary,
            'overview_modules' => $this->overviewModules($items),
            // Specs are display-only subdivisions. They never change the PCL
            // parent calculation or allocate a shared order refund/dispute.
            'variants' => $this->variantMetrics($items),
            'store_metrics' => $this->storeMetrics($items),
            'trend' => $this->trend($items),
        ];
    }

    /**
     * Display true Tongtu specifications below one PCL product. The parent
     * product remains the reporting unit; a same order is therefore only
     * counted once at parent level even when it has several specifications.
     *
     * @param list<LogisticsOrderItem> $items
     * @return list<array<string,mixed>>
     */
    private function variantMetrics(array $items): array
    {
        $groups = [];
        foreach ($items as $item) {
            $tongtuSku = trim((string) $item->tongtu_sku);
            $shopifySku = trim((string) $item->shopify_sku);
            $key = $tongtuSku !== ''
                ? 'tongtu:'.mb_strtolower($tongtuSku)
                : ($shopifySku !== '' ? 'shopify:'.mb_strtolower($shopifySku) : 'item:'.$item->getKey());
            $groups[$key][] = $item;
        }
        ksort($groups);

        return array_values(array_map(function (array $group): array {
            $first = $group[0];
            $summary = $this->summary($group);
            $shopifySkus = collect($group)->map(static fn (LogisticsOrderItem $item): string => trim((string) $item->shopify_sku))
                ->filter()->unique()->sort()->values()->all();

            return [
                'tongtu_sku' => trim((string) $first->tongtu_sku) ?: __('logistics.common.not_available'),
                'shopify_skus' => $shopifySkus,
                'tongtu_product_name' => trim((string) $first->tongtu_product_name) ?: __('logistics.common.not_available'),
                'stores' => $summary['stores'],
                'shop_count' => $summary['shop_count'],
                'order_count' => $this->ordersForItems($group)->count(),
                'sales' => $summary['sales'],
                'revenue' => $summary['revenue'],
                'paypal_refund_amount' => $summary['paypal_refund_amount'],
                'paypal_dispute_count' => $summary['paypal_dispute_count'],
                'credit_card_refund_amount' => $summary['credit_card_refund_amount'],
                'shipping_rate' => $summary['shipping_rate'],
                'delivery_rate' => $summary['delivery_rate'],
            ];
        }, $groups));
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
        $shippedOrders = $paidOrders->filter(fn (LogisticsOrder $order): bool => $this->isActuallyShipped($order));
        $deliveredOrders = $shippedOrders->filter(fn (LogisticsOrder $order): bool => $this->isDelivered($order));
        $paypalOrders = $paidOrders->filter(fn (LogisticsOrder $order): bool => $this->isPayPalModuleOrder($order));
        $creditCardOrders = $paidOrders->filter(fn (LogisticsOrder $order): bool => $this->isCreditCardOrder($order));
        $paypalRefundOrderIds = array_fill_keys($payment['paypal_refund_order_ids'], true);
        $creditCardRefundOrderIds = array_fill_keys($payment['credit_card_refund_order_ids'], true);
        $disputeOrderIds = array_fill_keys($payment['paypal_dispute_order_ids'], true);
        $paypalRefundRate = $paypalOrders->isEmpty() ? 0.0 : round($paypalOrders->filter(fn (LogisticsOrder $order): bool => isset($paypalRefundOrderIds[(int) $order->getKey()]))->count() / $paypalOrders->count() * 100, 2);
        $paypalDisputeRate = $paypalOrders->isEmpty() ? 0.0 : round($paypalOrders->filter(fn (LogisticsOrder $order): bool => isset($disputeOrderIds[(int) $order->getKey()]))->count() / $paypalOrders->count() * 100, 2);
        $creditCardRefundRate = $creditCardOrders->isEmpty() ? 0.0 : round($creditCardOrders->filter(fn (LogisticsOrder $order): bool => isset($creditCardRefundOrderIds[(int) $order->getKey()]))->count() / $creditCardOrders->count() * 100, 2);
        $combinedRefundOrderIds = $paypalRefundOrderIds + $creditCardRefundOrderIds;
        $combinedRefundRate = $paidOrders->isEmpty() ? 0.0 : round($paidOrders->filter(fn (LogisticsOrder $order): bool => isset($combinedRefundOrderIds[(int) $order->getKey()]))->count() / $paidOrders->count() * 100, 2);

        $relatedOrders = $this->relatedOrders($items, $payment);

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
            'paid_order_count' => $paidOrders->count(),
            'shipped_order_count' => $shippedOrders->count(),
            'delivered_order_count' => $deliveredOrders->count(),
            'shipping_rate' => $paidOrders->isEmpty() ? 0.0 : round($shippedOrders->count() / $paidOrders->count() * 100, 2),
            // 发货率、签收率均以当前产品的已付款订单为分母；不能把签收率
            // 误展示为“已签收 / 已发货”，否则会掩盖尚未发货的订单。
            'delivery_rate' => $paidOrders->isEmpty() ? 0.0 : round($deliveredOrders->count() / $paidOrders->count() * 100, 2),
            'related_orders' => $relatedOrders,
            'store_order_distribution' => $this->storeOrderDistribution($relatedOrders),
            'profit' => $profit,
            'actual_profit' => $profit,
            'profit_rate' => $profit !== null && $revenue > 0 ? round($profit / $revenue * 100, 2) : null,
            'refund_rate' => $combinedRefundRate,
            'decision' => $this->decision($profit, $revenue, $combinedRefundRate),
        ];
    }

    /**
     * @param list<array<string,mixed>> $orders
     * @return list<array{name:string,order_count:int}>
     */
    private function storeOrderDistribution(array $orders): array
    {
        $stores = [];
        foreach ($orders as $order) {
            $store = trim((string) ($order['store'] ?? '')) ?: __('logistics.common.unassigned_store');
            $orderId = (int) ($order['order_id'] ?? 0);
            if ($orderId <= 0) {
                continue;
            }

            $stores[$store][$orderId] = true;
        }
        ksort($stores);

        return array_map(
            static fn (array $orderIds, string $store): array => [
                'name' => $store,
                'order_count' => count($orderIds),
            ],
            $stores,
            array_keys($stores),
        );
    }

    /**
     * Build the three independently readable blocks used by the product-detail
     * overview.  The raw Shopify, PayPal and tracking records stay untouched;
     * this only groups the already loaded records for display.
     *
     * @param list<LogisticsOrderItem> $items
     * @return array{overall:array<string,mixed>,paypal:array<string,mixed>,credit_card:array<string,mixed>}
     */
    private function overviewModules(array $items): array
    {
        $orders = $this->ordersForItems($items);
        $paypalOrders = $orders
            ->filter(fn (LogisticsOrder $order): bool => $this->isPayPalModuleOrder($order))
            ->values();
        $creditCardOrders = $orders
            ->filter(fn (LogisticsOrder $order): bool => $this->isCreditCardOrder($order))
            ->values();

        return [
            'overall' => $this->overviewModule($items, $orders, true),
            'paypal' => $this->overviewModule($this->itemsForOrders($items, $paypalOrders), $paypalOrders, true),
            'credit_card' => $this->overviewModule($this->itemsForOrders($items, $creditCardOrders), $creditCardOrders, false),
        ];
    }

    /**
     * @param list<LogisticsOrderItem> $items
     * @param Collection<int,LogisticsOrder> $orders
     * @return list<LogisticsOrderItem>
     */
    private function itemsForOrders(array $items, Collection $orders): array
    {
        $orderIds = $orders
            ->map(static fn (LogisticsOrder $order): int => (int) $order->getKey())
            ->flip();

        return array_values(array_filter($items, static function (LogisticsOrderItem $item) use ($orderIds): bool {
            return $item->order instanceof LogisticsOrder
                && $orderIds->has((int) $item->order->getKey());
        }));
    }

    /**
     * @param list<LogisticsOrderItem> $items
     * @param Collection<int,LogisticsOrder> $orders
     * @return array<string,mixed>
     */
    private function overviewModule(array $items, Collection $orders, bool $disputesApplicable): array
    {
        $payment = $this->realPaymentSummary($orders);
        $refundAmount = round((float) $payment['paypal_refund_amount'] + (float) $payment['credit_card_refund_amount'], 4);
        $receivedAmount = $this->sum($items, 'line_revenue_usd');
        $shippedOrders = $orders->filter(fn (LogisticsOrder $order): bool => $this->isActuallyShipped($order));
        $deliveredOrders = $orders->filter(fn (LogisticsOrder $order): bool => $this->isDelivered($order));
        $disputeDetails = $disputesApplicable ? $payment['dispute_details'] : [];
        $disputeCount = count($disputeDetails);
        $disputeOrderCount = count(array_unique(array_filter(array_map(
            static fn (array $dispute): int => (int) ($dispute['order_id'] ?? 0),
            $disputeDetails,
        ))));

        return [
            'unit_count' => $this->sum($items, 'quantity'),
            'order_count' => $orders->count(),
            'received_amount' => $receivedAmount,
            'refund_amount' => $refundAmount,
            'refund_count' => count($payment['refund_details']),
            'refund_rate' => $receivedAmount > 0 ? round($refundAmount / $receivedAmount * 100, 2) : 0.0,
            'shipping_count' => $shippedOrders->count(),
            'delivery_count' => $deliveredOrders->count(),
            'shipping_rate' => $orders->isEmpty() ? 0.0 : round($shippedOrders->count() / $orders->count() * 100, 2),
            'delivery_rate' => $shippedOrders->isEmpty() ? 0.0 : round($deliveredOrders->count() / $shippedOrders->count() * 100, 2),
            'dispute_applicable' => $disputesApplicable,
            'dispute_count' => $disputeCount,
            'dispute_order_count' => $disputeOrderCount,
            'dispute_amount' => round(array_sum(array_column($disputeDetails, 'amount')), 4),
            'dispute_rate' => $orders->isEmpty() ? 0.0 : round($disputeOrderCount / $orders->count() * 100, 2),
            'dispute_reason_breakdown' => $this->disputeReasonBreakdown($disputeDetails, $disputeCount),
        ];
    }

    /**
     * @param list<array<string,mixed>> $disputes
     * @return list<array{reason:string,count:int,amount:float,ratio:float}>
     */
    private function disputeReasonBreakdown(array $disputes, int $total): array
    {
        $groups = [];
        foreach ($disputes as $dispute) {
            $reason = trim((string) ($dispute['reason'] ?? '')) ?: __('logistics.common.not_available');
            $groups[$reason] ??= ['reason' => $reason, 'count' => 0, 'amount' => 0.0];
            $groups[$reason]['count']++;
            $groups[$reason]['amount'] += (float) ($dispute['amount'] ?? 0);
        }

        uasort($groups, static function (array $left, array $right): int {
            return $right['count'] <=> $left['count'] ?: $right['amount'] <=> $left['amount'];
        });

        return array_values(array_map(static function (array $group) use ($total): array {
            return [
                'reason' => $group['reason'],
                'count' => $group['count'],
                'amount' => round((float) $group['amount'], 4),
                'ratio' => $total > 0 ? round((int) $group['count'] / $total * 100, 2) : 0.0,
            ];
        }, $groups));
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
            if ($this->isPayPalModuleOrder($order)) {
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
            if ($this->isCreditCardOrder($order)) {
                foreach ($order->creditCardRefunds as $refund) {
                    $key = 'credit_card:'.(int) $refund->getKey();
                    $creditCardRefunds[$key] ??= $this->creditCardRefundDetail($refund, $order);
                }
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
            'reason' => $dispute->reasonLabel(),
            'stage' => $dispute->typeLabel(),
            'status' => $dispute->statusLabel(),
            'outcome' => $dispute->outcomeLabel(),
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
                'tongtu_product_codes' => [],
                'tongtu_skus' => [],
                'shopify_skus' => [],
                'quantity' => 0.0,
                'revenue' => 0.0,
                'paypal_refund' => 0.0,
                'credit_card_refund' => 0.0,
                'actual_refund' => 0.0,
                'paypal_dispute_count' => 0,
                'tracking_number' => (string) ($order->tracking_number ?: __('logistics.common.not_available')),
                'logistics_status' => (string) ($order->logistics_status ?: __('logistics.common.not_available')),
                'payment_method' => $this->isPayPalModuleOrder($order)
                    ? __('logistics.product_statistics.payment_methods.paypal')
                    : ($this->isCreditCardOrder($order)
                        ? __('logistics.product_statistics.payment_methods.credit_card')
                        : __('logistics.common.not_available')),
                'payment_kind' => $this->isPayPalModuleOrder($order)
                    ? 'paypal'
                    : ($this->isCreditCardOrder($order) ? 'credit_card' : 'unknown'),
                'order_created_at' => $this->dateTime($order->order_created_at),
                '_sort_time' => $order->order_created_at?->timestamp ?? 0,
            ];
            $orders[$id]['quantity'] += (float) $item->quantity;
            $orders[$id]['revenue'] += (float) $item->line_revenue_usd;
            foreach (['tongtu_product_code' => 'tongtu_product_codes', 'tongtu_sku' => 'tongtu_skus', 'shopify_sku' => 'shopify_skus'] as $field => $target) {
                $value = trim((string) $item->{$field});
                if ($value !== '') {
                    $orders[$id][$target][mb_strtolower($value)] = $value;
                }
            }
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
            $order['tongtu_product_codes'] = array_values($order['tongtu_product_codes']);
            $order['tongtu_skus'] = array_values($order['tongtu_skus']);
            $order['shopify_skus'] = array_values($order['shopify_skus']);
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

    /**
     * Applies every row-level filter before any product aggregation.  This is
     * intentionally shared by the aggregate query and the detail query so a
     * product can never show a list total that differs from its detail modal.
     *
     * @param array<string,mixed> $filters
     */
    private function applyBaseFilters(object $query, array $filters, string $itemTable = 'logistics_order_items', string $orderTable = 'logistics_orders'): void
    {
        $this->applyDateFilters($query, $filters, $orderTable);
        if (trim((string) ($filters['store'] ?? '')) !== '') {
            $query->where($orderTable.'.store_name', $filters['store']);
        }

        $name = trim((string) ($filters['name'] ?? ''));
        if ($name !== '') {
            $query->where(function ($nested) use ($itemTable, $name): void {
                $nested->where($itemTable.'.product_title', 'like', '%'.$name.'%')
                    ->orWhere($itemTable.'.tongtu_product_name', 'like', '%'.$name.'%');
            });
        }

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where(function ($nested) use ($itemTable, $keyword): void {
                $nested->where($itemTable.'.shopify_sku', 'like', '%'.$keyword.'%')
                    ->orWhere($itemTable.'.tongtu_product_code', 'like', '%'.$keyword.'%')
                    ->orWhere($itemTable.'.tongtu_sku', 'like', '%'.$keyword.'%')
                    ->orWhere($itemTable.'.variant_title', 'like', '%'.$keyword.'%')
                    ->orWhere($itemTable.'.product_title', 'like', '%'.$keyword.'%')
                    ->orWhere($itemTable.'.tongtu_product_name', 'like', '%'.$keyword.'%');
            });
        }
    }

    /** @param array<string,mixed> $filters */
    private function applyDateFilters(object $query, array $filters, string $orderTable = 'logistics_orders'): void
    {
        if (trim((string) ($filters['date_from'] ?? '')) !== '') {
            $query->where($orderTable.'.order_created_at', '>=', $filters['date_from'].' 00:00:00');
        }
        if (trim((string) ($filters['date_to'] ?? '')) !== '') {
            $query->where($orderTable.'.order_created_at', '<=', $filters['date_to'].' 23:59:59');
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

    private function isActualPayPalOrder(LogisticsOrder $order): bool
    {
        return $order->paypal_account_id !== null && $order->paypal_association_source === 'actual_transaction';
    }

    private function isPayPalModuleOrder(LogisticsOrder $order): bool
    {
        return $this->isActualPayPalOrder($order) && !$this->hasCreditCardTag($order);
    }

    private function isCreditCardOrder(LogisticsOrder $order): bool
    {
        return $this->hasCreditCardTag($order);
    }

    private function hasCreditCardTag(LogisticsOrder $order): bool
    {
        $payload = $order->shopify_payload;
        if (is_string($payload)) {
            $payload = json_decode($payload, true);
        }
        if (!is_array($payload)) {
            return false;
        }

        $tags = $payload['tags'] ?? [];
        if (is_string($tags)) {
            $tags = explode(',', $tags);
        }

        foreach ((array) $tags as $tag) {
            if (is_string($tag) && str_contains(mb_strtolower(trim($tag)), 'creditcardby')) {
                return true;
            }
        }

        return false;
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
        $productCode = trim((string) $item->tongtu_product_code);
        if ($productCode !== '') {
            return 'tongtu-product-code:'.mb_strtolower($productCode);
        }

        $shopifySku = trim((string) $item->shopify_sku);
        if ($shopifySku !== '') {
            return 'shopify-sku:'.mb_strtolower($shopifySku);
        }

        $tongtuSku = trim((string) $item->tongtu_sku);
        if ($tongtuSku !== '') {
            return 'tongtu-sku:'.mb_strtolower($tongtuSku);
        }

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

        return 'sku:'.(mb_strtolower(trim((string) ($item->tongtu_product_code ?: $item->tongtu_sku ?: $item->shopify_sku))) ?: '__missing__');
    }

    private function productTitle(LogisticsOrderItem $item): string
    {
        return trim((string) $item->product_title) ?: trim((string) $item->tongtu_product_name) ?: __('logistics.product_statistics.unnamed_product');
    }

    private function matchedChineseProductName(string $productTitle, string $matchedName): ?string
    {
        $productTitle = trim($productTitle);
        $matchedName = trim($matchedName);

        if ($matchedName === '' || $matchedName === $productTitle || !preg_match('/\\p{Han}/u', $matchedName)) {
            return null;
        }

        return $matchedName;
    }

    private function productCode(LogisticsOrderItem $item): string
    {
        return trim((string) ($item->tongtu_product_code ?: $item->tongtu_sku ?: $item->shopify_sku)) ?: __('logistics.common.not_available');
    }

    /** @param list<LogisticsOrderItem> $items */
    private function searchText(array $items): string
    {
        return implode(' ', array_map(static fn (LogisticsOrderItem $item): string => implode(' ', [
            (string) $item->product_title,
            (string) $item->variant_title,
            (string) $item->shopify_sku,
            (string) $item->tongtu_product_code,
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
