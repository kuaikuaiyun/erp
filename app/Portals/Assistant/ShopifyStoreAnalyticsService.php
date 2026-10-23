<?php

namespace App\Portals\Assistant;

use App\Models\AdminUser;
use App\Models\AssistantDomainStore;
use App\Models\AssistantShopifyStoreMetric;
use App\Models\AssistantShopifyStoreOrder;
use App\Portals\Logistics\ShopifyOrderSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Assistant Shopify analytics source synchronizer.
 *
 * Shopify orders are stored with their source amounts and UTC timestamps. The
 * analytics page is deliberately a separate read-only reporting layer: it
 * chooses a reporting timezone, converts the requested dates to UTC, then
 * aggregates these rows without calling Shopify or changing source values.
 */
class ShopifyStoreAnalyticsService
{
    private const INITIAL_HISTORY_DAYS = 90;

    /** Extra prior days protect arbitrary report-timezone boundaries. */
    private const INITIAL_TIMEZONE_BUFFER_DAYS = 2;

    /** Store-local current date plus the three preceding dates. */
    private const INCREMENTAL_SYNC_DAYS = 4;

    public function __construct(
        private readonly ShopifyOrderSyncService $shopify,
        private readonly ShopifyAnalyticsReferenceRateService $referenceRates,
    ) {
    }

    /**
     * First successful request for a store loads the latest 90 store-local
     * calendar days (plus a prior timezone-boundary buffer). Later requests
     * replace the source rows for the current local date and prior 3 dates.
     */
    public function refresh(
        AssistantDomainStore $store,
        ?AdminUser $actor = null,
        bool $allowSubscriptionHistoryBackfill = false,
    ): AssistantShopifyStoreMetric
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

            // Store metadata is authoritative Shopify data and must not depend on
            // the much longer order synchronization succeeding. Otherwise a
            // changed Shopify timezone remains stale whenever history backfill or
            // an incremental order request fails after this metadata query.
            $metric->fill([
                'shop_timezone' => $shop['timezone'],
                'currency_code' => 'USD',
                'original_currency_code' => $shop['currency'],
            ])->save();

            $localToday = CarbonImmutable::now($shop['timezone'])->startOfDay();
            $isInitial = $metric->orders_initial_backfilled_at === null;
            $historyFromDate = $localToday->subDays(
                (self::INITIAL_HISTORY_DAYS - 1) + self::INITIAL_TIMEZONE_BUFFER_DAYS,
            );
            $historyStartUtc = $historyFromDate->startOfDay()->setTimezone('UTC');
            $historyEndUtc = $localToday->addDay()->startOfDay()->setTimezone('UTC');

            // Some rows may already contain the original Shopify tags while
            // their derived category is still empty (for example after a
            // partially completed deployment). Repair those rows locally
            // before deciding whether Shopify history needs to be requested.
            $this->repairStoredSubscriptionCategories(
                (int) $store->getKey(),
                $historyStartUtc,
                $historyEndUtc,
            );
            // Tags were introduced after order-history storage already existed.
            // Only an explicit user-selected synchronization may perform this
            // one-time 90-day tag backfill; the automatic 30-minute process
            // remains incremental and cannot unexpectedly fan out API work.
            $needsSubscriptionTagBackfill = $allowSubscriptionHistoryBackfill
                && $this->hasUnclassifiedSubscriptionOrders(
                    (int) $store->getKey(),
                    $historyStartUtc,
                    $historyEndUtc,
                );
            $requiresFullHistory = $isInitial || $needsSubscriptionTagBackfill;
            $fromDate = $requiresFullHistory
                ? $historyFromDate
                : $localToday->subDays(self::INCREMENTAL_SYNC_DAYS - 1);

            $this->synchroniseStoreDates(
                $store,
                $actor,
                $shop,
                $fromDate,
                $localToday,
            );

            if ($requiresFullHistory) {
                $this->repairStoredSubscriptionCategories(
                    (int) $store->getKey(),
                    $historyStartUtc,
                    $historyEndUtc,
                );

                if ($this->hasUnclassifiedSubscriptionOrders(
                    (int) $store->getKey(),
                    $historyStartUtc,
                    $historyEndUtc,
                )) {
                    throw new RuntimeException('Shopify subscription classification backfill is incomplete.');
                }
            }

            $currentDaySummary = $this->storeDaySummary($store, $localToday);
            $syncedAt = now();
            $payload = [
                'shop_timezone' => $shop['timezone'],
                'report_date' => $localToday->toDateString(),
                'currency_code' => 'USD',
                'original_currency_code' => $shop['currency'],
                'total_sales' => $currentDaySummary['total_sales'],
                'original_total_sales' => $currentDaySummary['original_total_sales'],
                'orders_count' => $currentDaySummary['orders_count'],
                'returning_customer_rate' => null,
                'fulfilled_orders_count' => $currentDaySummary['fulfilled_orders_count'],
                'refund_amount' => $currentDaySummary['refund_amount'],
                'net_sales' => $currentDaySummary['net_sales'],
                'average_order_value' => null,
                'sync_status' => AssistantShopifyStoreMetric::STATUS_SUCCESS,
                'sync_message' => null,
                'synced_at' => $syncedAt,
                'last_successful_synced_at' => $syncedAt,
            ];

            if ($isInitial) {
                $payload['orders_initial_backfilled_at'] = $syncedAt;
            }

            $metric->fill($payload)->save();

            return $metric;
        } catch (\Throwable $exception) {
            report($exception);
            $failure = $this->failure($exception);

            return $this->markFailure($metric, $failure['status'], $failure['reason']);
        }
    }

    private function repairStoredSubscriptionCategories(
        int $storeId,
        CarbonImmutable $startUtc,
        CarbonImmutable $endUtc,
    ): void {
        AssistantShopifyStoreOrder::query()
            ->where('assistant_domain_store_id', $storeId)
            ->where('shopify_created_at', '>=', $startUtc->format('Y-m-d H:i:s'))
            ->where('shopify_created_at', '<', $endUtc->format('Y-m-d H:i:s'))
            ->whereNotNull('shopify_tags')
            ->where(function ($orders): void {
                $orders
                    ->whereNull('subscription_category')
                    ->orWhere('subscription_category', '')
                    ->orWhereNotIn(
                        'subscription_category',
                        AssistantShopifyStoreOrder::subscriptionCategories(),
                    );
            })
            ->select(['id', 'shopify_tags'])
            ->chunkById(500, function ($orders): void {
                $idsByCategory = [];
                foreach ($orders as $order) {
                    $category = AssistantShopifyStoreOrder::subscriptionCategoryForTags($order->shopify_tags);
                    $idsByCategory[$category][] = (int) $order->getKey();
                }

                foreach ($idsByCategory as $category => $ids) {
                    AssistantShopifyStoreOrder::query()
                        ->whereKey($ids)
                        ->update([
                            'subscription_category' => $category,
                            'updated_at' => now(),
                        ]);
                }
            });
    }

    private function hasUnclassifiedSubscriptionOrders(
        int $storeId,
        CarbonImmutable $startUtc,
        CarbonImmutable $endUtc,
    ): bool {
        return AssistantShopifyStoreOrder::query()
            ->where('assistant_domain_store_id', $storeId)
            ->where('shopify_created_at', '>=', $startUtc->format('Y-m-d H:i:s'))
            ->where('shopify_created_at', '<', $endUtc->format('Y-m-d H:i:s'))
            ->where(function ($orders): void {
                $orders
                    // Rows written before tag persistence was introduced have
                    // no source tags. A legitimate tag-free order is stored as
                    // JSON [] and is classified as a regular order.
                    ->whereNull('shopify_tags')
                    ->orWhereNull('subscription_category')
                    ->orWhere('subscription_category', '')
                    ->orWhereNotIn(
                        'subscription_category',
                        AssistantShopifyStoreOrder::subscriptionCategories(),
                    );
            })
            ->exists();
    }

    /**
     * @param array{timezone:string,currency:string} $shop
     */
    private function synchroniseStoreDates(
        AssistantDomainStore $store,
        ?AdminUser $actor,
        array $shop,
        CarbonImmutable $fromDate,
        CarbonImmutable $toDate,
    ): void {
        $cursor = $fromDate->setTimezone($shop['timezone'])->startOfDay();
        $lastDate = $toDate->setTimezone($shop['timezone'])->startOfDay();

        while ($cursor <= $lastDate) {
            $this->synchroniseStoreDate($store, $actor, $shop, $cursor);
            $cursor = $cursor->addDay();
        }
    }

    /**
     * @param array{timezone:string,currency:string} $shop
     */
    private function synchroniseStoreDate(
        AssistantDomainStore $store,
        ?AdminUser $actor,
        array $shop,
        CarbonImmutable $localDate,
    ): void {
        $startUtc = $localDate->startOfDay()->setTimezone('UTC');
        $endUtc = $localDate->addDay()->startOfDay()->setTimezone('UTC');
        $orders = $this->fetchOrdersForCreatedWindow($store, $actor, $startUtc, $endUtc);

        if ($orders === []) {
            return;
        }

        $timestamp = now();
        $rowsByOrderId = [];
        foreach ($orders as $order) {
            $row = $this->orderRow($store, $order, $shop['currency'], $timestamp);
            $rowsByOrderId[$row['shopify_order_id']] = $row;
        }

        AssistantShopifyStoreOrder::query()->upsert(
            array_values($rowsByOrderId),
            ['assistant_domain_store_id', 'shopify_order_id'],
            [
                'shopify_legacy_resource_id',
                'shopify_order_number',
                'shopify_created_at',
                'shopify_processed_at',
                'shopify_updated_at',
                'shopify_cancelled_at',
                'is_test',
                'shopify_tags',
                'subscription_category',
                'original_currency_code',
                'original_total_price',
                'current_currency_code',
                'current_total_price',
                'refunded_currency_code',
                'refunded_total_amount',
                'financial_status',
                'fulfillment_status',
                'synced_at',
                'updated_at',
            ],
        );
    }

    /** @return list<array<string, mixed>> */
    private function fetchOrdersForCreatedWindow(
        AssistantDomainStore $store,
        ?AdminUser $actor,
        CarbonImmutable $startUtc,
        CarbonImmutable $endUtc,
    ): array {
        $cursor = null;
        $orders = [];
        $query = "created_at:>='".$this->shopifyUtc($startUtc)."' created_at:<'".$this->shopifyUtc($endUtc)."'";

        do {
            $response = $this->shopify->graphqlForStore(
                $store,
                $actor,
                $this->ordersQuery(),
                [
                    'cursor' => $cursor,
                    'query' => $query,
                ],
            );
            $connection = Arr::get($response, 'data.orders');
            if (!is_array($connection)) {
                throw new RuntimeException('Shopify did not return the orders connection.');
            }

            foreach (Arr::get($connection, 'edges', []) as $edge) {
                $order = is_array($edge) ? ($edge['node'] ?? null) : null;
                if (is_array($order)) {
                    $orders[] = $order;
                }
            }

            $pageInfo = Arr::get($connection, 'pageInfo', []);
            $hasNextPage = (bool) (is_array($pageInfo) ? ($pageInfo['hasNextPage'] ?? false) : false);
            $nextCursor = is_array($pageInfo) ? ($pageInfo['endCursor'] ?? null) : null;

            if ($hasNextPage && (!is_string($nextCursor) || $nextCursor === '')) {
                throw new RuntimeException('Shopify returned a next page without an order cursor.');
            }

            $cursor = $hasNextPage ? $nextCursor : null;

            // Keep long initial synchronizations friendly to the Shopify API
            // without truncating any result page.
            if ($hasNextPage) {
                usleep(120000);
            }
        } while ($cursor !== null);

        return $orders;
    }

    /** @return array<string, mixed> */
    private function orderRow(
        AssistantDomainStore $store,
        array $order,
        string $fallbackCurrency,
        \DateTimeInterface $syncedAt,
    ): array {
        $orderId = trim((string) ($order['id'] ?? ''));
        if ($orderId === '') {
            throw new RuntimeException('Shopify returned an order without an ID.');
        }

        $original = $this->money($order['originalTotalPriceSet'] ?? null, $fallbackCurrency);
        $current = $this->money($order['currentTotalPriceSet'] ?? null, $fallbackCurrency);
        $refunded = $this->money($order['totalRefundedSet'] ?? null, $fallbackCurrency);
        $tags = is_array($order['tags'] ?? null) ? array_values($order['tags']) : [];

        return [
            'assistant_domain_store_id' => $store->getKey(),
            'shopify_order_id' => $orderId,
            'shopify_legacy_resource_id' => $this->nullableString($order['legacyResourceId'] ?? null, 40),
            'shopify_order_number' => $this->nullableString($order['name'] ?? null, 80),
            'shopify_created_at' => $this->dateTime($order['createdAt'] ?? null),
            'shopify_processed_at' => $this->dateTime($order['processedAt'] ?? null, false),
            'shopify_updated_at' => $this->dateTime($order['updatedAt'] ?? null, false),
            'shopify_cancelled_at' => $this->dateTime($order['cancelledAt'] ?? null, false),
            'is_test' => (bool) ($order['test'] ?? false),
            'shopify_tags' => json_encode($tags, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'subscription_category' => AssistantShopifyStoreOrder::subscriptionCategoryForTags($tags),
            'original_currency_code' => $original['currency'],
            'original_total_price' => $original['amount'],
            'current_currency_code' => $current['currency'],
            'current_total_price' => $current['amount'],
            'refunded_currency_code' => $refunded['currency'],
            'refunded_total_amount' => $refunded['amount'],
            'financial_status' => $this->nullableString($order['displayFinancialStatus'] ?? null, 40),
            'fulfillment_status' => $this->nullableString($order['displayFulfillmentStatus'] ?? null, 40),
            'synced_at' => $syncedAt,
            'created_at' => $syncedAt,
            'updated_at' => $syncedAt,
        ];
    }

    /** @return array{currency:string,amount:?float} */
    private function money(mixed $moneyBag, string $fallbackCurrency): array
    {
        $shopMoney = is_array($moneyBag) ? ($moneyBag['shopMoney'] ?? null) : null;
        $currency = $fallbackCurrency;
        $amount = null;

        if (is_array($shopMoney)) {
            $candidateCurrency = strtoupper(trim((string) ($shopMoney['currencyCode'] ?? '')));
            if (preg_match('/^[A-Z]{3}$/', $candidateCurrency) === 1) {
                $currency = $candidateCurrency;
            }

            $candidateAmount = $shopMoney['amount'] ?? null;
            if ($candidateAmount !== null && $candidateAmount !== '' && !is_numeric($candidateAmount)) {
                throw new RuntimeException('Shopify returned a non-numeric order amount.');
            }
            $amount = is_numeric($candidateAmount) ? (float) $candidateAmount : null;
        }

        return ['currency' => $currency, 'amount' => $amount];
    }

    /** @return array{timezone:string,currency:string} */
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

    /** @return array{total_sales:?float,original_total_sales:?float,orders_count:int,fulfilled_orders_count:int,refund_amount:?float,net_sales:?float} */
    private function storeDaySummary(
        AssistantDomainStore $store,
        CarbonImmutable $localDate,
    ): array {
        $startUtc = $localDate->startOfDay()->setTimezone('UTC');
        $endUtc = $localDate->addDay()->startOfDay()->setTimezone('UTC');
        $orders = AssistantShopifyStoreOrder::query()
            ->where('assistant_domain_store_id', $store->getKey())
            ->where('shopify_created_at', '>=', $startUtc->format('Y-m-d H:i:s'))
            ->where('shopify_created_at', '<', $endUtc->format('Y-m-d H:i:s'))
            ->where('is_test', false)
            ->whereIn('financial_status', AssistantShopifyStoreOrder::successfulFinancialStatuses())
            ->get();

        $originalCurrency = strtoupper((string) $orders->pluck('original_currency_code')->filter()->first());
        $referenceRate = $this->referenceRates->rateFor($originalCurrency);
        $originalTotal = (float) $orders->sum(static fn (AssistantShopifyStoreOrder $order): float => (float) ($order->original_total_price ?? 0));
        $refund = (float) $orders->sum(static fn (AssistantShopifyStoreOrder $order): float => (float) ($order->refunded_total_amount ?? 0));
        $net = (float) $orders->sum(static fn (AssistantShopifyStoreOrder $order): float => (float) ($order->current_total_price ?? 0));

        return [
            'total_sales' => $referenceRate === null ? null : $originalTotal * $referenceRate,
            'original_total_sales' => $originalTotal,
            'orders_count' => $orders->count(),
            'fulfilled_orders_count' => $orders->filter(
                static fn (AssistantShopifyStoreOrder $order): bool => strtoupper((string) $order->fulfillment_status) === 'FULFILLED',
            )->count(),
            'refund_amount' => $referenceRate === null ? null : $refund * $referenceRate,
            'net_sales' => $referenceRate === null ? null : $net * $referenceRate,
        ];
    }

    private function ordersQuery(): string
    {
        return <<<'GRAPHQL'
query AssistantShopifyAnalyticsOrders($cursor: String, $query: String!) {
  orders(first: 250, after: $cursor, query: $query, sortKey: CREATED_AT, reverse: false) {
    pageInfo { hasNextPage endCursor }
    edges {
      node {
        id
        legacyResourceId
        name
        createdAt
        processedAt
        updatedAt
        cancelledAt
        test
        tags
        displayFinancialStatus
        displayFulfillmentStatus
        originalTotalPriceSet { shopMoney { amount currencyCode } }
        currentTotalPriceSet { shopMoney { amount currencyCode } }
        totalRefundedSet { shopMoney { amount currencyCode } }
      }
    }
  }
}
GRAPHQL;
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
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new RuntimeException('Shopify returned an invalid currency code.');
        }

        return $currency;
    }

    private function dateTime(mixed $value, bool $required = true): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            if ($required) {
                throw new RuntimeException('Shopify returned an order without a created timestamp.');
            }

            return null;
        }

        try {
            return CarbonImmutable::parse((string) $value)->setTimezone('UTC');
        } catch (\Throwable) {
            throw new RuntimeException('Shopify returned an invalid order timestamp.');
        }
    }

    private function nullableString(mixed $value, int $length): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : Str::limit($value, $length, '');
    }

    private function shopifyUtc(CarbonImmutable $value): string
    {
        return $value->setTimezone('UTC')->format('Y-m-d\\TH:i:s\\Z');
    }

    private function markFailure(
        AssistantShopifyStoreMetric $metric,
        string $status,
        string $reason,
    ): AssistantShopifyStoreMetric {
        $payload = [
            'sync_status' => $status,
            'sync_message' => $reason,
        ];

        if (!$metric->exists) {
            $payload['currency_code'] = 'USD';
        }

        $metric->fill($payload)->save();

        return $metric;
    }

    /** @return array{status:string,reason:string} */
    private function failure(\Throwable $exception): array
    {
        $rawMessage = trim($exception->getMessage());
        $message = Str::lower($rawMessage);
        $permissionMarkers = ['read_orders', 'permission', 'access denied', 'not authorized', 'forbidden'];

        if (preg_match('/shopify api http\s+(\d{3})/i', $rawMessage, $matches) === 1) {
            return [
                'status' => AssistantShopifyStoreMetric::STATUS_FAILED,
                'reason' => 'api_http_'.$matches[1],
            ];
        }

        if (Str::contains($message, $permissionMarkers)) {
            return [
                'status' => AssistantShopifyStoreMetric::STATUS_PERMISSION_DENIED,
                'reason' => 'orders_permission_denied',
            ];
        }

        if (Str::contains($message, ['invalid_client', 'invalid client', 'client secret', 'access token', 'oauth', 'token'])) {
            return [
                'status' => AssistantShopifyStoreMetric::STATUS_FAILED,
                'reason' => 'token_failed',
            ];
        }

        if (Str::contains($message, ['graphql', 'orders connection'])) {
            return [
                'status' => AssistantShopifyStoreMetric::STATUS_FAILED,
                'reason' => 'graphql_query_failed',
            ];
        }

        if (Str::contains($message, ['connection', 'timed out', 'timeout', 'curl', 'could not resolve', 'network'])) {
            return [
                'status' => AssistantShopifyStoreMetric::STATUS_FAILED,
                'reason' => 'connection_failed',
            ];
        }

        if (Str::contains($message, 'next page without an order cursor')) {
            return [
                'status' => AssistantShopifyStoreMetric::STATUS_FAILED,
                'reason' => 'order_cursor_missing',
            ];
        }

        if (Str::contains($message, 'subscription classification backfill is incomplete')) {
            return [
                'status' => AssistantShopifyStoreMetric::STATUS_FAILED,
                'reason' => 'subscription_classification_incomplete',
            ];
        }

        if (Str::contains($message, ['without an order id', 'without an id'])) {
            return [
                'status' => AssistantShopifyStoreMetric::STATUS_FAILED,
                'reason' => 'order_id_missing',
            ];
        }

        if (Str::contains($message, ['without a created timestamp', 'invalid order timestamp'])) {
            return [
                'status' => AssistantShopifyStoreMetric::STATUS_FAILED,
                'reason' => 'order_timestamp_invalid',
            ];
        }

        if (Str::contains($message, 'non-numeric order amount')) {
            return [
                'status' => AssistantShopifyStoreMetric::STATUS_FAILED,
                'reason' => 'order_amount_invalid',
            ];
        }

        if (Str::contains($message, 'invalid iana timezone')) {
            return [
                'status' => AssistantShopifyStoreMetric::STATUS_FAILED,
                'reason' => 'shop_timezone_invalid',
            ];
        }

        if (Str::contains($message, 'invalid currency code')) {
            return [
                'status' => AssistantShopifyStoreMetric::STATUS_FAILED,
                'reason' => 'shop_currency_invalid',
            ];
        }

        if (Str::contains($message, ['*.myshopify.com', 'shop domain'])) {
            return [
                'status' => AssistantShopifyStoreMetric::STATUS_FAILED,
                'reason' => 'shop_domain_invalid',
            ];
        }

        return [
            'status' => AssistantShopifyStoreMetric::STATUS_FAILED,
            'reason' => 'unexpected_exception',
        ];
    }
}
