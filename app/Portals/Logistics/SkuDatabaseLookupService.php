<?php

namespace App\Portals\Logistics;

use App\Models\LogisticsOrderItem;
use App\Models\LogisticsTongtuSkuMapping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class SkuDatabaseLookupService
{
    /** @param array<int, string|null> $skus
     *  @return array<string, array{sku:string,pcl:?string,product_name:?string,image_url:?string,guide_cost_cny:?float,current_cost_cny:?float,average_cost_cny:?float,weight_gram:?float,data_time:?string,match_status:?string}>
     */
    public function lookup(array $skus, bool $forceRefresh = false): array
    {
        $skus = collect($skus)->map(fn ($sku) => trim((string) $sku))->filter()->unique()->take(100)->values()->all();
        $url = trim((string) config('logistics.sku_database.url'));
        $token = trim((string) config('logistics.sku_database.token'));
        if ($skus === [] || $url === '' || $token === '') {
            return [];
        }

        $cacheKey = 'logistics:sku-database:v2:'.sha1(implode('|', array_map('mb_strtolower', $skus)));
        if ($forceRefresh) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, now()->addMinutes(max(1, (int) config('logistics.sku_database.cache_minutes', 10))), function () use ($url, $token, $skus): array {
            try {
                $response = Http::acceptJson()
                    ->withToken($token)
                    ->timeout(max(3, (int) config('logistics.sku_database.timeout', 15)))
                    ->post($url, ['skus' => $skus]);
                if (!$response->successful()) {
                    return [];
                }

                $rows = $this->normaliseResponse((array) $response->json());
                $this->persist($rows);

                return $rows;
            } catch (\Throwable) {
                return [];
            }
        });
    }

    /** @param array<string, array<string,mixed>> $rows */
    private function persist(array $rows): void
    {
        $matchedRows = array_filter($rows, static fn (array $row): bool => ($row['match_status'] ?? '') !== 'not_found'
            && collect(['pcl', 'product_name', 'image_url', 'guide_cost_cny', 'current_cost_cny', 'average_cost_cny', 'weight_gram'])
                ->contains(fn (string $field): bool => filled($row[$field] ?? null)));
        if ($matchedRows === []) {
            return;
        }

        DB::connection(config('admin.database.connection') ?: config('database.default'))->transaction(function () use ($matchedRows): void {
            foreach ($matchedRows as $row) {
                $sku = trim((string) $row['sku']);
                $requestedSku = trim((string) ($row['requested_sku'] ?? $sku));
                $normalizedSku = mb_strtolower($requestedSku);
                $mapping = LogisticsTongtuSkuMapping::query()
                    ->whereRaw('LOWER(TRIM(shopify_sku)) = ?', [$normalizedSku])
                    ->first();

                if ($mapping === null) {
                    $mapping = new LogisticsTongtuSkuMapping([
                        'shopify_sku' => $normalizedSku,
                        'status' => LogisticsTongtuSkuMapping::STATUS_MATCHED,
                    ]);
                }

                $values = [
                    'status' => LogisticsTongtuSkuMapping::STATUS_MATCHED,
                    'cost_currency' => 'CNY',
                    'tongtu_sku' => $sku,
                    'last_synced_at' => now(),
                    'last_requested_at' => now(),
                    'next_retry_at' => now()->addMinutes(5),
                    'refresh_lock_until' => null,
                    'last_error' => null,
                    'last_response' => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ];
                if (filled($row['pcl'] ?? null)) {
                    $values['tongtu_product_code'] = trim((string) $row['pcl']);
                }
                if (filled($row['product_name'] ?? null)) {
                    $values['tongtu_product_name'] = trim((string) $row['product_name']);
                }
                if (filled($row['image_url'] ?? null)) {
                    $values['tongtu_primary_image_url'] = trim((string) $row['image_url']);
                }
                if ($row['guide_cost_cny'] !== null) {
                    $values['product_guide_cost_cny'] = $row['guide_cost_cny'];
                }
                if ($row['current_cost_cny'] !== null) {
                    $values['goods_current_cost_cny'] = $row['current_cost_cny'];
                }
                if ($row['average_cost_cny'] !== null) {
                    $values['goods_average_cost_cny'] = $row['average_cost_cny'];
                }
                if ($row['weight_gram'] !== null) {
                    $values['goods_weight_gram'] = $row['weight_gram'];
                }
                $mapping->fill($values)->save();

                $itemValues = [
                    'tongtu_sku_mapping_id' => $mapping->getKey(),
                    'tongtu_sku' => $sku,
                    'tongtu_product_code' => $values['tongtu_product_code'] ?? $mapping->tongtu_product_code,
                    'tongtu_product_name' => $values['tongtu_product_name'] ?? $mapping->tongtu_product_name,
                ];
                if ($row['weight_gram'] !== null && (float) $row['weight_gram'] > 0) {
                    $itemValues['tongtu_goods_weight_gram'] = $row['weight_gram'];
                    $itemValues['sale_weight_gram'] = $row['weight_gram'];
                }
                if (filled($row['image_url'] ?? null)) {
                    $itemValues['product_image_url'] = trim((string) $row['image_url']);
                }
                LogisticsOrderItem::query()
                    ->where(function ($query) use ($normalizedSku): void {
                        $query->whereRaw('LOWER(TRIM(shopify_sku)) = ?', [$normalizedSku])
                            ->orWhereRaw('LOWER(TRIM(tongtu_sku)) = ?', [$normalizedSku]);
                    })
                    ->update($itemValues);
            }
        });
    }

    /** @return array<string, array<string,mixed>> */
    private function normaliseResponse(array $payload): array
    {
        $items = $payload['items'] ?? $payload['data'] ?? $payload['result'] ?? $payload['results'] ?? $payload['skus'] ?? [];
        if (is_array($items) && isset($items['items']) && is_array($items['items'])) {
            $items = $items['items'];
        }
        if (!is_array($items)) {
            return [];
        }

        $result = [];
        foreach ($items as $key => $item) {
            if (!is_array($item)) {
                continue;
            }
            foreach (['data', 'record', 'sku_data'] as $nestedKey) {
                if (isset($item[$nestedKey]) && is_array($item[$nestedKey])) {
                    $item = [...$item, ...$item[$nestedKey]];
                }
            }
            $sku = trim((string) ($item['sku'] ?? $item['goodsSku'] ?? $item['goods_sku'] ?? (is_string($key) ? $key : '')));
            if ($sku === '') {
                continue;
            }
            $requestedSku = trim((string) (is_string($key) ? $key : $sku));
            $result[mb_strtolower($requestedSku)] = [
                'sku' => $sku,
                'requested_sku' => $requestedSku,
                'pcl' => $this->text($item, ['pcl', 'product_code', 'productCode']),
                'product_name' => $this->text($item, ['productName', 'product_name', 'name']),
                'image_url' => $this->text($item, ['imageUrl', 'image_url', 'image']),
                'guide_cost_cny' => $this->number($item, ['procurement_guide_cost', 'procurement_guide_cost_cny', 'purchase_guide_cost', 'purchase_guide_cost_cny', 'purchaseGuideCost', 'guide_cost', 'guideCost', 'guide_price', 'productGuideCost', 'product_guide_cost_cny']),
                'current_cost_cny' => $this->number($item, ['currentCost', 'current_cost', 'goods_current_cost_cny']),
                'average_cost_cny' => $this->number($item, ['averageCost', 'average_cost', 'goods_average_cost_cny']),
                'weight_gram' => $this->number($item, ['weight', 'weight_g', 'weight_gram', 'weightGrams', 'goods_weight', 'goodsWeight', 'goods_weight_gram']),
                'data_time' => $this->text($item, ['data_time', 'dataTime', 'readAt', 'sourceReadAt', 'updated_at', 'updatedAt']),
                'match_status' => $this->text($item, ['match_status', 'matchStatus', 'status']),
            ];
        }

        return $result;
    }

    private function number(array $item, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (isset($item[$key]) && is_numeric($item[$key])) {
                return (float) $item[$key];
            }
        }
        return null;
    }

    private function text(array $item, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = trim((string) ($item[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
        return null;
    }
}
