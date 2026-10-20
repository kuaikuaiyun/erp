<?php

namespace App\Portals\Logistics;

use App\Models\LogisticsOrder;
use App\Models\LogisticsOrderItem;
use App\Models\LogisticsCreditCardRefund;
use App\Models\LogisticsPayPalDispute;
use App\Models\LogisticsPayPalRefund;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
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
        $perPage = (int) ($filters['per_page'] ?? 10);
        $page = max(1, (int) ($filters['page'] ?? 1));

        if ($this->canUseLightweightPageQuery($filters)) {
            $candidates = $this->lightweightProductCandidates($filters);
            $summary = $this->cachedLightweightSummary($candidates, $filters);
            $total = $candidates->count();
            $lastPage = max(1, (int) ceil($total / $perPage));
            $page = min($page, $lastPage);
            $offset = ($page - 1) * $perPage;
            $pageCandidates = $this->sortLightweightCandidates(
                $candidates,
                trim((string) ($filters['sort'] ?? '')),
                trim((string) ($filters['direction'] ?? '')),
            )->slice($offset, $perPage)->values();
            $pageKeys = $pageCandidates->pluck('product_key')->map(static fn ($key): string => (string) $key)->all();
            $fullRows = $this->productStatisticsQuery($filters, false, $pageKeys)->get()->keyBy('product_key');
            $productRows = $pageCandidates
                ->map(static fn (object $candidate) => $fullRows->get($candidate->product_key))
                ->filter()
                ->values();
        } else {
            // Some specialised sorts and the decision filter require the
            // complete calculated data set, so keep their exact SQL path.
            $query = $this->productStatisticsQuery($filters, false);
            $summary = $this->overallSummaryFromQuery($query, $filters);
            $total = (clone $query)->count();
            $lastPage = max(1, (int) ceil($total / $perPage));
            $page = min($page, $lastPage);
            $offset = ($page - 1) * $perPage;
            $this->applyDatabaseSort($query, trim((string) ($filters['sort'] ?? '')), trim((string) ($filters['direction'] ?? '')));
            $productRows = $query->forPage($page, $perPage)->get();
        }

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
        $optionFilters = $filters;
        unset($optionFilters['store'], $optionFilters['decision'], $optionFilters['sort'], $optionFilters['direction'], $optionFilters['page'], $optionFilters['per_page']);

        // Reuse the list's lightweight index instead of issuing another
        // whole-table scan just to build the store dropdown.
        return $this->lightweightProductCandidates($optionFilters)
            ->flatMap(static fn (object $row): array => explode("\x1F", (string) ($row->store_names ?? '')))
            ->filter(static fn (string $store): bool => $store !== '' && $store !== '__unassigned__')
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /** @param array<string,mixed> $filters */
    private function canUseLightweightPageQuery(array $filters): bool
    {
        if (trim((string) ($filters['decision'] ?? '')) !== '') {
            return false;
        }

        return in_array(trim((string) ($filters['sort'] ?? '')), [
            '', 'name', 'sku', 'product_code', 'variant_count', 'shop_count', 'sales', 'revenue', 'cost', 'fees',
        ], true);
    }

    /**
     * @param array<string,mixed> $filters
     * @return Collection<int,object>
     */
    private function lightweightProductCandidates(array $filters): Collection
    {
        $cacheKey = $this->statisticsCacheKey('candidates', $filters);
        $rows = Cache::remember($cacheKey, now()->addSeconds(45), function () use ($filters): array {
            return $this->lightweightProductCandidateQuery($filters)
                ->get()
                ->map(static fn (object $row): array => (array) $row)
                ->all();
        });

        return collect($rows)->map(static fn (array $row): object => (object) $row)->values();
    }

    /**
     * @param Collection<int,object> $candidates
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    private function cachedLightweightSummary(Collection $candidates, array $filters): array
    {
        return Cache::remember(
            $this->statisticsCacheKey('summary', $filters),
            now()->addSeconds(45),
            fn (): array => $this->overallSummaryFromCandidates($candidates, $filters),
        );
    }

    /**
     * @param Collection<int,object> $candidates
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    private function overallSummaryFromCandidates(Collection $candidates, array $filters): array
    {
        $refunds = $this->uniqueRefundSummaryForBaseFilters($filters);
        $sales = round((float) $candidates->sum(static fn (object $row): float => (float) $row->sales), 4);
        $revenue = round((float) $candidates->sum(static fn (object $row): float => (float) $row->revenue), 4);
        $shipping = round((float) $candidates->sum(static fn (object $row): float => (float) $row->shipping), 4);
        $handling = round((float) $candidates->sum(static fn (object $row): float => (float) $row->handling), 4);
        $hasMissingCost = $candidates->contains(static fn (object $row): bool => (int) $row->missing_cost_count > 0);
        $cost = $hasMissingCost
            ? null
            : round((float) $candidates->sum(static fn (object $row): float => (float) $row->cost), 4);
        $actualRefunds = round((float) $refunds['paypal'] + (float) $refunds['credit_card'], 4);
        $profit = $cost === null ? null : round($revenue - $cost - $shipping - $handling - $actualRefunds, 4);

        return [
            'product_count' => $candidates->count(),
            'variant_count' => 0,
            'sales' => $sales,
            'revenue' => $revenue,
            'actual_refunds' => $actualRefunds,
            'profit' => $profit,
            'profit_rate' => $profit !== null && $revenue > 0 ? round($profit / $revenue * 100, 2) : null,
        ];
    }

    /**
     * @param Collection<int,object> $candidates
     * @return Collection<int,object>
     */
    private function sortLightweightCandidates(Collection $candidates, string $sort, string $direction): Collection
    {
        $descending = $direction === 'desc';
        $rows = $candidates->all();
        usort($rows, function (object $left, object $right) use ($sort, $descending): int {
            $leftValue = $this->lightweightCandidateSortValue($left, $sort);
            $rightValue = $this->lightweightCandidateSortValue($right, $sort);
            $numeric = is_int($leftValue) || is_float($leftValue) || is_int($rightValue) || is_float($rightValue);
            $comparison = $numeric
                ? ((float) $leftValue <=> (float) $rightValue)
                : strnatcasecmp((string) $leftValue, (string) $rightValue);
            if ($comparison !== 0 && $descending) {
                $comparison *= -1;
            }

            return $comparison ?: ((int) $left->first_item_id <=> (int) $right->first_item_id);
        });

        return collect($rows)->values();
    }

    private function lightweightCandidateSortValue(object $row, string $sort): string|int|float
    {
        return match ($sort) {
            'name' => trim((string) ($row->product_title ?: $row->tongtu_product_name)),
            'sku', 'product_code' => trim((string) $row->tongtu_product_code),
            'variant_count' => (int) $row->tongtu_variant_count,
            'shop_count' => (int) $row->shop_count,
            'sales' => (float) $row->sales,
            'revenue' => (float) $row->revenue,
            'cost' => (int) $row->missing_cost_count > 0 ? -PHP_FLOAT_MAX : (float) $row->cost,
            'fees' => (float) $row->shipping + (float) $row->handling,
            default => (int) $row->first_item_id,
        };
    }

    /** @param array<string,mixed> $filters */
    private function statisticsCacheKey(string $segment, array $filters): string
    {
        $keyFilters = [];
        foreach (['date_from', 'date_to', 'name', 'keyword', 'store'] as $key) {
            $keyFilters[$key] = (string) ($filters[$key] ?? '');
        }

        return 'logistics.product-statistics.'.$segment.'.'.sha1(json_encode($keyFilters));
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
     * Returns the product modal in small, independently paginated pieces.
     *
     * The old detail() method remains for compatibility with any external
     * caller, but the portal endpoint must use this method.  In particular it
     * must never load every order/refund/dispute into the browser just to show
     * one page of a tab.
     *
     * @param array<string,mixed> $filters
     * @param array<string,mixed> $options
     * @return array<string,mixed>|null
     */
    public function detailPage(array $filters, string $productId, array $options = []): ?array
    {
        $key = $this->decodeProductId($productId);
        if ($key === null) {
            return null;
        }

        $tab = (string) ($options['tab'] ?? 'overview');
        $withOverview = $tab === 'overview';
        $row = $this->productStatisticsQuery($filters, $withOverview, [$key])->first();
        if ($row === null) {
            return null;
        }

        $product = $this->listProduct($row);
        $decision = trim((string) ($filters['decision'] ?? ''));
        if ($decision !== '' && $decision !== $product['decision']) {
            return null;
        }

        if ($withOverview) {
            // The overview query deliberately keeps the main product list
            // lightweight.  Reason distribution is only needed inside this
            // one product modal, so fetch it here instead of loading every
            // dispute record for every product on the list page.
            $disputeReasonBreakdown = $this->overviewDisputeReasonBreakdown($filters, $key);
            $product['overview_modules']['overall']['dispute_reason_breakdown'] = $disputeReasonBreakdown;
            $product['overview_modules']['paypal']['dispute_reason_breakdown'] = $disputeReasonBreakdown;
            $product['store_order_distribution'] = $this->detailStoreOrderDistribution($filters, $key);

            return [
                'tab' => 'overview',
                'product' => $product,
            ];
        }

        return [
            'tab' => $tab,
            'detail' => match ($tab) {
                'variants' => $this->variantDetailPage($filters, $key, $options),
                'refunds' => $this->refundDetailPage($filters, $key, $options),
                'disputes' => $this->disputeDetailPage($filters, $key, $options),
                'paypal_orders' => $this->orderDetailPage($filters, $key, $options, 'paypal'),
                'credit_card_orders' => $this->orderDetailPage($filters, $key, $options, 'credit_card'),
                default => $this->orderDetailPage($filters, $key, $options),
            },
        ];
    }

    /**
     * @param array<string,mixed> $filters
     * @return list<array{name:string,order_count:int}>
     */
    private function detailStoreOrderDistribution(array $filters, string $key): array
    {
        return $this->baseItemQuery($filters)
            ->whereRaw($this->productKeyExpression('items').' = ?', [$key])
            ->selectRaw("COALESCE(NULLIF(TRIM(orders.store_name), ''), '__unassigned__') as store_name")
            ->selectRaw('COUNT(DISTINCT orders.id) as order_count')
            ->groupBy('store_name')
            ->orderBy('store_name')
            ->get()
            ->map(static fn (object $row): array => [
                'name' => $row->store_name === '__unassigned__' ? __('logistics.common.unassigned_store') : (string) $row->store_name,
                'order_count' => (int) $row->order_count,
            ])
            ->all();
    }

    /**
     * Return the PayPal dispute-reason distribution for just one product
     * overview.  The selected orders and account association intentionally
     * follow the same rules as the PayPal overview module: actual PayPal
     * transactions only, excluding independent CreditCardBy orders.
     *
     * @param array<string,mixed> $filters
     * @return list<array{reason:string,count:int,amount:float,ratio:float}>
     */
    private function overviewDisputeReasonBreakdown(array $filters, string $key): array
    {
        $creditCardTag = "(CASE WHEN JSON_VALID(COALESCE(orders.shopify_payload, '')) THEN JSON_SEARCH(JSON_EXTRACT(orders.shopify_payload, '$.tags'), 'one', '%CreditCardBy%') ELSE NULL END IS NOT NULL)";
        $productOrders = $this->baseItemQuery($filters)
            ->whereRaw($this->productKeyExpression('items').' = ?', [$key])
            ->whereNotNull('orders.paypal_account_id')
            ->where('orders.paypal_association_source', 'actual_transaction')
            ->whereRaw("NOT {$creditCardTag}")
            ->select([
                'orders.id as order_id',
                'orders.paypal_account_id as paypal_account_id',
            ])
            ->groupBy('orders.id', 'orders.paypal_account_id');

        $rows = $this->database()->table('logistics_paypal_disputes as dispute')
            ->joinSub($productOrders, 'product_orders', function ($join): void {
                $join->on('product_orders.order_id', '=', 'dispute.logistics_order_id')
                    ->on('product_orders.paypal_account_id', '=', 'dispute.logistics_paypal_account_id');
            })
            ->selectRaw("COALESCE(NULLIF(TRIM(dispute.reason), ''), '__unknown__') as reason_code")
            ->selectRaw('COUNT(DISTINCT dispute.id) as dispute_count')
            ->selectRaw("SUM(COALESCE(dispute.dispute_amount_usd, CASE WHEN UPPER(dispute.currency_code) = 'USD' THEN dispute.original_amount ELSE 0 END)) as dispute_amount")
            ->groupBy('reason_code')
            ->orderByDesc('dispute_count')
            ->orderByDesc('dispute_amount')
            ->get();

        $total = (int) $rows->sum(static fn (object $row): int => (int) $row->dispute_count);

        return $rows
            ->map(static function (object $row) use ($total): array {
                $reasonCode = (string) $row->reason_code;

                return [
                    'reason' => $reasonCode === '__unknown__'
                        ? __('logistics.common.not_available')
                        : LogisticsPayPalDispute::reasonLabelFor($reasonCode),
                    'count' => (int) $row->dispute_count,
                    'amount' => round((float) $row->dispute_amount, 4),
                    'ratio' => $total > 0 ? round((int) $row->dispute_count / $total * 100, 2) : 0.0,
                ];
            })
            ->all();
    }

    /**
     * @param array<string,mixed> $filters
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function orderDetailPage(array $filters, string $key, array $options, ?string $paymentKind = null): array
    {
        $orderMetrics = $this->detailOrderMetricsQuery($filters, $key, $options, $paymentKind);
        $rowsQuery = $this->database()->query()
            ->fromSub($orderMetrics, 'detail_order_metrics')
            ->join('logistics_orders as orders', 'orders.id', '=', 'detail_order_metrics.order_id')
            ->select('orders.id')
            ->addSelect('detail_order_metrics.quantity as detail_quantity')
            ->addSelect('detail_order_metrics.revenue as detail_revenue');

        $sort = (string) ($options['sort'] ?? '');
        $sortColumns = [
            'shopify_order_number' => 'orders.shopify_order_number',
            'store' => 'orders.store_name',
            'bill_number' => 'orders.bill_number',
            'payment_method' => 'orders.payment_method',
            'tracking_number' => 'orders.tracking_number',
            'logistics_status' => 'orders.logistics_status',
            'order_created_at' => 'orders.order_created_at',
            'quantity' => 'detail_order_metrics.quantity',
            'revenue' => 'detail_order_metrics.revenue',
        ];
        $this->applyDetailSort($rowsQuery, $sortColumns[$sort] ?? 'orders.order_created_at', $options, 'orders.id');
        $page = $this->paginateDetailQuery($rowsQuery, $options);
        $orderIdsOnPage = $page['rows']
            ->map(static fn (object $row): int => (int) $row->id)
            ->all();

        return [
            'rows' => $this->hydratedOrderRows($filters, $key, $orderIdsOnPage),
            'pagination' => $page['pagination'],
            'options' => [
                'logistics_status' => $this->detailLogisticsStatuses($filters, $key, $paymentKind),
            ],
        ];
    }

    /**
     * @param array<string,mixed> $filters
     * @param list<int> $orderIds
     * @return list<array<string,mixed>>
     */
    private function hydratedOrderRows(array $filters, string $key, array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        $orders = LogisticsOrder::query()
            ->with([
                'paypalRefunds.disputeLinks.dispute',
                'paypalDisputes.refundLinks.refund',
                'creditCardRefunds',
            ])
            ->whereIn('id', $orderIds)
            ->get()
            ->keyBy(static fn (LogisticsOrder $order): int => (int) $order->getKey());

        $items = LogisticsOrderItem::query()
            ->whereIn('logistics_order_id', $orderIds)
            ->whereRaw($this->productKeyExpression('logistics_order_items').' = ?', [$key])
            ->get()
            ->filter(function (LogisticsOrderItem $item) use ($orders): bool {
                $order = $orders->get((int) $item->logistics_order_id);
                if (!$order instanceof LogisticsOrder) {
                    return false;
                }
                $item->setRelation('order', $order);

                return true;
            })
            ->values();

        $payment = $this->realPaymentSummary($orders->values());
        $rows = collect($this->relatedOrders($items->all(), $payment))
            ->keyBy(static fn (array $row): int => (int) $row['order_id']);

        return array_values(array_filter(array_map(
            static fn (int $orderId): ?array => $rows->get($orderId),
            $orderIds,
        )));
    }

    /**
     * @param array<string,mixed> $filters
     * @param array<string,mixed> $options
     */
    private function detailOrderIdsQuery(array $filters, string $key, array $options = [], ?string $paymentKind = null): Builder
    {
        $query = $this->baseItemQuery($filters)
            ->whereRaw($this->productKeyExpression('items').' = ?', [$key]);
        $this->applyDetailPaymentKind($query, $paymentKind, 'orders');
        $this->applyDetailOrderFilters($query, $options, 'orders');

        return $query->select('orders.id as order_id')->distinct();
    }

    /**
     * @param array<string,mixed> $filters
     * @param array<string,mixed> $options
     */
    private function detailOrderMetricsQuery(array $filters, string $key, array $options = [], ?string $paymentKind = null): Builder
    {
        $query = $this->baseItemQuery($filters)
            ->whereRaw($this->productKeyExpression('items').' = ?', [$key]);
        $this->applyDetailPaymentKind($query, $paymentKind, 'orders');
        $this->applyDetailOrderFilters($query, $options, 'orders');

        return $query
            ->select('orders.id as order_id')
            ->selectRaw('SUM(COALESCE(items.quantity, 0)) as quantity')
            ->selectRaw('SUM(COALESCE(items.line_revenue_usd, 0)) as revenue')
            ->groupBy('orders.id');
    }

    /** @param array<string,mixed> $options */
    private function applyDetailOrderFilters(object $query, array $options, string $orderTable): void
    {
        $keyword = trim((string) ($options['keyword'] ?? ''));
        if ($keyword !== '') {
            $like = '%'.$this->escapeLike($keyword).'%';
            $query->where(function ($nested) use ($like, $orderTable): void {
                $nested->where("{$orderTable}.shopify_order_number", 'like', $like)
                    ->orWhere("{$orderTable}.store_name", 'like', $like)
                    ->orWhere("{$orderTable}.bill_number", 'like', $like)
                    ->orWhere("{$orderTable}.tracking_number", 'like', $like)
                    ->orWhere("{$orderTable}.payment_method", 'like', $like);
            });
        }

        $status = trim((string) ($options['logistics_status'] ?? ''));
        if ($status !== '') {
            $query->where("{$orderTable}.logistics_status", $status);
        }
    }

    private function applyDetailPaymentKind(object $query, ?string $paymentKind, string $orderTable): void
    {
        if ($paymentKind === null) {
            return;
        }

        $creditCardTag = "(CASE WHEN JSON_VALID(COALESCE({$orderTable}.shopify_payload, '')) THEN JSON_SEARCH(JSON_EXTRACT({$orderTable}.shopify_payload, '$.tags'), 'one', '%CreditCardBy%') ELSE NULL END IS NOT NULL)";
        if ($paymentKind === 'credit_card') {
            $query->whereRaw($creditCardTag);

            return;
        }

        $query->whereNotNull("{$orderTable}.paypal_account_id")
            ->where("{$orderTable}.paypal_association_source", 'actual_transaction')
            ->whereRaw("NOT {$creditCardTag}");
    }

    /**
     * @param array<string,mixed> $filters
     * @return list<string>
     */
    private function detailLogisticsStatuses(array $filters, string $key, ?string $paymentKind): array
    {
        $query = $this->baseItemQuery($filters)
            ->whereRaw($this->productKeyExpression('items').' = ?', [$key]);
        $this->applyDetailPaymentKind($query, $paymentKind, 'orders');

        return $query
            ->whereNotNull('orders.logistics_status')
            ->distinct()
            ->orderBy('orders.logistics_status')
            ->pluck('orders.logistics_status')
            ->map(static fn ($value): string => (string) $value)
            ->values()
            ->all();
    }

    /**
     * @param array<string,mixed> $filters
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function refundDetailPage(array $filters, string $key, array $options): array
    {
        $orderOptions = $options;
        $orderOptions['keyword'] = '';
        $orderOptions['logistics_status'] = '';
        $paypalOrderIds = $this->detailOrderIdsQuery($filters, $key, $orderOptions, 'paypal');
        $creditCardOrderIds = $this->detailOrderIdsQuery($filters, $key, $orderOptions, 'credit_card');

        $paypal = $this->database()->table('logistics_paypal_refunds as refund')
            ->join('logistics_orders as orders', 'orders.id', '=', 'refund.logistics_order_id')
            ->whereIn('refund.logistics_order_id', $paypalOrderIds)
            ->selectRaw("'paypal' as payment_method")
            ->selectRaw('refund.id as record_id')
            ->selectRaw('refund.status as raw_status')
            ->selectRaw('refund.refund_transaction_id as reference')
            ->selectRaw('refund.refund_reason as source_reason')
            ->selectRaw('refund.manual_reason as manual_reason')
            ->selectRaw('COALESCE(refund.refund_amount_usd, CASE WHEN UPPER(refund.currency_code) = \'USD\' THEN refund.original_amount ELSE 0 END) as amount')
            ->selectRaw('COALESCE(refund.refunded_at, refund.created_at) as happened_at');
        $creditCard = $this->database()->table('logistics_credit_card_refunds as refund')
            ->join('logistics_orders as orders', 'orders.id', '=', 'refund.logistics_order_id')
            ->whereIn('refund.logistics_order_id', $creditCardOrderIds)
            ->selectRaw("'credit_card' as payment_method")
            ->selectRaw('refund.id as record_id')
            ->selectRaw("'credit_card' as raw_status")
            ->selectRaw('refund.refund_reference as reference')
            ->selectRaw("'' as source_reason")
            ->selectRaw('refund.refund_reason as manual_reason')
            ->selectRaw('refund.refund_amount_usd as amount')
            ->selectRaw('COALESCE(refund.last_imported_at, refund.updated_at, refund.created_at) as happened_at');

        $query = $this->database()->query()->fromSub($paypal->unionAll($creditCard), 'refund_rows');
        $this->applyDetailTextFilter($query, $options, ['reference', 'source_reason', 'manual_reason']);
        if (filled($options['payment_method'] ?? null)) {
            $query->where('payment_method', (string) $options['payment_method']);
        }
        if (filled($options['status'] ?? null)) {
            $query->where('raw_status', (string) $options['status']);
        }
        $this->applyDetailSort($query, [
            'payment_method_label' => 'payment_method', 'refund_number' => 'reference', 'amount' => 'amount',
            'source_reason' => 'source_reason', 'manual_reason' => 'manual_reason', 'status' => 'raw_status', 'imported_at' => 'happened_at',
        ][(string) ($options['sort'] ?? '')] ?? 'happened_at', $options, 'record_id');
        $page = $this->paginateDetailQuery($query, $options);

        $paypalIds = [];
        $creditCardIds = [];
        foreach ($page['rows'] as $row) {
            if ($row->payment_method === 'paypal') $paypalIds[] = (int) $row->record_id;
            if ($row->payment_method === 'credit_card') $creditCardIds[] = (int) $row->record_id;
        }
        $paypalRows = LogisticsPayPalRefund::query()->with('disputeLinks.dispute')->whereIn('id', $paypalIds)->get()->keyBy('id');
        $creditCardRows = LogisticsCreditCardRefund::query()->whereIn('id', $creditCardIds)->get()->keyBy('id');
        $orderIds = array_values(array_unique(array_merge(
            $paypalRows->pluck('logistics_order_id')->map(static fn ($id): int => (int) $id)->all(),
            $creditCardRows->pluck('logistics_order_id')->map(static fn ($id): int => (int) $id)->all(),
        )));
        $orders = LogisticsOrder::query()->whereIn('id', $orderIds)->get()->keyBy('id');
        $rows = [];
        foreach ($page['rows'] as $row) {
            $refund = $row->payment_method === 'paypal' ? $paypalRows->get((int) $row->record_id) : $creditCardRows->get((int) $row->record_id);
            $order = $refund ? $orders->get((int) $refund->logistics_order_id) : null;
            if ($refund && $order instanceof LogisticsOrder) {
                $rows[] = $row->payment_method === 'paypal'
                    ? $this->paypalRefundDetail($refund, $order)
                    : $this->creditCardRefundDetail($refund, $order);
            }
        }

        return [
            'rows' => $rows,
            'pagination' => $page['pagination'],
            'options' => [
                'payment_method' => [
                    ['value' => 'paypal', 'label' => __('logistics.product_statistics.payment_methods.paypal')],
                    ['value' => 'credit_card', 'label' => __('logistics.product_statistics.payment_methods.credit_card')],
                ],
                'status' => $this->refundStatusOptions($filters, $key),
            ],
        ];
    }

    /** @param array<string,mixed> $filters @return list<array{value:string,label:string}> */
    private function refundStatusOptions(array $filters, string $key): array
    {
        $options = ['keyword' => '', 'logistics_status' => ''];
        $paypalIds = $this->detailOrderIdsQuery($filters, $key, $options, 'paypal');
        $creditIds = $this->detailOrderIdsQuery($filters, $key, $options, 'credit_card');

        $values = $this->database()->query()
            ->fromSub(
                $this->database()->table('logistics_paypal_refunds as refund')->whereIn('refund.logistics_order_id', $paypalIds)->select('refund.status as value')
                    ->unionAll($this->database()->table('logistics_credit_card_refunds as refund')->whereIn('refund.logistics_order_id', $creditIds)->selectRaw("'credit_card' as value")),
                'refund_status_options',
            )
            ->whereNotNull('value')->where('value', '<>', '')
            ->distinct()->orderBy('value')->pluck('value')->map(static fn ($value): string => (string) $value)->all();

        return array_map(static fn (string $value): array => [
            'value' => $value,
            'label' => $value === 'credit_card'
                ? __('logistics.product_statistics.credit_card_refund_status')
                : LogisticsPayPalRefund::statusLabelFor($value),
        ], $values);
    }

    /**
     * @param array<string,mixed> $filters
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function disputeDetailPage(array $filters, string $key, array $options): array
    {
        $orderOptions = $options;
        $orderOptions['keyword'] = '';
        $orderOptions['logistics_status'] = '';
        $orderIds = $this->detailOrderIdsQuery($filters, $key, $orderOptions, 'paypal');
        $query = LogisticsPayPalDispute::query()
            ->join('logistics_orders as orders', 'orders.id', '=', 'logistics_paypal_disputes.logistics_order_id')
            ->whereIn('logistics_paypal_disputes.logistics_order_id', $orderIds)
            ->select('logistics_paypal_disputes.*');
        $this->applyDetailTextFilter($query, $options, [
            'logistics_paypal_disputes.dispute_id', 'logistics_paypal_disputes.reason', 'orders.shopify_order_number', 'orders.store_name', 'orders.bill_number',
        ]);
        foreach (['status' => 'status', 'type' => 'channel', 'outcome' => 'outcome_code'] as $option => $column) {
            if (filled($options[$option] ?? null)) {
                $query->where('logistics_paypal_disputes.'.$column, (string) $options[$option]);
            }
        }
        $this->applyDetailSort($query, [
            'dispute_id' => 'logistics_paypal_disputes.dispute_id', 'amount' => 'logistics_paypal_disputes.dispute_amount_usd',
            'status' => 'logistics_paypal_disputes.status', 'stage' => 'logistics_paypal_disputes.channel',
            'outcome' => 'logistics_paypal_disputes.outcome_code', 'updated_at' => 'logistics_paypal_disputes.dispute_updated_at',
        ][(string) ($options['sort'] ?? '')] ?? 'logistics_paypal_disputes.dispute_updated_at', $options, 'logistics_paypal_disputes.id');
        $page = $this->paginateDetailQuery($query, $options);
        $ids = $page['rows']
            ->map(static fn (object $row): int => (int) $row->id)
            ->all();
        $details = LogisticsPayPalDispute::query()->with(['order', 'refundLinks.refund'])->whereIn('id', $ids)->get()->keyBy('id');
        $rows = array_values(array_filter(array_map(function (int $id) use ($details): ?array {
            $dispute = $details->get($id);
            return $dispute instanceof LogisticsPayPalDispute && $dispute->order instanceof LogisticsOrder
                ? $this->paypalDisputeDetail($dispute, $dispute->order)
                : null;
        }, $ids)));

        return [
            'rows' => $rows,
            'pagination' => $page['pagination'],
            'options' => $this->disputeOptions($filters, $key),
        ];
    }

    /** @param array<string,mixed> $filters @return array<string,list<array{value:string,label:string}>> */
    private function disputeOptions(array $filters, string $key): array
    {
        $options = ['keyword' => '', 'logistics_status' => ''];
        $orderIds = $this->detailOrderIdsQuery($filters, $key, $options, 'paypal');
        $base = $this->database()->table('logistics_paypal_disputes as dispute')->whereIn('dispute.logistics_order_id', $orderIds);
        $values = static function (Builder $query, string $column): array {
            return $query->whereNotNull($column)->where($column, '<>', '')->distinct()->orderBy($column)->pluck($column)
                ->map(static fn ($value): string => (string) $value)->all();
        };

        $types = $values(clone $base, 'dispute.channel');
        $statuses = $values(clone $base, 'dispute.status');
        $outcomes = $values(clone $base, 'dispute.outcome_code');

        return [
            'type' => array_map(static fn (string $value): array => [
                'value' => $value,
                'label' => LogisticsPayPalDispute::typeLabelFor($value),
            ], $types),
            'status' => array_map(static fn (string $value): array => [
                'value' => $value,
                'label' => LogisticsPayPalDispute::statusLabelFor($value),
            ], $statuses),
            'outcome' => array_map(static fn (string $value): array => [
                'value' => $value,
                'label' => LogisticsPayPalDispute::outcomeLabelFor($value),
            ], $outcomes),
        ];
    }

    /**
     * @param array<string,mixed> $filters
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function variantDetailPage(array $filters, string $key, array $options): array
    {
        $variantExpression = $this->detailVariantKeyExpression('items');
        $query = $this->baseItemQuery($filters)
            ->whereRaw($this->productKeyExpression('items').' = ?', [$key])
            ->selectRaw("{$variantExpression} as variant_key")
            ->groupByRaw($variantExpression);
        $this->applyDetailTextFilter($query, $options, ['items.tongtu_sku', 'items.shopify_sku', 'items.tongtu_product_name', 'items.product_title', 'orders.store_name']);
        $this->applyDetailSort($query, 'variant_key', $options, 'variant_key');
        $page = $this->paginateDetailQuery($query, $options);
        $keys = $page['rows']
            ->map(static fn (object $row): string => (string) $row->variant_key)
            ->all();
        if ($keys === []) {
            return ['rows' => [], 'pagination' => $page['pagination'], 'options' => []];
        }

        $items = LogisticsOrderItem::query()
            ->with([
                'order',
                'order.paypalRefunds.disputeLinks.dispute',
                'order.paypalDisputes.refundLinks.refund',
                'order.creditCardRefunds',
            ])
            ->select('logistics_order_items.*')
            ->selectRaw('logistics_orders.store_name as statistics_store_name')
            ->selectRaw('logistics_orders.order_created_at as statistics_order_created_at')
            ->join('logistics_orders', 'logistics_orders.id', '=', 'logistics_order_items.logistics_order_id')
            ->whereNotNull('logistics_order_items.line_revenue_usd')
            ->whereRaw($this->productKeyExpression('logistics_order_items').' = ?', [$key])
            ->whereIn(DB::raw($this->detailVariantKeyExpression('logistics_order_items')), $keys);
        $this->applyBaseFilters($items, $filters);
        $groups = [];
        foreach ($items->get()->all() as $item) {
            $groups[$this->detailVariantKeyFromItem($item)][] = $item;
        }
        foreach ($groups as $variantKey => $groupItems) {
            $metrics = $this->variantMetrics($groupItems);
            $groups[$variantKey] = $metrics[0] ?? null;
        }

        return [
            'rows' => array_values(array_filter(array_map(static fn (string $variantKey): ?array => $groups[$variantKey] ?? null, $keys))),
            'pagination' => $page['pagination'],
            'options' => [],
        ];
    }

    /** @param array<string,mixed> $options */
    private function applyDetailTextFilter(object $query, array $options, array $columns): void
    {
        $keyword = trim((string) ($options['keyword'] ?? ''));
        if ($keyword === '') {
            return;
        }
        $like = '%'.$this->escapeLike($keyword).'%';
        $query->where(function ($nested) use ($columns, $like): void {
            foreach ($columns as $index => $column) {
                if ($index === 0) $nested->where($column, 'like', $like);
                else $nested->orWhere($column, 'like', $like);
            }
        });
    }

    /** @param array<string,mixed> $options */
    private function applyDetailSort(object $query, string $column, array $options, string $tieBreaker): void
    {
        $direction = ($options['direction'] ?? '') === 'asc' ? 'asc' : 'desc';
        $query->orderBy($column, $direction)->orderBy($tieBreaker, $direction);
    }

    /**
     * @param array<string,mixed> $options
     * @return array{rows:Collection<int,object>,pagination:array<string,int>}
     */
    private function paginateDetailQuery(object $query, array $options): array
    {
        $perPage = in_array((int) ($options['per_page'] ?? 10), [5, 10, 20], true) ? (int) $options['per_page'] : 10;
        $countQuery = clone $query;
        if (method_exists($countQuery, 'reorder')) {
            $countQuery->reorder();
        }
        if ($countQuery instanceof \Illuminate\Database\Eloquent\Builder) {
            $countQuery = $countQuery->toBase();
        }
        $total = $this->database()->query()->fromSub($countQuery, 'detail_page_count')->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, (int) ($options['page'] ?? 1)), $lastPage);
        $rows = $query->forPage($page, $perPage)->get();

        return [
            'rows' => $rows,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
                'from' => $total === 0 ? 0 : (($page - 1) * $perPage) + 1,
                'to' => min($page * $perPage, $total),
            ],
        ];
    }

    private function detailVariantKeyExpression(string $alias): string
    {
        return "CASE WHEN NULLIF(TRIM({$alias}.tongtu_sku), '') IS NOT NULL THEN CONCAT('tongtu:', LOWER(TRIM({$alias}.tongtu_sku))) "
            . "WHEN NULLIF(TRIM({$alias}.shopify_sku), '') IS NOT NULL THEN CONCAT('shopify:', LOWER(TRIM({$alias}.shopify_sku))) "
            . "ELSE CONCAT('item:', {$alias}.id) END";
    }

    private function detailVariantKeyFromItem(LogisticsOrderItem $item): string
    {
        $tongtuSku = trim((string) $item->tongtu_sku);
        if ($tongtuSku !== '') {
            return 'tongtu:'.mb_strtolower($tongtuSku);
        }

        $shopifySku = trim((string) $item->shopify_sku);
        if ($shopifySku !== '') {
            return 'shopify:'.mb_strtolower($shopifySku);
        }

        return 'item:'.(int) $item->getKey();
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
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
    private function productStatisticsQuery(array $filters, bool $withOverviewModules = true, ?array $productKeys = null): Builder
    {
        $itemStatistics = $this->itemAggregateQuery($filters, $productKeys);
        $orderStatistics = $this->productOrderStatisticsQuery($filters, $withOverviewModules, $productKeys);
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
            ->selectRaw('COALESCE(order_statistics.credit_card_refund_amount, 0) as credit_card_refund_amount')
            ->selectRaw('COALESCE(order_statistics.credit_card_refund_count, 0) as credit_card_refund_count')
            ->selectRaw('COALESCE(order_statistics.credit_card_refund_order_count, 0) as credit_card_refund_order_count')
            ->selectRaw('COALESCE(order_statistics.credit_card_order_count, 0) as credit_card_order_count')
            ->selectRaw('COALESCE(order_statistics.combined_refund_order_count, 0) as combined_refund_order_count')
            ->selectRaw('COALESCE(order_statistics.paid_order_count, 0) as paid_order_count');

        if ($withOverviewModules) {
            $query
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
                ->selectRaw('COALESCE(order_statistics.credit_card_module_chargeback_count, 0) as credit_card_module_chargeback_count')
                ->selectRaw('COALESCE(order_statistics.credit_card_module_chargeback_order_count, 0) as credit_card_module_chargeback_order_count')
                ->selectRaw('COALESCE(order_statistics.credit_card_module_chargeback_amount, 0) as credit_card_module_chargeback_amount')
                ->selectRaw('COALESCE(order_statistics.credit_card_module_shipped_order_count, 0) as credit_card_module_shipped_order_count')
                ->selectRaw('COALESCE(order_statistics.credit_card_module_delivered_order_count, 0) as credit_card_module_delivered_order_count');
        }

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

    /**
     * The primary list selector deliberately only uses item-level data. It is
     * enough to find and order a page of products, while the payment-heavy
     * statistics are fetched afterwards for just that page.
     *
     * @param array<string,mixed> $filters
     */
    private function lightweightProductCandidateQuery(array $filters): Builder
    {
        $itemStatistics = $this->itemAggregateQuery($filters);

        return $this->database()->query()
            ->fromSub($itemStatistics, 'item_statistics')
            ->join('logistics_order_items as first_item', 'first_item.id', '=', 'item_statistics.first_item_id')
            ->leftJoin('logistics_order_items as image_item', 'image_item.id', '=', 'item_statistics.image_item_id')
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
            ->selectRaw("COALESCE(NULLIF(TRIM(first_item.product_image_url), ''), NULLIF(TRIM(image_item.product_image_url), '')) as product_image_url");
    }

    /**
     * @param array<string,mixed> $filters
     * @param list<string>|null $productKeys
     */
    private function itemAggregateQuery(array $filters, ?array $productKeys = null): Builder
    {
        $key = $this->productKeyExpression('items');
        $query = $this->baseItemQuery($filters);
        if ($productKeys !== null) {
            $query->whereIn(DB::raw($key), $productKeys);
        }

        return $query
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
    private function productOrderStatisticsQuery(array $filters, bool $withOverviewModules = true, ?array $productKeys = null): Builder
    {
        $orders = $this->productOrderQuery($filters, $withOverviewModules, $productKeys);
        $paypalRefunds = $this->paypalRefundAggregateQuery();
        $paypalDisputes = $this->paypalDisputeAggregateQuery($withOverviewModules);
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
        $query = $this->database()->query()
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
            ->select('product_orders.product_key');

        if ($withOverviewModules) {
            $query->leftJoinSub(
                $this->creditCardChargebackAggregateQuery(),
                'credit_card_chargebacks',
                'credit_card_chargebacks.logistics_order_id',
                '=',
                'product_orders.order_id',
            );

            $paypalModule = "({$actualPaypal} AND NOT {$creditCardTag})";
            $quote = fn (string $value): string => $this->database()->getPdo()->quote($value);
            $shipped = 'product_orders.logistics_status IN ('.implode(', ', array_map($quote, [
                LogisticsOrder::LOGISTICS_IN_TRANSIT,
                LogisticsOrder::LOGISTICS_EXCEPTION,
                LogisticsOrder::LOGISTICS_DELIVERED,
            ])).')';
            $delivered = 'product_orders.logistics_status = '.$quote(LogisticsOrder::LOGISTICS_DELIVERED);

            $query
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
                ->selectRaw("SUM(CASE WHEN {$creditCard} THEN COALESCE(credit_card_chargebacks.chargeback_count, 0) ELSE 0 END) as credit_card_module_chargeback_count")
                ->selectRaw("SUM(CASE WHEN {$creditCard} AND COALESCE(credit_card_chargebacks.chargeback_count, 0) > 0 THEN 1 ELSE 0 END) as credit_card_module_chargeback_order_count")
                ->selectRaw("SUM(CASE WHEN {$creditCard} THEN COALESCE(credit_card_chargebacks.chargeback_amount, 0) ELSE 0 END) as credit_card_module_chargeback_amount")
                ->selectRaw("SUM(CASE WHEN {$creditCard} AND {$shipped} THEN 1 ELSE 0 END) as credit_card_module_shipped_order_count")
                ->selectRaw("SUM(CASE WHEN {$creditCard} AND {$delivered} THEN 1 ELSE 0 END) as credit_card_module_delivered_order_count");
        }

        return $query
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
    private function productOrderQuery(array $filters, bool $withOverviewModules = true, ?array $productKeys = null): Builder
    {
        $key = $this->productKeyExpression('items');
        $query = $this->baseItemQuery($filters);
        if ($productKeys !== null) {
            $query->whereIn(DB::raw($key), $productKeys);
        }

        $query
            ->selectRaw("{$key} as product_key")
            ->addSelect('orders.id as order_id')
            ->selectRaw('MAX(orders.paid_at) as paid_at')
            ->selectRaw('MAX(COALESCE(orders.paid_amount_usd, 0)) as paid_amount_usd')
            ->selectRaw('MAX(orders.paypal_account_id) as paypal_account_id')
            ->selectRaw('MAX(orders.paypal_association_source) as paypal_association_source')
            ->selectRaw('MAX(orders.payment_method) as payment_method')
            ->selectRaw('MAX(orders.payment_gateway) as payment_gateway')
            ->selectRaw('MAX(orders.shopify_payload) as shopify_payload');

        if ($withOverviewModules) {
            $query
                ->selectRaw('SUM(COALESCE(items.quantity, 0)) as unit_count')
                ->selectRaw('SUM(COALESCE(items.line_revenue_usd, 0)) as revenue')
                ->selectRaw('MAX(orders.logistics_status) as logistics_status');
        }

        return $query->groupByRaw("{$key}, orders.id");
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

    private function paypalDisputeAggregateQuery(bool $withOverviewModules = true): Builder
    {
        $query = $this->database()->table('logistics_paypal_disputes as dispute')
            ->whereNotNull('dispute.logistics_order_id')
            ->select(['dispute.logistics_order_id', 'dispute.logistics_paypal_account_id'])
            ->selectRaw('COUNT(*) as dispute_count');

        if ($withOverviewModules) {
            $query->selectRaw("SUM(COALESCE(dispute.dispute_amount_usd, CASE WHEN UPPER(dispute.currency_code) = 'USD' THEN dispute.original_amount ELSE 0 END)) as dispute_amount");
        }

        return $query->groupBy('dispute.logistics_order_id', 'dispute.logistics_paypal_account_id');
    }

    private function creditCardRefundAggregateQuery(): Builder
    {
        return $this->database()->table('logistics_credit_card_refunds as refund')
            ->select('refund.logistics_order_id')
            ->selectRaw('SUM(refund.refund_amount_usd) as refund_amount')
            ->selectRaw('COUNT(*) as refund_count')
            ->groupBy('refund.logistics_order_id');
    }

    /**
     * A credit-card refund is considered a chargeback when its imported reason
     * contains one of the industry markers Ethoca, CDRN, or RDR.  The import
     * reason is user-supplied text, therefore matching is deliberately
     * case-insensitive and allows surrounding text.
     */
    private function creditCardChargebackAggregateQuery(): Builder
    {
        return $this->database()->table('logistics_credit_card_refunds as refund')
            ->where(function (Builder $query): void {
                $query
                    ->whereRaw("LOWER(COALESCE(refund.refund_reason, '')) LIKE ?", ['%ethoca%'])
                    ->orWhereRaw("LOWER(COALESCE(refund.refund_reason, '')) LIKE ?", ['%cdrn%'])
                    ->orWhereRaw("LOWER(COALESCE(refund.refund_reason, '')) LIKE ?", ['%rdr%']);
            })
            ->select('refund.logistics_order_id')
            ->selectRaw('COUNT(*) as chargeback_count')
            ->selectRaw('SUM(refund.refund_amount_usd) as chargeback_amount')
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
        $query = $this->productStatisticsQuery($filters, false);
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
        $decision = trim((string) ($filters['decision'] ?? ''));
        $productStatistics = $decision === ''
            ? $this->itemAggregateQuery($filters)
            : clone $products;
        $aggregate = $this->database()->query()->fromSub($productStatistics, 'product_statistics')
            ->selectRaw('COUNT(*) as product_count')
            ->selectRaw('COALESCE(SUM(product_statistics.sales), 0) as sales')
            ->selectRaw('COALESCE(SUM(product_statistics.revenue), 0) as revenue')
            ->selectRaw('COALESCE(SUM(product_statistics.shipping), 0) as shipping')
            ->selectRaw('COALESCE(SUM(product_statistics.handling), 0) as handling')
            ->selectRaw('COALESCE(SUM(product_statistics.cost_complete), 0) as cost')
            ->selectRaw('SUM(CASE WHEN product_statistics.cost_complete IS NULL THEN 1 ELSE 0 END) as has_missing_cost')
            ->first();
        $refunds = $decision === ''
            ? $this->uniqueRefundSummaryForBaseFilters($filters)
            : $this->uniqueRefundSummary($products, $filters);
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
            ->fromSub($this->productOrderQuery($filters, false), 'product_orders')
            ->joinSub($productKeys, 'product_statistics', 'product_statistics.product_key', '=', 'product_orders.product_key')
            ->select('product_orders.order_id')
            ->selectRaw('MAX(product_orders.paypal_account_id) as paypal_account_id')
            ->selectRaw('MAX(product_orders.paypal_association_source) as paypal_association_source')
            ->groupBy('product_orders.order_id');

        return $this->refundSummaryForOrders($selectedOrders);
    }

    /**
     * The default page summary does not filter by an outcome decision, so it
     * can read the matching order IDs directly instead of rebuilding the whole
     * per-product refunds/disputes statistics query.
     *
     * @param array<string,mixed> $filters
     * @return array{paypal:float,credit_card:float}
     */
    private function uniqueRefundSummaryForBaseFilters(array $filters): array
    {
        $selectedOrders = $this->database()->query()
            ->fromSub($this->productOrderQuery($filters, false), 'product_orders')
            ->select('product_orders.order_id')
            ->selectRaw('MAX(product_orders.paypal_account_id) as paypal_account_id')
            ->selectRaw('MAX(product_orders.paypal_association_source) as paypal_association_source')
            ->groupBy('product_orders.order_id');

        return $this->refundSummaryForOrders($selectedOrders);
    }

    /** @return array{paypal:float,credit_card:float} */
    private function refundSummaryForOrders(Builder $selectedOrders): array
    {
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

        $product = [
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
        ];

        if (isset($row->paypal_module_unit_count)) {
            $product['overview_modules'] = $this->overviewModulesFromRow($row);
        }

        return $product;
    }

    /**
     * The full statistics query can provide these compact aggregates to a
     * caller that specifically needs a product overview. The normal list
     * intentionally skips them, because the modal loads one product on demand.
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
            true,
            (int) $row->credit_card_module_chargeback_count,
            (int) $row->credit_card_module_chargeback_order_count,
            (float) $row->credit_card_module_chargeback_amount,
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
                false,
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
        bool $creditCardChargebacksApplicable = false,
        int $chargebackCount = 0,
        int $chargebackOrderCount = 0,
        float $chargebackAmount = 0.0,
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
            'dispute_reason_breakdown' => [],
            'chargeback_applicable' => $creditCardChargebacksApplicable,
            'chargeback_count' => $chargebackCount,
            'chargeback_order_count' => $chargebackOrderCount,
            'chargeback_amount' => round($chargebackAmount, 4),
            'chargeback_rate' => $orderCount > 0 ? round($chargebackOrderCount / $orderCount * 100, 2) : 0.0,
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
            'credit_card' => $this->overviewModule($this->itemsForOrders($items, $creditCardOrders), $creditCardOrders, false, true),
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
    private function overviewModule(array $items, Collection $orders, bool $disputesApplicable, bool $creditCardChargebacksApplicable = false): array
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
        $chargebackDetails = $creditCardChargebacksApplicable
            ? array_values(array_filter(
                $payment['credit_card_refund_details'],
                fn (array $refund): bool => $this->isCreditCardChargebackReason($refund['refund_reason'] ?? ''),
            ))
            : [];
        $chargebackOrderCount = count(array_unique(array_filter(array_map(
            static fn (array $refund): int => (int) ($refund['order_id'] ?? 0),
            $chargebackDetails,
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
            'chargeback_applicable' => $creditCardChargebacksApplicable,
            'chargeback_count' => count($chargebackDetails),
            'chargeback_order_count' => $chargebackOrderCount,
            'chargeback_amount' => round(array_sum(array_column($chargebackDetails, 'amount')), 4),
            'chargeback_rate' => $orders->isEmpty() ? 0.0 : round($chargebackOrderCount / $orders->count() * 100, 2),
        ];
    }

    private function isCreditCardChargebackReason(mixed $reason): bool
    {
        $value = mb_strtolower(trim((string) $reason));

        return $value !== ''
            && (str_contains($value, 'ethoca') || str_contains($value, 'cdrn') || str_contains($value, 'rdr'));
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
