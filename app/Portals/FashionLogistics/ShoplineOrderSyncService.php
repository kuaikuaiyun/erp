<?php

namespace App\Portals\FashionLogistics;

use App\Models\AssistantDomainStore;
use App\Models\FashionLogisticsOrder;
use App\Models\FashionLogisticsSyncState;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;

class ShoplineOrderSyncService
{
    private const PLATFORM = 'shopline';

    private const API_VERSION = 'v20261201';

    /** @return array{orders:int,items:int,sync_states:int} */
    public function clearableDataCounts(): array
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $db = DB::connection($connection);
        $orders = $db->table('fashion_logistics_orders')->where('source_platform', self::PLATFORM);

        return [
            'orders' => (clone $orders)->count(),
            'items' => $db->table('fashion_logistics_order_items')
                ->whereIn('fashion_logistics_order_id', (clone $orders)->select('id'))
                ->count(),
            'sync_states' => FashionLogisticsSyncState::query()
                ->whereIn('assistant_domain_store_id', $this->shoplineStoreIds())
                ->count(),
        ];
    }

    /** @return array{orders:int,items:int,sync_states:int} */
    public function clearShoplineData(): array
    {
        $connection = config('admin.database.connection') ?: config('database.default');

        return DB::connection($connection)->transaction(function () use ($connection): array {
            $db = DB::connection($connection);
            $orders = $db->table('fashion_logistics_orders')->where('source_platform', self::PLATFORM);
            $items = $db->table('fashion_logistics_order_items')
                ->whereIn('fashion_logistics_order_id', (clone $orders)->select('id'))
                ->delete();
            $orderCount = (clone $orders)->count();
            $orders->delete();
            $syncStates = FashionLogisticsSyncState::query()
                ->whereIn('assistant_domain_store_id', $this->shoplineStoreIds())
                ->delete();

            return ['orders' => $orderCount, 'items' => $items, 'sync_states' => $syncStates];
        });
    }

    /** @return array{stores:int,success:int,failed:int,created:int,updated:int,errors:list<string>} */
    public function syncAll(?int $storeId = null, int $initialDays = 60, bool $full = false, bool $allHistory = false): array
    {
        $lock = Cache::lock('fashion-logistics:shopline-sync', 86400);
        if (! $lock->get()) {
            return ['stores' => 0, 'success' => 0, 'failed' => 0, 'created' => 0, 'updated' => 0, 'errors' => ['Shopline sync is already running.']];
        }

        try {
            return $this->syncAllUnlocked($storeId, $initialDays, $full, $allHistory);
        } finally {
            $lock->release();
        }
    }

    /** @return array{stores:int,success:int,failed:int,created:int,updated:int,errors:list<string>} */
    private function syncAllUnlocked(?int $storeId = null, int $initialDays = 60, bool $full = false, bool $allHistory = false): array
    {
        $stores = AssistantDomainStore::query()
            ->when($storeId, fn ($query) => $query->whereKey($storeId))
            ->whereRaw("LOWER(TRIM(site_builder)) = 'shopline'")
            ->where('shop_status', AssistantDomainStore::SHOP_STATUS_IN_USE)
            ->orderBy('id')
            ->get();

        $summary = ['stores' => $stores->count(), 'success' => 0, 'failed' => 0, 'created' => 0, 'updated' => 0, 'errors' => []];
        foreach ($stores as $store) {
            try {
                $result = $this->syncStore($store, $initialDays, $full, $allHistory);
                $summary['success']++;
                $summary['created'] += $result['created'];
                $summary['updated'] += $result['updated'];
            } catch (\Throwable $exception) {
                $summary['failed']++;
                $summary['errors'][] = ($store->shop_name ?: '#'.$store->getKey()).'：'.$exception->getMessage();
            }
        }

        return $summary;
    }

    /** @return array{created:int,updated:int,pages:int} */
    public function syncStore(AssistantDomainStore $store, int $initialDays = 60, bool $full = false, bool $allHistory = false): array
    {
        $this->assertStore($store);
        $state = FashionLogisticsSyncState::query()->firstOrCreate(['assistant_domain_store_id' => $store->getKey()]);
        $state->forceFill(['status' => 'syncing', 'last_started_at' => now(), 'last_error' => null])->save();

        $historyStart = Carbon::create(2000, 1, 1, 0, 0, 0, 'UTC');
        $after = $allHistory || (! $full && ! $state->last_cursor_at)
            ? $historyStart
            : (! $full
                ? $state->last_cursor_at->copy()->subMinutes(10)
                : now()->subDays(max(1, $initialDays)));
        $before = now()->addMinute();
        $latestModified = $state->last_cursor_at?->copy() ?: $after->copy();
        $created = 0;
        $updated = 0;
        $pages = 0;
        $pageInfo = null;

        try {
            for ($page = 1; $page <= 1000; $page++) {
                $result = $this->requestPage($store, $after, $before, $pageInfo);
                $pages++;
                foreach ($result['orders'] as $payload) {
                    if (! is_array($payload) || blank($payload['id'] ?? null)) {
                        continue;
                    }

                    $modified = $this->date($payload['updated_at'] ?? null);
                    if ($modified && $modified->gt($latestModified)) {
                        $latestModified = $modified;
                    }

                    $persisted = $this->persist($store, $payload);
                    $persisted['created'] ? $created++ : $updated++;
                }

                $pageInfo = $result['next_page_info'];
                if ($pageInfo === null) {
                    break;
                }
            }

            $state->forceFill([
                'status' => 'success',
                'last_succeeded_at' => now(),
                'last_cursor_at' => $latestModified,
                'last_created_count' => $created,
                'last_updated_count' => $updated,
                'last_error' => null,
            ])->save();
        } catch (\Throwable $exception) {
            $state->forceFill([
                'status' => 'failed',
                'last_created_count' => $created,
                'last_updated_count' => $updated,
                'last_error' => Str::limit($exception->getMessage(), 2000),
            ])->save();
            throw $exception;
        }

        return compact('created', 'updated', 'pages');
    }

    /** @return array{orders:list<array<string,mixed>>,next_page_info:?string} */
    private function requestPage(AssistantDomainStore $store, Carbon $after, Carbon $before, ?string $pageInfo): array
    {
        $query = $pageInfo !== null
            ? ['page_info' => $pageInfo, 'limit' => 100]
            : [
                'updated_at_min' => $after->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
                'updated_at_max' => $before->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
                'status' => 'any',
                'limit' => 100,
                'sort_condition' => 'order_at:asc,id:asc',
            ];

        try {
            $response = Http::acceptJson()
                ->withHeaders([
                    'Authorization' => trim((string) $store->shopline_app_token),
                    'Content-Type' => 'application/json; charset=utf-8',
                ])
                ->timeout(45)
                ->connectTimeout(15)
                ->get($this->endpoint($store), $query);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('无法连接 Shopline 订单接口：'.$exception->getMessage(), 0, $exception);
        }

        if ($response->status() === 429) {
            throw new RuntimeException('Shopline 接口触发频率限制（HTTP 429），请等待下一次定时同步。');
        }
        if (! $response->successful()) {
            throw new RuntimeException('Shopline 接口返回 HTTP '.$response->status().'：'.Str::limit($response->body(), 500));
        }

        $data = $response->json();
        if (! is_array($data) || ! is_array($data['orders'] ?? null)) {
            throw new RuntimeException('Shopline 订单接口返回格式异常，未找到 orders 数组。');
        }

        return [
            'orders' => array_values(array_filter($data['orders'], 'is_array')),
            'next_page_info' => $this->nextPageInfo($response),
        ];
    }

    /** @return array{created:bool,order:FashionLogisticsOrder} */
    private function persist(AssistantDomainStore $store, array $payload): array
    {
        $externalId = (string) $payload['id'];
        $billing = is_array($payload['billing_address'] ?? null) ? $payload['billing_address'] : [];
        $shipping = is_array($payload['shipping_address'] ?? null) ? $payload['shipping_address'] : [];
        $customer = is_array($payload['customer'] ?? null) ? $payload['customer'] : [];
        $connection = config('admin.database.connection') ?: config('database.default');

        return DB::connection($connection)->transaction(function () use ($store, $payload, $externalId, $billing, $shipping, $customer): array {
            $order = FashionLogisticsOrder::query()->firstOrNew([
                'assistant_domain_store_id' => $store->getKey(),
                'source_platform' => self::PLATFORM,
                'external_order_id' => $externalId,
            ]);
            $created = ! $order->exists;
            $recipient = $shipping !== [] ? $shipping : $billing;
            $customerName = $this->name($billing) ?: $this->name($customer);

            $order->fill([
                'store_name' => trim((string) $store->shop_name),
                'source_domain' => $this->handle($store).'.myshopline.com',
                'marketing_source' => $this->marketingSource($payload),
                'order_number' => (string) ($payload['name'] ?? $payload['order_number'] ?? $externalId),
                'status' => (string) ($payload['status'] ?? ''),
                'payment_status' => (string) ($payload['financial_status'] ?? ''),
                'fulfillment_status' => $this->fulfillmentStatus($payload),
                'currency' => (string) ($payload['currency'] ?? ''),
                'total_amount' => $this->amount($payload, ['current_total_price', 'total_price']),
                'shipping_amount' => $this->amount($payload, ['total_shipping_price', 'shipping_price']),
                'discount_amount' => $this->amount($payload, ['current_total_discounts', 'total_discounts']),
                'tax_amount' => $this->amount($payload, ['current_total_tax', 'total_tax']),
                'payment_method' => $this->paymentMethod($payload),
                'payment_method_title' => $this->paymentTitle($payload),
                'transaction_id' => (string) ($payload['transaction_id'] ?? $payload['payment_id'] ?? ''),
                'customer_id' => $this->safeUnsignedId($customer['id'] ?? $payload['customer_id'] ?? null),
                'customer_name' => $customerName,
                'customer_email' => (string) ($payload['email'] ?? $billing['email'] ?? $customer['email'] ?? ''),
                'customer_phone' => (string) ($billing['phone'] ?? $customer['phone'] ?? ''),
                'shipping_country' => (string) ($recipient['country'] ?? $recipient['country_code'] ?? ''),
                'shipping_state' => (string) ($recipient['province'] ?? $recipient['province_code'] ?? ''),
                'shipping_city' => (string) ($recipient['city'] ?? ''),
                'shipping_address' => $this->address($recipient),
                'customer_note' => (string) ($payload['buyer_note'] ?? $payload['note'] ?? ''),
                'order_created_at_utc' => $this->date($payload['created_at'] ?? $payload['order_at'] ?? null)?->utc(),
                'order_created_at' => $this->date($payload['created_at'] ?? $payload['order_at'] ?? null)?->setTimezone('Asia/Shanghai'),
                'order_modified_at' => $this->date($payload['updated_at'] ?? null),
                'paid_at' => $this->date($payload['paid_at'] ?? $payload['processed_at'] ?? null),
                'paid_at_utc' => $this->date($payload['paid_at'] ?? $payload['processed_at'] ?? null)?->utc(),
                'completed_at' => $this->date($payload['closed_at'] ?? $payload['completed_at'] ?? null),
                'source_payload' => $payload,
                'source_synced_at' => now(),
            ])->save();

            $order->items()->delete();
            foreach ((array) ($payload['line_items'] ?? []) as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $order->items()->create([
                    'external_item_id' => isset($item['id']) ? (string) $item['id'] : null,
                    'product_id' => $this->safeUnsignedId($item['product_id'] ?? null),
                    'variation_id' => $this->safeUnsignedId($item['variant_id'] ?? $item['variation_id'] ?? null),
                    'product_name' => (string) ($item['name'] ?? ''),
                    'sku' => (string) ($item['sku'] ?? ''),
                    'quantity' => $this->decimal($item['quantity'] ?? 0),
                    'subtotal' => $this->decimal($item['price'] ?? $item['original_price'] ?? 0) * $this->decimal($item['quantity'] ?? 0),
                    'total' => $this->decimal($item['line_price'] ?? $item['total'] ?? $item['price'] ?? 0) * ($item['line_price'] ?? $item['total'] ?? null ? 1 : $this->decimal($item['quantity'] ?? 0)),
                    'total_tax' => $this->decimal($item['total_tax'] ?? 0),
                    'image_url' => is_string($item['image_url'] ?? null) ? $item['image_url'] : null,
                    'meta_data' => $item,
                ]);
            }

            return ['created' => $created, 'order' => $order];
        });
    }

    private function assertStore(AssistantDomainStore $store): void
    {
        if (strtolower(trim((string) $store->site_builder)) !== self::PLATFORM) {
            throw new RuntimeException('该店铺不是 Shopline 建站系统。');
        }
        if ($this->handle($store) === '') {
            throw new RuntimeException('店铺未填写 Shopline 店铺 Handle。');
        }
        if (blank($store->shopline_app_token)) {
            throw new RuntimeException('店铺未填写 Shopline App Token。');
        }
    }

    private function endpoint(AssistantDomainStore $store): string
    {
        return 'https://'.$this->handle($store).'.myshopline.com/admin/openapi/'.self::API_VERSION.'/orders.json';
    }

    private function shoplineStoreIds()
    {
        return AssistantDomainStore::query()
            ->select('id')
            ->whereRaw("LOWER(TRIM(site_builder)) = 'shopline'");
    }

    private function handle(AssistantDomainStore $store): string
    {
        $handle = strtolower(trim((string) $store->shop_name));
        return preg_match('/^[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?$/', $handle) ? $handle : '';
    }

    private function nextPageInfo(Response $response): ?string
    {
        $link = (string) $response->header('Link');
        if ($link === '' || ! preg_match('/<([^>]+)>;\s*rel="?next"?/i', $link, $matches)) {
            return null;
        }
        $query = parse_url($matches[1], PHP_URL_QUERY);
        if (! is_string($query)) {
            return null;
        }
        parse_str($query, $parameters);
        return isset($parameters['page_info']) && is_string($parameters['page_info']) && $parameters['page_info'] !== ''
            ? $parameters['page_info']
            : null;
    }

    private function amount(array $payload, array $fields): float
    {
        foreach ($fields as $field) {
            if (isset($payload[$field]) && is_numeric($payload[$field])) {
                return (float) $payload[$field];
            }
        }
        return 0.0;
    }

    private function paymentMethod(array $payload): string
    {
        $gateways = $payload['payment_gateway_names'] ?? [];
        if (is_array($gateways) && $gateways !== []) {
            return implode(', ', array_map('strval', $gateways));
        }
        return (string) ($payload['gateway'] ?? '');
    }

    private function paymentTitle(array $payload): string
    {
        return (string) ($payload['payment_gateway_name'] ?? $payload['gateway'] ?? '');
    }

    private function fulfillmentStatus(array $payload): string
    {
        $status = strtolower(trim((string) ($payload['fulfillment_status'] ?? '')));

        return $status === '' || $status === 'null' ? 'unshipped' : $status;
    }

    private function marketingSource(array $payload): string
    {
        $parameters = [];
        $utm = $payload['utm_parameters'] ?? null;
        if (is_string($utm) && trim($utm) !== '') {
            $decoded = json_decode($utm, true);
            if (is_array($decoded)) {
                $parameters = $decoded;
            }
        } elseif (is_array($utm)) {
            $parameters = $utm;
        }

        foreach (['landing_site', 'referring_site', 'source_url'] as $field) {
            $url = $payload[$field] ?? null;
            if (! is_string($url) || trim($url) === '') {
                continue;
            }
            $query = parse_url($url, PHP_URL_QUERY);
            if (is_string($query)) {
                parse_str($query, $queryParameters);
                $parameters = array_merge($parameters, $queryParameters);
            }
        }

        $normalized = [];
        foreach ($parameters as $key => $value) {
            if (is_scalar($value)) {
                $normalized[strtolower((string) $key)] = strtolower(trim((string) $value));
            }
        }
        $utmSource = $normalized['utm_source'] ?? $normalized['sl_source_ad_platform'] ?? '';
        $sourceHints = strtolower(implode(' ', array_filter([
            is_scalar($payload['source_name'] ?? null) ? (string) $payload['source_name'] : '',
            is_scalar($payload['referring_site'] ?? null) ? (string) $payload['referring_site'] : '',
            is_scalar($payload['source_url'] ?? null) ? (string) $payload['source_url'] : '',
        ])));

        if (filled($normalized['gclid'] ?? null) || filled($normalized['gad_campaignid'] ?? null) || str_contains($utmSource, 'google')) {
            return 'google_ads';
        }
        if (filled($normalized['ttclid'] ?? null) || str_contains($utmSource, 'tiktok') || $utmSource === 'tt' || str_contains($sourceHints, 'tiktok')) {
            return 'tiktok';
        }
        if (filled($normalized['fbclid'] ?? null) || filled($normalized['igshid'] ?? null) || preg_match('/facebook|instagram|meta/', $utmSource.' '.$sourceHints) === 1) {
            return 'meta';
        }
        if ($utmSource !== '') {
            return 'other_utm';
        }

        foreach (['referring_site', 'source_url'] as $field) {
            $url = $payload[$field] ?? null;
            $host = is_string($url) ? strtolower((string) parse_url($url, PHP_URL_HOST)) : '';
            if ($host !== '') {
                return 'external_referral';
            }
        }

        return 'direct_or_unknown';
    }

    private function name(array $person): string
    {
        $name = trim((string) ($person['name'] ?? ''));
        return $name !== '' ? $name : trim(implode(' ', array_filter([$person['first_name'] ?? null, $person['last_name'] ?? null])));
    }

    private function address(array $address): string
    {
        return trim(implode(', ', array_filter([
            $address['address1'] ?? null, $address['address2'] ?? null, $address['city'] ?? null,
            $address['province'] ?? null, $address['zip'] ?? null, $address['country'] ?? null,
        ], fn ($value) => $value !== null && $value !== '')));
    }

    private function date(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function decimal(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function safeUnsignedId(mixed $value): ?int
    {
        $value = is_scalar($value) ? trim((string) $value) : '';
        return preg_match('/^\d{1,18}$/', $value) ? (int) $value : null;
    }
}
