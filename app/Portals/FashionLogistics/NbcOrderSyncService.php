<?php

namespace App\Portals\FashionLogistics;

use App\Models\AssistantDomainStore;
use App\Models\FashionLogisticsOrder;
use App\Models\FashionLogisticsSyncState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class NbcOrderSyncService
{
    public function syncAll(?int $storeId = null, bool $full = false, bool $all = false): array
    {
        $stores = AssistantDomainStore::query()->whereRaw("LOWER(TRIM(site_builder)) IN ('nbc', 'shopyy')")
            ->where('shop_status', AssistantDomainStore::SHOP_STATUS_IN_USE)
            ->when($storeId, fn ($q) => $q->whereKey($storeId))->orderBy('id')->get();
        $result = ['stores' => $stores->count(), 'success' => 0, 'failed' => 0, 'created' => 0, 'updated' => 0, 'errors' => []];
        foreach ($stores as $store) {
            try {
                $counts = $this->syncStore($store, $full, $all);
                $result['success']++;
                $result['created'] += $counts['created'];
                $result['updated'] += $counts['updated'];
            } catch (Throwable $e) {
                $result['failed']++;
                $message = $e instanceof RuntimeException && str_starts_with($e->getMessage(), '[NBC]') ? $e->getMessage() : __('nbc_orders.errors.internal');
                $result['errors'][] = __('nbc_orders.errors.store', ['id' => $store->getKey(), 'message' => $message]);
            }
        }

        return $result;
    }

    public function syncStore(AssistantDomainStore $store, bool $full = false, bool $all = false): array
    {
        if (! in_array($this->platform($store), ['nbc', 'shopyy'], true) || $store->shop_status !== AssistantDomainStore::SHOP_STATUS_IN_USE) {
            throw new RuntimeException(__('nbc_orders.errors.store_type'));
        }
        $lock = Cache::lock('fashion-logistics:nbc-sync:'.$store->getKey(), 86400);
        if (! $lock->get()) {
            throw new RuntimeException(__('nbc_orders.errors.locked'));
        }
        try {
            return $this->syncLocked($store, $full, $all);
        } finally {
            $lock->release();
        }
    }

    private function syncLocked(AssistantDomainStore $store, bool $full, bool $all): array
    {
        $state = FashionLogisticsSyncState::query()->firstOrCreate(['assistant_domain_store_id' => $store->getKey()]);
        $state->fill(['status' => 'syncing', 'last_started_at' => now(), 'last_error' => null])->save();
        $created = $updated = 0;
        $startedAt = CarbonImmutable::now('UTC');
        $history = $full || $all || $state->last_cursor_at === null;
        // A store changing builders must complete history for its new source platform.
        $platform = $this->platform($store);
        $storeOrders = FashionLogisticsOrder::query()->where('assistant_domain_store_id', $store->getKey());
        if ((clone $storeOrders)->where('source_platform', '!=', $platform)->exists()
            && ! (clone $storeOrders)->where('source_platform', $platform)->exists()) {
            $history = true;
        }
        $today = $startedAt->setTimezone('Asia/Shanghai')->startOfDay();
        $after = $history ? 0 : $today->subDays(7)->timestamp;
        $before = $history ? $startedAt->timestamp : $today->addDays(7)->endOfDay()->timestamp;
        try {
            if (blank($store->nbc_app_token)) {
                throw new RuntimeException(__('nbc_orders.errors.token'));
            }
            $seen = [];
            $maxPages = max(1, (int) config('fashion_logistics.nbc.max_pages', 10000));
            for ($page = 1; $page <= $maxPages; $page++) {
                $data = $this->requestPage($store, $after, $before, $page);
                $list = $data['list'];
                $pagination = $data['paginate'];
                if (! isset($pagination['current'], $pagination['pageTotal']) || ! is_numeric($pagination['current']) || ! is_numeric($pagination['pageTotal'])
                    || (int) $pagination['current'] !== $page || (int) $pagination['pageTotal'] < 0) {
                    throw new RuntimeException(__('nbc_orders.errors.pagination'));
                }
                $last = (int) $pagination['pageTotal'];
                if ($list === [] && ($page > 1 || $last > 1 || (int) ($pagination['total'] ?? 0) > 0)) {
                    throw new RuntimeException(__('nbc_orders.errors.pagination'));
                }
                if ($list !== []) {
                    $fingerprint = hash('sha256', json_encode(array_column($list, 'id')));
                    if (isset($seen[$fingerprint])) {
                        throw new RuntimeException(__('nbc_orders.errors.pagination'));
                    }
                    $seen[$fingerprint] = true;
                }
                foreach ($list as $payload) {
                    if (! is_array($payload) || ! is_scalar($payload['id'] ?? null) || (string) $payload['id'] === '' || ! isset($payload['financial_status'])) {
                        throw new RuntimeException(__('nbc_orders.errors.order'));
                    }
                    $action = $this->persist($store, $payload);
                    $created += $action === 'created' ? 1 : 0;
                    $updated += $action === 'updated' ? 1 : 0;
                }
                if ($page >= $last) {
                    // This is a completion marker, never the future end of the date window.
                    $state->fill(['status' => 'success', 'last_succeeded_at' => now(), 'last_cursor_at' => $startedAt,
                        'last_created_count' => $created, 'last_updated_count' => $updated, 'last_error' => null])->save();

                    return compact('created', 'updated');
                }
            }
            throw new RuntimeException(__('nbc_orders.errors.page_limit'));
        } catch (Throwable $e) {
            // Persist only our sanitized messages; database/HTTP exception text may contain secrets.
            $message = $e instanceof RuntimeException && str_starts_with($e->getMessage(), '[NBC]')
                ? $e->getMessage() : __('nbc_orders.errors.internal');
            $state->fill(['status' => 'failed', 'last_error' => $message, 'last_created_count' => $created, 'last_updated_count' => $updated])->save();
            throw new RuntimeException($message);
        }
    }

    private function requestPage(AssistantDomainStore $store, int $after, int $before, int $page): array
    {
        $delay = max(0, (int) config('fashion_logistics.nbc.request_interval_ms', 600));
        if ($delay > 0) {
            usleep($delay * 1000);
        }
        try {
            // User-selected contract: filter normal orders by creation time, not update time.
            $response = Http::acceptJson()->withHeaders(['token' => trim((string) $store->nbc_app_token)])
                ->timeout(45)->connectTimeout(15)->withOptions(['allow_redirects' => false])
                ->get(config('fashion_logistics.nbc.endpoint'), [
                    'group' => 'all', 'created_at_min' => $after, 'created_at_max' => $before,
                    'order_field' => 'id', 'order_by' => 'asc', 'page' => $page,
                    'limit' => max(1, (int) config('fashion_logistics.nbc.page_size', 10)),
                ]);
        } catch (Throwable) {
            throw new RuntimeException(__('nbc_orders.errors.connection'));
        }
        if (! $response->successful()) {
            throw new RuntimeException(__('nbc_orders.errors.http', ['status' => $response->status()]));
        }
        try {
            $body = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (Throwable) {
            throw new RuntimeException(__('nbc_orders.errors.response'));
        }
        if (! is_array($body) || ! array_key_exists('code', $body) || ! in_array($body['code'], [0, '0'], true)) {
            throw new RuntimeException($this->apiError($body, trim((string) $store->nbc_app_token)));
        }
        $data = $body['data'] ?? null;
        if (! is_array($data) || ! is_array($data['list'] ?? null) || ! array_is_list($data['list']) || ! is_array($data['paginate'] ?? null)) {
            throw new RuntimeException(__('nbc_orders.errors.response'));
        }

        return $data;
    }

    private function apiError(mixed $body, string $token): string
    {
        $body = is_array($body) ? $body : [];

        return __('nbc_orders.errors.api', [
            'code' => $this->diagnosticValue($body['code'] ?? null, $token, 32, __('nbc_orders.errors.not_provided')),
            'message' => $this->diagnosticValue($body['msg'] ?? $body['message'] ?? null, $token, 240, __('nbc_orders.errors.api_no_message')),
            'trace' => $this->diagnosticValue($body['trace_id'] ?? null, $token, 96, __('nbc_orders.errors.not_provided')),
        ]);
    }

    private function diagnosticValue(mixed $value, string $token, int $limit, string $fallback): string
    {
        // Only short scalar diagnostics are exposed, never a serialized response or headers.
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return $fallback;
        }
        $value = rawurldecode(html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $redacted = __('nbc_orders.errors.redacted');
        if ($token !== '') {
            $value = str_replace([$token, urlencode($token), base64_encode($token)], $redacted, $value);
        }
        $value = preg_replace_callback('/\b(Bearer|Basic)\s+[^\s,;，；]+/iu', fn ($match) => $match[1].' '.$redacted, $value) ?? '';
        $value = preg_replace_callback(
            '~\b((?:nbc_app_token|app_token|access_token|token|authorization|password|client_secret|api_key)\s*["\x27]?\s*[:=]\s*)(?:"[^"]*"|\x27[^\x27]*\x27|[^\s,;，；}\]]+)~iu',
            fn ($match) => $match[1].$redacted,
            $value,
        ) ?? '';
        $value = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', strip_tags($value)) ?? '');

        return $value === '' ? $fallback : mb_substr($value, 0, $limit);
    }

    private function persist(AssistantDomainStore $store, array $payload): ?string
    {
        return DB::connection(config('admin.database.connection') ?: config('database.default'))->transaction(function () use ($store, $payload) {
            $order = FashionLogisticsOrder::query()->firstOrNew([
                'assistant_domain_store_id' => $store->getKey(), 'source_platform' => $this->platform($store), 'external_order_id' => (string) $payload['id'],
            ]);
            if ((string) $payload['financial_status'] !== '230' && ! $order->exists) {
                return null;
            }
            if (! is_array($payload['products'] ?? null)) {
                throw new RuntimeException(__('nbc_orders.errors.order'));
            }
            $createdAt = $this->timestamp($payload['created_at'] ?? null);
            $modifiedAt = $this->timestamp($payload['updated_at'] ?? null);
            $paidAt = $this->timestamp($payload['pay_at'] ?? null);
            if (! $createdAt || ! $modifiedAt || ((string) $payload['financial_status'] === '230' && ! $paidAt)) {
                throw new RuntimeException(__('nbc_orders.errors.time'));
            }
            $created = ! $order->exists;
            $shipping = is_array($payload['shipping_address'] ?? null) ? $payload['shipping_address'] : [];
            $billing = is_array($payload['billing_address'] ?? null) ? $payload['billing_address'] : [];
            $recipient = $shipping ?: $billing;
            $transaction = is_array($payload['transaction'] ?? null) ? $payload['transaction'] : [];
            $order->fill([
                'store_name' => (string) $store->shop_name,
                'source_domain' => (string) ($payload['domain'] ?? $store->domain ?? ''),
                'marketing_source' => $this->marketingSource($payload),
                'order_number' => (string) ($payload['order_number'] ?? $payload['id']),
                'status' => (string) ($payload['status'] ?? ''), 'payment_status' => (string) $payload['financial_status'],
                'fulfillment_status' => (string) ($payload['fulfillment_status'] ?? ''),
                'currency' => (string) ($payload['currency_code'] ?? ''),
                'total_amount' => $this->decimal($payload['current_total_price'] ?? $payload['total_price'] ?? null),
                'shipping_amount' => $this->decimal($payload['current_shipping_price'] ?? 0),
                'discount_amount' => bcadd($this->decimal($payload['current_coupon_price'] ?? 0), $this->decimal($payload['current_promotion_price'] ?? 0), 4),
                'tax_amount' => $this->decimal($payload['current_tax_price'] ?? 0),
                'payment_method' => (string) ($transaction['payment_method'] ?? $payload['payment_method'] ?? ''),
                'payment_method_title' => (string) ($transaction['payment_type'] ?? $payload['payment_type'] ?? ''),
                'transaction_id' => (string) ($transaction['transaction_no'] ?? ''),
                'customer_id' => $this->unsignedId($payload['customer_id'] ?? null),
                'customer_name' => (string) ($payload['customer_name'] ?? $billing['name'] ?? ''),
                'customer_email' => (string) ($payload['customer_email'] ?? ''),
                'customer_phone' => (string) ($billing['phone'] ?? $recipient['phone'] ?? ''),
                'shipping_country' => (string) ($recipient['country_name'] ?? $recipient['country_code'] ?? ''),
                'shipping_state' => (string) ($recipient['province'] ?? ''), 'shipping_city' => (string) ($recipient['city'] ?? ''),
                'shipping_address' => implode(' ', array_filter(array_map(fn ($key) => (string) ($recipient[$key] ?? ''), ['address1', 'address2', 'area', 'city', 'province', 'country_name', 'zip']))),
                'customer_note' => (string) ($payload['note'] ?? ''),
                'source_order_created_at' => $createdAt, 'source_order_timezone' => 'UTC',
                'order_created_at_utc' => $createdAt, 'order_created_at' => $createdAt->setTimezone('Asia/Shanghai'),
                'order_modified_at' => $modifiedAt, 'paid_at' => $paidAt,
                // Existing reports interpret this legacy column as China wall-clock time.
                'paid_at_utc' => $paidAt?->setTimezone('Asia/Shanghai'),
                'completed_at' => null, 'source_payload' => $payload, 'source_synced_at' => now(),
            ])->save();
            $order->items()->delete();
            foreach ($payload['products'] as $item) {
                if (! is_array($item)) {
                    throw new RuntimeException(__('nbc_orders.errors.order'));
                }
                $quantity = $this->decimal($item['quantity'] ?? null);
                $price = $this->decimal($item['price'] ?? null);
                $discountPrice = $this->decimal($item['discount_price'] ?? $item['price'] ?? null);
                $order->items()->create([
                    'external_item_id' => isset($item['id']) ? (string) $item['id'] : null,
                    'product_id' => $this->unsignedId($item['product_id'] ?? null), 'variation_id' => $this->unsignedId($item['variant_id'] ?? null),
                    'product_name' => (string) ($item['product_title'] ?? ''), 'sku' => (string) ($item['sku'] ?? ''),
                    'quantity' => $quantity, 'subtotal' => bcmul($price, $quantity, 4), 'total' => bcmul($discountPrice, $quantity, 4),
                    'total_tax' => $this->decimal($item['tax_price'] ?? 0),
                    'image_url' => $this->imageUrl($item['src'] ?? null), 'meta_data' => $item,
                ]);
            }

            return $created ? 'created' : 'updated';
        });
    }

    private function platform(AssistantDomainStore $store): string
    {
        return strtolower(trim((string) $store->site_builder));
    }

    private function timestamp(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            return null;
        }
        if (! preg_match('/^\d{1,10}$/D', (string) $value)) {
            throw new RuntimeException(__('nbc_orders.errors.time'));
        }

        return CarbonImmutable::createFromTimestamp((int) $value, 'UTC');
    }

    private function decimal(mixed $value): string
    {
        if (! is_scalar($value) || ! preg_match('/^-?\d{1,14}(?:\.\d+)?$/D', (string) $value)) {
            throw new RuntimeException(__('nbc_orders.errors.amount'));
        }

        return bcadd((string) $value, '0', 4);
    }

    private function unsignedId(mixed $id): ?string
    {
        return is_scalar($id) && preg_match('/^\d{1,18}$/D', (string) $id) ? (string) $id : null;
    }

    private function imageUrl(mixed $url): ?string
    {
        return is_string($url) && strlen($url) <= 1000 && filter_var($url, FILTER_VALIDATE_URL)
            && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true) ? $url : null;
    }

    private function marketingSource(array $payload): string
    {
        $source = strtolower(trim((string) ($payload['utm_source'] ?? '')));
        $parameters = [];
        parse_str((string) parse_url((string) ($payload['landing_page'] ?? ''), PHP_URL_QUERY), $parameters);
        if (isset($parameters['gclid']) || str_contains($source, 'google')) {
            return 'google_ads';
        }
        if (isset($parameters['ttclid']) || str_contains($source, 'tiktok')) {
            return 'tiktok';
        }
        if (isset($parameters['fbclid']) || in_array($source, ['meta', 'facebook', 'instagram', 'fb', 'ig'], true)) {
            return 'meta';
        }

        return $source !== '' ? 'other_utm' : 'direct_or_unknown';
    }
}
