<?php

namespace App\Portals\Logistics;

use App\Models\AdminUser;
use App\Models\AssistantDomainStore;
use App\Models\LogisticsOrder;
use App\Models\LogisticsShopifyConnection;
use App\Models\LogisticsSyncLog;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class ShopifyOrderSyncService
{
    public function __construct(
        private readonly LogisticsProfitCalculator $profitCalculator,
        private readonly LogisticsOrderItemService $items,
        private readonly LogisticsTongtuAutomationService $automation,
        private readonly LogisticsTrackingService $tracking,
        private readonly PayPalOrderAssociationService $paypalAssociations,
    )
    {
    }

    /** @return array{created:int, updated:int, sku_exception:int, sku_item_count:int, sku_missing_item_count:int, sku_missing_details:list<array{order_number:string,item_count:int,missing_count:int}>, skipped_unpaid:int, webhook_active:bool, webhook_error:?string} */
    public function syncStore(AssistantDomainStore $store, AdminUser $actor, ?int $syncDays = null): array
    {
        $connection = LogisticsShopifyConnection::query()->firstOrNew([
            'assistant_domain_store_id' => $store->getKey(),
        ]);
        $connection = $this->ensureAccessToken($connection, $store, $actor);
        $summary = $this->sync(
            $connection,
            $store,
            $actor,
            max(1, $syncDays ?? (int) config('logistics.shopify.sync_days', 60)),
        );
        $webhook = $this->registerWebhooks($connection, $actor);

        return [...$summary, ...$webhook];
    }

    /** @return array{webhook_active:bool, webhook_error:?string} */
    public function registerStoreWebhooks(AssistantDomainStore $store, ?AdminUser $actor): array
    {
        $connection = LogisticsShopifyConnection::query()->firstOrNew([
            'assistant_domain_store_id' => $store->getKey(),
        ]);

        try {
            $connection = $this->ensureAccessToken($connection, $store, $actor);
        } catch (\Throwable $exception) {
            $message = Str::limit($exception->getMessage(), 1000);
            $this->webhookFailed($connection, $message, $actor);

            return ['webhook_active' => false, 'webhook_error' => $message];
        }

        return $this->registerWebhooks($connection, $actor);
    }

    /**
     * Execute a server-generated Admin GraphQL query for a configured Shopify
     * store. This keeps token acquisition and expiry handling in one place for
     * both logistics synchronization and Assistant analytics.
     *
     * @param array<string, mixed> $variables
     * @return array<string, mixed>
     */
    public function graphqlForStore(
        AssistantDomainStore $store,
        ?AdminUser $actor,
        string $query,
        array $variables = [],
    ): array {
        $connection = LogisticsShopifyConnection::query()->firstOrNew([
            'assistant_domain_store_id' => $store->getKey(),
        ]);
        $connection = $this->ensureAccessToken($connection, $store, $actor);

        return $this->graphql($connection, $query, $variables);
    }

    public function syncWebhookOrder(LogisticsShopifyConnection $connection, array $webhookPayload, ?string $topic): ?LogisticsOrder
    {
        $orderId = $this->webhookOrderId($webhookPayload, $topic);
        if ($orderId === '') {
            throw new RuntimeException('Webhook 未包含可识别的 Shopify 原生订单 ID。');
        }

        $sourceStore = $connection->store;
        $payload = null;
        if ($sourceStore instanceof AssistantDomainStore
            && filled($sourceStore->shopify_client_id)
            && filled($sourceStore->shopify_client_secret)) {
            try {
                if ($this->shopDomain($sourceStore) === $this->normaliseDomain((string) $connection->shop_domain)) {
                    $connection = $this->ensureAccessToken($connection, $sourceStore, null);
                }
            } catch (RuntimeException) {
                // The old connection can still authenticate the already registered shop until its Token expires.
            }
            if ($connection->hasReusableAccessToken()) {
                $payload = $this->fetchOrder($connection, $orderId);
            }
        }

        if ($payload === null) {
            $payload = $this->normaliseWebhookOrder($webhookPayload, $orderId);
        }
        if ($payload === null) {
            throw new RuntimeException('无法读取 Webhook 对应的完整 Shopify 订单，请稍后重试。');
        }

        $assignedStore = $this->isActiveShopifyStoreForDomain($sourceStore, (string) $connection->shop_domain)
            ? $sourceStore
            : null;

        $result = $this->persistOrder($assignedStore, $payload, (string) $connection->shop_domain, $connection);

        return $result['order'] ?? null;
    }

    private function ensureAccessToken(
        LogisticsShopifyConnection $connection,
        AssistantDomainStore $store,
        ?AdminUser $actor,
    ): LogisticsShopifyConnection {
        $shopDomain = $this->shopDomain($store);
        $this->assertCredentials($store);

        $credentialsChanged = filled($connection->webhook_secret)
            && !hash_equals((string) $connection->webhook_secret, (string) $store->shopify_client_secret);
        $connection->webhook_secret = (string) $store->shopify_client_secret;
        $sameDomain = $this->normaliseDomain((string) $connection->shop_domain) === $shopDomain;
        if ($connection->hasReusableAccessToken() && $sameDomain && !$credentialsChanged) {
            if ($connection->isDirty()) {
                $connection->save();
            }

            return $connection;
        }

        $connection->shop_domain = $shopDomain;

        try {
            $response = Http::asForm()
                ->acceptJson()
                ->timeout(30)
                ->post('https://'.$shopDomain.'/admin/oauth/access_token', [
                    'grant_type' => 'client_credentials',
                    'client_id' => $store->shopify_client_id,
                    'client_secret' => $store->shopify_client_secret,
                ]);
        } catch (ConnectionException $exception) {
            $message = '无法连接 Shopify 获取 Token：'.$exception->getMessage();
            $this->tokenFailed($connection, $shopDomain, $message, $actor);
            throw new RuntimeException('无法连接 Shopify，请稍后重试。');
        }

        if (!$response->successful() || blank($response->json('access_token'))) {
            $errorCode = trim((string) $response->json('error'));
            $message = trim((string) ($response->json('error_description') ?: $response->body()));
            if ($errorCode === 'shop_not_permitted') {
                $message = 'Shopify 拒绝 Client Credentials（shop_not_permitted）：该店铺与应用必须位于同一 Shopify Dev Dashboard Organization。';
            }
            $message = 'Shopify 获取 Token 失败：'.Str::limit($message ?: '未知错误', 800);
            $this->tokenFailed($connection, $shopDomain, $message, $actor);
            throw new RuntimeException($message);
        }

        $obtainedAt = now();
        $expiresIn = max(1, (int) ($response->json('expires_in') ?: 86399));
        $connection->fill([
            'shop_domain' => $shopDomain,
            'access_token' => (string) $response->json('access_token'),
            'granted_scopes' => (string) ($response->json('scope') ?: ''),
            'status' => LogisticsShopifyConnection::STATUS_TOKEN_READY,
            'state_token' => null,
            'state_expires_at' => null,
            'authorized_at' => null,
            'token_obtained_at' => $obtainedAt,
            'token_expires_at' => $obtainedAt->copy()->addSeconds($expiresIn),
        ]);
        $connection->save();
        $this->log($connection, null, 'shopify', 'token', 'success', 'Client Credentials Token 已获取。', $actor, [
            'expires_in' => $expiresIn,
        ]);

        return $connection;
    }

    /** @return array{created:int, updated:int, sku_exception:int, sku_item_count:int, sku_missing_item_count:int, sku_missing_details:list<array{order_number:string,item_count:int,missing_count:int}>, skipped_unpaid:int} */
    private function sync(
        LogisticsShopifyConnection $connection,
        AssistantDomainStore $store,
        AdminUser $actor,
        int $syncDays,
    ): array {
        if (!$connection->hasReusableAccessToken()) {
            throw new RuntimeException('Shopify Token 无效或已过期，请重新同步。');
        }

        $summary = [
            'created' => 0,
            'updated' => 0,
            'sku_exception' => 0,
            'sku_item_count' => 0,
            'sku_missing_item_count' => 0,
            'sku_missing_details' => [],
            'skipped_unpaid' => 0,
        ];
        $cursor = null;
        $hasNextPage = true;
        $createdAfter = now()->subDays($syncDays)->utc()->format('Y-m-d\\TH:i:s\\Z');

        try {
            while ($hasNextPage) {
                $body = $this->graphql($connection, $this->ordersQuery(), [
                    'after' => $cursor,
                    'query' => 'created_at:>='.$createdAfter,
                ]);

                $orders = Arr::get($body, 'data.orders.nodes', []);
                foreach ($orders as $orderPayload) {
                    if (!is_array($orderPayload)) {
                        continue;
                    }
                    $result = $this->persistOrder($store, $orderPayload, (string) $connection->shop_domain, $connection);
                    if ($result === null) {
                        $summary['skipped_unpaid']++;
                        continue;
                    }
                    $summary[$result['state']]++;
                    if ($result['shopify_sku_missing']) {
                        $summary['sku_exception']++;
                        $summary['sku_item_count'] += $result['item_count'];
                        $summary['sku_missing_item_count'] += $result['missing_sku_count'];
                        $summary['sku_missing_details'][] = [
                            'order_number' => (string) ($result['order']->shopify_order_number ?: $result['order']->shopify_order_id),
                            'item_count' => $result['item_count'],
                            'missing_count' => $result['missing_sku_count'],
                        ];
                    }
                }

                $pageInfo = Arr::get($body, 'data.orders.pageInfo', []);
                $hasNextPage = (bool) ($pageInfo['hasNextPage'] ?? false);
                $cursor = $pageInfo['endCursor'] ?? null;
            }
        } catch (\Throwable $exception) {
            $connection->fill([
                'last_sync_status' => 'failed',
                'last_sync_message' => Str::limit($exception->getMessage(), 1000),
            ])->save();
            $this->log($connection, null, 'shopify', 'sync', 'failed', $exception->getMessage(), $actor);
            throw $exception;
        }

        $connection->fill([
            'last_synced_at' => now(),
            'last_sync_status' => 'success',
            'last_sync_message' => '近 '.$syncDays.' 天：新增 '.$summary['created'].' 条，更新 '.$summary['updated'].' 条。',
        ])->save();
        $this->log($connection, null, 'shopify', 'sync', 'success', $connection->last_sync_message, $actor, $summary);

        return $summary;
    }

    /** @return array{webhook_active:bool, webhook_error:?string} */
    private function registerWebhooks(LogisticsShopifyConnection $connection, ?AdminUser $actor): array
    {
        $topics = [
            'ORDERS_CREATE',
            'ORDERS_PAID',
            'ORDERS_UPDATED',
            'ORDERS_FULFILLED',
            'ORDERS_PARTIALLY_FULFILLED',
            'REFUNDS_CREATE',
            'FULFILLMENTS_CREATE',
            'FULFILLMENTS_UPDATE',
            'FULFILLMENT_EVENTS_CREATE',
        ];
        $url = trim((string) config('logistics.shopify.webhook_url'));
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL) || !Str::startsWith($url, 'https://')) {
            $message = 'SHOPIFY_WEBHOOK_URL 必须是有效的 HTTPS 地址。';
            $this->webhookFailed($connection, $message, $actor);

            return ['webhook_active' => false, 'webhook_error' => $message];
        }

        try {
            $body = $this->graphql($connection, <<<'GRAPHQL'
query ExistingWebhookSubscriptions {
  webhookSubscriptions(first: 250) {
    nodes { id topic uri }
  }
}
GRAPHQL);
            $existing = collect(Arr::get($body, 'data.webhookSubscriptions.nodes', []))
                ->filter(static fn ($subscription): bool => is_array($subscription))
                ->keyBy(static fn (array $subscription): string => (string) $subscription['topic'].'|'.(string) $subscription['uri']);
            $subscriptionIds = [];

            foreach ($topics as $topic) {
                $key = $topic.'|'.$url;
                if ($existing->has($key)) {
                    $subscriptionIds[] = (string) Arr::get($existing->get($key), 'id');
                    continue;
                }

                $created = $this->graphql($connection, <<<'GRAPHQL'
mutation CreateWebhookSubscription($topic: WebhookSubscriptionTopic!, $webhookSubscription: WebhookSubscriptionInput!) {
  webhookSubscriptionCreate(topic: $topic, webhookSubscription: $webhookSubscription) {
    webhookSubscription { id topic uri }
    userErrors { field message }
  }
}
GRAPHQL, [
                    'topic' => $topic,
                    'webhookSubscription' => ['uri' => $url],
                ]);
                $errors = Arr::get($created, 'data.webhookSubscriptionCreate.userErrors', []);
                if (is_array($errors) && $errors !== []) {
                    throw new RuntimeException(collect($errors)->pluck('message')->filter()->implode('；'));
                }
                $id = trim((string) Arr::get($created, 'data.webhookSubscriptionCreate.webhookSubscription.id'));
                if ($id === '') {
                    throw new RuntimeException('Shopify 未返回 Webhook 订阅 ID。');
                }
                $subscriptionIds[] = $id;
            }

            $connection->fill([
                'webhook_status' => 'active',
                'webhook_registered_at' => now(),
                'webhook_last_error' => null,
                'webhook_subscription_ids' => $subscriptionIds,
            ])->save();
            $this->log($connection, null, 'shopify', 'webhook_subscribe', 'success', 'Webhook 已登记。', $actor, [
                'topics' => $topics,
                'url' => $url,
            ]);

            return ['webhook_active' => true, 'webhook_error' => null];
        } catch (\Throwable $exception) {
            $message = Str::limit($exception->getMessage(), 1000);
            $this->webhookFailed($connection, $message, $actor);

            return ['webhook_active' => false, 'webhook_error' => $message];
        }
    }

    /** @return array{state:'created'|'updated', order:LogisticsOrder, shopify_sku_missing:bool, item_count:int, missing_sku_count:int}|null */
    private function persistOrder(
        ?AssistantDomainStore $store,
        array $payload,
        ?string $sourceShopDomain,
        ?LogisticsShopifyConnection $connection = null,
    ): ?array
    {
        $shopifyOrderId = (string) ($payload['legacyResourceId'] ?? $payload['id'] ?? '');
        if ($shopifyOrderId === '') {
            throw new RuntimeException('Shopify 返回的订单缺少原生订单 ID。');
        }

        $sourceShopDomain = $this->normaliseDomain($sourceShopDomain);
        $current = $this->existingOrder($store, $sourceShopDomain, $shopifyOrderId);
        if (!$this->isPaidOrder($payload, $current)) {
            return null;
        }
        $isNew = !$current;
        $current ??= new LogisticsOrder();

        $attributes = $this->attributes($payload['customAttributes'] ?? []);
        $lineItems = is_array($payload['lineItems']['nodes'] ?? null) ? $payload['lineItems']['nodes'] : [];
        $shopifyTags = $this->normalisedTags($payload['tags'] ?? []);
        $skuRows = array_map(static fn (array $item): string => trim((string) ($item['sku'] ?? '')), $lineItems);
        $itemCount = count($lineItems);
        $missingSkuCount = $lineItems === []
            ? 0
            : count(array_filter($skuRows, static fn (string $sku): bool => $sku === ''));
        $missingSku = $lineItems === [] || $missingSkuCount > 0;
        $transactions = is_array($payload['transactions'] ?? null) ? $payload['transactions'] : [];
        $refunds = is_array($payload['refunds'] ?? null) ? $payload['refunds'] : [];
        $refundData = $this->refundData($refunds);
        $paidTransaction = $this->successfulPayment($transactions);
        $orderNumber = preg_replace('/^#/', '', (string) ($payload['name'] ?? ''));
        $isAssigned = $store instanceof AssistantDomainStore;
        $tongtuOrderNumber = $isAssigned ? $store->makeTongtuOrderNumber($orderNumber) : null;

        $dataStatus = $this->dataStatus($current, $isAssigned, filled($tongtuOrderNumber), $missingSku);
        $skuExceptionReason = $missingSku
            ? 'Shopify SKU 缺失'
            : ($current->data_status === LogisticsOrder::STATUS_SKU_EXCEPTION ? $current->sku_exception_reason : null);
        $paymentMethod = $attributes['yx_payment_method'] ?? null;
        $paymentGateway = $attributes['yx_payment_gateway'] ?? ($paidTransaction['gateway'] ?? null);
        $skuFingerprint = $this->skuFingerprint($lineItems);
        $skuChanged = filled($current->sku_fingerprint) && !hash_equals((string) $current->sku_fingerprint, $skuFingerprint);
        $subscription = $this->subscriptionData($payload, $store, $current, $connection, $attributes, $lineItems);
        $fulfillment = $this->fulfillmentData($payload);

        $current->fill([
            'assistant_domain_store_id' => $store?->getKey(),
            'source_shop_domain' => $sourceShopDomain,
            'shopify_order_id' => $shopifyOrderId,
            'shopify_order_number' => $orderNumber,
            'tongtu_order_number' => $tongtuOrderNumber,
            'store_name' => $isAssigned ? $store->shop_name : '未匹配店铺',
            'operator_name' => $isAssigned ? $store->user_name : null,
            'group_names' => $isAssigned ? AssistantDomainStore::displayMultiValue($store->team) : null,
            'staff_status' => $isAssigned ? '正常' : '—',
            'buyer_name' => $this->incoming($this->buyerName($payload), $current->buyer_name),
            'buyer_email' => $this->incoming($payload['email'] ?? Arr::get($payload, 'customer.email'), $current->buyer_email),
            'buyer_phone' => $this->incoming($payload['phone'] ?? Arr::get($payload, 'shippingAddress.phone') ?? Arr::get($payload, 'customer.phone'), $current->buyer_phone),
            'is_add_on' => in_array('postupsell', $shopifyTags, true) ? '已加购' : '未加购',
            'subscription_type' => $subscription['type'],
            'subscription_origin_order_id' => $subscription['origin_order_id'],
            'subscription_status' => $subscription['status'],
            'subscription_link_source' => $subscription['source'],
            'subscription_link_status' => $subscription['link_status'],
            'payment_status' => $this->paymentStatus((string) ($payload['displayFinancialStatus'] ?? '')),
            'payment_method' => $this->incoming($paymentMethod, $current->payment_method),
            'payment_gateway' => $this->incoming($paymentGateway, $current->payment_gateway),
            'paid_amount_usd' => $this->money($payload, 'currentTotalPriceSet'),
            'total_shipping_usd' => $this->money($payload, 'currentShippingPriceSet'),
            'paid_at' => $this->dateTime($paidTransaction['processedAt'] ?? $payload['processedAt'] ?? null),
            'channel_refund_amount_usd' => $refundData['channel_amount'],
            'shopify_refund_amount_usd' => $refundData['shopify_amount'],
            'refunded_at' => $refundData['refunded_at'],
            'refund_reason' => $refundData['reason'],
            'collection_account' => $this->incoming($attributes['yx_payment_alias'] ?? null, $current->collection_account),
            'collection_account_email' => $this->incoming($attributes['yx_paypal_merchant_email'] ?? null, $current->collection_account_email),
            'transaction_id' => $this->incoming($attributes['yx_order_id'] ?? ($paidTransaction['id'] ?? null), $current->transaction_id),
            'bill_number' => $this->incoming($attributes['yx_payment_gateway_txn_id'] ?? null, $current->bill_number),
            'destination' => $this->incoming($this->destination($payload['shippingAddress'] ?? []), $current->destination),
            'shipping_company' => array_key_exists('fulfillments', $payload) ? $fulfillment['companies'] : $current->shipping_company,
            'tracking_number' => array_key_exists('fulfillments', $payload) ? $fulfillment['numbers'] : $current->tracking_number,
            // Shopify 只负责同步物流单号；真实物流状态必须由 51Tracking 回写，不能被订单重拉覆盖。
            'logistics_status' => $current->logistics_status ?: LogisticsOrder::LOGISTICS_PENDING,
            'fulfillment_payload' => json_encode($fulfillment['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'first_fulfilled_at' => $current->first_fulfilled_at ?: $fulfillment['first_fulfilled_at'],
            'order_created_at' => $this->dateTime($payload['createdAt'] ?? null),
            'data_status' => $skuChanged && !$missingSku ? LogisticsOrder::STATUS_PENDING_TONGTU : $dataStatus,
            'sku_exception_reason' => $skuChanged && !$missingSku ? null : $skuExceptionReason,
            'sku_fingerprint' => $skuFingerprint,
            'sku_exception_fingerprint' => $skuChanged ? null : $current->sku_exception_fingerprint,
            'shopify_customer_id' => $this->legacyId(Arr::get($payload, 'customer.id')),
            'shopify_customer_order_count' => is_numeric(Arr::get($payload, 'customer.numberOfOrders'))
                ? (int) Arr::get($payload, 'customer.numberOfOrders')
                : null,
            'time_zone' => null,
            'shopify_checkout_id' => $this->incoming($attributes['yx_checkout_id'] ?? null, $current->shopify_checkout_id),
            'shopify_cart_token' => $this->incoming($attributes['yx_shopify_cart_token'] ?? null, $current->shopify_cart_token),
            'payment_gateway_order_id' => $this->incoming($attributes['yx_payment_gateway_order_id'] ?? null, $current->payment_gateway_order_id),
            'paypal_merchant_id' => $this->incoming($attributes['yx_paypal_merchant_id'] ?? null, $current->paypal_merchant_id),
            'page_source' => $this->incoming($attributes['yx_page_source'] ?? null, $current->page_source),
            'shopify_payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        $trackingNumberChanged = $current->isDirty('tracking_number');
        $this->profitCalculator->recalculate($current);
        $current->save();
        $this->paypalAssociations->reconcileOrder($current);
        $this->items->syncFromShopifyPayload($current, $payload);
        $this->automation->scheduleForOrder($current);
        if ($isNew || $trackingNumberChanged) {
            $this->tracking->scheduleForOrder($current);
        }

        return [
            'state' => $isNew ? 'created' : 'updated',
            'order' => $current,
            'shopify_sku_missing' => $missingSku,
            'item_count' => $itemCount,
            'missing_sku_count' => $lineItems === [] ? 0 : $missingSkuCount,
        ];
    }

    private function existingOrder(?AssistantDomainStore $store, ?string $sourceShopDomain, string $shopifyOrderId): ?LogisticsOrder
    {
        if ($sourceShopDomain !== null) {
            $bySource = LogisticsOrder::query()
                ->where('source_shop_domain', $sourceShopDomain)
                ->where('shopify_order_id', $shopifyOrderId)
                ->first();
            if ($bySource) {
                return $bySource;
            }
        }

        return $store
            ? LogisticsOrder::query()
                ->where('assistant_domain_store_id', $store->getKey())
                ->where('shopify_order_id', $shopifyOrderId)
                ->first()
            : null;
    }

    private function dataStatus(LogisticsOrder $current, bool $isAssigned, bool $hasTongtuOrderNumber, bool $missingSku): string
    {
        if (!$isAssigned) {
            return LogisticsOrder::STATUS_STORE_UNMATCHED;
        }
        if (!$hasTongtuOrderNumber) {
            return LogisticsOrder::STATUS_STORE_CONFIG_EXCEPTION;
        }
        if ($missingSku) {
            return LogisticsOrder::STATUS_SKU_EXCEPTION;
        }

        return in_array($current->data_status, [
            LogisticsOrder::STATUS_NORMAL,
            LogisticsOrder::STATUS_TONGTU_NOT_FOUND,
            LogisticsOrder::STATUS_TONGTU_FAILED,
            LogisticsOrder::STATUS_SKU_EXCEPTION,
        ], true)
            ? $current->data_status
            : LogisticsOrder::STATUS_PENDING_TONGTU;
    }

    /** @return array<string, string> */
    private function attributes(array $attributes): array
    {
        $mapped = [];
        foreach ($attributes as $attribute) {
            $key = trim((string) ($attribute['key'] ?? ''));
            if ($key !== '') {
                $mapped[$key] = (string) ($attribute['value'] ?? '');
            }
        }

        return $mapped;
    }

    /** @return array{shopify_amount:?string, channel_amount:?string, refunded_at:?Carbon, reason:?string} */
    private function refundData(array $refunds): array
    {
        $shopifyAmount = 0.0;
        $channelAmount = 0.0;
        $refundedAt = null;
        $reason = null;

        foreach ($refunds as $refund) {
            if (!is_array($refund)) {
                continue;
            }
            $shopifyAmount += (float) Arr::get($refund, 'totalRefundedSet.shopMoney.amount', 0);
            $refundedAt = $this->dateTime($refund['processedAt'] ?? $refund['createdAt'] ?? null) ?: $refundedAt;
            $reason = $refund['note'] ?: $reason;
            foreach (($refund['transactions']['nodes'] ?? []) as $transaction) {
                if (strtoupper((string) ($transaction['status'] ?? '')) === 'SUCCESS') {
                    $channelAmount += (float) Arr::get($transaction, 'amountSet.shopMoney.amount', 0);
                }
            }
        }

        return [
            'shopify_amount' => $shopifyAmount > 0 ? number_format($shopifyAmount, 4, '.', '') : null,
            'channel_amount' => $channelAmount > 0 ? number_format($channelAmount, 4, '.', '') : null,
            'refunded_at' => $refundedAt,
            'reason' => $reason,
        ];
    }

    /** @return array<string, mixed> */
    private function successfulPayment(array $transactions): array
    {
        foreach ($transactions as $transaction) {
            if (strtoupper((string) ($transaction['status'] ?? '')) === 'SUCCESS'
                && in_array(strtoupper((string) ($transaction['kind'] ?? '')), ['SALE', 'CAPTURE'], true)) {
                return $transaction;
            }
        }

        return [];
    }

    private function buyerName(array $payload): ?string
    {
        $name = trim(implode(' ', array_filter([
            Arr::get($payload, 'customer.firstName'),
            Arr::get($payload, 'customer.lastName'),
        ])));

        return $name !== '' ? $name : Arr::get($payload, 'shippingAddress.name');
    }

    private function destination(array $address): ?string
    {
        $parts = array_filter([
            $address['country'] ?? null,
            $address['province'] ?? null,
            $address['city'] ?? null,
        ], static fn ($value): bool => filled($value));

        return $parts === [] ? null : implode(' / ', $parts);
    }

    private function paymentStatus(string $status): ?string
    {
        return match (strtoupper($status)) {
            'PAID' => '已付款',
            'PENDING' => '待付款',
            'PARTIALLY_REFUNDED' => '部分退款',
            'REFUNDED' => '已退款',
            'PARTIALLY_PAID' => '部分付款',
            default => $status !== '' ? $status : null,
        };
    }

    private function money(array $payload, string $field): ?string
    {
        $amount = Arr::get($payload, $field.'.shopMoney.amount');

        return $amount === null || $amount === '' ? null : (string) $amount;
    }

    private function dateTime(?string $value): ?Carbon
    {
        return filled($value) ? Carbon::parse($value) : null;
    }

    private function firstAttribute(array $attributes, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (filled($attributes[$key] ?? null)) {
                return $attributes[$key];
            }
        }

        return null;
    }

    private function hasSubscription(array $lineItems): bool
    {
        foreach ($lineItems as $lineItem) {
            if (filled($lineItem['sellingPlan']['name'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    private function isPaidOrder(array $payload, ?LogisticsOrder $existing): bool
    {
        if ($existing?->paid_at) {
            return true;
        }

        $status = strtoupper(trim((string) ($payload['displayFinancialStatus'] ?? '')));
        if ($status === 'PAID') {
            return true;
        }
        if (!in_array($status, ['PARTIALLY_REFUNDED', 'REFUNDED'], true)) {
            return false;
        }

        $transactions = is_array($payload['transactions'] ?? null) ? $payload['transactions'] : [];

        return $this->successfulPayment($transactions) !== [];
    }

    /** @param list<array<string, mixed>> $lineItems */
    private function skuFingerprint(array $lineItems): string
    {
        $parts = [];
        foreach ($lineItems as $item) {
            $sku = mb_strtolower(trim((string) ($item['sku'] ?? '')));
            $variant = mb_strtolower(trim((string) Arr::get($item, 'variant.id', '')));
            $quantity = is_numeric($item['quantity'] ?? null) ? number_format((float) $item['quantity'], 4, '.', '') : '0.0000';
            $parts[] = ($sku !== '' ? $sku : 'variant:'.$variant).'@'.$quantity;
        }
        sort($parts, SORT_STRING);

        return hash('sha256', implode('|', $parts));
    }

    /** @return array{status:string,companies:?string,numbers:?string,first_fulfilled_at:?Carbon,payload:list<array<string,mixed>>} */
    private function fulfillmentData(array $payload): array
    {
        $nodes = Arr::get($payload, 'fulfillments.nodes', $payload['fulfillments'] ?? []);
        $nodes = is_array($nodes) ? array_values(array_filter($nodes, 'is_array')) : [];
        $companies = [];
        $numbers = [];
        $active = [];
        $firstFulfilledAt = null;
        $hasException = false;

        foreach ($nodes as $node) {
            $status = strtoupper((string) ($node['status'] ?? ''));
            $displayStatus = strtoupper((string) ($node['displayStatus'] ?? $node['shipment_status'] ?? ''));
            if (in_array($status, ['CANCELLED', 'CANCELED'], true)) {
                continue;
            }
            $active[] = $node;
            $createdAt = $this->dateTime($node['createdAt'] ?? $node['created_at'] ?? null);
            if ($createdAt && (!$firstFulfilledAt || $createdAt->lessThan($firstFulfilledAt))) {
                $firstFulfilledAt = $createdAt;
            }
            if (in_array($status, ['FAILURE', 'ERROR'], true)
                || in_array($displayStatus, ['FAILURE', 'FAILED', 'NOT_DELIVERED', 'CANCELLED', 'CANCELED'], true)) {
                $hasException = true;
            }

            $trackingRows = $node['trackingInfo'] ?? [];
            if (!is_array($trackingRows)) {
                $trackingRows = [];
            }
            if (isset($node['tracking_number']) || isset($node['tracking_company'])) {
                $trackingRows[] = [
                    'number' => $node['tracking_number'] ?? null,
                    'company' => $node['tracking_company'] ?? null,
                    'url' => $node['tracking_url'] ?? null,
                ];
            }
            foreach ($trackingRows as $tracking) {
                if (!is_array($tracking) || blank($tracking['number'] ?? null)) {
                    continue;
                }
                $numbers[] = trim((string) $tracking['number']);
                $companies[] = trim((string) ($tracking['company'] ?? '')) ?: 'other';
            }
        }

        $status = LogisticsOrder::LOGISTICS_PENDING;
        if ($active !== []) {
            $allDelivered = collect($active)->every(static function (array $node): bool {
                return filled($node['deliveredAt'] ?? null)
                    || strtoupper((string) ($node['displayStatus'] ?? $node['shipment_status'] ?? '')) === 'DELIVERED';
            });
            $status = $hasException
                ? LogisticsOrder::LOGISTICS_EXCEPTION
                : ($allDelivered ? LogisticsOrder::LOGISTICS_DELIVERED : LogisticsOrder::LOGISTICS_SHIPPED);
        }

        return [
            'status' => $status,
            'companies' => $companies === [] ? null : implode("\n", $companies),
            'numbers' => $numbers === [] ? null : implode("\n", $numbers),
            'first_fulfilled_at' => $firstFulfilledAt,
            'payload' => $nodes,
        ];
    }

    /** @param array<string,string> $attributes
     * @param list<array<string,mixed>> $lineItems
     * @return array{type:string,status:string,origin_order_id:?string,source:?string,link_status:?string}
     */
    private function subscriptionData(
        array $payload,
        ?AssistantDomainStore $store,
        LogisticsOrder $current,
        ?LogisticsShopifyConnection $connection,
        array $attributes,
        array $lineItems,
    ): array {
        $tags = collect(is_array($payload['tags'] ?? null) ? $payload['tags'] : [])
            ->map(static fn ($tag): string => mb_strtolower(trim((string) $tag)))
            ->filter()
            ->values();
        $first = $tags->contains('subscription first order');
        $recurring = $tags->contains('ouyicart subscription recurring order');
        $subscribed = $first || $recurring || $tags->contains('subscription') || $this->hasSubscription($lineItems);

        if (!$subscribed) {
            return ['type' => '无订阅', 'status' => '未订阅', 'origin_order_id' => null, 'source' => null, 'link_status' => null];
        }
        if ($first) {
            return [
                'type' => '订阅首次购买',
                'status' => '已订阅',
                'origin_order_id' => null,
                'source' => 'Shopify标签',
                'link_status' => '已确认',
            ];
        }
        if (!$recurring) {
            return [
                'type' => '订阅',
                'status' => '已订阅',
                'origin_order_id' => $this->firstAttribute($attributes, ['subscription_origin_order_id', 'yx_subscription_origin_order_id']),
                'source' => 'Shopify订阅计划',
                'link_status' => null,
            ];
        }

        $candidates = $this->localSubscriptionCandidates($payload, $store, $current);
        $source = '本地订单推断';
        if ($candidates === [] && $connection) {
            $candidates = $this->remoteSubscriptionCandidates($payload, $connection);
            $source = 'Shopify客户历史推断';
        }
        $sameProduct = $this->subscriptionProductKey($lineItems);
        $matching = array_values(array_filter($candidates, fn (array $candidate): bool => $this->subscriptionProductKey($candidate['line_items'] ?? []) === $sameProduct));
        $origin = collect($matching)->first(static fn (array $candidate): bool => in_array('subscription first order', $candidate['tags'] ?? [], true));
        $origin ??= $matching[0] ?? null;
        $recurringCount = max(1, count(array_filter($matching, static fn (array $candidate): bool => in_array('ouyicart subscription recurring order', $candidate['tags'] ?? [], true))) + 1);

        return [
            'type' => '订阅复购'.$recurringCount.'次',
            'status' => '已订阅',
            'origin_order_id' => $origin['yx_order_id'] ?? $origin['legacy_id'] ?? $origin['order_number'] ?? null,
            'source' => $origin ? $source : null,
            'link_status' => $origin ? '推断关联' : '待检核',
        ];
    }

    /** @return list<array<string,mixed>> */
    private function localSubscriptionCandidates(array $payload, ?AssistantDomainStore $store, LogisticsOrder $current): array
    {
        if (!$store) {
            return [];
        }
        $customerId = $this->legacyId(Arr::get($payload, 'customer.id'));
        $email = trim((string) ($payload['email'] ?? Arr::get($payload, 'customer.email', '')));
        $phone = trim((string) ($payload['phone'] ?? Arr::get($payload, 'customer.phone', '')));
        if ($customerId === null && $email === '' && $phone === '') {
            return [];
        }
        $createdAt = $this->dateTime($payload['createdAt'] ?? null);
        $query = LogisticsOrder::query()->where('assistant_domain_store_id', $store->getKey());
        $query->where(function ($identity) use ($customerId, $email, $phone): void {
            if ($customerId !== null) {
                $identity->orWhere('shopify_customer_id', $customerId);
            }
            if ($email !== '') {
                $identity->orWhere('buyer_email', $email);
            }
            if ($phone !== '') {
                $identity->orWhere('buyer_phone', $phone);
            }
        });
        if ($createdAt) {
            $query->where('order_created_at', '<', $createdAt);
        }
        if ($current->exists) {
            $query->where($current->getKeyName(), '!=', $current->getKey());
        }

        return $query->orderBy('order_created_at')->limit(100)->get()->map(function (LogisticsOrder $order): array {
            $payload = json_decode((string) $order->shopify_payload, true);
            $attributes = $this->attributes(is_array($payload) ? ($payload['customAttributes'] ?? []) : []);

            return [
                'legacy_id' => $order->shopify_order_id,
                'order_number' => $order->shopify_order_number,
                'yx_order_id' => $attributes['yx_order_id'] ?? $order->transaction_id,
                'tags' => $this->normalisedTags(is_array($payload) ? ($payload['tags'] ?? []) : []),
                'line_items' => is_array($payload) ? (Arr::get($payload, 'lineItems.nodes', [])) : [],
            ];
        })->all();
    }

    /** @return list<array<string,mixed>> */
    private function remoteSubscriptionCandidates(array $payload, LogisticsShopifyConnection $connection): array
    {
        $customerId = $this->legacyId(Arr::get($payload, 'customer.id'));
        if ($customerId === null) {
            return [];
        }
        $createdAfter = now()->subDays(60)->utc()->format('Y-m-d\\TH:i:s\\Z');
        $createdBefore = $this->dateTime($payload['createdAt'] ?? null)?->utc()->format('Y-m-d\\TH:i:s\\Z');
        try {
            $body = $this->graphql($connection, $this->subscriptionHistoryQuery(), [
                'query' => 'customer_id:'.$customerId.' created_at:>='.$createdAfter
                    .($createdBefore ? ' created_at:<='.$createdBefore : ''),
            ]);
        } catch (\Throwable) {
            return [];
        }

        $currentId = (string) ($payload['legacyResourceId'] ?? '');

        return collect(Arr::get($body, 'data.orders.nodes', []))
            ->filter(static fn ($item): bool => is_array($item))
            ->reject(static fn (array $order): bool => (string) ($order['legacyResourceId'] ?? '') === $currentId)
            ->map(function (array $order): array {
            $attributes = $this->attributes($order['customAttributes'] ?? []);

            return [
                'legacy_id' => (string) ($order['legacyResourceId'] ?? ''),
                'order_number' => preg_replace('/^#/', '', (string) ($order['name'] ?? '')),
                'yx_order_id' => $attributes['yx_order_id'] ?? null,
                'tags' => $this->normalisedTags($order['tags'] ?? []),
                'line_items' => Arr::get($order, 'lineItems.nodes', []),
            ];
            })->all();
    }

    /** @param list<array<string,mixed>> $items */
    private function subscriptionProductKey(array $items): string
    {
        $parts = [];
        foreach ($items as $item) {
            $sku = mb_strtolower(trim((string) ($item['sku'] ?? '')));
            $variant = mb_strtolower(trim((string) Arr::get($item, 'variant.id', '')));
            $parts[] = $sku !== '' ? $sku : 'variant:'.$variant;
        }
        sort($parts, SORT_STRING);

        return implode('|', $parts);
    }

    /** @return list<string> */
    private function normalisedTags(mixed $tags): array
    {
        return collect(is_array($tags) ? $tags : [])->map(static fn ($tag): string => mb_strtolower(trim((string) $tag)))->filter()->values()->all();
    }

    private function legacyId(mixed $gid): ?string
    {
        $value = trim((string) $gid);
        if ($value === '') {
            return null;
        }

        return Str::contains($value, '/') ? Str::afterLast($value, '/') : $value;
    }

    private function incoming(mixed $incoming, mixed $existing): mixed
    {
        return filled($incoming) ? $incoming : $existing;
    }

    private function shopDomain(AssistantDomainStore $store): string
    {
        $url = trim((string) $store->shop_login_url);
        $parseableUrl = Str::startsWith(Str::lower($url), ['http://', 'https://'])
            ? $url
            : 'https://'.$url;
        $host = $this->normaliseDomain((string) parse_url($parseableUrl, PHP_URL_HOST));

        if ($host === null || !preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/', $host)) {
            throw new RuntimeException('店铺登入网址必须是有效的 *.myshopify.com 地址。');
        }

        return $host;
    }

    private function assertCredentials(AssistantDomainStore $store): void
    {
        if (blank($store->shopify_client_id) || blank($store->shopify_client_secret)) {
            throw new RuntimeException('请先在助理系统填写此店铺的 Shopify Client ID 和 Client Secret。');
        }
    }

    private function isActiveShopifyStoreForDomain(?AssistantDomainStore $store, string $shopDomain): bool
    {
        if (!$store instanceof AssistantDomainStore
            || $store->site_builder !== 'shopify'
            || $store->shop_status !== AssistantDomainStore::SHOP_STATUS_IN_USE) {
            return false;
        }

        try {
            return $this->shopDomain($store) === $this->normaliseDomain($shopDomain);
        } catch (RuntimeException) {
            return false;
        }
    }

    /** @return array<string, mixed> */
    private function graphql(LogisticsShopifyConnection $connection, string $query, array $variables = []): array
    {
        $payload = ['query' => $query];
        if ($variables !== []) {
            $payload['variables'] = $variables;
        }

        try {
            $response = Http::acceptJson()
                ->timeout(60)
                ->withHeaders(['X-Shopify-Access-Token' => (string) $connection->access_token])
                ->post('https://'.$connection->shop_domain.'/admin/api/'.config('logistics.shopify.api_version').'/graphql.json', $payload);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('无法连接 Shopify：'.$exception->getMessage());
        }

        if (!$response->successful()) {
            throw new RuntimeException('Shopify API HTTP '.$response->status().': '.Str::limit($response->body(), 500));
        }
        $body = $response->json();
        if (!is_array($body)) {
            throw new RuntimeException('Shopify 返回了无效 JSON。');
        }
        if (!empty($body['errors'])) {
            throw new RuntimeException('Shopify GraphQL 错误：'.Str::limit(json_encode($body['errors'], JSON_UNESCAPED_UNICODE), 500));
        }

        return $body;
    }

    /** @return array<string, mixed>|null */
    private function fetchOrder(LogisticsShopifyConnection $connection, string $orderId): ?array
    {
        $gid = Str::startsWith($orderId, 'gid://') ? $orderId : 'gid://shopify/Order/'.$orderId;
        $body = $this->graphql($connection, $this->singleOrderQuery(), ['id' => $gid]);
        $order = Arr::get($body, 'data.order');

        return is_array($order) ? $order : null;
    }

    private function webhookOrderId(array $payload, ?string $topic): string
    {
        $candidates = [
            $payload['order_id'] ?? null,
            Arr::get($payload, 'order.id'),
            Arr::get($payload, 'refund.order_id'),
            Arr::get($payload, 'fulfillment.order_id'),
            $payload['admin_graphql_api_order_id'] ?? null,
        ];
        if ($topic === null || Str::startsWith($topic, 'orders/')) {
            array_unshift($candidates, $payload['id'] ?? null);
        }

        foreach ($candidates as $candidate) {
            if (filled($candidate)) {
                return (string) $candidate;
            }
        }

        return '';
    }

    /** @return array<string, mixed>|null */
    private function normaliseWebhookOrder(array $payload, string $orderId): ?array
    {
        $items = $payload['line_items'] ?? [];
        if (!is_array($items) || $items === []) {
            return null;
        }

        $money = static function ($value): array {
            return ['shopMoney' => ['amount' => (string) ($value ?? 0), 'currencyCode' => 'USD']];
        };
        $attributes = array_map(static fn (array $attribute): array => [
            'key' => $attribute['name'] ?? '',
            'value' => $attribute['value'] ?? '',
        ], is_array($payload['note_attributes'] ?? null) ? $payload['note_attributes'] : []);

        return [
            'legacyResourceId' => $orderId,
            'id' => 'gid://shopify/Order/'.$orderId,
            'name' => $payload['name'] ?? null,
            'createdAt' => $payload['created_at'] ?? null,
            'processedAt' => $payload['processed_at'] ?? null,
            'displayFinancialStatus' => $payload['financial_status'] ?? null,
            'displayFulfillmentStatus' => $payload['fulfillment_status'] ?? null,
            'tags' => is_array($payload['tags'] ?? null)
                ? $payload['tags']
                : array_values(array_filter(array_map('trim', explode(',', (string) ($payload['tags'] ?? ''))))),
            'email' => $payload['email'] ?? null,
            'phone' => $payload['phone'] ?? null,
            'currentTotalPriceSet' => $money($payload['current_total_price'] ?? $payload['total_price'] ?? null),
            'currentShippingPriceSet' => $money(Arr::get($payload, 'total_shipping_price_set.shop_money.amount') ?? $payload['total_shipping_price'] ?? null),
            'customer' => [
                'id' => filled(Arr::get($payload, 'customer.id')) ? 'gid://shopify/Customer/'.Arr::get($payload, 'customer.id') : null,
                'numberOfOrders' => Arr::get($payload, 'customer.orders_count'),
                'firstName' => Arr::get($payload, 'customer.first_name'),
                'lastName' => Arr::get($payload, 'customer.last_name'),
                'email' => Arr::get($payload, 'customer.email'),
                'phone' => Arr::get($payload, 'customer.phone'),
            ],
            'shippingAddress' => [
                'name' => Arr::get($payload, 'shipping_address.name'),
                'phone' => Arr::get($payload, 'shipping_address.phone'),
                'country' => Arr::get($payload, 'shipping_address.country'),
                'province' => Arr::get($payload, 'shipping_address.province'),
                'city' => Arr::get($payload, 'shipping_address.city'),
            ],
            'customAttributes' => $attributes,
            'lineItems' => ['nodes' => array_map(static function (array $item, int $position) use ($money, $orderId): array {
                $lineItemId = $item['id'] ?? null;
                $productId = $item['product_id'] ?? null;
                $variantId = $item['variant_id'] ?? null;
                $imageUrl = Arr::get($item, 'image.src');

                return [
                    'id' => $lineItemId ? 'gid://shopify/LineItem/'.$lineItemId : 'webhook-'.$orderId.'-'.$position,
                    'sku' => $item['sku'] ?? null,
                    'quantity' => $item['quantity'] ?? null,
                    'title' => $item['title'] ?? null,
                    'variantTitle' => $item['variant_title'] ?? null,
                    'discountedUnitPriceSet' => $money($item['price'] ?? null),
                    'originalUnitPriceSet' => $money($item['price'] ?? null),
                    'product' => [
                        'id' => $productId ? 'gid://shopify/Product/'.$productId : null,
                        'title' => $item['title'] ?? null,
                        'featuredImage' => ['url' => $imageUrl],
                    ],
                    'variant' => [
                        'id' => $variantId ? 'gid://shopify/ProductVariant/'.$variantId : null,
                        'title' => $item['variant_title'] ?? null,
                        'image' => ['url' => $imageUrl],
                        'product' => [
                            'id' => $productId ? 'gid://shopify/Product/'.$productId : null,
                            'title' => $item['title'] ?? null,
                            'featuredImage' => ['url' => $imageUrl],
                        ],
                    ],
                    'sellingPlan' => null,
                ];
            }, $items, array_keys($items))],
            'transactions' => [],
            'refunds' => [],
            'fulfillments' => ['nodes' => array_map(static function (array $fulfillment): array {
                $tracking = [];
                if (filled($fulfillment['tracking_number'] ?? null)) {
                    $tracking[] = [
                        'company' => $fulfillment['tracking_company'] ?? null,
                        'number' => $fulfillment['tracking_number'] ?? null,
                        'url' => $fulfillment['tracking_url'] ?? null,
                    ];
                }

                return [
                    'id' => filled($fulfillment['id'] ?? null) ? 'gid://shopify/Fulfillment/'.$fulfillment['id'] : null,
                    'status' => $fulfillment['status'] ?? null,
                    'displayStatus' => $fulfillment['shipment_status'] ?? null,
                    'createdAt' => $fulfillment['created_at'] ?? null,
                    'updatedAt' => $fulfillment['updated_at'] ?? null,
                    'inTransitAt' => null,
                    'deliveredAt' => null,
                    'trackingInfo' => $tracking,
                ];
            }, is_array($payload['fulfillments'] ?? null) ? $payload['fulfillments'] : [])],
        ];
    }

    private function normaliseDomain(?string $domain): ?string
    {
        $domain = strtolower(trim((string) $domain));
        $domain = preg_replace('#^https?://#', '', $domain) ?: '';
        $domain = trim(explode('/', $domain)[0]);

        return $domain !== '' ? $domain : null;
    }

    private function tokenFailed(LogisticsShopifyConnection $connection, string $shopDomain, string $message, ?AdminUser $actor): void
    {
        $connection->fill([
            'shop_domain' => $shopDomain,
            'status' => LogisticsShopifyConnection::STATUS_FAILED,
            'last_sync_status' => 'failed',
            'last_sync_message' => Str::limit($message, 1000),
        ])->save();
        $this->log($connection, null, 'shopify', 'token', 'failed', $message, $actor);
    }

    private function webhookFailed(LogisticsShopifyConnection $connection, string $message, ?AdminUser $actor): void
    {
        $connection->fill([
            'webhook_status' => 'failed',
            'webhook_last_error' => Str::limit($message, 1000),
        ])->save();
        $this->log($connection, null, 'shopify', 'webhook_subscribe', 'failed', $message, $actor);
    }

    private function log(
        LogisticsShopifyConnection $connection,
        ?LogisticsOrder $order,
        string $source,
        string $action,
        string $status,
        ?string $message,
        ?AdminUser $actor,
        ?array $context = null,
    ): void {
        LogisticsSyncLog::query()->create([
            'shopify_connection_id' => $connection->getKey(),
            'logistics_order_id' => $order?->getKey(),
            'source' => $source,
            'action' => $action,
            'status' => $status,
            'message' => $message,
            'context' => $context ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'actor_id' => $actor?->getKey(),
            'actor_name' => $actor?->name,
        ]);
    }

    private function ordersQuery(): string
    {
        return <<<'GRAPHQL'
query Orders($after: String, $query: String!) {
  orders(first: 50, after: $after, query: $query, sortKey: UPDATED_AT) {
    pageInfo { hasNextPage endCursor }
    nodes {
      id legacyResourceId name createdAt processedAt displayFinancialStatus displayFulfillmentStatus tags email phone
      currentTotalPriceSet { shopMoney { amount currencyCode } }
      currentShippingPriceSet { shopMoney { amount currencyCode } }
      customer { id numberOfOrders firstName lastName email phone }
      shippingAddress { name phone country province city }
      customAttributes { key value }
      lineItems(first: 250) {
        nodes {
          id sku quantity title
          discountedUnitPriceSet { shopMoney { amount currencyCode } }
          originalUnitPriceSet { shopMoney { amount currencyCode } }
          variant {
            id title image { url }
            product { id title featuredImage { url } }
          }
          sellingPlan { name }
        }
      }
      transactions { id kind status gateway processedAt amountSet { shopMoney { amount currencyCode } } }
      refunds {
        id createdAt processedAt note totalRefundedSet { shopMoney { amount currencyCode } }
        transactions(first: 50) { nodes { id kind status gateway processedAt amountSet { shopMoney { amount currencyCode } } } }
      }
      fulfillments(first: 50) {
        id status displayStatus createdAt updatedAt inTransitAt deliveredAt
        trackingInfo { company number url }
      }
    }
  }
}
GRAPHQL;
    }

    private function singleOrderQuery(): string
    {
        return <<<'GRAPHQL'
query Order($id: ID!) {
  order(id: $id) {
    id legacyResourceId name createdAt processedAt displayFinancialStatus displayFulfillmentStatus tags email phone
    currentTotalPriceSet { shopMoney { amount currencyCode } }
    currentShippingPriceSet { shopMoney { amount currencyCode } }
    customer { id numberOfOrders firstName lastName email phone }
    shippingAddress { name phone country province city }
    customAttributes { key value }
    lineItems(first: 250) {
      nodes {
        id sku quantity title
        discountedUnitPriceSet { shopMoney { amount currencyCode } }
        originalUnitPriceSet { shopMoney { amount currencyCode } }
        variant {
          id title image { url }
          product { id title featuredImage { url } }
        }
        sellingPlan { name }
      }
    }
    transactions { id kind status gateway processedAt amountSet { shopMoney { amount currencyCode } } }
    refunds {
      id createdAt processedAt note totalRefundedSet { shopMoney { amount currencyCode } }
      transactions(first: 50) { nodes { id kind status gateway processedAt amountSet { shopMoney { amount currencyCode } } } }
    }
    fulfillments(first: 50) {
      id status displayStatus createdAt updatedAt inTransitAt deliveredAt
      trackingInfo { company number url }
    }
  }
}
GRAPHQL;
    }

    private function subscriptionHistoryQuery(): string
    {
        return <<<'GRAPHQL'
query SubscriptionHistory($query: String!) {
  orders(first: 100, query: $query, sortKey: CREATED_AT) {
    nodes {
      id legacyResourceId name createdAt tags
      customAttributes { key value }
      lineItems(first: 250) { nodes { id sku quantity variant { id } } }
    }
  }
}
GRAPHQL;
    }
}
