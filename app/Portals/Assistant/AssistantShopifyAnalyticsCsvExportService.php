<?php

namespace App\Portals\Assistant;

use App\Models\AdminUser;
use App\Models\AssistantDomainStore;
use App\Models\AssistantShopifyStoreOrder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AssistantShopifyAnalyticsCsvExportService
{
    public function __construct(
        private readonly ShopifyAnalyticsReferenceRateService $referenceRates,
    ) {
    }

    /**
     * Export the same valid-order scope used by the Shopify analytics page.
     * Store grouping emits one summary row per visible store. Date grouping
     * emits a complete store-by-day grid so days without valid orders remain
     * visible as zero-valued rows.
     *
     * @param Builder<AssistantDomainStore> $stores
     * @param array{timezone:string, from_date:string, to_date:string} $filters
     */
    public function download(Builder $stores, array $filters, string $groupBy): StreamedResponse
    {
        $filename = __('assistant.shopify_analytics.export.analytics_file_name_'.$groupBy, [
            'from' => $filters['from_date'],
            'to' => $filters['to_date'],
            'timestamp' => now()->format('Ymd-His'),
        ]);

        return response()->streamDownload(function () use ($stores, $filters, $groupBy): void {
            set_time_limit(0);
            $output = fopen('php://output', 'wb');
            if ($output === false) {
                return;
            }

            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, $this->headers($groupBy));

            if ($groupBy === 'date') {
                $this->streamByDate($output, $stores, $filters);
            } else {
                $this->streamByStore($output, $stores, $filters);
            }

            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache',
        ]);
    }

    /**
     * @param resource $output
     * @param Builder<AssistantDomainStore> $stores
     * @param array{timezone:string, from_date:string, to_date:string} $filters
     */
    private function streamByStore($output, Builder $stores, array $filters): void
    {
        $this->exportStoresQuery($stores)->chunkById(100, function (Collection $storeChunk) use ($output, $filters): void {
            $metricsByStore = $this->metricRows(
                $storeChunk->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all(),
                $filters,
            )->keyBy('assistant_domain_store_id');

            foreach ($storeChunk as $store) {
                fputcsv($output, $this->row(
                    $store,
                    $metricsByStore->get($store->getKey()),
                    $filters,
                    null,
                ));
            }

            fflush($output);
        }, 'assistant_domain_stores.id', 'id');
    }

    /**
     * @param resource $output
     * @param Builder<AssistantDomainStore> $stores
     * @param array{timezone:string, from_date:string, to_date:string} $filters
     */
    private function streamByDate($output, Builder $stores, array $filters): void
    {
        $dateBuckets = $this->dateBuckets($filters);
        $reportDates = array_column($dateBuckets, 'date');
        $dateExpression = $this->reportDateExpression($dateBuckets);

        $this->exportStoresQuery($stores)->chunkById(80, function (Collection $storeChunk) use ($output, $filters, $dateExpression, $reportDates): void {
            $metricsByStoreAndDate = [];
            foreach ($this->metricRows(
                $storeChunk->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all(),
                $filters,
                $dateExpression,
            ) as $metric) {
                $metricsByStoreAndDate[(int) $metric->assistant_domain_store_id][(string) $metric->report_date] = $metric;
            }

            foreach ($storeChunk as $store) {
                $storeMetrics = $metricsByStoreAndDate[(int) $store->getKey()] ?? [];
                foreach ($reportDates as $reportDate) {
                    fputcsv($output, $this->row(
                        $store,
                        $storeMetrics[$reportDate] ?? null,
                        $filters,
                        $reportDate,
                    ));
                }
            }

            fflush($output);
        }, 'assistant_domain_stores.id', 'id');
    }

    /**
     * @param Builder<AssistantDomainStore> $stores
     * @return Builder<AssistantDomainStore>
     */
    private function exportStoresQuery(Builder $stores): Builder
    {
        $ownerTable = (new AdminUser())->getTable();

        return (clone $stores)
            ->leftJoin($ownerTable.' as store_owner', 'assistant_domain_stores.owner_user_id', '=', 'store_owner.id')
            ->select([
                'assistant_domain_stores.id',
                'assistant_domain_stores.shop_name',
                'assistant_domain_stores.domain',
                'assistant_domain_stores.team',
            ])
            ->selectRaw("COALESCE(NULLIF(store_owner.name, ''), NULLIF(store_owner.username, ''), '') as export_owner_name")
            ->orderBy('assistant_domain_stores.id');
    }

    /**
     * @param list<int> $storeIds
     * @param array{timezone:string, from_date:string, to_date:string} $filters
     * @return Collection<int, object>
     */
    private function metricRows(array $storeIds, array $filters, ?string $dateExpression = null): Collection
    {
        if ($storeIds === []) {
            return collect();
        }

        $connection = config('admin.database.connection') ?: config('database.default');
        $orderTable = (new AssistantShopifyStoreOrder())->getTable();
        [$fromUtc, $toUtc] = $this->reportingUtcRange($filters);
        $referenceSales = $this->referenceUsdAggregate(
            'shopify_order.original_total_price',
            'shopify_order.original_currency_code',
        );
        $referenceRefunds = $this->referenceUsdAggregate(
            'shopify_order.refunded_total_amount',
            'shopify_order.refunded_currency_code',
        );
        $referenceNetSales = $this->referenceUsdAggregate(
            'shopify_order.current_total_price',
            'shopify_order.current_currency_code',
        );

        $query = $this->baseMetricQuery($connection, $orderTable, $storeIds, $fromUtc, $toUtc)
            ->selectRaw('shopify_order.assistant_domain_store_id')
            ->selectRaw("GROUP_CONCAT(DISTINCT NULLIF(UPPER(shopify_order.original_currency_code), '') ORDER BY UPPER(shopify_order.original_currency_code) SEPARATOR ', ') as source_currencies")
            ->selectRaw('COALESCE(SUM(shopify_order.original_total_price), 0) as original_total_sales')
            ->selectRaw("COALESCE(SUM({$referenceSales}), 0) as total_sales_usd")
            ->selectRaw('COUNT(*) as orders_count')
            ->selectRaw("SUM(CASE WHEN UPPER(COALESCE(shopify_order.fulfillment_status, '')) = 'FULFILLED' THEN 1 ELSE 0 END) as fulfilled_orders_count")
            ->selectRaw("COALESCE(SUM({$referenceRefunds}), 0) as refund_amount_usd")
            ->selectRaw("COALESCE(SUM({$referenceNetSales}), 0) as net_sales_usd")
            ->selectRaw('COALESCE(SUM(CASE WHEN shopify_order.subscription_category = ? THEN 1 ELSE 0 END), 0) as regular_orders_count', [AssistantShopifyStoreOrder::SUBSCRIPTION_CATEGORY_REGULAR])
            ->selectRaw("COALESCE(SUM(CASE WHEN shopify_order.subscription_category = ? THEN {$referenceSales} ELSE 0 END), 0) as regular_sales_usd", [AssistantShopifyStoreOrder::SUBSCRIPTION_CATEGORY_REGULAR])
            ->selectRaw('COALESCE(SUM(CASE WHEN shopify_order.subscription_category = ? THEN 1 ELSE 0 END), 0) as subscribed_orders_count', [AssistantShopifyStoreOrder::SUBSCRIPTION_CATEGORY_SUBSCRIBED])
            ->selectRaw("COALESCE(SUM(CASE WHEN shopify_order.subscription_category = ? THEN {$referenceSales} ELSE 0 END), 0) as subscribed_sales_usd", [AssistantShopifyStoreOrder::SUBSCRIPTION_CATEGORY_SUBSCRIBED])
            ->selectRaw('COALESCE(SUM(CASE WHEN shopify_order.subscription_category = ? THEN 1 ELSE 0 END), 0) as recurring_orders_count', [AssistantShopifyStoreOrder::SUBSCRIPTION_CATEGORY_RECURRING])
            ->selectRaw("COALESCE(SUM(CASE WHEN shopify_order.subscription_category = ? THEN {$referenceSales} ELSE 0 END), 0) as recurring_sales_usd", [AssistantShopifyStoreOrder::SUBSCRIPTION_CATEGORY_RECURRING]);

        if ($dateExpression !== null) {
            $query
                ->selectRaw($dateExpression.' as report_date')
                ->groupBy('shopify_order.assistant_domain_store_id')
                ->groupByRaw($dateExpression)
                ->orderBy('shopify_order.assistant_domain_store_id')
                ->orderBy('report_date');
        } else {
            $query
                ->groupBy('shopify_order.assistant_domain_store_id')
                ->orderBy('shopify_order.assistant_domain_store_id');
        }

        return $query->get();
    }

    /**
     * @param list<int> $storeIds
     */
    private function baseMetricQuery(string $connection, string $orderTable, array $storeIds, CarbonImmutable $fromUtc, CarbonImmutable $toUtc): QueryBuilder
    {
        return \Illuminate\Support\Facades\DB::connection($connection)
            ->table($orderTable.' as shopify_order')
            ->whereIn('shopify_order.assistant_domain_store_id', $storeIds)
            ->where('shopify_order.shopify_created_at', '>=', $fromUtc->format('Y-m-d H:i:s'))
            ->where('shopify_order.shopify_created_at', '<', $toUtc->format('Y-m-d H:i:s'))
            ->where('shopify_order.is_test', false)
            ->whereIn('shopify_order.financial_status', AssistantShopifyStoreOrder::successfulFinancialStatuses());
    }

    /**
     * @param array{timezone:string, from_date:string, to_date:string} $filters
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function reportingUtcRange(array $filters): array
    {
        $fromUtc = CarbonImmutable::createFromFormat('!Y-m-d', $filters['from_date'], $filters['timezone'])
            ->startOfDay()
            ->setTimezone('UTC');
        $toUtc = CarbonImmutable::createFromFormat('!Y-m-d', $filters['to_date'], $filters['timezone'])
            ->addDay()
            ->startOfDay()
            ->setTimezone('UTC');

        return [$fromUtc, $toUtc];
    }

    /**
     * @param array{timezone:string, from_date:string, to_date:string} $filters
     * @return list<array{date:string, from:CarbonImmutable, to:CarbonImmutable}>
     */
    private function dateBuckets(array $filters): array
    {
        $cursor = CarbonImmutable::createFromFormat('!Y-m-d', $filters['from_date'], $filters['timezone'])->startOfDay();
        $last = CarbonImmutable::createFromFormat('!Y-m-d', $filters['to_date'], $filters['timezone'])->startOfDay();
        $buckets = [];

        while ($cursor->lessThanOrEqualTo($last)) {
            $buckets[] = [
                'date' => $cursor->toDateString(),
                'from' => $cursor->setTimezone('UTC'),
                'to' => $cursor->addDay()->setTimezone('UTC'),
            ];
            $cursor = $cursor->addDay();
        }

        return $buckets;
    }

    /**
     * Build a timezone-safe daily bucket expression without relying on MySQL
     * timezone tables. All values are generated from validated local dates.
     *
     * @param list<array{date:string, from:CarbonImmutable, to:CarbonImmutable}> $buckets
     */
    private function reportDateExpression(array $buckets): string
    {
        $clauses = [];
        foreach ($buckets as $bucket) {
            $clauses[] = "WHEN shopify_order.shopify_created_at >= '".$bucket['from']->format('Y-m-d H:i:s')
                ."' AND shopify_order.shopify_created_at < '".$bucket['to']->format('Y-m-d H:i:s')
                ."' THEN '".$bucket['date']."'";
        }

        return 'CASE '.implode(' ', $clauses).' ELSE NULL END';
    }

    /** @return list<string> */
    private function headers(string $groupBy): array
    {
        $keys = $groupBy === 'date'
            ? ['report_date']
            : ['from_date', 'to_date'];

        $keys = array_merge($keys, [
            'store',
            'domain',
            'team',
            'owner',
            'report_timezone',
            'source_currencies',
            'orders_count',
            'total_sales_usd',
            'original_total_sales',
            'fulfilled_orders_count',
            'refund_amount_usd',
            'net_sales_usd',
            'average_order_value_usd',
            'regular_orders_count',
            'regular_sales_usd',
            'subscribed_orders_count',
            'subscribed_sales_usd',
            'recurring_orders_count',
            'recurring_sales_usd',
        ]);

        return array_map(
            static fn (string $key): string => __('assistant.shopify_analytics.export.columns.'.$key),
            $keys,
        );
    }

    /**
     * @param AssistantDomainStore $store
     * @param object|null $metric
     * @param array{timezone:string, from_date:string, to_date:string} $filters
     * @return list<string>
     */
    private function row(AssistantDomainStore $store, ?object $metric, array $filters, ?string $reportDate): array
    {
        $prefix = $reportDate === null
            ? [$filters['from_date'], $filters['to_date']]
            : [$reportDate];
        $ordersCount = (int) ($metric?->orders_count ?? 0);
        $totalSales = (float) ($metric?->total_sales_usd ?? 0);

        return array_merge($prefix, [
            $this->text($store->shop_name),
            $this->text($store->domain),
            $this->text(AssistantDomainStore::displayMultiValue($store->team)),
            $this->text((string) ($store->export_owner_name ?? '')),
            $this->text($filters['timezone']),
            $this->text((string) ($metric?->source_currencies ?? '')),
            (string) $ordersCount,
            $this->decimal($totalSales),
            $this->decimal($metric?->original_total_sales),
            (string) ((int) ($metric?->fulfilled_orders_count ?? 0)),
            $this->decimal($metric?->refund_amount_usd),
            $this->decimal($metric?->net_sales_usd),
            $this->decimal($ordersCount > 0 ? $totalSales / $ordersCount : 0),
            (string) ((int) ($metric?->regular_orders_count ?? 0)),
            $this->decimal($metric?->regular_sales_usd),
            (string) ((int) ($metric?->subscribed_orders_count ?? 0)),
            $this->decimal($metric?->subscribed_sales_usd),
            (string) ((int) ($metric?->recurring_orders_count ?? 0)),
            $this->decimal($metric?->recurring_sales_usd),
        ]);
    }

    private function referenceUsdAggregate(string $amountExpression, string $currencyExpression): string
    {
        $clauses = [];
        foreach ($this->referenceRates->rates() as $currency => $rate) {
            $clauses[] = "WHEN '{$currency}' THEN ({$amountExpression}) * ".number_format($rate, 6, '.', '');
        }

        return "CASE UPPER(COALESCE({$currencyExpression}, '')) ".implode(' ', $clauses).' ELSE NULL END';
    }

    private function decimal(mixed $value): string
    {
        return number_format((float) ($value ?? 0), 2, '.', '');
    }

    private function text(mixed $value): string
    {
        $value = is_scalar($value) ? (string) $value : '';

        return preg_match('/^[=+\-@]/', $value) === 1 ? "'".$value : $value;
    }
}
