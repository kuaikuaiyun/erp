<?php

namespace App\Portals\FashionLogistics;

use App\Models\AssistantDomainStore;
use App\Models\FashionLogisticsOrder;
use App\Models\FashionLogisticsSyncState;
use Carbon\Carbon;
use DateTimeZone;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class XShoppyOrderSyncService
{
    public function clearOrders(?int $storeId = null): int
    {
        $connection = config('admin.database.connection') ?: config('database.default');

        return DB::connection($connection)->transaction(function () use ($connection, $storeId): int {
            $orders = DB::connection($connection)->table('fashion_logistics_orders')
                ->where('source_platform', 'xshoppy')
                ->when($storeId, fn ($query) => $query->where('assistant_domain_store_id', $storeId));
            $orderIds = (clone $orders)->pluck('id');

            if ($orderIds->isNotEmpty()) {
                DB::connection($connection)->table('fashion_logistics_order_items')
                    ->whereIn('fashion_logistics_order_id', $orderIds)
                    ->delete();
            }

            $deleted = $orders->delete();
            FashionLogisticsSyncState::query()
                ->when($storeId, fn ($query) => $query->where('assistant_domain_store_id', $storeId))
                ->update([
                    'last_cursor_at' => null,
                    'last_created_count' => 0,
                    'last_updated_count' => 0,
                ]);

            return $deleted;
        });
    }

    /** @return array{stores:int,success:int,failed:int,created:int,updated:int,errors:list<string>} */
    public function syncAll(?int $storeId = null, int $initialDays = 60, bool $full = false, bool $allHistory = false): array
    {
        $stores = AssistantDomainStore::query()
            ->when($storeId, fn ($query) => $query->whereKey($storeId))
            ->whereRaw("LOWER(TRIM(site_builder)) IN ('xshoppy','xshopy')")
            ->whereNotNull('xshoppy_api_key')->where('xshoppy_api_key', '!=', '')
            ->whereNotNull('xshoppy_password')->where('xshoppy_password', '!=', '')
            ->orderBy('id')->get();
        $summary = ['stores' => $stores->count(), 'success' => 0, 'failed' => 0, 'created' => 0, 'updated' => 0, 'errors' => []];

        foreach ($stores as $store) {
            try {
                $result = $this->syncStore($store, $initialDays, $full, $allHistory);
                $summary['success']++;
                $summary['created'] += $result['created'];
                $summary['updated'] += $result['updated'];
            } catch (\Throwable $exception) {
                $summary['failed']++;
                $summary['errors'][] = ($store->shop_name ?: $store->shop_login_url ?: '#'.$store->id).'：'.$exception->getMessage();
            }
        }

        return $summary;
    }

    /** @return array{created:int,updated:int,pages:int} */
    public function syncStore(AssistantDomainStore $store, int $initialDays = 60, bool $full = false, bool $allHistory = false): array
    {
        $this->assertStore($store);
        $storeTimezone = $this->requestStoreTimezone($store);
        $state = FashionLogisticsSyncState::query()->firstOrCreate(['assistant_domain_store_id' => $store->getKey()]);
        $state->forceFill(['status' => 'syncing', 'last_started_at' => now(), 'last_error' => null])->save();
        $historyStart = Carbon::create(2000, 1, 1, 0, 0, 0, 'UTC');
        $after = $allHistory || (! $full && ! $state->last_cursor_at)
            ? $historyStart
            : (! $full
                ? $state->last_cursor_at->copy()->subHours(3)
                : now()->subDays(max(1, $initialDays)));
        $before = now()->addMinute();
        $created = 0;
        $updated = 0;
        $pages = 0;
        $latestModified = $state->last_cursor_at?->copy() ?: $after->copy();

        try {
            for ($page = 1; $page <= 1000; $page++) {
                $orders = $this->requestPage($store, $after, $before, $page);
                $pages++;
                foreach ($orders as $payload) {
                    if (! is_array($payload) || blank($payload['id'] ?? null)) continue;
                    $modified = $this->parseStoreTimeUtc($payload['date_modified'] ?? null, $storeTimezone);
                    if ($modified && $modified->gt($latestModified)) $latestModified = $modified;

                    if (! $this->isPaid($payload)) {
                        $this->deleteExistingOrder($store, (string) $payload['id']);
                        continue;
                    }

                    $result = $this->persist($store, $payload, $storeTimezone);
                    $result['created'] ? $created++ : $updated++;
                }
                if (count($orders) < 100) break;
            }

            $state->forceFill([
                'status' => 'success', 'last_succeeded_at' => now(), 'last_cursor_at' => $latestModified,
                'last_created_count' => $created, 'last_updated_count' => $updated, 'last_error' => null,
            ])->save();
        } catch (\Throwable $exception) {
            $state->forceFill(['status' => 'failed', 'last_created_count' => $created, 'last_updated_count' => $updated, 'last_error' => Str::limit($exception->getMessage(), 2000)])->save();
            throw $exception;
        }

        return compact('created', 'updated', 'pages');
    }

    /** @return list<array<string,mixed>> */
    private function requestPage(AssistantDomainStore $store, Carbon $after, Carbon $before, int $page): array
    {
        try {
            $response = Http::acceptJson()->timeout(45)->connectTimeout(15)->retry(2, 1000)
                ->get($this->endpoint($store), [
                    'consumer_key' => trim((string) $store->xshoppy_api_key),
                    'consumer_secret' => trim((string) $store->xshoppy_password),
                    'is_show' => 1,
                    'after' => $after->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
                    'before' => $before->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
                    'page' => $page,
                    'per_page' => 100,
                    'status' => 'all',
                ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('无法连接 XShoppy 订单接口：'.$exception->getMessage(), 0, $exception);
        }
        if (! $response->successful()) {
            throw new RuntimeException('XShoppy 接口返回 HTTP '.$response->status().'：'.Str::limit($response->body(), 500));
        }
        $data = $response->json();
        if (! is_array($data)) throw new RuntimeException('XShoppy 接口返回格式不是 JSON 数组。');
        if (isset($data['data']) && is_array($data['data'])) $data = $data['data'];
        return array_values(array_filter($data, 'is_array'));
    }

    /** @return array{created:bool,order:FashionLogisticsOrder} */
    private function persist(AssistantDomainStore $store, array $payload, string $storeTimezone): array
    {
        $billing = is_array($payload['billing'] ?? null) ? $payload['billing'] : [];
        $shipping = is_array($payload['shipping'] ?? null) ? $payload['shipping'] : [];
        $externalId = (string) $payload['id'];
        $connection = config('admin.database.connection') ?: config('database.default');

        return DB::connection($connection)->transaction(function () use ($store, $payload, $billing, $shipping, $externalId, $storeTimezone): array {
            $order = FashionLogisticsOrder::query()->firstOrNew([
                'assistant_domain_store_id' => $store->getKey(),
                'source_platform' => 'xshoppy',
                'external_order_id' => $externalId,
            ]);
            $created = ! $order->exists;
            $customerName = trim(implode(' ', array_filter([$billing['first_name'] ?? null, $billing['last_name'] ?? null])));
            $address = trim(implode(', ', array_filter([$shipping['address_1'] ?? null, $shipping['address_2'] ?? null, $shipping['city'] ?? null, $shipping['state'] ?? null, $shipping['postcode'] ?? null, $shipping['country'] ?? null])));
            $order->fill([
                'store_name' => $store->shop_name, 'source_domain' => $this->domain($store),
                'order_number' => (string) ($payload['number'] ?? $externalId), 'status' => (string) ($payload['status'] ?? ''),
                'currency' => (string) ($payload['currency'] ?? ''), 'total_amount' => $this->decimal($payload['total'] ?? 0),
                'shipping_amount' => $this->decimal($payload['shipping_total'] ?? 0), 'discount_amount' => $this->decimal($payload['discount_total'] ?? 0),
                'tax_amount' => $this->decimal($payload['total_tax'] ?? 0), 'payment_method' => (string) ($payload['payment_method'] ?? ''),
                'payment_method_title' => (string) ($payload['payment_method_title'] ?? ''), 'transaction_id' => (string) ($payload['transaction_id'] ?? ''),
                'customer_id' => isset($payload['customer_id']) ? (int) $payload['customer_id'] : null, 'customer_name' => $customerName,
                'customer_email' => (string) ($billing['email'] ?? ''), 'customer_phone' => (string) ($billing['phone'] ?? ''),
                'shipping_country' => (string) ($shipping['country'] ?? ''), 'shipping_state' => (string) ($shipping['state'] ?? ''),
                'shipping_city' => (string) ($shipping['city'] ?? ''), 'shipping_address' => $address,
                'customer_note' => (string) ($payload['customer_note'] ?? ''),
                'source_order_created_at' => $this->parseStoreTime($payload['date_created'] ?? null, $storeTimezone),
                'source_order_timezone' => $storeTimezone,
                'order_created_at' => $this->parseStoreTimeChina($payload['date_created'] ?? null, $storeTimezone),
                'order_modified_at' => $this->parseStoreTimeChina($payload['date_modified'] ?? null, $storeTimezone),
                'paid_at' => $this->parseStoreTimeChina($payload['date_paid'] ?? null, $storeTimezone),
                'completed_at' => $this->parseStoreTimeChina($payload['date_completed'] ?? null, $storeTimezone),
                'source_payload' => $payload, 'source_synced_at' => now(),
            ])->save();

            $order->items()->delete();
            foreach ((array) ($payload['line_items'] ?? []) as $item) {
                if (! is_array($item)) continue;
                $image = is_array($item['image'] ?? null) ? ($item['image']['src'] ?? null) : null;
                $order->items()->create([
                    'external_item_id' => isset($item['id']) ? (string) $item['id'] : null,
                    'product_id' => isset($item['product_id']) ? (int) $item['product_id'] : null,
                    'variation_id' => isset($item['variation_id']) ? (int) $item['variation_id'] : null,
                    'product_name' => (string) ($item['name'] ?? ''), 'sku' => (string) ($item['sku'] ?? ''),
                    'quantity' => $this->decimal($item['quantity'] ?? 0), 'subtotal' => $this->decimal($item['subtotal'] ?? 0),
                    'total' => $this->decimal($item['total'] ?? 0), 'total_tax' => $this->decimal($item['total_tax'] ?? 0),
                    'image_url' => is_string($image) ? $image : null, 'meta_data' => $item['meta_data'] ?? [],
                ]);
            }

            return ['created' => $created, 'order' => $order];
        });
    }

    private function assertStore(AssistantDomainStore $store): void
    {
        abort_unless(in_array(strtolower(trim((string) $store->site_builder)), ['xshoppy', 'xshopy'], true), 422, '该店铺不是 XShoppy 建站系统。');
        if (blank($store->xshoppy_api_key) || blank($store->xshoppy_password)) throw new RuntimeException('店铺未填写 XShoppy API 密钥或密码。');
        if ($this->domain($store) === '') throw new RuntimeException('店铺未填写有效的店铺登入网址。');
    }

    private function endpoint(AssistantDomainStore $store): string
    {
        return 'https://'.$this->domain($store).'/wp-json/wc/v3/orders';
    }

    private function shopEndpoint(AssistantDomainStore $store): string
    {
        return 'https://'.$this->domain($store).'/wp-json/wc/v3/shop';
    }

    private function requestStoreTimezone(AssistantDomainStore $store): string
    {
        try {
            $response = Http::acceptJson()->timeout(45)->connectTimeout(15)->retry(2, 1000)
                ->get($this->shopEndpoint($store), [
                    'consumer_key' => trim((string) $store->xshoppy_api_key),
                    'consumer_secret' => trim((string) $store->xshoppy_password),
                ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('无法连接 XShoppy 店铺信息接口：'.$exception->getMessage(), 0, $exception);
        }

        if (! $response->successful()) {
            throw new RuntimeException('XShoppy 店铺信息接口返回 HTTP '.$response->status().'：'.Str::limit($response->body(), 500));
        }

        $timezone = trim((string) data_get($response->json(), 'data.time_zone'));
        if ($timezone === '') {
            throw new RuntimeException('XShoppy 店铺信息未返回 time_zone，已停止同步以避免订单时间错误。');
        }

        try {
            new DateTimeZone($timezone);
        } catch (\Throwable) {
            throw new RuntimeException('XShoppy 店铺信息返回了无效时区：'.$timezone);
        }

        return $timezone;
    }

    private function domain(AssistantDomainStore $store): string
    {
        $value = trim((string) $store->shop_login_url);
        if ($value === '') return '';
        $host = parse_url(Str::startsWith($value, ['http://', 'https://']) ? $value : 'https://'.$value, PHP_URL_HOST);
        return strtolower(trim((string) $host, '.'));
    }

    private function parseStoreTime(mixed $value, string $storeTimezone): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') return null;
        try { return Carbon::parse($value, $storeTimezone); } catch (\Throwable) { return null; }
    }

    private function parseStoreTimeChina(mixed $value, string $storeTimezone): ?Carbon
    {
        return $this->parseStoreTime($value, $storeTimezone)?->setTimezone('Asia/Shanghai');
    }

    private function parseStoreTimeUtc(mixed $value, string $storeTimezone): ?Carbon
    {
        return $this->parseStoreTime($value, $storeTimezone)?->utc();
    }

    /**
     * XShoppy / WooCommerce 的部分订单可能尚未写入 date_paid，
     * 但已进入 processing 或 completed 状态；这两种状态同样视为已支付。
     */
    private function isPaid(array $payload): bool
    {
        if (filled($payload['date_paid'] ?? null)) return true;

        return in_array(strtolower(trim((string) ($payload['status'] ?? ''))), ['processing', 'completed'], true);
    }

    private function decimal(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }
}
