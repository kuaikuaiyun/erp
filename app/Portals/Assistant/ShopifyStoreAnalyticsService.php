<?php

namespace App\Portals\Assistant;

use App\Models\AdminUser;
use App\Models\AssistantDomainStore;
use App\Models\AssistantShopifyAnalyticsBackfill;
use App\Models\AssistantShopifyStoreMetric;
use App\Models\AssistantShopifyStoreMetricHistory;
use App\Models\AssistantShopifyStoreMetricHour;
use App\Models\AssistantShopifyStoreMetricPeriod;
use App\Portals\Logistics\ShopifyOrderSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class ShopifyStoreAnalyticsService
{
    public function __construct(private readonly ShopifyOrderSyncService $shopify)
    {
    }

    /** Fetch the current business-day metrics in the Shopify store's own timezone. */
    public function refresh(AssistantDomainStore $store, ?AdminUser $actor = null): AssistantShopifyStoreMetric
    {
        $metric = AssistantShopifyStoreMetric::query()->firstOrNew([
            'assistant_domain_store_id' => $store->getKey(),
        ]);

        if (blank($store->shopify_client_id) || blank($store->shopify_client_secret)) {
            return $this->markFailure(
                $metric,
                AssistantShopifyStoreMetric::STATUS_NOT_CONFIGURED,
                'credentials_missing',
            );
        }

        try {
            $shop = $this->shopInfo($store, $actor);
            $reportTimezone = $shop['timezone'];
            $reportDate = now($reportTimezone)->toDateString();
            $period = $this->refreshPeriod($store, $reportTimezone, $reportDate, $reportDate, $actor, $shop);

            if ($period->sync_status !== AssistantShopifyStoreMetric::STATUS_SUCCESS) {
                return $this->markFailure($metric, $period->sync_status, $period->sync_message ?: 'unknown');
            }

            // Store the current Shopify-local business day on a UTC hour axis.
            // The UTC axis is only for later screen-side timezone conversion;
            // it never changes this store's Shopify-local synchronization day.
            $localDayStartUtc = CarbonImmutable::now($shop['timezone'])
                ->startOfDay()
                ->setTimezone('UTC')
                ->startOfHour();
            $nowUtc = CarbonImmutable::now('UTC')->startOfHour();
            $this->refreshUtcHours($store, $localDayStartUtc, $nowUtc, $actor, $shop);

            $payload = [
                'shop_timezone' => $period->shop_timezone,
                'report_date' => $reportDate,
                'currency_code' => $period->currency_code,
                'original_currency_code' => $period->original_currency_code,
                'total_sales' => $period->total_sales,
                'original_total_sales' => $period->original_total_sales,
                'orders_count' => $period->orders_count,
                'returning_customer_rate' => $period->returning_customer_rate,
                'fulfilled_orders_count' => $period->fulfilled_orders_count,
                'refund_amount' => $period->refund_amount,
                'net_sales' => $period->net_sales,
                'average_order_value' => $period->average_order_value,
                'sync_status' => $period->sync_status,
                'sync_message' => $period->sync_message,
                'synced_at' => $period->synced_at,
            ];

            DB::connection($metric->getConnectionName())->transaction(function () use ($metric, $store, $payload, $reportTimezone, $shop): void {
                $metric->fill($payload)->save();

                // A store can be refreshed repeatedly within its own local
                // business day. Keep one history row for that day and replace
                // it with the most recently fetched successful metrics.
                AssistantShopifyStoreMetricHistory::query()->updateOrCreate(
                    [
                        'assistant_domain_store_id' => $store->getKey(),
                        'report_timezone' => $reportTimezone,
                        'report_date' => $payload['report_date'],
                    ],
                    Arr::except($payload, ['report_date', 'sync_message']),
                );

                $this->startInitialBackfill($store, $shop['timezone']);
            });

            return $metric;
        } catch (\Throwable $exception) {
            report($exception);

            $failure = $this->failure($exception);

            return $this->markFailure($metric, $failure['status'], $failure['reason']);
        }
    }

    /**
     * Fetch and persist one completed store-local business day without
     * changing the current-day snapshot. The scheduler uses this for later
     * day finalisation, while initial history uses refreshHistoricalRange().
     */
    public function refreshHistoricalDate(
        AssistantDomainStore $store,
        string $reportTimezone,
        string $reportDate,
        ?AdminUser $actor = null,
    ): AssistantShopifyStoreMetricHistory {
        $reportTimezone = $this->validTimezone($reportTimezone);
        $reportDate = $this->validReportDate($reportDate, $reportTimezone);

        if (blank($store->shopify_client_id) || blank($store->shopify_client_secret)) {
            throw new RuntimeException('Shopify analytics credentials are not configured.');
        }

        $shop = $this->shopInfo($store, $actor);
        $sales = $this->shopifyQl($store, $actor, $this->salesQuery($reportTimezone, $reportDate));
        $originalSales = $shop['currency'] === 'USD'
            ? $sales
            : $this->shopifyQl($store, $actor, $this->salesQuery($reportTimezone, $reportDate, $shop['currency'], true));
        $fulfillments = $this->shopifyQl($store, $actor, $this->fulfillmentsQuery($reportTimezone, $reportDate));
        $payload = Arr::except(
            $this->successfulPayload($shop['timezone'], $reportDate, $sales, $fulfillments, $originalSales, $shop['currency']),
            ['report_date', 'sync_message'],
        );

        return AssistantShopifyStoreMetricHistory::query()->updateOrCreate(
            [
                'assistant_domain_store_id' => $store->getKey(),
                'report_timezone' => $reportTimezone,
                'report_date' => $reportDate,
            ],
            $payload,
        );
    }

    /**
     * Fetch a completed store-local date range in two ShopifyQL requests:
     * one sales series and one fulfillment series. Each returned day is
     * independently upserted, so rerunning this operation is safe.
     */
    public function refreshHistoricalRange(
        AssistantDomainStore $store,
        string $reportTimezone,
        string $fromDate,
        string $toDate,
        ?AdminUser $actor = null,
    ): int {
        $reportTimezone = $this->validTimezone($reportTimezone);
        $fromDate = $this->validReportDate($fromDate, $reportTimezone);
        $toDate = $this->validReportDate($toDate, $reportTimezone);

        if ($fromDate > $toDate) {
            throw new RuntimeException('The historical date range is invalid.');
        }

        if (blank($store->shopify_client_id) || blank($store->shopify_client_secret)) {
            throw new RuntimeException('Shopify analytics credentials are not configured.');
        }

        $shop = $this->shopInfo($store, $actor);
        $salesByDate = $this->rowsByReportDate(
            $this->shopifyQlRows($store, $actor, $this->salesTimeSeriesQuery($reportTimezone, $fromDate, $toDate)),
            $reportTimezone,
            $fromDate,
            $toDate,
        );
        $originalSalesByDate = $shop['currency'] === 'USD'
            ? $salesByDate
            : $this->rowsByReportDate(
                $this->shopifyQlRows($store, $actor, $this->salesTimeSeriesQuery($reportTimezone, $fromDate, $toDate, $shop['currency'], true)),
                $reportTimezone,
                $fromDate,
                $toDate,
            );
        $fulfillmentsByDate = $this->rowsByReportDate(
            $this->shopifyQlRows($store, $actor, $this->fulfillmentsTimeSeriesQuery($reportTimezone, $fromDate, $toDate)),
            $reportTimezone,
            $fromDate,
            $toDate,
        );

        $count = 0;
        foreach ($this->historicalDates($reportTimezone, $fromDate, $toDate) as $reportDate) {
            $payload = Arr::except(
                $this->successfulPayload(
                    $shop['timezone'],
                    $reportDate,
                    $salesByDate[$reportDate] ?? [],
                    $fulfillmentsByDate[$reportDate] ?? [],
                    $originalSalesByDate[$reportDate] ?? [],
                    $shop['currency'],
                ),
                ['report_date', 'sync_message'],
            );

            AssistantShopifyStoreMetricHistory::query()->updateOrCreate(
                [
                    'assistant_domain_store_id' => $store->getKey(),
                    'report_timezone' => $reportTimezone,
                    'report_date' => $reportDate,
                ],
                $payload,
            );

            $count++;
        }

        return $count;
    }

    public function refreshPeriod(
        AssistantDomainStore $store,
        string $reportTimezone,
        string $fromDate,
        string $toDate,
        ?AdminUser $actor = null,
        ?array $shopInfo = null,
    ): AssistantShopifyStoreMetricPeriod {
        $reportTimezone = $this->validTimezone($reportTimezone);
        $fromDate = $this->validReportDate($fromDate, $reportTimezone);
        $toDate = $this->validReportDate($toDate, $reportTimezone);
        if ($fromDate > $toDate) {
            throw new RuntimeException('The analytics date range is invalid.');
        }

        $period = AssistantShopifyStoreMetricPeriod::query()->firstOrNew([
            'assistant_domain_store_id' => $store->getKey(),
            'report_timezone' => $reportTimezone,
            'from_date' => $fromDate,
            'to_date' => $toDate,
        ]);

        try {
            if (blank($store->shopify_client_id) || blank($store->shopify_client_secret)) {
                throw new RuntimeException('Shopify analytics credentials are not configured.');
            }

            $shop = $shopInfo ?? $this->shopInfo($store, $actor);
            $sales = $this->shopifyQl($store, $actor, $this->salesRangeQuery($reportTimezone, $fromDate, $toDate));
            $originalSales = $shop['currency'] === 'USD'
                ? $sales
                : $this->shopifyQl($store, $actor, $this->salesRangeQuery($reportTimezone, $fromDate, $toDate, $shop['currency'], true));
            $fulfillments = $this->shopifyQl($store, $actor, $this->fulfillmentsRangeQuery($reportTimezone, $fromDate, $toDate));
            $payload = $this->successfulPayload(
                $shop['timezone'],
                $toDate,
                $sales,
                $fulfillments,
                $originalSales,
                $shop['currency'],
            );
            $period->fill(Arr::except($payload, ['report_date']))->save();

            // Sync tasks always initialise their own Shopify-local history.
            // The list filter timezone never reaches this Shopify request.
            $this->startInitialBackfill($store, $shop['timezone']);

            return $period;
        } catch (\Throwable $exception) {
            report($exception);
            $failure = $this->failure($exception);
            $period->fill([
                'shop_timezone' => $period->shop_timezone,
                'currency_code' => 'USD',
                'sync_status' => $failure['status'],
                'sync_message' => $failure['reason'],
                'synced_at' => now(),
            ])->save();

            return $period;
        }
    }

    /**
     * Fetch ShopifyQL hourly values in UTC and store them as the canonical
     * source for every later reporting timezone. The requested bounds are
     * inclusive UTC hour starts.
     */
    public function refreshUtcHours(
        AssistantDomainStore $store,
        CarbonImmutable $fromUtc,
        CarbonImmutable $toUtc,
        ?AdminUser $actor = null,
        ?array $shopInfo = null,
    ): int {
        $fromUtc = $fromUtc->setTimezone('UTC')->startOfHour();
        $toUtc = $toUtc->setTimezone('UTC')->startOfHour();

        if ($fromUtc > $toUtc) {
            throw new RuntimeException('The UTC hourly history range is invalid.');
        }

        if (blank($store->shopify_client_id) || blank($store->shopify_client_secret)) {
            throw new RuntimeException('Shopify analytics credentials are not configured.');
        }

        $shop = $shopInfo ?? $this->shopInfo($store, $actor);
        $salesByHour = $this->rowsByUtcHour(
            $this->shopifyQlRows($store, $actor, $this->salesHourlyTimeSeriesQuery($fromUtc, $toUtc)),
            $fromUtc,
            $toUtc,
        );
        $originalSalesByHour = $shop['currency'] === 'USD'
            ? $salesByHour
            : $this->rowsByUtcHour(
                $this->shopifyQlRows($store, $actor, $this->salesHourlyTimeSeriesQuery($fromUtc, $toUtc, $shop['currency'], true)),
                $fromUtc,
                $toUtc,
            );
        $fulfillmentsByHour = $this->rowsByUtcHour(
            $this->shopifyQlRows($store, $actor, $this->fulfillmentsHourlyTimeSeriesQuery($fromUtc, $toUtc)),
            $fromUtc,
            $toUtc,
        );

        $syncedAt = now();
        $rows = [];
        foreach ($this->utcHours($fromUtc, $toUtc) as $hour) {
            $hourKey = $hour->format('Y-m-d H:00:00');
            $sales = $salesByHour[$hourKey] ?? [];
            $originalSales = $originalSalesByHour[$hourKey] ?? [];
            $fulfillments = $fulfillmentsByHour[$hourKey] ?? [];

            $rows[] = [
                'assistant_domain_store_id' => $store->getKey(),
                'report_hour_utc' => $hourKey,
                'shop_timezone' => $shop['timezone'],
                'currency_code' => 'USD',
                'original_currency_code' => $shop['currency'],
                'total_sales' => $this->decimal($sales, 'total_sales'),
                'original_total_sales' => $this->decimal($originalSales, 'total_sales'),
                'orders_count' => $this->integer($sales, 'orders'),
                'returning_customer_rate' => $this->decimal($sales, 'returning_customer_rate'),
                'fulfilled_orders_count' => $this->integer($fulfillments, 'orders_fulfilled'),
                'refund_amount' => $this->decimal($sales, 'returns'),
                'net_sales' => $this->decimal($sales, 'net_sales'),
                'average_order_value' => $this->decimal($sales, 'average_order_value'),
                'synced_at' => $syncedAt,
                'created_at' => $syncedAt,
                'updated_at' => $syncedAt,
            ];
        }

        if ($rows === []) {
            return 0;
        }

        AssistantShopifyStoreMetricHour::query()->upsert(
            $rows,
            ['assistant_domain_store_id', 'report_hour_utc'],
            [
                'shop_timezone',
                'currency_code',
                'original_currency_code',
                'total_sales',
                'original_total_sales',
                'orders_count',
                'returning_customer_rate',
                'fulfilled_orders_count',
                'refund_amount',
                'net_sales',
                'average_order_value',
                'synced_at',
                'updated_at',
            ],
        );

        return count($rows);
    }

    /** @param array<string, mixed> $sales @param array<string, mixed> $fulfillments @return array<string, mixed> */
    private function successfulPayload(
        string $shopTimezone,
        string $reportDate,
        array $sales,
        array $fulfillments,
        array $originalSales,
        string $originalCurrency,
    ): array
    {
        return [
            'shop_timezone' => $shopTimezone,
            'report_date' => $reportDate,
            'currency_code' => 'USD',
            'original_currency_code' => $originalCurrency,
            'total_sales' => $this->decimal($sales, 'total_sales'),
            'original_total_sales' => $this->decimal($originalSales, 'total_sales'),
            'orders_count' => $this->integer($sales, 'orders'),
            'returning_customer_rate' => $this->decimal($sales, 'returning_customer_rate'),
            'fulfilled_orders_count' => $this->integer($fulfillments, 'orders_fulfilled'),
            'refund_amount' => $this->decimal($sales, 'returns'),
            'net_sales' => $this->decimal($sales, 'net_sales'),
            'average_order_value' => $this->decimal($sales, 'average_order_value'),
            'sync_status' => AssistantShopifyStoreMetric::STATUS_SUCCESS,
            'sync_message' => null,
            'synced_at' => now(),
        ];
    }

    /** Create the initial 90-day plan using the Shopify store's own local calendar. */
    private function startInitialBackfill(AssistantDomainStore $store, string $shopTimezone): void
    {
        $shopTimezone = $this->validTimezone($shopTimezone);
        $localToday = CarbonImmutable::now($shopTimezone)->startOfDay();
        $fromDate = $localToday->subDays(89);
        $toDate = $localToday;

        $task = AssistantShopifyAnalyticsBackfill::query()->firstOrNew(
            ['assistant_domain_store_id' => $store->getKey()],
        );

        if (!$task->exists || $task->shop_timezone !== $shopTimezone) {
            $task->fill([
                'shop_timezone' => $shopTimezone,
                'from_date' => $fromDate->toDateString(),
                'to_date' => $toDate->toDateString(),
                'next_date' => $toDate->toDateString(),
                'status' => AssistantShopifyAnalyticsBackfill::STATUS_PENDING,
                'attempts' => 0,
                'next_attempt_at' => null,
                'last_error' => null,
                'last_processed_at' => null,
                'last_finalized_date' => null,
                'completed_at' => null,
            ])->save();
        }
    }

    /** @return array{timezone: string, currency: string} */
    private function shopInfo(AssistantDomainStore $store, ?AdminUser $actor): array
    {
        $response = $this->shopify->graphqlForStore($store, $actor, <<<'GQL'
            query AssistantShopifyAnalyticsShopInfo {
              shop {
                ianaTimezone
                currencyCode
              }
            }
            GQL);

        return [
            'timezone' => $this->validTimezone((string) Arr::get($response, 'data.shop.ianaTimezone')),
            'currency' => $this->validCurrency((string) Arr::get($response, 'data.shop.currencyCode')),
        ];
    }

    /** @return array<string, mixed> */
    private function shopifyQl(AssistantDomainStore $store, ?AdminUser $actor, string $shopifyQl): array
    {
        return $this->shopifyQlRows($store, $actor, $shopifyQl)[0] ?? [];
    }

    /** @return array<int, array<string, mixed>> */
    private function shopifyQlRows(AssistantDomainStore $store, ?AdminUser $actor, string $shopifyQl): array
    {
        $response = $this->shopify->graphqlForStore($store, $actor, <<<'GQL'
            query AssistantShopifyAnalytics($query: String!) {
              shopifyqlQuery(query: $query) {
                tableData {
                  rows
                }
                parseErrors
              }
            }
            GQL, ['query' => $shopifyQl]);

        $errors = Arr::get($response, 'data.shopifyqlQuery.parseErrors', []);
        if (is_array($errors) && $errors !== []) {
            throw new RuntimeException('ShopifyQL query could not be parsed.');
        }

        $rows = Arr::get($response, 'data.shopifyqlQuery.tableData.rows', []);
        if (!is_array($rows) || $rows === []) {
            return [];
        }

        $validRows = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new RuntimeException('ShopifyQL returned an invalid table row.');
            }

            $validRows[] = $row;
        }

        return $validRows;
    }

    private function salesQuery(string $timezone, ?string $reportDate = null, string $currency = 'USD', bool $onlyTotalSales = false): string
    {
        $fields = $onlyTotalSales
            ? 'total_sales'
            : 'total_sales, orders, returning_customer_rate, returns, net_sales, average_order_value';

        return "FROM sales SHOW {$fields}\n"
            ."WITH CURRENCY '{$currency}', TIMEZONE '{$timezone}'\n"
            .$this->dateClause($reportDate);
    }

    private function salesRangeQuery(string $timezone, string $fromDate, string $toDate, string $currency = 'USD', bool $onlyTotalSales = false): string
    {
        $fields = $onlyTotalSales
            ? 'total_sales'
            : 'total_sales, orders, returning_customer_rate, returns, net_sales, average_order_value';

        return "FROM sales SHOW {$fields}\n"
            ."WITH CURRENCY '{$currency}', TIMEZONE '{$timezone}'\n"
            ."SINCE {$fromDate} UNTIL {$toDate}";
    }

    private function fulfillmentsQuery(string $timezone, ?string $reportDate = null): string
    {
        return "FROM fulfillments SHOW orders_fulfilled\n"
            ."WITH TIMEZONE '{$timezone}'\n"
            .$this->dateClause($reportDate);
    }

    private function fulfillmentsRangeQuery(string $timezone, string $fromDate, string $toDate): string
    {
        return "FROM fulfillments SHOW orders_fulfilled\n"
            ."WITH TIMEZONE '{$timezone}'\n"
            ."SINCE {$fromDate} UNTIL {$toDate}";
    }

    private function salesTimeSeriesQuery(
        string $timezone,
        string $fromDate,
        string $toDate,
        string $currency = 'USD',
        bool $onlyTotalSales = false,
    ): string
    {
        $fields = $onlyTotalSales
            ? 'total_sales'
            : 'total_sales, orders, returning_customer_rate, returns, net_sales, average_order_value';

        return "FROM sales SHOW {$fields}\n"
            ."WITH CURRENCY '{$currency}', TIMEZONE '{$timezone}'\n"
            ."TIMESERIES day\n"
            ."SINCE {$fromDate} UNTIL {$toDate}\n"
            .'ORDER BY day ASC';
    }

    private function fulfillmentsTimeSeriesQuery(string $timezone, string $fromDate, string $toDate): string
    {
        return "FROM fulfillments SHOW orders_fulfilled\n"
            ."WITH TIMEZONE '{$timezone}'\n"
            ."TIMESERIES day\n"
            ."SINCE {$fromDate} UNTIL {$toDate}\n"
            .'ORDER BY day ASC';
    }

    private function salesHourlyTimeSeriesQuery(
        CarbonImmutable $fromUtc,
        CarbonImmutable $toUtc,
        string $currency = 'USD',
        bool $onlyTotalSales = false,
    ): string {
        $fields = $onlyTotalSales
            ? 'total_sales'
            : 'total_sales, orders, returning_customer_rate, returns, net_sales, average_order_value';

        return "FROM sales SHOW {$fields}\n"
            ."WITH CURRENCY '{$currency}', TIMEZONE 'UTC'\n"
            ."TIMESERIES hour\n"
            .'SINCE '.$fromUtc->format('Y-m-d\\TH:i').' UNTIL '.$toUtc->addHour()->format('Y-m-d\\TH:i')."\n"
            .'ORDER BY hour ASC';
    }

    private function fulfillmentsHourlyTimeSeriesQuery(CarbonImmutable $fromUtc, CarbonImmutable $toUtc): string
    {
        return "FROM fulfillments SHOW orders_fulfilled\n"
            ."WITH TIMEZONE 'UTC'\n"
            ."TIMESERIES hour\n"
            .'SINCE '.$fromUtc->format('Y-m-d\\TH:i').' UNTIL '.$toUtc->addHour()->format('Y-m-d\\TH:i')."\n"
            .'ORDER BY hour ASC';
    }

    private function dateClause(?string $reportDate): string
    {
        if ($reportDate === null) {
            return 'DURING today';
        }

        return "SINCE {$reportDate} UNTIL {$reportDate}";
    }

    private function validTimezone(string $timezone): string
    {
        if ($timezone === '' || !in_array($timezone, timezone_identifiers_list(), true)) {
            throw new RuntimeException('Shopify returned an invalid IANA timezone.');
        }

        return $timezone;
    }

    private function validCurrency(string $currency): string
    {
        $currency = strtoupper(trim($currency));

        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new RuntimeException('Shopify returned an invalid currency code.');
        }

        return $currency;
    }

    private function validReportDate(string $reportDate, string $timezone): string
    {
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $reportDate, $timezone);
        $errors = CarbonImmutable::getLastErrors();

        if (!$date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new RuntimeException('The requested history date is invalid.');
        }

        return $date->toDateString();
    }

    /**
     * ShopifyQL returns day as an ISO date (or timestamp) when TIMESERIES day
     * is used. Ignore unexpected rows rather than attributing metrics to a
     * wrong business day.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, array<string, mixed>>
     */
    private function rowsByReportDate(array $rows, string $timezone, string $fromDate, string $toDate): array
    {
        $result = [];

        foreach ($rows as $row) {
            $day = $row['day'] ?? null;
            if (!is_scalar($day)) {
                continue;
            }

            $candidate = substr(trim((string) $day), 0, 10);
            try {
                $reportDate = $this->validReportDate($candidate, $timezone);
            } catch (RuntimeException) {
                continue;
            }

            if ($reportDate < $fromDate || $reportDate > $toDate) {
                continue;
            }

            $result[$reportDate] = $row;
        }

        return $result;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, array<string, mixed>>
     */
    private function rowsByUtcHour(array $rows, CarbonImmutable $fromUtc, CarbonImmutable $toUtc): array
    {
        $result = [];

        foreach ($rows as $row) {
            $rawHour = $row['hour'] ?? null;
            if (!is_scalar($rawHour)) {
                continue;
            }

            try {
                $hour = CarbonImmutable::parse((string) $rawHour, 'UTC')->setTimezone('UTC')->startOfHour();
            } catch (\Throwable) {
                continue;
            }

            if ($hour < $fromUtc || $hour > $toUtc) {
                continue;
            }

            $result[$hour->format('Y-m-d H:00:00')] = $row;
        }

        return $result;
    }

    /** @return array<int, string> */
    private function historicalDates(string $timezone, string $fromDate, string $toDate): array
    {
        $dates = [];
        $cursor = CarbonImmutable::createFromFormat('!Y-m-d', $fromDate, $timezone);
        $until = CarbonImmutable::createFromFormat('!Y-m-d', $toDate, $timezone);

        while ($cursor <= $until) {
            $dates[] = $cursor->toDateString();
            $cursor = $cursor->addDay();
        }

        return $dates;
    }

    /** @return array<int, CarbonImmutable> */
    private function utcHours(CarbonImmutable $fromUtc, CarbonImmutable $toUtc): array
    {
        $hours = [];
        $hour = $fromUtc;

        while ($hour <= $toUtc) {
            $hours[] = $hour;
            $hour = $hour->addHour();
        }

        return $hours;
    }

    /** @param array<string, mixed> $row */
    private function decimal(array $row, string $field): float
    {
        $value = $row[$field] ?? 0;

        return is_numeric($value) ? (float) $value : 0.0;
    }

    /** @param array<string, mixed> $row */
    private function integer(array $row, string $field): int
    {
        $value = $row[$field] ?? 0;

        return is_numeric($value) ? (int) round((float) $value) : 0;
    }

    private function markFailure(
        AssistantShopifyStoreMetric $metric,
        string $status,
        string $reason,
    ): AssistantShopifyStoreMetric
    {
        $metric->fill([
            'currency_code' => 'USD',
            'sync_status' => $status,
            'sync_message' => $reason,
            'synced_at' => now(),
        ])->save();

        return $metric;
    }

    /** @return array{status: string, reason: string} */
    private function failure(\Throwable $exception): array
    {
        $message = Str::lower($exception->getMessage());
        $permissionMarkers = ['read_reports', 'permission', 'access denied', 'not authorized', 'forbidden'];

        if (Str::contains($message, $permissionMarkers)) {
            return [
                'status' => AssistantShopifyStoreMetric::STATUS_PERMISSION_DENIED,
                'reason' => 'reports_permission_denied',
            ];
        }

        if (Str::contains($message, ['invalid_client', 'invalid client', 'client secret', 'access token', 'oauth'])) {
            return [
                'status' => AssistantShopifyStoreMetric::STATUS_FAILED,
                'reason' => 'token_failed',
            ];
        }

        if (Str::contains($message, ['connection', 'timed out', 'timeout', 'cURL', 'could not resolve', 'network'])) {
            return [
                'status' => AssistantShopifyStoreMetric::STATUS_FAILED,
                'reason' => 'connection_failed',
            ];
        }

        if (Str::contains($message, ['shopifyql', 'parse error', 'parseerrors'])) {
            return [
                'status' => AssistantShopifyStoreMetric::STATUS_FAILED,
                'reason' => 'report_query_failed',
            ];
        }

        return [
            'status' => AssistantShopifyStoreMetric::STATUS_FAILED,
            'reason' => 'unknown',
        ];
    }
}
