<?php

namespace App\Portals\Payment;

use App\Models\LogisticsOrder;
use App\Portals\Logistics\LogisticsProductStatisticsService;
use App\Portals\Logistics\LogisticsCreditCardRefundReason;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class PaymentProductRiskService
{
    public function __construct(
        private readonly LogisticsProductStatisticsService $statistics,
        private readonly PaymentProductAlertService $alerts,
    )
    {
    }

    /** @param array<string,mixed> $filters @return array<string,mixed> */
    public function dashboard(array $filters): array
    {
        $cacheKey = 'payment:product-risk-dashboard:v2:'.sha1((string) json_encode(
            $filters,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));

        return Cache::remember(
            $cacheKey,
            now()->addSeconds(30),
            fn (): array => $this->buildDashboard($filters),
        );
    }

    /** @param array<string,mixed> $filters @return array<string,mixed> */
    private function buildDashboard(array $filters): array
    {
        $data = $this->withPaymentProductNames($this->statistics->page([...$filters, 'skip_refund_report' => true]));
        $summary = $data['summary'];
        $revenue = (float) ($summary['revenue'] ?? 0);
        $refunds = (float) ($summary['actual_refunds'] ?? 0);

        return [
            ...$data,
            'filters' => $filters,
            'stores' => $this->statistics->storeOptions($filters),
            'refund_rate' => $revenue > 0 ? round($refunds / $revenue * 100, 2) : 0.0,
        ];
    }

    /** @param array<string,mixed> $filters @return array<string,mixed> */
    public function tab(array $filters, string $tab): array
    {
        $cacheKey = 'payment:product-risk-tab:v2:'.sha1((string) json_encode([
            'filters' => $filters,
            'tab' => $tab,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return Cache::remember(
            $cacheKey,
            now()->addSeconds(30),
            fn (): array => $this->buildTab($filters, $tab),
        );
    }

    /** @param array<string,mixed> $filters @return array<string,mixed> */
    private function buildTab(array $filters, string $tab): array
    {
        if ($tab !== 'refund_reason') {
            return [
                ...$this->withPaymentProductNames($this->statistics->page([...$filters, 'skip_refund_report' => true])),
                'filters' => $filters,
            ];
        }

        $source = $this->statistics->page([...$filters, 'page' => 1, 'per_page' => 1]);
        $report = $source['refund_reason_report'] ?? [];
        $rows = [];
        foreach (['source_reason', 'manual_reason'] as $type) {
            foreach (($report[$type] ?? []) as $row) {
                $rows[] = [...$row, 'reason_type' => $type];
            }
        }
        $perPage = in_array((int) ($filters['per_page'] ?? 50), [50, 100], true) ? (int) $filters['per_page'] : 50;
        $total = count($rows);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, (int) ($filters['page'] ?? 1)), $lastPage);
        $offset = ($page - 1) * $perPage;

        return [
            'refund_reason_rows' => array_slice($rows, $offset, $perPage),
            'filters' => $filters,
            'pagination' => [
                'current_page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => $lastPage,
                'from' => $total === 0 ? 0 : $offset + 1, 'to' => min($offset + $perPage, $total),
            ],
        ];
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function withPaymentProductNames(array $data): array
    {
        $products = $data['products'] ?? [];
        $keys = collect($products)->mapWithKeys(function (array $product): array {
            $encoded = str_starts_with((string) ($product['id'] ?? ''), 'product-') ? substr((string) $product['id'], 8) : '';
            $encoded = strtr($encoded, '-_', '+/').str_repeat('=', (4 - strlen($encoded) % 4) % 4);
            $decoded = $encoded === '' ? false : base64_decode($encoded, true);
            return $decoded === false || $decoded === '' ? [] : [(string) $decoded => (string) $decoded];
        })->all();
        if ($keys === []) {
            return $data;
        }

        $keyExpression = "CASE "
            ."WHEN NULLIF(TRIM(items.tongtu_product_code), '') IS NOT NULL THEN CONCAT('tongtu-product-code:', LOWER(TRIM(items.tongtu_product_code))) "
            ."WHEN NULLIF(TRIM(items.shopify_sku), '') IS NOT NULL THEN CONCAT('shopify-sku:', LOWER(TRIM(items.shopify_sku))) "
            ."WHEN NULLIF(TRIM(items.tongtu_sku), '') IS NOT NULL THEN CONCAT('tongtu-sku:', LOWER(TRIM(items.tongtu_sku))) "
            ."WHEN NULLIF(TRIM(items.shopify_product_id), '') IS NOT NULL THEN CONCAT('shopify-product:', LOWER(TRIM(items.shopify_product_id))) "
            ."WHEN NULLIF(TRIM(COALESCE(items.tongtu_product_name, items.product_title)), '') IS NOT NULL THEN CONCAT('product-name:', LOWER(TRIM(COALESCE(items.tongtu_product_name, items.product_title)))) "
            ."ELSE CONCAT('sku:', COALESCE(NULLIF(LOWER(TRIM(items.tongtu_product_code)), ''), NULLIF(LOWER(TRIM(items.tongtu_sku)), ''), NULLIF(LOWER(TRIM(items.shopify_sku)), ''), '__missing__')) END";
        $firstItems = DB::connection(config('admin.database.connection') ?: config('database.default'))
            ->table('logistics_order_items as items')
            ->whereIn(DB::raw($keyExpression), array_values($keys))
            ->selectRaw("{$keyExpression} as product_key")
            ->selectRaw('MIN(items.id) as first_item_id')
            ->groupByRaw($keyExpression);
        $names = DB::connection(config('admin.database.connection') ?: config('database.default'))
            ->query()->fromSub($firstItems, 'payment_product_names')
            ->join('logistics_order_items as source_item', 'source_item.id', '=', 'payment_product_names.first_item_id')
            ->get(['payment_product_names.product_key', 'source_item.product_title', 'source_item.tongtu_product_name'])
            ->keyBy('product_key');

        $latestPaidQuery = DB::connection(config('admin.database.connection') ?: config('database.default'))
            ->table('logistics_order_items as items')
            ->join('logistics_orders as orders', 'orders.id', '=', 'items.logistics_order_id')
            ->whereIn(DB::raw($keyExpression), array_values($keys))
            ->whereNotNull('orders.paid_at');
        $activeFilters = (array) ($data['filters'] ?? []);
        if (($activeFilters['date_from'] ?? '') !== '') $latestPaidQuery->whereDate('orders.paid_at', '>=', $activeFilters['date_from']);
        if (($activeFilters['date_to'] ?? '') !== '') $latestPaidQuery->whereDate('orders.paid_at', '<=', $activeFilters['date_to']);
        if (($activeFilters['store'] ?? '') !== '') $latestPaidQuery->where('orders.store_name', $activeFilters['store']);
        $orderFacts = $latestPaidQuery
            ->selectRaw("{$keyExpression} as product_key")
            ->selectRaw('COUNT(DISTINCT orders.id) as order_count')
            ->selectRaw('MIN(orders.paid_at) as first_paid_at')
            ->selectRaw('MAX(orders.paid_at) as latest_paid_at')
            ->groupByRaw($keyExpression)->get()->keyBy('product_key');

        $riskMetrics = $this->alerts->metricsByProductKey(array_values($keys), $activeFilters);
        $data['products'] = collect($products)->map(function (array $product) use ($names, $riskMetrics, $orderFacts): array {
            $encoded = substr((string) $product['id'], 8);
            $encoded = strtr($encoded, '-_', '+/').str_repeat('=', (4 - strlen($encoded) % 4) % 4);
            $key = base64_decode($encoded, true);
            $name = $key === false ? null : $names->get($key);
            $product['source_product_name'] = trim((string) ($name->product_title ?? ''));
            $product['tongtu_product_name'] = trim((string) ($name->tongtu_product_name ?? ''));
            $channels = $key === false ? [] : ($riskMetrics[$key] ?? []);
            $paypal = $channels['paypal'] ?? [];
            $card = $channels['credit_card'] ?? [];
            $product['paypal_initial_order_count'] = (int) ($paypal['eligible_order_count'] ?? 0);
            $product['paypal_initial_dispute_count'] = (int) ($paypal['dispute_count'] ?? 0);
            $product['paypal_initial_dispute_rate'] = (float) ($paypal['dispute_rate'] ?? 0);
            $product['paypal_initial_refund_amount'] = (float) ($paypal['refund_amount'] ?? 0);
            $product['paypal_initial_received_amount'] = (float) ($paypal['received_amount'] ?? 0);
            $product['paypal_initial_refund_amount_rate'] = (float) ($paypal['refund_amount_rate'] ?? 0);
            $product['paypal_delivered_order_count'] = (int) ($paypal['delivered_order_count'] ?? 0);
            $product['paypal_delivered_dispute_count'] = (int) ($paypal['delivered_dispute_order_count'] ?? 0);
            $product['paypal_delivered_dispute_rate'] = (float) ($paypal['delivered_dispute_rate'] ?? 0);
            $product['paypal_description_mismatch_amount'] = (float) ($paypal['description_mismatch_amount'] ?? 0);
            $product['paypal_description_mismatch_rate'] = (float) ($paypal['description_mismatch_rate'] ?? 0);
            $product['credit_card_order_count'] = (int) ($card['eligible_order_count'] ?? 0);
            $product['credit_card_complaint_count'] = (int) ($card['complaint_count'] ?? 0);
            $product['credit_card_dispute_count'] = (int) ($card['dispute_count'] ?? 0);
            $product['credit_card_dispute_rate'] = (float) ($card['dispute_rate'] ?? 0);
            $product['credit_card_initial_refund_amount'] = (float) ($card['refund_amount'] ?? 0);
            $product['credit_card_initial_received_amount'] = (float) ($card['received_amount'] ?? 0);
            $product['credit_card_initial_refund_amount_rate'] = (float) ($card['refund_amount_rate'] ?? 0);
            $product['credit_card_delivered_order_count'] = (int) ($card['delivered_order_count'] ?? 0);
            $product['credit_card_delivered_dispute_count'] = (int) ($card['delivered_dispute_order_count'] ?? 0);
            $product['credit_card_delivered_dispute_rate'] = (float) ($card['delivered_dispute_rate'] ?? 0);
            $facts = $key === false ? null : $orderFacts->get($key);
            $product['order_count'] = (int) ($facts->order_count ?? 0);
            $firstPaidAt = $facts->first_paid_at ?? null;
            $product['first_paid_at'] = $firstPaidAt
                ? Carbon::parse((string) $firstPaidAt, 'UTC')->timezone('Asia/Shanghai')->format('Y-m-d H:i')
                : '-';
            $latestPaidAt = $facts->latest_paid_at ?? null;
            $product['latest_paid_at'] = $latestPaidAt
                ? Carbon::parse((string) $latestPaidAt, 'UTC')->timezone('Asia/Shanghai')->format('Y-m-d H:i')
                : '-';
            return $product;
        })->all();

        return $data;
    }

    /** @param array<string,mixed> $filters @param array<string,mixed> $options @return array<string,mixed>|null */
    public function detail(array $filters, string $productId, array $options): ?array
    {
        $cacheKey = 'payment:product-risk-detail:v2:'.sha1((string) json_encode([
            'filters' => $filters,
            'product_id' => $productId,
            'options' => $options,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return Cache::remember(
            $cacheKey,
            now()->addSeconds(30),
            fn (): ?array => $this->buildDetail($filters, $productId, $options),
        );
    }

    /** @param array<string,mixed> $filters @param array<string,mixed> $options @return array<string,mixed>|null */
    private function buildDetail(array $filters, string $productId, array $options): ?array
    {
        if (($options['tab'] ?? '') === 'variants') {
            $key = $this->decodeProductKey($productId);
            if ($key === null) {
                return null;
            }

            return [
                'tab' => 'variants',
                'product' => [],
                'detail' => $this->variantDetailPage($filters, $key, $options),
            ];
        }

        $detail = $this->statistics->detailPage($filters, $productId, $options);
        if ($detail === null) {
            return null;
        }
        $key = $this->decodeProductKey($productId);
        if ($key !== null) {
            $detail['channel_risk'] = $this->alerts->metricsByProductKey([$key])[$key] ?? [];
            $context = $this->productOrderContext($filters, $key);
            $detail['product'] = [...($detail['product'] ?? []), ...$context];
            if (($options['tab'] ?? '') === 'first_renewal' && isset($detail['detail'])) {
                $detail['detail']['channel_groups'] = $this->channelLifecycleGroups($filters, $key);
            }
        }

        return $detail;
    }

    /** @param array<string,mixed> $filters @return array<string,mixed> */
    private function productOrderContext(array $filters, string $productKey): array
    {
        $database = DB::connection(config('admin.database.connection') ?: config('database.default'));
        $expression = $this->productKeyExpression('items');
        $query = $database->table('logistics_order_items as items')
            ->join('logistics_orders as orders', 'orders.id', '=', 'items.logistics_order_id')
            ->whereRaw("{$expression} = ?", [$productKey]);
        if (($filters['date_from'] ?? '') !== '') $query->whereDate('orders.paid_at', '>=', $filters['date_from']);
        if (($filters['date_to'] ?? '') !== '') $query->whereDate('orders.paid_at', '<=', $filters['date_to']);
        if (($filters['store'] ?? '') !== '') $query->where('orders.store_name', $filters['store']);

        $timeline = (clone $query)->selectRaw('MIN(orders.paid_at) as first_order_at, MAX(orders.paid_at) as last_order_at')->first();
        $stores = (clone $query)
            ->selectRaw("COALESCE(NULLIF(TRIM(orders.store_name), ''), '-') as name")
            ->selectRaw("COALESCE(NULLIF(TRIM(orders.operator_name), ''), '-') as operator_name")
            ->selectRaw('COUNT(DISTINCT orders.id) as order_count')
            ->selectRaw('MIN(orders.paid_at) as first_order_at')
            ->selectRaw('MAX(orders.paid_at) as last_order_at')
            ->groupBy('name', 'operator_name')->orderByDesc('order_count')->orderByDesc('last_order_at')->get()
            ->map(fn (object $row): array => [
                'name' => (string) $row->name,
                'operator_name' => (string) $row->operator_name,
                'order_count' => (int) $row->order_count,
                'first_order_at' => $this->displayDateTime($row->first_order_at),
                'last_order_at' => $this->displayDateTime($row->last_order_at),
            ])->all();

        return [
            'first_order_at' => $this->displayDateTime($timeline?->first_order_at),
            'last_order_at' => $this->displayDateTime($timeline?->last_order_at),
            'store_order_distribution' => $stores,
        ];
    }

    private function displayDateTime(mixed $value): string
    {
        return $value ? Carbon::parse((string) $value, 'UTC')->timezone('Asia/Shanghai')->format('Y-m-d H:i') : '-';
    }

    /**
     * 支付中台使用独立的轻量规格汇总，避免产品表现页加载完整订单关系树。
     *
     * @param array<string,mixed> $filters
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function variantDetailPage(array $filters, string $productKey, array $options): array
    {
        $database = DB::connection(config('admin.database.connection') ?: config('database.default'));
        $productExpression = $this->productKeyExpression('items');
        $variantExpression = $this->variantKeyExpression('items');
        $query = $database->table('logistics_order_items as items')
            ->join('logistics_orders as orders', 'orders.id', '=', 'items.logistics_order_id')
            ->whereNotNull('items.line_revenue_usd')
            ->whereRaw("{$productExpression} = ?", [$productKey]);
        $this->applyVariantFilters($query, $filters, $options);

        $grouped = (clone $query)
            ->selectRaw("{$variantExpression} as variant_key")
            ->selectRaw("MIN(NULLIF(TRIM(items.tongtu_sku), '')) as tongtu_sku")
            ->selectRaw("MIN(NULLIF(TRIM(items.tongtu_product_name), '')) as tongtu_product_name")
            ->selectRaw("MIN(NULLIF(TRIM(items.tongtu_product_code), '')) as tongtu_product_code")
            ->selectRaw("MIN(NULLIF(TRIM(items.product_image_url), '')) as product_image_url")
            ->selectRaw('COUNT(DISTINCT orders.id) as order_count')
            ->selectRaw('COALESCE(SUM(items.quantity), 0) as sales')
            ->selectRaw('COALESCE(SUM(items.line_revenue_usd), 0) as revenue')
            ->selectRaw("GROUP_CONCAT(DISTINCT NULLIF(TRIM(items.shopify_sku), '') ORDER BY items.shopify_sku SEPARATOR '|||') as shopify_skus")
            ->selectRaw("GROUP_CONCAT(DISTINCT NULLIF(TRIM(orders.store_name), '') ORDER BY orders.store_name SEPARATOR '|||') as stores")
            ->groupByRaw($variantExpression);

        $countQuery = (clone $query)->selectRaw("{$variantExpression} as variant_key")->groupByRaw($variantExpression);
        $total = $database->query()->fromSub($countQuery, 'payment_variant_count')->count();
        $perPage = (int) ($options['per_page'] ?? 50) === 100 ? 100 : 50;
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, (int) ($options['page'] ?? 1)), $lastPage);
        $direction = ($options['direction'] ?? '') === 'asc' ? 'asc' : 'desc';
        $sortColumns = [
            'tongtu_sku' => 'tongtu_sku', 'tongtu_product_name' => 'tongtu_product_name',
            'order_count' => 'order_count', 'sales' => 'sales', 'revenue' => 'revenue',
        ];
        $sort = $sortColumns[(string) ($options['sort'] ?? '')] ?? 'variant_key';
        $rows = $grouped->orderBy($sort, $direction)->orderBy('variant_key')->forPage($page, $perPage)->get();
        $variantKeys = $rows->pluck('variant_key')->map(static fn ($key): string => (string) $key)->all();
        $orderGroups = [];
        $orderRows = collect();
        if ($variantKeys !== []) {
            $orderQuery = $database->table('logistics_order_items as items')
                ->join('logistics_orders as orders', 'orders.id', '=', 'items.logistics_order_id')
                ->whereRaw("{$productExpression} = ?", [$productKey])
                ->whereIn(DB::raw($variantExpression), $variantKeys)
                ->selectRaw("{$variantExpression} as variant_key")
                ->addSelect(['orders.id', 'orders.logistics_status']);
            $this->applyVariantFilters($orderQuery, $filters, []);
            $orderRows = $orderQuery->distinct()->get();
            foreach ($orderRows as $order) {
                $orderGroups[(string) $order->variant_key][(int) $order->id] = true;
            }
        }

        $orderIds = $orderRows->pluck('id')->map(static fn ($id): int => (int) $id)->unique()->values()->all();
        $paypalRefunds = $orderIds === [] ? collect() : $database->table('logistics_paypal_refunds')
            ->whereIn('logistics_order_id', $orderIds)
            ->selectRaw("logistics_order_id, SUM(COALESCE(refund_amount_usd, CASE WHEN UPPER(currency_code) = 'USD' THEN original_amount ELSE 0 END)) as amount")
            ->groupBy('logistics_order_id')->pluck('amount', 'logistics_order_id');
        $paypalDisputes = $orderIds === [] ? collect() : $database->table('logistics_paypal_disputes')
            ->whereIn('logistics_order_id', $orderIds)->selectRaw('logistics_order_id, COUNT(DISTINCT id) as total')
            ->groupBy('logistics_order_id')->pluck('total', 'logistics_order_id');
        $creditCardRows = $orderIds === [] ? collect() : $database->table('logistics_credit_card_refunds')
            ->whereIn('logistics_order_id', $orderIds)->get(['logistics_order_id', 'refund_amount_usd', 'refund_reason'])->groupBy('logistics_order_id');
        $statusByOrder = $orderRows->keyBy('id');

        $resultRows = $rows->map(function (object $row) use ($orderGroups, $paypalRefunds, $paypalDisputes, $creditCardRows, $statusByOrder): array {
            $ids = array_keys($orderGroups[(string) $row->variant_key] ?? []);
            $paypalRefundAmount = 0.0;
            $paypalDisputeCount = 0;
            $creditCardRefundAmount = 0.0;
            $shipped = 0;
            $delivered = 0;
            foreach ($ids as $orderId) {
                $paypalRefundAmount += (float) ($paypalRefunds[$orderId] ?? 0);
                $paypalDisputeCount += (int) ($paypalDisputes[$orderId] ?? 0);
                foreach ($creditCardRows->get($orderId, collect()) as $risk) {
                    $reason = LogisticsCreditCardRefundReason::normalize((string) $risk->refund_reason);
                    if (in_array($reason, LogisticsCreditCardRefundReason::CUSTOMER_SERVICE, true)) {
                        $creditCardRefundAmount += (float) $risk->refund_amount_usd;
                    }
                }
                $status = (string) ($statusByOrder[$orderId]->logistics_status ?? '');
                if (in_array($status, [LogisticsOrder::LOGISTICS_IN_TRANSIT, LogisticsOrder::LOGISTICS_EXCEPTION, LogisticsOrder::LOGISTICS_DELIVERED], true)) $shipped++;
                if ($status === LogisticsOrder::LOGISTICS_DELIVERED) $delivered++;
            }
            $orderCount = count($ids);

            return [
                'tongtu_sku' => (string) ($row->tongtu_sku ?: '-'),
                'tongtu_product_name' => (string) ($row->tongtu_product_name ?: '-'),
                'tongtu_product_code' => (string) ($row->tongtu_product_code ?: ''),
                'product_image_url' => $row->product_image_url ?: null,
                'shopify_skus' => $this->splitVariantList($row->shopify_skus),
                'stores' => $this->splitVariantList($row->stores),
                'order_count' => $orderCount,
                'sales' => (float) $row->sales,
                'revenue' => round((float) $row->revenue, 2),
                'paypal_refund_amount' => round($paypalRefundAmount, 2),
                'paypal_dispute_count' => $paypalDisputeCount,
                'credit_card_refund_amount' => round($creditCardRefundAmount, 2),
                'shipping_rate' => $orderCount > 0 ? round($shipped / $orderCount * 100, 2) : 0.0,
                'delivery_rate' => $orderCount > 0 ? round($delivered / $orderCount * 100, 2) : 0.0,
            ];
        })->values()->all();

        return [
            'rows' => $resultRows,
            'pagination' => [
                'current_page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => $lastPage,
                'from' => $total === 0 ? 0 : (($page - 1) * $perPage) + 1, 'to' => min($page * $perPage, $total),
            ],
            'options' => [],
        ];
    }

    private function productKeyExpression(string $alias): string
    {
        return "CASE WHEN NULLIF(TRIM({$alias}.tongtu_product_code), '') IS NOT NULL THEN CONCAT('tongtu-product-code:', LOWER(TRIM({$alias}.tongtu_product_code))) "
            ."WHEN NULLIF(TRIM({$alias}.shopify_sku), '') IS NOT NULL THEN CONCAT('shopify-sku:', LOWER(TRIM({$alias}.shopify_sku))) "
            ."WHEN NULLIF(TRIM({$alias}.tongtu_sku), '') IS NOT NULL THEN CONCAT('tongtu-sku:', LOWER(TRIM({$alias}.tongtu_sku))) "
            ."WHEN NULLIF(TRIM({$alias}.shopify_product_id), '') IS NOT NULL THEN CONCAT('shopify-product:', LOWER(TRIM({$alias}.shopify_product_id))) "
            ."WHEN NULLIF(TRIM(COALESCE({$alias}.tongtu_product_name, {$alias}.product_title)), '') IS NOT NULL THEN CONCAT('product-name:', LOWER(TRIM(COALESCE({$alias}.tongtu_product_name, {$alias}.product_title)))) "
            ."ELSE CONCAT('sku:', COALESCE(NULLIF(LOWER(TRIM({$alias}.tongtu_product_code)), ''), NULLIF(LOWER(TRIM({$alias}.tongtu_sku)), ''), NULLIF(LOWER(TRIM({$alias}.shopify_sku)), ''), '__missing__')) END";
    }

    private function variantKeyExpression(string $alias): string
    {
        return "CASE WHEN NULLIF(TRIM({$alias}.tongtu_sku), '') IS NOT NULL THEN CONCAT('tongtu:', LOWER(TRIM({$alias}.tongtu_sku))) "
            ."WHEN NULLIF(TRIM({$alias}.shopify_sku), '') IS NOT NULL THEN CONCAT('shopify:', LOWER(TRIM({$alias}.shopify_sku))) "
            ."ELSE CONCAT('item:', {$alias}.id) END";
    }

    /** @param array<string,mixed> $filters @param array<string,mixed> $options */
    private function applyVariantFilters(object $query, array $filters, array $options): void
    {
        if (($filters['date_from'] ?? '') !== '') $query->whereDate('orders.paid_at', '>=', $filters['date_from']);
        if (($filters['date_to'] ?? '') !== '') $query->whereDate('orders.paid_at', '<=', $filters['date_to']);
        if (($filters['store'] ?? '') !== '') $query->where('orders.store_name', $filters['store']);
        $keyword = trim((string) ($options['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where(function ($nested) use ($keyword): void {
                $like = '%'.$keyword.'%';
                $nested->where('items.tongtu_sku', 'like', $like)->orWhere('items.shopify_sku', 'like', $like)
                    ->orWhere('items.tongtu_product_name', 'like', $like)->orWhere('items.product_title', 'like', $like)
                    ->orWhere('orders.store_name', 'like', $like);
            });
        }
    }

    /** @return list<string> */
    private function splitVariantList(mixed $value): array
    {
        return array_values(array_filter(array_map('trim', explode('|||', (string) $value))));
    }

    private function decodeProductKey(string $productId): ?string
    {
        if (!str_starts_with($productId, 'product-')) return null;
        $encoded = strtr(substr($productId, 8), '-_', '+/');
        $encoded .= str_repeat('=', (4 - strlen($encoded) % 4) % 4);
        $decoded = base64_decode($encoded, true);

        return $decoded === false || $decoded === '' ? null : $decoded;
    }

    /** @param array<string,mixed> $filters @return array<string,array<string,array<string,int|float>>> */
    private function channelLifecycleGroups(array $filters, string $productKey): array
    {
        $database = DB::connection(config('admin.database.connection') ?: config('database.default'));
        $keyExpression = "CASE "
            ."WHEN NULLIF(TRIM(items.tongtu_product_code), '') IS NOT NULL THEN CONCAT('tongtu-product-code:', LOWER(TRIM(items.tongtu_product_code))) "
            ."WHEN NULLIF(TRIM(items.shopify_sku), '') IS NOT NULL THEN CONCAT('shopify-sku:', LOWER(TRIM(items.shopify_sku))) "
            ."WHEN NULLIF(TRIM(items.tongtu_sku), '') IS NOT NULL THEN CONCAT('tongtu-sku:', LOWER(TRIM(items.tongtu_sku))) "
            ."WHEN NULLIF(TRIM(items.shopify_product_id), '') IS NOT NULL THEN CONCAT('shopify-product:', LOWER(TRIM(items.shopify_product_id))) "
            ."WHEN NULLIF(TRIM(COALESCE(items.tongtu_product_name, items.product_title)), '') IS NOT NULL THEN CONCAT('product-name:', LOWER(TRIM(COALESCE(items.tongtu_product_name, items.product_title)))) "
            ."ELSE CONCAT('sku:', COALESCE(NULLIF(LOWER(TRIM(items.tongtu_product_code)), ''), NULLIF(LOWER(TRIM(items.tongtu_sku)), ''), NULLIF(LOWER(TRIM(items.shopify_sku)), ''), '__missing__')) END";
        $ordersQuery = $database->table('logistics_order_items as items')
            ->join('logistics_orders as orders', 'orders.id', '=', 'items.logistics_order_id')
            ->whereRaw("{$keyExpression} = ?", [$productKey])
            ->where(function ($query): void {
                $query->whereNotNull('orders.paid_at')->orWhere('orders.paid_amount_usd', '>', 0);
            });
        if (($filters['date_from'] ?? '') !== '') $ordersQuery->whereDate('orders.paid_at', '>=', $filters['date_from']);
        if (($filters['date_to'] ?? '') !== '') $ordersQuery->whereDate('orders.paid_at', '<=', $filters['date_to']);
        if (($filters['store'] ?? '') !== '') $ordersQuery->where('orders.store_name', $filters['store']);
        $orders = $ordersQuery->select([
            'orders.id', 'orders.paid_amount_usd', 'orders.subscription_type', 'orders.subscription_origin_order_id',
            'orders.paypal_account_id', 'orders.paypal_association_source', 'orders.shopify_payload', 'orders.paid_at',
        ])->distinct()->orderBy('orders.paid_at')->orderBy('orders.id')->get();
        $orderIds = $orders->pluck('id')->map(fn ($id): int => (int) $id)->all();

        $paypalRefunds = $orderIds === [] ? collect() : $database->table('logistics_paypal_refunds')
            ->whereIn('logistics_order_id', $orderIds)->selectRaw('logistics_order_id, SUM(COALESCE(refund_amount_usd, CASE WHEN UPPER(currency_code) = \'USD\' THEN original_amount ELSE 0 END)) amount')
            ->groupBy('logistics_order_id')->pluck('amount', 'logistics_order_id');
        $paypalDisputes = $orderIds === [] ? collect() : $database->table('logistics_paypal_disputes')
            ->whereIn('logistics_order_id', $orderIds)->selectRaw('logistics_order_id, COUNT(DISTINCT id) count')
            ->groupBy('logistics_order_id')->pluck('count', 'logistics_order_id');
        $cardRows = $orderIds === [] ? collect() : $database->table('logistics_credit_card_refunds')
            ->whereIn('logistics_order_id', $orderIds)->get(['logistics_order_id', 'refund_amount_usd', 'refund_reason'])->groupBy('logistics_order_id');

        $empty = static fn (): array => ['order_count' => 0, 'received_amount' => 0.0, 'refund_amount' => 0.0, 'refund_rate' => 0.0, 'dispute_count' => 0, 'dispute_rate' => 0.0, 'realised_loss' => 0.0];
        $groups = [];
        foreach (['paypal', 'credit_card'] as $channel) foreach (['one_time', 'first_charge', 'first_renewal', 'later_renewal'] as $stage) $groups[$channel][$stage] = $empty();
        $seenRecurring = [];
        foreach ($orders as $order) {
            $payload = strtolower((string) $order->shopify_payload);
            $channel = $order->paypal_account_id !== null && $order->paypal_association_source === 'actual_transaction'
                ? 'paypal' : (str_contains($payload, 'creditcardby') ? 'credit_card' : null);
            if ($channel === null) continue;
            $type = trim((string) $order->subscription_type);
            $origin = trim((string) $order->subscription_origin_order_id);
            if ($origin === '' && ($type === '' || str_contains($type, '无订阅'))) $stage = 'one_time';
            elseif ($origin === '') $stage = 'first_charge';
            else {
                $seenRecurring[$channel][$origin] = ($seenRecurring[$channel][$origin] ?? 0) + 1;
                preg_match('/(\d+)/u', $type, $match);
                $number = isset($match[1]) ? (int) $match[1] : $seenRecurring[$channel][$origin];
                $stage = $number <= 1 ? 'first_renewal' : 'later_renewal';
            }
            $refundAmount = 0.0;
            $disputeCount = 0;
            if ($channel === 'paypal') {
                $refundAmount = (float) ($paypalRefunds[(int) $order->id] ?? 0);
                $disputeCount = (int) ($paypalDisputes[(int) $order->id] ?? 0);
            } else {
                foreach ($cardRows->get((int) $order->id, collect()) as $risk) {
                    $reason = LogisticsCreditCardRefundReason::normalize((string) $risk->refund_reason);
                    if (in_array($reason, LogisticsCreditCardRefundReason::CUSTOMER_SERVICE, true)) $refundAmount += (float) $risk->refund_amount_usd;
                    if (in_array($reason, LogisticsCreditCardRefundReason::DISPUTE, true)) $disputeCount++;
                }
            }
            $groups[$channel][$stage]['order_count']++;
            $groups[$channel][$stage]['received_amount'] += (float) $order->paid_amount_usd;
            $groups[$channel][$stage]['refund_amount'] += $refundAmount;
            $groups[$channel][$stage]['dispute_count'] += $disputeCount;
            $groups[$channel][$stage]['realised_loss'] += $refundAmount;
        }
        foreach ($groups as &$channelGroups) foreach ($channelGroups as &$group) {
            $group['received_amount'] = round($group['received_amount'], 4);
            $group['refund_amount'] = round($group['refund_amount'], 4);
            $group['realised_loss'] = round($group['realised_loss'], 4);
            $group['refund_rate'] = $group['received_amount'] > 0 ? round($group['refund_amount'] / $group['received_amount'] * 100, 2) : 0.0;
            $group['dispute_rate'] = $group['order_count'] > 0 ? round($group['dispute_count'] / $group['order_count'] * 100, 2) : 0.0;
        }
        unset($channelGroups, $group);
        return $groups;
    }
}
