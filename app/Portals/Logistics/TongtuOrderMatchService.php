<?php

namespace App\Portals\Logistics;

use App\Models\AdminUser;
use App\Models\LogisticsOrder;
use App\Models\LogisticsOrderItem;
use App\Models\LogisticsSyncLog;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class TongtuOrderMatchService
{
    public function __construct(
        private readonly LogisticsProfitCalculator $profitCalculator,
        private readonly LogisticsOrderItemService $items,
        private readonly TongtuRequestPacer $pacer,
    )
    {
    }

    /** @param iterable<LogisticsOrder> $orders
     *  @return array{matched:int, not_found:int, failed:int, sku_exception:int, errors:array<int, string>}
     */
    public function match(iterable $orders, ?AdminUser $actor): array
    {
        $this->assertConfigured();
        $session = $this->authenticatedSession();
        $summary = ['matched' => 0, 'not_found' => 0, 'failed' => 0, 'sku_exception' => 0, 'errors' => []];

        foreach ($orders as $order) {
            $configurationError = $this->refreshTongtuOrderNumber($order);
            if ($configurationError !== null) {
                $this->log($order, 'failed', $configurationError, $actor);
                $summary['failed']++;
                $summary['errors'][(int) $order->getKey()] = $configurationError;
                continue;
            }

            try {
                $result = $this->lookupAndApplyWithTokenRefresh($order, $session, $actor !== null);
                if ($result === null) {
                    $order->fill([
                        'data_status' => LogisticsOrder::STATUS_TONGTU_NOT_FOUND,
                        'sku_exception_reason' => null,
                    ])->save();
                    $this->log($order, 'not_found', '通途未找到订单。', $actor);
                    $summary['not_found']++;
                    continue;
                }

                $summary[$order->data_status === LogisticsOrder::STATUS_SKU_EXCEPTION ? 'sku_exception' : 'matched']++;
                $this->log($order, 'success', '通途订单匹配完成。', $actor, $result);
            } catch (\Throwable $exception) {
                $message = Str::limit($exception->getMessage(), 1000);
                $order->fill(['data_status' => LogisticsOrder::STATUS_TONGTU_FAILED])->save();
                $this->log($order, 'failed', $message, $actor);
                $summary['failed']++;
                $summary['errors'][(int) $order->getKey()] = $message;
            }
        }

        return $summary;
    }

    private function refreshTongtuOrderNumber(LogisticsOrder $order): ?string
    {
        $store = $order->store()->first();
        if (!$store) {
            $order->forceFill([
                'tongtu_order_number' => null,
                'data_status' => LogisticsOrder::STATUS_STORE_UNMATCHED,
            ])->save();

            return '订单尚未归属 Shopify 店铺，无法生成通途订单号。';
        }

        $tongtuOrderNumber = $store->makeTongtuOrderNumber($order->shopify_order_number);
        if (blank($tongtuOrderNumber)) {
            $order->forceFill([
                'tongtu_order_number' => null,
                'data_status' => LogisticsOrder::STATUS_STORE_CONFIG_EXCEPTION,
            ])->save();

            return '归属店铺尚未填写通途前缀，无法生成通途订单号。';
        }

        if (!hash_equals((string) $order->tongtu_order_number, $tongtuOrderNumber)) {
            $order->forceFill(['tongtu_order_number' => $tongtuOrderNumber])->save();
        }

        return null;
    }

    /**
     * 401 时仅清除当前通途会话并重试一次，避免失效 appToken 直接导致订单匹配失败。
     *
     * @param array{app_token:string, app_secret:string, merchant_id:string} $session
     * @return array<string, mixed>|null
     */
    private function lookupAndApplyWithTokenRefresh(LogisticsOrder $order, array &$session, bool $forceCatalogueLookup): ?array
    {
        try {
            return $this->lookupAndApply($order, $session, $forceCatalogueLookup);
        } catch (\Throwable $exception) {
            if (!$this->isUnauthorized($exception)) {
                throw $exception;
            }

            Cache::forget('logistics:tongtu:session');
            $session = $this->authenticatedSession();

            return $this->lookupAndApply($order, $session, $forceCatalogueLookup);
        }
    }

    /** @param array{app_token:string, app_secret:string, merchant_id:string} $session
     *  @return array<string, mixed>|null
     */
    private function lookupAndApply(LogisticsOrder $order, array $session, bool $forceCatalogueLookup): ?array
    {
        $result = $this->lookup($order, $session);
        if ($result !== null) {
            $this->applyResult($order, $result, $session, $forceCatalogueLookup);
        }

        return $result;
    }

    /** @param array{app_token:string, app_secret:string, merchant_id:string} $session
     *  @return array<string, mixed>|null
     */
    private function lookup(LogisticsOrder $order, array $session): ?array
    {
        $shopifyOrderId = trim((string) $order->shopify_order_id);
        if ($shopifyOrderId === '') {
            throw new RuntimeException('缺少 Shopify 本订单 ID，无法查询通途订单。');
        }

        $body = $this->postJson(
            (string) config('logistics.tongtu.shopify_order_query_url'),
            $this->authParameters($session),
            [
                'merchantId' => $session['merchant_id'],
                'shopifyOrderId' => $shopifyOrderId,
                'pageNo' => 1,
                'pageSize' => 100,
            ],
        );
        $this->assertSuccess($body, 'Shopify 订单查询');

        $records = Arr::get($body, 'datas.array', []);
        if (!is_array($records) || $records === []) {
            return null;
        }

        foreach ($records as $record) {
            if (is_array($record) && trim((string) Arr::get($record, 'shopifOrderId')) === $shopifyOrderId) {
                return $record;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $data
     *  @param array{app_token:string, app_secret:string, merchant_id:string} $session
     */
    private function applyResult(LogisticsOrder $order, array $data, array $session, bool $forceCatalogueLookup): void
    {
        $payload = json_decode((string) $order->shopify_payload, true);
        if (is_array($payload)) {
            $this->items->syncFromShopifyPayload($order, $payload);
        }
        $shopifyItems = $this->shopifyItems($order);
        $skuFingerprint = $this->skuFingerprint($shopifyItems);
        if (!$forceCatalogueLookup
            && $order->data_status === LogisticsOrder::STATUS_SKU_EXCEPTION
            && filled($order->sku_exception_fingerprint)
            && hash_equals((string) $order->sku_exception_fingerprint, $skuFingerprint)) {
            $order->forceFill([
                'tongtu_payload' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ])->save();

            return;
        }
        $hasMissingShopifySku = $shopifyItems === [] || collect($shopifyItems)
            ->contains(static fn (array $item): bool => $item['sku'] === '');
        $catalogue = $this->goodsBySku(
            array_values(array_unique(array_filter(array_column($shopifyItems, 'sku')))),
            $session,
            $forceCatalogueLookup,
        );

        $tongtuSkus = [];
        $tongtuProductCodes = [];
        $productNames = [];
        $quantities = [];
        $weights = [];
        $itemResults = [];
        $totalCostCny = 0.0;
        $canCalculateCost = !$hasMissingShopifySku && $shopifyItems !== [];
        $missingInErp = [];

        foreach ($shopifyItems as $item) {
            $sku = $item['sku'];
            $quantity = $item['quantity'];
            $matchedProduct = $catalogue[$this->skuKey($sku)] ?? null;
            if (!$matchedProduct) {
                $missingInErp[] = $sku;
                $canCalculateCost = false;
                $tongtuSkus[] = '—';
                $tongtuProductCodes[] = '—';
                $productNames[] = '—';
                $quantities[] = $this->numberLine($quantity);
                $weights[] = '—';
                $itemResults[] = [
                    'source_key' => $item['source_key'],
                    'tongtu_sku' => null,
                    'tongtu_product_code' => null,
                    'tongtu_product_name' => null,
                    'tongtu_product_type' => null,
                    'product_cost_usd' => null,
                    'sale_weight_gram' => null,
                ];
                continue;
            }

            $product = $matchedProduct['product'];
            $productType = $matchedProduct['product_type'];

            $tongtuProductCode = trim((string) ($product['productCode'] ?? ''));
            $tongtuSku = trim((string) ($product['sku'] ?? $product['productSku'] ?? ''));
            $productName = trim((string) ($product['productPackingName'] ?? $product['productName'] ?? '—'));
            $tongtuSkus[] = $tongtuSku;
            $tongtuProductCodes[] = $tongtuProductCode;
            $productNames[] = $productName;
            $quantities[] = $this->numberLine($quantity);
            $details = $this->goodsDetails($product);
            $unitWeight = 0.0;
            $unitCost = 0.0;
            $hasCost = false;
            $hasCompleteCost = $details !== [];

            foreach ($details as $detail) {
                $packageQuantity = $this->numberValue($detail['goodsQuantity'] ?? null) ?? 0.0;
                $weight = $this->numberValue($detail['goodsWeight'] ?? null) ?? 0.0;
                $cost = $this->numberValue($detail['goodsAveCost'] ?? null);
                $unitWeight += $weight * $packageQuantity;
                if ($cost === null) {
                    $canCalculateCost = false;
                    $hasCompleteCost = false;
                } else {
                    $unitCost += $cost * $packageQuantity;
                    $hasCost = true;
                }
            }

            if ($details === [] || !$hasCost) {
                $canCalculateCost = false;
                $hasCompleteCost = false;
            }
            $saleWeight = $details === [] ? null : $unitWeight * $quantity;
            $weights[] = $saleWeight === null ? '—' : $this->numberLine($saleWeight);
            $totalCostCny += $unitCost * $quantity;
            $itemResults[] = [
                'source_key' => $item['source_key'],
                'tongtu_sku' => $tongtuSku !== '' ? $tongtuSku : null,
                'tongtu_product_code' => $tongtuProductCode !== '' ? $tongtuProductCode : null,
                'tongtu_product_name' => $productName === '—' ? null : $productName,
                'tongtu_product_type' => $productType,
                'product_cost_usd' => $hasCompleteCost ? ($unitCost * $quantity / max(0.0001, (float) config('logistics.profit.cny_per_usd', 6.7049))) : null,
                'sale_weight_gram' => $saleWeight,
            ];
        }

        $skuMissing = $hasMissingShopifySku || $missingInErp !== [];
        $reason = $hasMissingShopifySku
            ? 'Shopify SKU 缺失'
            : ($missingInErp !== [] ? 'ERP 未找到 SKU：'.implode('、', $missingInErp) : null);
        $exchangeRate = max(0.0001, (float) config('logistics.profit.cny_per_usd', 6.7049));

        $order->fill([
            'tongtu_sku' => $this->lines($tongtuSkus),
            'tongtu_product_code' => $this->lines($tongtuProductCodes),
            'tongtu_product_name' => $this->lines($productNames),
            'sale_quantity' => $this->lines($quantities),
            'sale_product_weight' => $this->lines($weights),
            'product_cost_usd' => $canCalculateCost ? $this->decimal($totalCostCny / $exchangeRate) : null,
            'data_status' => $skuMissing ? LogisticsOrder::STATUS_SKU_EXCEPTION : LogisticsOrder::STATUS_NORMAL,
            'sku_exception_reason' => $reason,
            'sku_fingerprint' => $skuFingerprint,
            'sku_exception_fingerprint' => $skuMissing ? $skuFingerprint : null,
            'tongtu_payload' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        $this->profitCalculator->recalculate($order);
        $order->save();
        $this->items->applyTongtuMatch($order, $itemResults);
    }

    /** @return list<array{source_key:string,sku:string,quantity:float}> */
    private function shopifyItems(LogisticsOrder $order): array
    {
        $payload = json_decode((string) $order->shopify_payload, true);
        if (!is_array($payload)) {
            return [];
        }

        $items = $payload['lineItems']['nodes'] ?? [];
        if (!is_array($items)) {
            return [];
        }

        return array_map(static fn (array $item, int $position): array => [
            'source_key' => LogisticsOrderItemService::sourceKey($item, $position),
            'sku' => trim((string) ($item['sku'] ?? '')),
            'quantity' => is_numeric($item['quantity'] ?? null) ? (float) $item['quantity'] : 0.0,
        ], $items, array_keys($items));
    }

    /** @param list<string> $skus
     *  @param array{app_token:string, app_secret:string, merchant_id:string} $session
     *  @return array<string, array{product:array<string,mixed>, product_type:string}>
     */
    private function goodsBySku(array $skus, array $session, bool $forceRefresh = false): array
    {
        if ($skus === []) {
            return [];
        }

        $products = [];
        $pending = [];
        foreach (array_values(array_unique($skus)) as $sku) {
            if (!$forceRefresh) {
                $cached = Cache::get($this->goodsCacheKey($sku));
                if (is_array($cached) && array_key_exists('found', $cached)) {
                    if (($cached['found'] ?? false)
                        && is_array($cached['product'] ?? null)
                        && in_array((string) ($cached['product_type'] ?? ''), ['0', '1', '2', '3'], true)) {
                        $products[$this->skuKey($sku)] = [
                            'product' => $cached['product'],
                            'product_type' => (string) $cached['product_type'],
                        ];
                    }
                    continue;
                }
            }

            $pending[$this->skuKey($sku)] = $sku;
        }

        if ($pending === []) {
            return $products;
        }

        $knownTypes = $this->knownProductTypes(array_values($pending));
        $attempted = [];
        $firstPass = [];
        $discoverable = [];
        foreach ($pending as $key => $sku) {
            $isKnownType = isset($knownTypes[$key]);
            $productType = $isKnownType ? $knownTypes[$key] : '2';
            $firstPass[$productType][$key] = $sku;
            $attempted[$key][$productType] = true;
            if (!$isKnownType) {
                $discoverable[$key] = true;
            }
        }

        foreach ($firstPass as $productType => $typeSkus) {
            $this->findProductsByType($typeSkus, (string) $productType, $session, $products, $pending);
        }

        foreach (['2', '0', '1', '3'] as $productType) {
            $fallback = [];
            foreach ($pending as $key => $sku) {
                if (isset($discoverable[$key]) && !isset($attempted[$key][$productType])) {
                    $fallback[$key] = $sku;
                    $attempted[$key][$productType] = true;
                }
            }
            if ($fallback !== []) {
                $this->findProductsByType($fallback, $productType, $session, $products, $pending);
            }
        }

        foreach ($pending as $sku) {
            Cache::put($this->goodsCacheKey($sku), [
                'found' => false,
                'product' => null,
                'product_type' => null,
            ], now()->addMinutes(max(1, (int) config('logistics.tongtu.goods_cache_minutes', 360))));
        }

        return $products;
    }

    /**
     * @param array<string, string> $skus SKU 标准化值 => 原始 SKU
     * @param array{app_token:string, app_secret:string, merchant_id:string} $session
     * @param array<string, array{product:array<string,mixed>, product_type:string}> $products
     * @param array<string, string> $pending
     */
    private function findProductsByType(array $skus, string $productType, array $session, array &$products, array &$pending): void
    {
        foreach (array_chunk($skus, 10, true) as $chunk) {
            $body = $this->postJson(
                (string) config('logistics.tongtu.goods_query_url'),
                $this->authParameters($session),
                [
                    'merchantId' => $session['merchant_id'],
                    'productType' => $productType,
                    'skus' => array_values($chunk),
                    'pageNo' => 1,
                    'pageSize' => 100,
                ],
            );
            $this->assertSuccess($body, '通途商品信息查询');

            $found = [];
            foreach ($this->goodsRecords($body) as $product) {
                foreach ($this->productSkuKeys($product) as $productSku) {
                    $found[$this->skuKey($productSku)] = $product;
                }
            }

            foreach ($chunk as $key => $sku) {
                $product = $found[$key] ?? null;
                if ($product === null) {
                    continue;
                }

                $products[$key] = ['product' => $product, 'product_type' => $productType];
                unset($pending[$key]);
                Cache::put($this->goodsCacheKey($sku), [
                    'found' => true,
                    'product' => $product,
                    'product_type' => $productType,
                ], now()->addMinutes(max(1, (int) config('logistics.tongtu.goods_cache_minutes', 360))));
            }
        }
    }

    /** @param list<string> $skus
     *  @return array<string, string>
     */
    private function knownProductTypes(array $skus): array
    {
        $records = LogisticsOrderItem::query()
            ->whereIn('shopify_sku', $skus)
            ->whereIn('tongtu_product_type', ['0', '1', '2', '3'])
            ->latest('updated_at')
            ->get(['shopify_sku', 'tongtu_product_type']);
        $types = [];
        foreach ($records as $record) {
            $sku = trim((string) $record->shopify_sku);
            $productType = trim((string) $record->tongtu_product_type);
            if ($sku !== '' && !isset($types[$this->skuKey($sku)])) {
                $types[$this->skuKey($sku)] = $productType;
            }
        }

        return $types;
    }

    /** @param array<string, mixed> $product
     *  @return list<string>
     */
    private function productSkuKeys(array $product): array
    {
        $keys = [];
        foreach (['productCode', 'sku', 'productSku', 'skuAlias'] as $field) {
            $value = trim((string) ($product[$field] ?? ''));
            if ($value !== '') {
                $keys[] = $value;
            }
        }
        foreach (['skuAliases', 'skuAliasList', 'productSkuAliases'] as $field) {
            $values = $product[$field] ?? [];
            if (is_string($values)) {
                $values = preg_split('/[\s,，;；]+/', $values) ?: [];
            }
            if (is_array($values)) {
                foreach ($values as $value) {
                    $value = trim((string) $value);
                    if ($value !== '') {
                        $keys[] = $value;
                    }
                }
            }
        }

        return array_values(array_unique($keys));
    }

    /** @return list<array<string, mixed>> */
    private function goodsRecords(array $body): array
    {
        $records = Arr::get($body, 'datas.array');
        if (!is_array($records)) {
            $records = Arr::get($body, 'datas');
        }

        return is_array($records) ? array_values(array_filter($records, 'is_array')) : [];
    }

    /** @return list<array<string, mixed>> */
    private function goodsDetails(array $product): array
    {
        $details = $product['goodsDetail'] ?? $product['goodsDetails'] ?? [];

        return is_array($details) ? array_values(array_filter($details, 'is_array')) : [];
    }

    private function skuKey(string $sku): string
    {
        return mb_strtolower(trim($sku));
    }

    private function goodsCacheKey(string $sku): string
    {
        return 'logistics:tongtu:goods:typed:'.sha1($this->skuKey($sku));
    }

    /** @param list<string> $values */
    private function lines(array $values): ?string
    {
        return $values === [] ? null : implode("\n", $values);
    }

    private function numberLine(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }

    private function decimal(float $value): string
    {
        return number_format(round($value, 4), 4, '.', '');
    }

    private function numberValue(mixed $value): ?float
    {
        return $value === null || $value === '' || !is_numeric($value) ? null : (float) $value;
    }

    /** @return array{app_token:string, app_secret:string, merchant_id:string} */
    private function authenticatedSession(): array
    {
        $cached = Cache::get('logistics:tongtu:session');
        if (is_array($cached)
            && filled($cached['app_token'] ?? null)
            && filled($cached['app_secret'] ?? null)
            && filled($cached['merchant_id'] ?? null)) {
            return $cached;
        }

        $appSecret = (string) config('logistics.tongtu.app_secret');
        $tokenBody = $this->getJson((string) config('logistics.tongtu.app_token_url'), [
            'accessKey' => (string) config('logistics.tongtu.app_key'),
            'secretAccessKey' => $appSecret,
        ]);
        $this->assertSuccess($tokenBody, '获取 appToken');

        $tokenData = Arr::get($tokenBody, 'datas');
        $appToken = is_array($tokenData)
            ? trim((string) ($tokenData['appToken'] ?? $tokenData['token'] ?? ''))
            : trim((string) $tokenData);
        if ($appToken === '') {
            throw new RuntimeException('通途未返回 appToken。');
        }

        $merchantId = trim((string) config('logistics.tongtu.merchant_id'));
        if ($merchantId === '') {
            $merchantId = $this->partnerOpenId($appToken, $appSecret);
        }

        $session = ['app_token' => $appToken, 'app_secret' => $appSecret, 'merchant_id' => $merchantId];
        $expiresAt = now()->addMinutes(max(1, (int) config('logistics.tongtu.app_token_cache_minutes', 50)));
        $expireTimestamp = is_array($tokenData)
            ? ($tokenData['appTokenExpireDate'] ?? null)
            : Arr::get($tokenBody, 'appTokenExpireDate');
        if (is_numeric($expireTimestamp)) {
            $candidate = Carbon::createFromTimestampMs((int) $expireTimestamp)->subMinutes(5);
            if ($candidate->isAfter(now())) {
                $expiresAt = $candidate;
            }
        }
        Cache::put(
            'logistics:tongtu:session',
            $session,
            $expiresAt,
        );

        return $session;
    }

    /** @param array{app_token:string, app_secret:string, merchant_id:string} $session
     *  @return array{app_token:string, timestamp:string, sign:string}
     */
    private function authParameters(array $session): array
    {
        return $this->signedParameters($session['app_token'], $session['app_secret']);
    }

    /** @return array{app_token:string, timestamp:string, sign:string} */
    private function signedParameters(string $appToken, string $appSecret): array
    {
        $timestamp = (string) ((int) floor(microtime(true) * 1000));
        $sign = md5('app_token'.$appToken.'timestamp'.$timestamp.$appSecret);

        return ['app_token' => $appToken, 'timestamp' => $timestamp, 'sign' => $sign];
    }

    private function partnerOpenId(string $appToken, string $appSecret): string
    {
        $body = $this->getJson(
            (string) config('logistics.tongtu.partner_list_url'),
            $this->signedParameters($appToken, $appSecret),
        );
        $this->assertSuccess($body, '查询通途合作伙伴信息');

        $data = Arr::get($body, 'datas', []);
        if (is_array($data) && is_array($data['array'] ?? null)) {
            $data = $data['array'];
        }
        $records = is_array($data) && array_key_exists('partnerOpenId', $data)
            ? [$data]
            : array_values(array_filter(is_array($data) ? $data : [], 'is_array'));
        $merchantIds = array_values(array_unique(array_filter(array_map(
            static fn (array $record): string => trim((string) ($record['partnerOpenId'] ?? '')),
            $records,
        ))));

        if ($merchantIds === []) {
            throw new RuntimeException('通途未返回 partnerOpenId，无法确定业务接口 merchantId。');
        }
        if (count($merchantIds) > 1) {
            throw new RuntimeException('通途返回多个 partnerOpenId，请在 .env 中配置 LOGISTICS_TONGTU_MERCHANT_ID 为对应值。');
        }

        return $merchantIds[0];
    }

    /** @param array<string, string> $query
     *  @return array<string, mixed>
     */
    private function getJson(string $url, array $query): array
    {
        $this->pacer->waitForTurn();

        try {
            $response = Http::acceptJson()->timeout(45)->get($url, $query);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('无法连接通途：'.$exception->getMessage());
        }

        return $this->responseJson($response, '通途 GET 请求');
    }

    /** @param array<string, string> $query
     *  @param array<string, mixed> $payload
     *  @return array<string, mixed>
     */
    private function postJson(string $url, array $query, array $payload): array
    {
        $this->pacer->waitForTurn();
        $url .= '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        try {
            $response = Http::acceptJson()->timeout(45)->post($url, $payload);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('无法连接通途：'.$exception->getMessage());
        }

        return $this->responseJson($response, '通途 POST 请求');
    }

    /** @return array<string, mixed> */
    private function responseJson(\Illuminate\Http\Client\Response $response, string $requestName): array
    {
        if (!$response->successful()) {
            throw new RuntimeException('通途请求失败（HTTP '.$response->status().'）：'.Str::limit($response->body(), 500));
        }

        $body = $response->json();
        if (!is_array($body)) {
            throw new RuntimeException($requestName.' 返回了无效 JSON。');
        }

        return $body;
    }

    /** @param array<string, mixed> $body */
    private function assertSuccess(array $body, string $action): void
    {
        if (!$this->isSuccess($body)) {
            throw new RuntimeException($action.'失败：'.(string) (Arr::get($body, 'message') ?: Arr::get($body, 'msg') ?: '未知错误'));
        }
    }

    /** @param array<string, mixed> $body */
    private function isSuccess(array $body): bool
    {
        $code = Arr::get($body, 'code');

        return $code === 0 || $code === '0' || $code === 200 || $code === '200' || Arr::get($body, 'success') === true;
    }

    private function isUnauthorized(\Throwable $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, 'HTTP 401') || str_contains($message, '未授权的请求');
    }

    private function assertConfigured(): void
    {
        foreach (['app_key', 'app_secret', 'app_token_url', 'partner_list_url', 'shopify_order_query_url', 'goods_query_url'] as $key) {
            if (blank(config('logistics.tongtu.'.$key))) {
                throw new RuntimeException('通途 '.str_replace('_', ' ', $key).' 尚未配置。');
            }
        }
    }

    /** @param array<string, mixed>|null $context */
    private function log(LogisticsOrder $order, string $status, string $message, ?AdminUser $actor, ?array $context = null): void
    {
        LogisticsSyncLog::query()->create([
            'logistics_order_id' => $order->getKey(),
            'source' => 'tongtu',
            'action' => 'match',
            'status' => $status,
            'message' => $message,
            'context' => $context ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'actor_id' => $actor?->getKey(),
            'actor_name' => $actor?->name,
        ]);
    }

    /** @param list<array{source_key:string,sku:string,quantity:float}> $items */
    private function skuFingerprint(array $items): string
    {
        $parts = array_map(static fn (array $item): string => mb_strtolower(trim($item['sku'])).'@'.number_format($item['quantity'], 4, '.', ''), $items);
        sort($parts, SORT_STRING);

        return hash('sha256', implode('|', $parts));
    }
}
