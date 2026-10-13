<?php

namespace App\Portals\Assistant;

use App\Models\AdminUser;
use App\Models\AssistantDomainStore;
use App\Models\AssistantShopifyAnalyticsBackfill;
use App\Models\AssistantShopifyStoreMetric;
use App\Models\AssistantShopifyStoreMetricHistory;
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

    /**
     * Fetch the current local business-day metrics for one store. ShopifyQL
     * performs the currency conversion and timezone-boundary calculation; this
     * application stores Shopify's returned result as the latest snapshot and
     * one updatable daily history snapshot per store.
     */
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
            $timezone = $this->shopTimezone($store, $actor);
            $reportDate = now($timezone)->toDateString();
            $sales = $this->shopifyQl($store, $actor, $this->salesQuery($timezone));
            $fulfillments = $this->shopifyQl($store, $actor, $this->fulfillmentsQuery($timezone));
            $payload = $this->successfulPayload($timezone, $reportDate, $sales, $fulfillments);

            DB::connection($metric->getConnectionName())->transaction(function () use ($metric, $store, $payload): void {
                $metric->fill($payload)->save();

                // A store can be refreshed repeatedly within its own local
                // business day. Keep one history row for that day and replace
                // it with the most recently fetched successful metrics.
                AssistantShopifyStoreMetricHistory::query()->updateOrCreate(
                    [
                        'assistant_domain_store_id' => $store->getKey(),
                        'report_date' => $payload['report_date'],
                    ],
                    Arr::except($payload, ['report_date', 'sync_message']),
                );

                $this->startInitialBackfill($store, $payload['shop_timezone'], $payload['report_date']);
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
        string $timezone,
        string $reportDate,
        ?AdminUser $actor = null,
    ): AssistantShopifyStoreMetricHistory {
        $timezone = $this->validTimezone($timezone);
        $reportDate = $this->validReportDate($reportDate, $timezone);

        if (blank($store->shopify_client_id) || blank($store->shopify_client_secret)) {
            throw new RuntimeException('Shopify analytics credentials are not configured.');
        }

        $sales = $this->shopifyQl($store, $actor, $this->salesQuery($timezone, $reportDate));
        $fulfillments = $this->shopifyQl($store, $actor, $this->fulfillmentsQuery($timezone, $reportDate));
        $payload = Arr::except(
            $this->successfulPayload($timezone, $reportDate, $sales, $fulfillments),
            ['report_date', 'sync_message'],
        );

        return AssistantShopifyStoreMetricHistory::query()->updateOrCreate(
            [
                'assistant_domain_store_id' => $store->getKey(),
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
        string $timezone,
        string $fromDate,
        string $toDate,
        ?AdminUser $actor = null,
    ): int {
        $timezone = $this->validTimezone($timezone);
        $fromDate = $this->validReportDate($fromDate, $timezone);
        $toDate = $this->validReportDate($toDate, $timezone);

        if ($fromDate > $toDate) {
            throw new RuntimeException('The historical date range is invalid.');
        }

        if (blank($store->shopify_client_id) || blank($store->shopify_client_secret)) {
            throw new RuntimeException('Shopify analytics credentials are not configured.');
        }

        $salesByDate = $this->rowsByReportDate(
            $this->shopifyQlRows($store, $actor, $this->salesTimeSeriesQuery($timezone, $fromDate, $toDate)),
            $timezone,
            $fromDate,
            $toDate,
        );
        $fulfillmentsByDate = $this->rowsByReportDate(
            $this->shopifyQlRows($store, $actor, $this->fulfillmentsTimeSeriesQuery($timezone, $fromDate, $toDate)),
            $timezone,
            $fromDate,
            $toDate,
        );

        $count = 0;
        foreach ($this->historicalDates($timezone, $fromDate, $toDate) as $reportDate) {
            $payload = Arr::except(
                $this->successfulPayload(
                    $timezone,
                    $reportDate,
                    $salesByDate[$reportDate] ?? [],
                    $fulfillmentsByDate[$reportDate] ?? [],
                ),
                ['report_date', 'sync_message'],
            );

            AssistantShopifyStoreMetricHistory::query()->updateOrCreate(
                [
                    'assistant_domain_store_id' => $store->getKey(),
                    'report_date' => $reportDate,
                ],
                $payload,
            );

            $count++;
        }

        return $count;
    }

    /** @param array<string, mixed> $sales @param array<string, mixed> $fulfillments @return array<string, mixed> */
    private function successfulPayload(string $timezone, string $reportDate, array $sales, array $fulfillments): array
    {
        return [
            'shop_timezone' => $timezone,
            'report_date' => $reportDate,
            'currency_code' => 'USD',
            'total_sales' => $this->decimal($sales, 'total_sales'),
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

    /**
     * Create one resumable initial history plan after the first successful
     * current-day sync. It intentionally does not reset an existing plan.
     */
    private function startInitialBackfill(AssistantDomainStore $store, string $timezone, string $reportDate): void
    {
        $localToday = CarbonImmutable::createFromFormat('Y-m-d', $reportDate, $timezone)->startOfDay();
        $toDate = $localToday->subDay();
        $fromDate = $toDate->subDays(59);

        AssistantShopifyAnalyticsBackfill::query()->firstOrCreate(
            ['assistant_domain_store_id' => $store->getKey()],
            [
                'shop_timezone' => $timezone,
                'from_date' => $fromDate->toDateString(),
                'to_date' => $toDate->toDateString(),
                'next_date' => $toDate->toDateString(),
                'status' => AssistantShopifyAnalyticsBackfill::STATUS_PENDING,
            ],
        );
    }

    private function shopTimezone(AssistantDomainStore $store, ?AdminUser $actor): string
    {
        $response = $this->shopify->graphqlForStore($store, $actor, <<<'GQL'
            query AssistantShopifyTimezone {
              shop {
                ianaTimezone
              }
            }
            GQL);

        return $this->validTimezone((string) Arr::get($response, 'data.shop.ianaTimezone'));
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

    private function salesQuery(string $timezone, ?string $reportDate = null): string
    {
        return "FROM sales SHOW total_sales, orders, returning_customer_rate, returns, net_sales, average_order_value\n"
            ."WITH CURRENCY 'USD', TIMEZONE '{$timezone}'\n"
            .$this->dateClause($reportDate);
    }

    private function fulfillmentsQuery(string $timezone, ?string $reportDate = null): string
    {
        return "FROM fulfillments SHOW orders_fulfilled\n"
            ."WITH TIMEZONE '{$timezone}'\n"
            .$this->dateClause($reportDate);
    }

    private function salesTimeSeriesQuery(string $timezone, string $fromDate, string $toDate): string
    {
        return "FROM sales SHOW total_sales, orders, returning_customer_rate, returns, net_sales, average_order_value\n"
            ."WITH CURRENCY 'USD', TIMEZONE '{$timezone}'\n"
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
