<?php

namespace App\Portals\Logistics;

use App\Jobs\RefreshTongtuSkuMappingsJob;
use App\Models\LogisticsOrder;
use App\Models\LogisticsOrderItem;
use App\Models\LogisticsTongtuSkuMapping;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class TongtuSkuMappingService
{
    private const REQUEST_SKU_LIMIT = 10;
    private const LOCK_MINUTES = 60;

    /** @var list<string> */
    private const PRODUCT_TYPES = ['0', '1', '2', '3'];

    /**
     * Schedule direct SKU matching without querying Tongtu orders.
     */
    public function scheduleForOrder(LogisticsOrder $order): void
    {
        $skus = $order->items()
            ->pluck('shopify_sku')
            ->map(static fn ($sku): string => LogisticsTongtuSkuMapping::normalizedSku((string) $sku))
            ->filter()
            ->unique()
            ->values()
            ->all();
        if ($skus === []) {
            return;
        }

        $mappings = $this->ensureMappings($skus);
        foreach ($mappings as $mapping) {
            if ($mapping->status === LogisticsTongtuSkuMapping::STATUS_MATCHED) {
                $this->applyMappingToOrderItems($mapping, $order);
            }
        }
    }

    /** @param list<string> $skus
     *  @return Collection<int, LogisticsTongtuSkuMapping>
     */
    public function ensureMappings(array $skus): Collection
    {
        $normalized = array_values(array_unique(array_filter(array_map(
            static fn (string $sku): string => LogisticsTongtuSkuMapping::normalizedSku($sku),
            $skus,
        ))));
        if ($normalized === []) {
            return new Collection();
        }

        foreach ($normalized as $sku) {
            LogisticsTongtuSkuMapping::query()->firstOrCreate(
                ['shopify_sku' => $sku],
                [
                    'status' => LogisticsTongtuSkuMapping::STATUS_QUEUED,
                    'next_retry_at' => now(),
                ],
            );
        }

        return LogisticsTongtuSkuMapping::query()
            ->whereIn('shopify_sku', $normalized)
            ->get()
            ->keyBy('shopify_sku');
    }

    /** @param list<int> $mappingIds */
    public function queueRefresh(array $mappingIds): void
    {
        $mappingIds = array_values(array_unique(array_filter(array_map('intval', $mappingIds))));
        if ($mappingIds === []) {
            return;
        }

        foreach (array_chunk($mappingIds, 100) as $chunk) {
            RefreshTongtuSkuMappingsJob::dispatch($chunk)
                ->onConnection('database')
                ->onQueue('logistics-tongtu-high')
                ->afterCommit();
        }
    }

    public function scheduleDueRefreshes(int $limit = 100): int
    {
        $now = now();
        $ids = LogisticsTongtuSkuMapping::query()
            ->where('status', LogisticsTongtuSkuMapping::STATUS_QUEUED)
            ->where(function ($due) use ($now): void {
                $due->whereNull('next_retry_at')->orWhere('next_retry_at', '<=', $now);
            })
            ->where(function ($locks) use ($now): void {
                $locks->whereNull('refresh_lock_until')->orWhere('refresh_lock_until', '<=', $now);
            })
            ->orderBy('created_at')
            ->limit(max(1, min(1000, $limit)))
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $this->queueRefresh($ids);

        return count($ids);
    }

    /**
     * Run one or more batched SKU queries. The API accepts at most ten SKUs per request.
     *
     * @param list<int> $mappingIds
     */
    public function refreshMappings(array $mappingIds, bool $warehouseWeightRefresh = false): void
    {
        $mappings = $this->claimMappings($mappingIds, $warehouseWeightRefresh);
        if ($mappings->isEmpty()) {
            return;
        }

        try {
            $session = $this->authenticatedSession();
            /** @var array<string, LogisticsTongtuSkuMapping> $pending */
            $pending = $mappings->keyBy('shopify_sku')->all();
            $unknown = [];
            $firstPass = [];
            foreach ($pending as $sku => $mapping) {
                $type = trim((string) $mapping->tongtu_product_type);
                if (!in_array($type, self::PRODUCT_TYPES, true)) {
                    $unknown[$sku] = true;
                    $type = self::PRODUCT_TYPES[0];
                }
                $firstPass[$type][$sku] = $mapping;
            }

            foreach ($firstPass as $type => $typeMappings) {
                $this->findProductsByType($typeMappings, (string) $type, $session, $pending, 'skus', $warehouseWeightRefresh);

                // A Shopify SKU can be maintained as a Tongtu SKU alias instead
                // of the canonical SKU. Only query the still-unresolved values by
                // alias, so a normal SKU match always has priority.
                $aliasFallback = array_intersect_key($typeMappings, $pending);
                if ($aliasFallback !== []) {
                    $this->findProductsByType($aliasFallback, (string) $type, $session, $pending, 'skuAliases', $warehouseWeightRefresh);
                }
            }

            foreach (array_slice(self::PRODUCT_TYPES, 1) as $type) {
                $fallback = [];
                foreach ($pending as $sku => $mapping) {
                    if (isset($unknown[$sku])) {
                        $fallback[$sku] = $mapping;
                    }
                }
                if ($fallback !== []) {
                    $this->findProductsByType($fallback, $type, $session, $pending, 'skus', $warehouseWeightRefresh);

                    $aliasFallback = array_intersect_key($fallback, $pending);
                    if ($aliasFallback !== []) {
                        $this->findProductsByType($aliasFallback, $type, $session, $pending, 'skuAliases', $warehouseWeightRefresh);
                    }
                }
            }

            foreach ($pending as $mapping) {
                $this->markNotReturned($mapping);
            }
        } catch (Throwable $exception) {
            $this->releaseAfterTechnicalFailure($mappings, $exception->getMessage());
            throw $exception;
        }
    }

    /** @param list<int> $mappingIds */
    public function markTechnicalFailure(array $mappingIds, string $reason): void
    {
        $mappings = LogisticsTongtuSkuMapping::query()->whereIn('id', $mappingIds)->get();
        $this->releaseAfterTechnicalFailure($mappings, $reason);
    }

    public function queueHistoricalMappings(int $limit = 100): int
    {
        $table = 'logistics_order_items';
        $mappingTable = 'logistics_tongtu_sku_mappings';
        $skus = DB::connection(config('admin.database.connection') ?: config('database.default'))
            ->table($table.' as items')
            ->selectRaw("LOWER(TRIM(items.shopify_sku)) as shopify_sku")
            ->whereNotNull('items.shopify_sku')
            ->whereRaw("TRIM(items.shopify_sku) <> ''")
            ->whereNotExists(function ($query) use ($mappingTable): void {
                $query->selectRaw('1')
                    ->from($mappingTable.' as mappings')
                    ->whereRaw("mappings.shopify_sku = LOWER(TRIM(items.shopify_sku))");
            })
            ->groupByRaw("LOWER(TRIM(items.shopify_sku))")
            ->orderBy('shopify_sku')
            ->limit(max(1, min(1000, $limit)))
            ->pluck('shopify_sku')
            ->map(static fn ($sku): string => (string) $sku)
            ->all();

        $this->ensureMappings($skus);

        return count($skus);
    }

    /**
     * Clear only Tongtu SKU mapping state and rebuild a queued SKU list from
     * existing order-item rows. Orders and order items are deliberately left
     * untouched, so the new matching rule can be applied safely.
     *
     * @return array{deleted_mappings:int,deleted_jobs:int,queued_skus:int}
     */
    public function resetMappingsForRebuild(): array
    {
        $connection = DB::connection(config('admin.database.connection') ?: config('database.default'));

        return $connection->transaction(function () use ($connection): array {
            $deletedMappings = $connection->table('logistics_tongtu_sku_mappings')->delete();
            $deletedJobs = 0;
            if ($connection->getSchemaBuilder()->hasTable('jobs')) {
                $deletedJobs = $connection->table('jobs')
                    ->whereIn('queue', ['logistics-tongtu', 'logistics-tongtu-high'])
                    ->delete();
            }

            $now = now();
            $rows = $connection->table('logistics_order_items')
                ->selectRaw('LOWER(TRIM(shopify_sku)) as shopify_sku')
                ->whereNotNull('shopify_sku')
                ->whereRaw("TRIM(shopify_sku) <> ''")
                ->groupByRaw('LOWER(TRIM(shopify_sku))')
                ->orderBy('shopify_sku')
                ->cursor();
            $batch = [];
            $queued = 0;
            foreach ($rows as $row) {
                $sku = LogisticsTongtuSkuMapping::normalizedSku((string) $row->shopify_sku);
                if ($sku === '') {
                    continue;
                }
                $batch[] = [
                    'shopify_sku' => $sku,
                    'status' => LogisticsTongtuSkuMapping::STATUS_QUEUED,
                    'next_retry_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                if (count($batch) >= 500) {
                    $connection->table('logistics_tongtu_sku_mappings')->insertOrIgnore($batch);
                    $queued += count($batch);
                    $batch = [];
                }
            }
            if ($batch !== []) {
                $connection->table('logistics_tongtu_sku_mappings')->insertOrIgnore($batch);
                $queued += count($batch);
            }

            return [
                'deleted_mappings' => $deletedMappings,
                'deleted_jobs' => $deletedJobs,
                'queued_skus' => $queued,
            ];
        });
    }

    /**
     * Requeue unresolved mappings directly by Shopify SKU. This is intended for
     * the product-statistics screen: one SKU mapping is shared by every order
     * that contains that SKU, so it is both more focused and more efficient than
     * retrying a collection of orders.
     *
     * @param list<string> $skus
     */
    public function retryUnmatchedForSkus(array $skus): int
    {
        return $this->retryUnmatchedMappings($this->ensureMappings($skus));
    }

    /** @param Collection<int, LogisticsTongtuSkuMapping> $mappings */
    private function retryUnmatchedMappings(Collection $mappings): int
    {
        foreach ($mappings as $mapping) {
            if ($mapping->status === LogisticsTongtuSkuMapping::STATUS_MATCHED) {
                continue;
            }

            $mapping->forceFill([
                'status' => LogisticsTongtuSkuMapping::STATUS_QUEUED,
                'not_found_attempts' => 0,
                'next_retry_at' => now(),
                'refresh_lock_until' => null,
                'last_error' => null,
            ])->save();
        }

        return $mappings->where('status', '!=', LogisticsTongtuSkuMapping::STATUS_MATCHED)->count();
    }

    /**
     * Refresh warehouse-facing Tongtu weights and images for the requested SKUs.
     *
     * This compatibility entry point keeps the warehouse product view on the
     * current SKU-mapping architecture instead of restoring the removed legacy
     * order-match service.
     *
     * @param list<string> $tongtuSkus
     * @return array{refreshed:int,failed:int,not_found:int,skipped:int,images:array<string,string>}
     */
    public function refreshWeightsByTongtuSkus(array $tongtuSkus): array
    {
        $skus = array_values(array_unique(array_filter(array_map(
            static fn ($sku): string => LogisticsTongtuSkuMapping::normalizedSku((string) $sku),
            $tongtuSkus,
        ))));
        if ($skus === []) {
            return ['refreshed' => 0, 'failed' => 0, 'not_found' => 0, 'skipped' => 0, 'images' => []];
        }

        $mappings = LogisticsTongtuSkuMapping::query()
            ->whereIn('shopify_sku', $skus)
            ->orWhereIn(DB::raw('LOWER(TRIM(tongtu_sku))'), $skus)
            ->get();

        $knownKeys = $mappings
            ->flatMap(static fn (LogisticsTongtuSkuMapping $mapping): array => array_filter([
                LogisticsTongtuSkuMapping::normalizedSku((string) $mapping->shopify_sku),
                LogisticsTongtuSkuMapping::normalizedSku((string) $mapping->tongtu_sku),
            ]))
            ->unique()
            ->all();
        $missing = array_values(array_diff($skus, $knownKeys));
        if ($missing !== []) {
            $this->ensureMappings($missing);
        }

        $mappings = LogisticsTongtuSkuMapping::query()
            ->whereIn('shopify_sku', $skus)
            ->orWhereIn(DB::raw('LOWER(TRIM(tongtu_sku))'), $skus)
            ->get();

        $ids = $mappings->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        if ($ids !== []) {
            $this->refreshMappings($ids, true);
        }

        $mappings = LogisticsTongtuSkuMapping::query()->whereIn('id', $ids)->get();
        $refreshed = 0;
        $failed = 0;
        $notFound = 0;
        $images = [];

        foreach ($mappings as $mapping) {
            $key = LogisticsTongtuSkuMapping::normalizedSku((string) ($mapping->tongtu_sku ?: $mapping->shopify_sku));
            if ($mapping->status === LogisticsTongtuSkuMapping::STATUS_MATCHED) {
                $weight = (float) ($mapping->goods_weight_gram ?? 0);
                LogisticsOrderItem::query()
                    ->where(function ($query) use ($mapping): void {
                        $query->whereRaw('LOWER(TRIM(shopify_sku)) = ?', [$mapping->shopify_sku]);
                        if (filled($mapping->tongtu_sku)) {
                            $query->orWhereRaw('LOWER(TRIM(tongtu_sku)) = ?', [LogisticsTongtuSkuMapping::normalizedSku((string) $mapping->tongtu_sku)]);
                        }
                    })
                    ->update(['sale_weight_gram' => $weight]);
                if (filled($mapping->tongtu_primary_image_url)) {
                    $images[$key] = (string) $mapping->tongtu_primary_image_url;
                }
                $refreshed++;
                continue;
            }

            if ($mapping->status === LogisticsTongtuSkuMapping::STATUS_NOT_FOUND) {
                $notFound++;
            } else {
                $failed++;
            }
        }

        return [
            'refreshed' => $refreshed,
            'failed' => $failed,
            'not_found' => $notFound,
            'skipped' => max(0, count($skus) - $refreshed - $failed - $notFound),
            'images' => $images,
        ];
    }

    /** @param list<int> $mappingIds
     *  @return Collection<int, LogisticsTongtuSkuMapping>
     */
    private function claimMappings(array $mappingIds, bool $allowMatched = false): Collection
    {
        $now = now();
        $claimed = new Collection();
        foreach (array_values(array_unique(array_filter(array_map('intval', $mappingIds)))) as $id) {
            $updated = LogisticsTongtuSkuMapping::query()
                ->whereKey($id)
                ->where(function ($query) use ($now): void {
                    $query->whereNull('refresh_lock_until')->orWhere('refresh_lock_until', '<=', $now);
                })
                ->when(!$allowMatched, fn ($query) => $query->where('status', LogisticsTongtuSkuMapping::STATUS_QUEUED))
                ->update([
                    'refresh_lock_until' => $now->copy()->addMinutes(self::LOCK_MINUTES),
                    'last_requested_at' => $now,
                    'updated_at' => $now,
                ]);
            if ($updated !== 1) {
                continue;
            }
            $mapping = LogisticsTongtuSkuMapping::query()->find($id);
            if ($mapping) {
                $claimed->push($mapping);
            }
        }

        return $claimed;
    }

    /**
     * @param array<string, LogisticsTongtuSkuMapping> $mappings
     * @param array{app_token:string, app_secret:string, merchant_id:string} $session
     * @param array<string, LogisticsTongtuSkuMapping> $pending
     */
    private function findProductsByType(
        array $mappings,
        string $productType,
        array $session,
        array &$pending,
        string $queryField = 'skus',
        bool $warehouseWeightRefresh = false,
    ): void
    {
        if (!in_array($queryField, ['skus', 'skuAliases'], true)) {
            throw new RuntimeException('Unsupported Tongtu SKU query field: '.$queryField);
        }

        foreach (array_chunk($mappings, self::REQUEST_SKU_LIMIT, true) as $chunk) {
            $body = $this->postJson(
                (string) config('logistics.tongtu.goods_query_url'),
                $this->authParameters($session),
                [
                    'merchantId' => $session['merchant_id'],
                    'productType' => $productType,
                    $queryField => array_keys($chunk),
                    'pageNo' => 1,
                    'pageSize' => 100,
                ],
            );
            $this->assertSuccess($body, 'Tongtu goods query');

            $candidates = [];
            foreach ($this->goodsRecords($body) as $product) {
                foreach ($this->productSkuKeys($product) as $sku) {
                    if (isset($chunk[$sku])) {
                        $candidates[$sku][] = $product;
                    }
                }
            }

            foreach ($chunk as $sku => $mapping) {
                $products = $this->uniqueProducts($candidates[$sku] ?? []);
                if ($products === []) {
                    continue;
                }
                if (count($products) > 1) {
                    $this->markConflict($mapping, $products);
                    unset($pending[$sku]);
                    continue;
                }

                $this->storeMatchedProduct($mapping, $products[0], $productType, $warehouseWeightRefresh);
                unset($pending[$sku]);
            }
        }
    }

    /** @param list<array<string,mixed>> $products
     *  @return list<array<string,mixed>>
     */
    private function uniqueProducts(array $products): array
    {
        $unique = [];
        foreach ($products as $product) {
            $id = trim((string) ($product['product_id'] ?? $product['productId'] ?? ''));
            $code = trim((string) ($product['productCode'] ?? ''));
            $key = $id !== '' ? 'id:'.$id : ($code !== '' ? 'code:'.$code : md5(json_encode($product) ?: ''));
            $unique[$key] = $product;
        }

        return array_values($unique);
    }

    /** @param array<string,mixed> $product */
    private function storeMatchedProduct(LogisticsTongtuSkuMapping $mapping, array $product, string $productType, bool $warehouseWeightRefresh = false): void
    {
        $detail = $this->matchingDetail($product, $mapping->shopify_sku);
        $images = $this->images($product);
        $now = now();
        $values = [
            'status' => LogisticsTongtuSkuMapping::STATUS_MATCHED,
            'not_found_attempts' => 0,
            'next_retry_at' => null,
            'refresh_lock_until' => null,
            'tongtu_product_id' => $this->text($product['product_id'] ?? $product['productId'] ?? null),
            'tongtu_product_code' => $this->text($product['productCode'] ?? null),
            'tongtu_sku' => $this->text($detail['goodsSku'] ?? $product['sku'] ?? $product['productSku'] ?? null),
            'tongtu_goods_detail_id' => $this->text($detail['goodsDetailId'] ?? $detail['id'] ?? null),
            'tongtu_product_type' => $productType,
            'tongtu_product_name' => $this->text($product['productName'] ?? null),
            'tongtu_product_packing_name' => $this->text($product['productPackingName'] ?? $product['productPackingEnName'] ?? null),
            'tongtu_primary_image_url' => $images['primary'],
            'tongtu_images' => json_encode($images['all'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'tongtu_updated_at' => $this->date($product['updatedDate'] ?? null),
            'last_synced_at' => $now,
            'last_error' => null,
            'last_response' => json_encode($product, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
        if ($warehouseWeightRefresh) {
            $values += [
                'goods_weight_gram' => $this->decimal($detail['goodsWeight'] ?? null),
                'package_weight_gram' => $this->decimal($product['packageWeight'] ?? null),
                'product_length_cm' => $this->decimal($product['productLength'] ?? null),
                'product_width_cm' => $this->decimal($product['productWidth'] ?? null),
                'product_height_cm' => $this->decimal($product['productHeight'] ?? null),
                'package_length_cm' => $this->decimal($product['packageLength'] ?? null),
                'package_width_cm' => $this->decimal($product['packageWidth'] ?? null),
                'package_height_cm' => $this->decimal($product['packageHeight'] ?? null),
            ];
        }
        $mapping->fill($values);
        $mapping->save();
    }

    /** @param list<array<string,mixed>> $products */
    private function markConflict(LogisticsTongtuSkuMapping $mapping, array $products): void
    {
        $mapping->forceFill([
            'status' => LogisticsTongtuSkuMapping::STATUS_CONFLICT,
            'next_retry_at' => null,
            'refresh_lock_until' => null,
            'last_error' => 'Multiple Tongtu products matched the same Shopify SKU.',
            'last_response' => json_encode($products, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ])->save();
    }

    private function markNotReturned(LogisticsTongtuSkuMapping $mapping): void
    {
        if ($mapping->status === LogisticsTongtuSkuMapping::STATUS_MATCHED) {
            $mapping->forceFill([
                'next_retry_at' => null,
                'refresh_lock_until' => null,
                'last_error' => 'Tongtu did not return the previously matched SKU; last good product data was retained.',
            ])->save();

            return;
        }

        $mapping->forceFill([
            'status' => LogisticsTongtuSkuMapping::STATUS_FAILED,
            'not_found_attempts' => (int) $mapping->not_found_attempts + 1,
            'next_retry_at' => null,
            'refresh_lock_until' => null,
            'last_error' => 'Tongtu did not return a product for this Shopify SKU.',
        ])->save();
    }

    /** @param Collection<int, LogisticsTongtuSkuMapping> $mappings */
    private function releaseAfterTechnicalFailure(Collection $mappings, string $reason): void
    {
        foreach ($mappings as $mapping) {
            $mapping->forceFill([
                'refresh_lock_until' => null,
                'status' => LogisticsTongtuSkuMapping::STATUS_FAILED,
                'next_retry_at' => null,
                'last_error' => Str::limit($reason, 1000),
            ])->save();
        }
    }

    private function applyMappingToOrderItems(LogisticsTongtuSkuMapping $mapping, ?LogisticsOrder $onlyOrder = null): void
    {
        if (!$onlyOrder) {
            return;
        }

        LogisticsOrderItem::query()
            ->where('logistics_order_id', $onlyOrder->getKey())
            ->whereRaw("LOWER(TRIM(shopify_sku)) = ?", [$mapping->shopify_sku])
            ->update([
                'tongtu_sku_mapping_id' => $mapping->getKey(),
                'tongtu_product_id' => $mapping->tongtu_product_id,
                'tongtu_goods_detail_id' => $mapping->tongtu_goods_detail_id,
                'tongtu_sku' => $mapping->tongtu_sku,
                'tongtu_product_code' => $mapping->tongtu_product_code,
                'tongtu_product_name' => $mapping->tongtu_product_packing_name ?: $mapping->tongtu_product_name,
                'tongtu_product_type' => $mapping->tongtu_product_type,
            ]);
    }

    /** @param array<string,mixed> $product
     *  @return array<string,mixed>
     */
    private function matchingDetail(array $product, string $shopifySku): array
    {
        $key = LogisticsTongtuSkuMapping::normalizedSku($shopifySku);
        $details = array_values(array_filter($product['goodsDetail'] ?? $product['goodsDetails'] ?? [], 'is_array'));
        foreach ($details as $detail) {
            if (LogisticsTongtuSkuMapping::normalizedSku((string) ($detail['goodsSku'] ?? '')) === $key) {
                return $detail;
            }
        }

        // 传入的是产品编号（productCode）时，通途规格 SKU 往往带后缀，
        // 用前缀匹配唯一命中，避免把整个产品的多条规格误判为无法确定。
        // Prefix matching can select a different product variant. Normal SKU
        // matching must be exact; aliases are handled by the API request.
        $prefixed = [];
        if (count($prefixed) === 1) {
            return $prefixed[0];
        }

        // 一个产品下多条规格都以该 SKU 为前缀（例如产品编号对应多个颜色规格）时，
        // 只有当这些规格的重量完全一致才采用，避免取到错误规格的重量。
        if (count($prefixed) > 1) {
            $weights = array_values(array_unique(array_map(
                static fn (array $detail): string => trim((string) ($detail['goodsWeight'] ?? '')),
                $prefixed
            )));
            if (count($weights) === 1) {
                return $prefixed[0];
            }
        }

        return count($details) === 1 ? $details[0] : [];
    }

    /** @param array<string,mixed> $product
     *  @return list<string>
     */
    private function productSkuKeys(array $product): array
    {
        $keys = [];
        // productCode（产品编号）也作为可匹配键：通途侧 SKU 被改名或加后缀
        // 时（例如 XMSantaClaus26093001 → XMSantaClaus26093001-1pc-停用），
        // 仓库仍只有产品编号，用它发起查询也能命中同一个产品。
        foreach (['sku', 'productSku', 'skuAlias'] as $field) {
            $value = $this->text($product[$field] ?? null);
            if ($value !== null) {
                $keys[] = LogisticsTongtuSkuMapping::normalizedSku($value);
            }
        }
        foreach (['skuAliases', 'skuAliasList', 'productSkuAliases'] as $field) {
            $values = $product[$field] ?? [];
            foreach (is_array($values) ? $values : [] as $value) {
                $value = $this->text($value);
                if ($value !== null) {
                    $keys[] = LogisticsTongtuSkuMapping::normalizedSku($value);
                }
            }
        }
        foreach (array_values(array_filter($product['goodsDetail'] ?? $product['goodsDetails'] ?? [], 'is_array')) as $detail) {
            $value = $this->text($detail['goodsSku'] ?? null);
            if ($value !== null) {
                $keys[] = LogisticsTongtuSkuMapping::normalizedSku($value);
            }
        }

        return array_values(array_unique($keys));
    }

    /** @param array<string,mixed> $product
     *  @return array{primary:?string,all:list<array<string,mixed>>}
     */
    private function images(array $product): array
    {
        $all = [];
        foreach (array_values(array_filter($product['productImgList'] ?? [], 'is_array')) as $image) {
            $url = $this->text($image['imageGroupId'] ?? $image['imageUrl'] ?? $image['url'] ?? null);
            if ($url === null) {
                continue;
            }
            $all[] = [
                'url' => $url,
                'image_id' => $this->text($image['imageId'] ?? null),
                'is_primary' => (string) ($image['isPrimaryImage'] ?? '') === '1',
            ];
        }
        $primary = null;
        foreach ($all as $image) {
            if ($image['is_primary']) {
                $primary = $image['url'];
                break;
            }
        }
        $primary ??= $all[0]['url'] ?? null;

        return ['primary' => $primary, 'all' => $all];
    }

    /** @return list<array<string,mixed>> */
    private function goodsRecords(array $body): array
    {
        $records = Arr::get($body, 'datas.array');
        if (!is_array($records)) {
            $records = Arr::get($body, 'datas');
        }

        return is_array($records) ? array_values(array_filter($records, 'is_array')) : [];
    }

    /** @return array{app_token:string, app_secret:string, merchant_id:string} */
    private function authenticatedSession(): array
    {
        $cached = Cache::get('logistics:tongtu:session');
        if (is_array($cached) && filled($cached['app_token'] ?? null) && filled($cached['app_secret'] ?? null) && filled($cached['merchant_id'] ?? null)) {
            return $cached;
        }

        $this->assertConfigured();
        $appSecret = (string) config('logistics.tongtu.app_secret');
        $tokenBody = $this->getJson((string) config('logistics.tongtu.app_token_url'), [
            'accessKey' => (string) config('logistics.tongtu.app_key'),
            'secretAccessKey' => $appSecret,
        ]);
        $this->assertSuccess($tokenBody, 'Tongtu app token');
        $tokenData = Arr::get($tokenBody, 'datas');
        $appToken = is_array($tokenData) ? $this->text($tokenData['appToken'] ?? $tokenData['token'] ?? null) : $this->text($tokenData);
        if ($appToken === null) {
            throw new RuntimeException('Tongtu did not return an app token.');
        }

        $merchantId = trim((string) config('logistics.tongtu.merchant_id'));
        if ($merchantId === '') {
            $merchantId = $this->partnerOpenId($appToken, $appSecret);
        }
        $session = ['app_token' => $appToken, 'app_secret' => $appSecret, 'merchant_id' => $merchantId];
        Cache::put('logistics:tongtu:session', $session, now()->addMinutes(max(1, (int) config('logistics.tongtu.app_token_cache_minutes', 50))));

        return $session;
    }

    /** @param array{app_token:string, app_secret:string, merchant_id:string} $session
     *  @return array<string,string>
     */
    private function authParameters(array $session): array
    {
        return $this->signedParameters($session['app_token'], $session['app_secret']);
    }

    /** @return array<string,string> */
    private function signedParameters(string $appToken, string $appSecret): array
    {
        $timestamp = (string) ((int) floor(microtime(true) * 1000));

        return [
            'app_token' => $appToken,
            'timestamp' => $timestamp,
            'sign' => md5('app_token'.$appToken.'timestamp'.$timestamp.$appSecret),
        ];
    }

    private function partnerOpenId(string $appToken, string $appSecret): string
    {
        $body = $this->getJson((string) config('logistics.tongtu.partner_list_url'), $this->signedParameters($appToken, $appSecret));
        $this->assertSuccess($body, 'Tongtu partner lookup');
        $records = Arr::get($body, 'datas.array', Arr::get($body, 'datas', []));
        $records = is_array($records) && array_key_exists('partnerOpenId', $records) ? [$records] : $records;
        $merchantIds = array_values(array_unique(array_filter(array_map(
            static fn ($record): string => is_array($record) ? trim((string) ($record['partnerOpenId'] ?? '')) : '',
            is_array($records) ? $records : [],
        ))));
        if (count($merchantIds) !== 1) {
            throw new RuntimeException('Tongtu merchant configuration is ambiguous.');
        }

        return $merchantIds[0];
    }

    /** @param array<string,string> $query
     *  @return array<string,mixed>
     */
    private function getJson(string $url, array $query): array
    {
        $this->pacer()->waitForTurn();
        try {
            $response = Http::acceptJson()->timeout(45)->get($url, $query);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Tongtu connection failed: '.$exception->getMessage());
        }

        return $this->responseJson($response, 'Tongtu GET request');
    }

    /** @param array<string,string> $query
     *  @param array<string,mixed> $payload
     *  @return array<string,mixed>
     */
    private function postJson(string $url, array $query, array $payload): array
    {
        $this->pacer()->waitForTurn();
        try {
            $response = Http::acceptJson()->timeout(45)->post($url.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986), $payload);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Tongtu connection failed: '.$exception->getMessage());
        }

        return $this->responseJson($response, 'Tongtu goods request');
    }

    private function pacer(): TongtuRequestPacer
    {
        return app(TongtuRequestPacer::class);
    }

    /** @return array<string,mixed> */
    private function responseJson(\Illuminate\Http\Client\Response $response, string $requestName): array
    {
        if (!$response->successful()) {
            throw new RuntimeException($requestName.' failed (HTTP '.$response->status().'): '.Str::limit($response->body(), 500));
        }
        $body = $response->json();
        if (!is_array($body)) {
            throw new RuntimeException($requestName.' returned invalid JSON.');
        }

        return $body;
    }

    /** @param array<string,mixed> $body */
    private function assertSuccess(array $body, string $action): void
    {
        $code = $body['code'] ?? null;
        if (!in_array($code, [0, '0', 200, '200'], true) && ($body['success'] ?? null) !== true) {
            throw new RuntimeException($action.' failed: '.(string) ($body['message'] ?? $body['msg'] ?? 'Unknown error'));
        }
    }

    private function assertConfigured(): void
    {
        foreach (['app_key', 'app_secret', 'app_token_url', 'partner_list_url', 'goods_query_url'] as $key) {
            if (blank(config('logistics.tongtu.'.$key))) {
                throw new RuntimeException('Tongtu configuration is incomplete.');
            }
        }
    }

    private function text(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function decimal(mixed $value): ?string
    {
        return is_numeric($value) ? number_format(round((float) $value, 4), 4, '.', '') : null;
    }

    private function date(mixed $value): ?Carbon
    {
        if (blank($value)) {
            return null;
        }
        try {
            return Carbon::parse((string) $value);
        } catch (Throwable) {
            return null;
        }
    }
}
