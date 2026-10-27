<?php

namespace App\Portals\Payment;

use App\Portals\Logistics\LogisticsProductStatisticsService;
use Illuminate\Support\Facades\DB;

class PaymentProductRiskService
{
    public function __construct(private readonly LogisticsProductStatisticsService $statistics)
    {
    }

    /** @param array<string,mixed> $filters @return array<string,mixed> */
    public function dashboard(array $filters): array
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

        $data['products'] = collect($products)->map(function (array $product) use ($names): array {
            $encoded = substr((string) $product['id'], 8);
            $encoded = strtr($encoded, '-_', '+/').str_repeat('=', (4 - strlen($encoded) % 4) % 4);
            $key = base64_decode($encoded, true);
            $name = $key === false ? null : $names->get($key);
            $product['source_product_name'] = trim((string) ($name->product_title ?? ''));
            $product['tongtu_product_name'] = trim((string) ($name->tongtu_product_name ?? ''));
            return $product;
        })->all();

        return $data;
    }

    /** @param array<string,mixed> $filters @param array<string,mixed> $options @return array<string,mixed>|null */
    public function detail(array $filters, string $productId, array $options): ?array
    {
        return $this->statistics->detailPage($filters, $productId, $options);
    }
}
