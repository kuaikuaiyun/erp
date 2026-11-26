<?php

namespace App\Portals\Operation;

use App\Models\AdminUser;
use App\Models\AssistantDomainStore;
use App\Models\AssistantDomainStoreOwnerHistory;
use App\Models\AssistantShopifyStoreOrder;
use App\Models\LogisticsOrder;
use App\Models\LogisticsOrderItem;
use App\Models\LogisticsTongtuSkuMapping;
use App\Portals\Assistant\AssistantStoreOwnerAttributionService;
use App\Portals\Assistant\ShopifyAnalyticsReferenceRateService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class OperationReportMetricsService
{
    private const CNY_PER_USD = 6.5;

    /** 运营业绩按固定 UTC-4 统计，不跟随夏令时变化。 */
    public const REPORT_TIMEZONE = '-04:00';

    public function __construct(
        private readonly AssistantStoreOwnerAttributionService $ownerAttribution,
        private readonly ShopifyAnalyticsReferenceRateService $referenceRates,
    ) {}

    /** @return list<array{sku:string,product_name:string,missing_fields:list<string>,item_count:int,quantity:float,latest_order_at:?string}> */
    public function missingGuideSkuSummary(array $excludedStoreNames = []): array
    {
        $mappedValues = [];
        LogisticsTongtuSkuMapping::query()
            ->get(['shopify_sku', 'tongtu_sku', 'tongtu_product_code', 'product_guide_cost_cny', 'goods_weight_gram'])
            ->each(function (LogisticsTongtuSkuMapping $mapping) use (&$mappedValues): void {
                foreach ([$mapping->shopify_sku, $mapping->tongtu_sku, $mapping->tongtu_product_code] as $value) {
                    $value = mb_strtolower(trim((string) $value));
                    if ($value === '') {
                        continue;
                    }
                    $mappedValues[$value] ??= ['guide' => false, 'weight' => false];
                    $mappedValues[$value]['guide'] = $mappedValues[$value]['guide'] || (float) $mapping->product_guide_cost_cny > 0;
                    $mappedValues[$value]['weight'] = $mappedValues[$value]['weight'] || (float) $mapping->goods_weight_gram > 0;
                }
            });

        return LogisticsOrderItem::query()
            ->join('logistics_orders', 'logistics_orders.id', '=', 'logistics_order_items.logistics_order_id')
            ->when($excludedStoreNames !== [], fn ($query) => $query->whereNotIn(
                DB::raw("LOWER(TRIM(COALESCE(logistics_orders.store_name, '')))"),
                collect($excludedStoreNames)->map(fn ($name) => mb_strtolower(trim((string) $name)))->filter()->values()->all(),
            ))
            ->whereIn('logistics_orders.subscription_type', ['订阅首次购买', '无订阅'])
            ->where(function ($query): void {
                $query->whereRaw("TRIM(COALESCE(logistics_order_items.shopify_sku, '')) <> ''")
                    ->orWhereRaw("TRIM(COALESCE(logistics_order_items.tongtu_sku, '')) <> ''")
                    ->orWhereRaw("TRIM(COALESCE(logistics_order_items.tongtu_product_code, '')) <> ''");
            })
            ->select([
                'logistics_order_items.shopify_sku',
                'logistics_order_items.tongtu_sku',
                'logistics_order_items.tongtu_product_code',
            ])
            ->selectRaw('MAX(logistics_order_items.product_title) as product_name')
            ->selectRaw('COUNT(*) as item_count')
            ->selectRaw('SUM(logistics_order_items.quantity) as total_quantity')
            ->selectRaw('MAX(GREATEST(COALESCE(logistics_order_items.sale_weight_gram, 0), COALESCE(logistics_order_items.tongtu_goods_weight_gram, 0))) as saved_weight_gram')
            ->selectRaw('MAX(logistics_orders.order_created_at) as latest_order_at')
            ->groupBy('logistics_order_items.shopify_sku', 'logistics_order_items.tongtu_sku', 'logistics_order_items.tongtu_product_code')
            ->get()
            ->filter(function ($row) use ($mappedValues): bool {
                $coverage = ['guide' => false, 'weight' => (float) $row->saved_weight_gram > 0];
                foreach ([$row->shopify_sku, $row->tongtu_sku, $row->tongtu_product_code] as $value) {
                    $value = mb_strtolower(trim((string) $value));
                    if ($value === '' || ! isset($mappedValues[$value])) {
                        continue;
                    }
                    $coverage['guide'] = $coverage['guide'] || $mappedValues[$value]['guide'];
                    $coverage['weight'] = $coverage['weight'] || $mappedValues[$value]['weight'];
                }
                $row->missing_fields = array_values(array_filter([
                    $coverage['guide'] ? null : '指导价',
                    $coverage['weight'] ? null : '重量',
                ]));

                return $row->missing_fields !== [];
            })
            ->map(function ($row): array {
                $sku = collect([$row->shopify_sku, $row->tongtu_sku, $row->tongtu_product_code])
                    ->map(fn ($value) => trim((string) $value))->first(fn ($value) => $value !== '');

                return [
                    'sku' => $sku ?: '未填写 SKU',
                    'product_name' => trim((string) $row->product_name) ?: '—',
                    'missing_fields' => $row->missing_fields,
                    'item_count' => (int) $row->item_count,
                    'quantity' => (float) $row->total_quantity,
                    'latest_order_at' => $row->latest_order_at
                        ? Carbon::parse($row->latest_order_at, 'UTC')->timezone(self::REPORT_TIMEZONE)->format('Y-m-d H:i:s')
                        : null,
                ];
            })
            ->sortByDesc('latest_order_at')
            ->values()
            ->all();
    }

    /** @return list<array{item_id:int,order_id:int,product_name:string,shop_domain:string,quantity:float,ordered_at:string}> */
    public function missingOriginalSkuSummary(?string $date = null, array $excludedStoreNames = []): array
    {
        $start = $date !== null
            ? Carbon::createFromFormat('Y-m-d H:i:s', $date.' 00:00:00', self::REPORT_TIMEZONE)->utc()
            : null;
        $end = $date !== null
            ? Carbon::createFromFormat('Y-m-d H:i:s', $date.' 23:59:59', self::REPORT_TIMEZONE)->utc()
            : Carbon::now('UTC');

        $items = LogisticsOrderItem::query()
            ->join('logistics_orders', 'logistics_orders.id', '=', 'logistics_order_items.logistics_order_id')
            ->leftJoin('assistant_domain_stores', 'assistant_domain_stores.id', '=', 'logistics_orders.assistant_domain_store_id')
            ->when($excludedStoreNames !== [], fn ($query) => $query->whereNotIn(
                DB::raw("LOWER(TRIM(COALESCE(logistics_orders.store_name, '')))"),
                collect($excludedStoreNames)->map(fn ($name) => mb_strtolower(trim((string) $name)))->filter()->values()->all(),
            ))
            ->when(
                $start !== null,
                fn ($query) => $query->whereBetween('logistics_orders.order_created_at', [$start, $end]),
                fn ($query) => $query->where('logistics_orders.order_created_at', '<=', $end),
            )
            ->where(function ($query): void {
                $query->whereNull('logistics_order_items.shopify_sku')
                    ->orWhereRaw("TRIM(logistics_order_items.shopify_sku) = ''");
            })
            ->whereRaw("LOWER(TRIM(COALESCE(logistics_order_items.product_title, ''))) <> 'tipping'")
            ->whereRaw("LOWER(TRIM(COALESCE(logistics_order_items.product_title, ''))) NOT LIKE '%shipping protection%'")
            ->whereRaw("LOWER(TRIM(COALESCE(logistics_order_items.product_title, ''))) NOT LIKE '%shipping insurance%'")
            ->whereRaw("LOWER(TRIM(COALESCE(logistics_order_items.product_title, ''))) NOT LIKE '%freight insurance%'")
            ->whereRaw("TRIM(COALESCE(logistics_order_items.tongtu_product_name, '')) NOT LIKE '%运费险%'")
            ->orderByDesc('logistics_orders.order_created_at')
            ->orderByDesc('logistics_order_items.id')
            ->get([
                'logistics_orders.id as order_id',
                'logistics_orders.order_created_at',
                'assistant_domain_stores.domain as managed_domain',
                'logistics_orders.source_shop_domain',
                'logistics_orders.store_name',
                'logistics_order_items.id as item_id',
                'logistics_order_items.product_title',
                'logistics_order_items.variant_title',
                'logistics_order_items.tongtu_product_name',
                'logistics_order_items.quantity',
            ]);

        return $items
            ->unique(static function ($row): string {
                $productName = trim((string) ($row->product_title ?: $row->tongtu_product_name ?: ''));
                $variantName = trim((string) $row->variant_title);

                return mb_strtolower($productName.'|'.$variantName);
            })
            ->map(static function ($row): array {
                return [
                    'item_id' => (int) $row->item_id,
                    'order_id' => (int) $row->order_id,
                    'product_name' => trim((string) ($row->product_title ?: $row->tongtu_product_name ?: $row->variant_title)) ?: '未填写商品名称',
                    'shop_domain' => trim((string) ($row->managed_domain ?: $row->source_shop_domain ?: $row->store_name)) ?: '未知店铺',
                    'quantity' => (float) $row->quantity,
                    'ordered_at' => Carbon::parse($row->order_created_at, 'UTC')->timezone(self::REPORT_TIMEZONE)->format('Y-m-d H:i:s'),
                ];
            })
            ->all();
    }

    /** @return array{rows:list<array<string,mixed>>,totals:array<string,float|int>,warnings:list<string>} */
    public function build(string $date, int $operatorUserId): array
    {
        return $this->buildRange($date, $date, $operatorUserId);
    }

    /** @return array{rows:list<array<string,mixed>>,totals:array<string,float|int>,warnings:list<string>} */
    public function buildRange(string $dateFrom, string $dateTo, int $operatorUserId): array
    {
        $start = Carbon::createFromFormat('Y-m-d H:i:s', $dateFrom.' 00:00:00', self::REPORT_TIMEZONE)->utc();
        $end = Carbon::createFromFormat('Y-m-d H:i:s', $dateTo.' 23:59:59', self::REPORT_TIMEZONE)->utc();
        $operator = AdminUser::query()->find($operatorUserId);
        $operatorUsers = $operator ? collect([$operator]) : collect();
        $operatorIdentityMap = $this->operatorIdentityMap($operatorUsers);
        $operatorSearchValues = $this->operatorSearchValues($operatorUsers);
        $orders = LogisticsOrder::query()
            ->with(['items', 'store'])
            ->whereBetween('order_created_at', [$start, $end])
            ->where(function ($query) use ($operatorUserId, $operatorSearchValues): void {
                $query->where('assigned_owner_user_id', $operatorUserId)
                    ->orWhere(function ($legacy) use ($operatorUserId, $operatorSearchValues): void {
                        $legacy->whereNull('assigned_owner_user_id')
                            ->where(function ($fallback) use ($operatorUserId, $operatorSearchValues): void {
                                $fallback->whereHas('store', fn ($store) => $store->where('owner_user_id', $operatorUserId));
                                if ($operatorSearchValues !== []) {
                                    $fallback->orWhereIn('operator_name', $operatorSearchValues);
                                }
                            });
                    });
            })
            ->orderBy('order_created_at')
            ->get()
            ->filter(fn (LogisticsOrder $order): bool => $this->resolveOperatorId($order, $operatorIdentityMap, [$operatorUserId]) === $operatorUserId)
            ->values();
        $operatorName = (string) ($operator?->name ?: $operator?->username ?: '未分配');

        $mappingIds = $orders->flatMap(fn ($order) => $order->items->pluck('tongtu_sku_mapping_id'))->filter()->unique();
        $skuValues = $orders->flatMap(fn ($order) => $order->items->flatMap(fn ($item) => [
            trim((string) $item->shopify_sku), trim((string) $item->tongtu_sku), trim((string) $item->tongtu_product_code),
        ]))->filter()->unique()->values();
        $mappingRows = LogisticsTongtuSkuMapping::query()
            ->where(function ($query) use ($mappingIds, $skuValues): void {
                if ($mappingIds->isNotEmpty()) {
                    $query->orWhereIn('id', $mappingIds);
                }
                if ($skuValues->isNotEmpty()) {
                    $query->orWhereIn('shopify_sku', $skuValues)
                        ->orWhereIn('tongtu_sku', $skuValues)
                        ->orWhereIn('tongtu_product_code', $skuValues);
                }
            })->get();
        $mappings = $mappingRows->keyBy('id');
        $mappingByValue = [];
        foreach ($mappingRows as $mapping) {
            foreach ([$mapping->shopify_sku, $mapping->tongtu_sku, $mapping->tongtu_product_code] as $value) {
                $value = mb_strtolower(trim((string) $value));
                if ($value !== '') {
                    $mappingByValue[$value] = $mapping;
                }
            }
        }
        $groups = [];
        $missingGuide = 0;
        $missingGuideSkus = [];
        $missingWeight = 0;

        foreach ($orders as $order) {
            // “域名与店铺信息管理”是店铺域名的权威数据源；订单字段仅作为未关联店铺时的兜底。
            $domain = trim((string) ($order->store?->domain ?: $order->source_shop_domain ?: $order->store_name ?: '未知店铺'));
            $media = trim((string) ($order->media_source ?: LogisticsOrder::MEDIA_SOURCE_OTHER));
            $rowDate = Carbon::parse($order->getRawOriginal('order_created_at'), 'UTC')->timezone(self::REPORT_TIMEZONE)->format('Y-m-d');
            $key = $rowDate."\x1F".$domain."\x1F".$media;
            $groups[$key] ??= $this->emptyRow($rowDate, $operatorName, $domain, $media);
            $row = &$groups[$key];
            $amount = (float) ($order->paid_amount_usd ?? 0);
            $isSubscriptionFirst = $order->subscription_type === '订阅首次购买';
            $isNormalFirst = $order->subscription_type === '无订阅';
            $isRenewal = str_starts_with((string) $order->subscription_type, '订阅复购');

            if ($isSubscriptionFirst) {
                $row['first_subscription_orders']++;
                $row['first_subscription_revenue'] += $amount;
            } elseif ($isNormalFirst) {
                $row['first_normal_orders']++;
                $row['first_normal_revenue'] += $amount;
            } elseif ($isRenewal) {
                $row['renewal_orders']++;
                $row['renewal_revenue'] += $amount;
            }

            if ($isSubscriptionFirst || $isNormalFirst) {
                $orderWeight = 0.0;
                foreach ($order->items as $item) {
                    $quantity = max(0.0, (float) $item->quantity);
                    $mapping = $item->tongtu_sku_mapping_id ? $mappings->get($item->tongtu_sku_mapping_id) : null;
                    if (! $mapping) {
                        foreach ([$item->shopify_sku, $item->tongtu_sku, $item->tongtu_product_code] as $value) {
                            $lookup = mb_strtolower(trim((string) $value));
                            if ($lookup !== '' && isset($mappingByValue[$lookup])) {
                                $mapping = $mappingByValue[$lookup];
                                break;
                            }
                        }
                    }
                    $guide = $mapping?->product_guide_cost_cny;
                    $weight = $item->sale_weight_gram ?: $item->tongtu_goods_weight_gram ?: $mapping?->goods_weight_gram;
                    if ($guide === null || (float) $guide <= 0) {
                        $missingGuide++;
                        $missingGuideSkus[] = $this->itemSku($item);
                    } else {
                        $row['first_purchase_cost'] += (float) $guide * $quantity / self::CNY_PER_USD;
                    }
                    if ($weight === null || (float) $weight <= 0) {
                        $missingWeight++;
                    } else {
                        $orderWeight += (float) $weight * $quantity;
                    }
                }
                $row['first_shipping_cost'] += ($orderWeight / 1000 * 80 + 20) / self::CNY_PER_USD;
            }
            unset($row);
        }

        $rows = array_values(array_map(function (array $row): array {
            $row['first_total_orders'] = $row['first_subscription_orders'] + $row['first_normal_orders'];
            $row['first_total_revenue'] = $row['first_subscription_revenue'] + $row['first_normal_revenue'];
            $row['first_return_fee'] = $row['first_total_revenue'] * 0.12;
            $row['renewal_cost'] = 0.0;
            $row['total_orders'] = $row['first_total_orders'] + $row['renewal_orders'];
            $row['total_revenue'] = $row['first_total_revenue'] + $row['renewal_revenue'];
            foreach ($row as $field => $value) {
                if (is_float($value)) {
                    $row[$field] = round($value, 4);
                }
            }

            return $row;
        }, $groups));
        usort($rows, fn (array $a, array $b) => [$a['date'], $a['shop_domain'], $a['media_source']] <=> [$b['date'], $b['shop_domain'], $b['media_source']]);

        $numericFields = ['first_subscription_orders', 'first_subscription_revenue', 'first_normal_orders', 'first_normal_revenue', 'first_total_orders', 'first_total_revenue', 'first_purchase_cost', 'first_shipping_cost', 'first_return_fee', 'renewal_orders', 'renewal_revenue', 'renewal_cost', 'total_orders', 'total_revenue'];
        $totals = array_fill_keys($numericFields, 0.0);
        foreach ($rows as $row) {
            foreach ($numericFields as $field) {
                $totals[$field] += (float) $row[$field];
            }
        }

        $warnings = [];
        if ($missingGuide > 0) {
            $skus = implode('、', array_values(array_unique($missingGuideSkus)));
            $warnings[] = $missingGuide.' 个首单商品缺少指导价，采购费未包含这些商品。对应 SKU：'.$skus.'。';
        }
        if ($missingWeight > 0) {
            $warnings[] = $missingWeight.' 个首单商品缺少重量，运费可能偏低。';
        }

        return ['rows' => $rows, 'totals' => $totals, 'warnings' => $warnings];
    }

    /** @param list<int> $operatorUserIds
     * @return array<int,array{rows:list<array<string,mixed>>,totals:array<string,float|int>,warnings:list<string>}>
     */
    public function buildRangeForOperators(string $dateFrom, string $dateTo, array $operatorUserIds, string $timezone = self::REPORT_TIMEZONE, bool $requireBothSubscriptionTags = false, bool $attributeByStoreOwnerHistory = false, bool $useShopifySourceMetrics = false): array
    {
        $operatorUserIds = collect($operatorUserIds)->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
        if ($operatorUserIds === []) {
            return [];
        }
        $start = Carbon::createFromFormat('Y-m-d H:i:s', $dateFrom.' 00:00:00', $timezone)->utc();
        $end = Carbon::createFromFormat('Y-m-d H:i:s', $dateTo.' 23:59:59', $timezone)->utc();
        $operatorUsers = AdminUser::query()->whereIn('id', $operatorUserIds)->get();
        $operatorIdentityMap = $this->operatorIdentityMap($operatorUsers);
        $operatorSearchValues = $this->operatorSearchValues($operatorUsers);
        $historyStoreIds = $attributeByStoreOwnerHistory
            ? AssistantDomainStoreOwnerHistory::query()
                ->whereIn('owner_user_id', $operatorUserIds)
                ->pluck('assistant_domain_store_id')->map(fn ($id) => (int) $id)->unique()->values()
            : collect();
        $ownerTimelines = $attributeByStoreOwnerHistory
            ? $this->ownerAttribution->timelines($historyStoreIds)
            : collect();
        $orders = LogisticsOrder::query()->with(['items', 'store'])
            ->whereBetween('order_created_at', [$start, $end])
            ->where(function ($query) use ($operatorUserIds, $operatorSearchValues, $historyStoreIds): void {
                if ($historyStoreIds->isNotEmpty()) {
                    $query->whereIn('assistant_domain_store_id', $historyStoreIds);
                }
                $method = $historyStoreIds->isNotEmpty() ? 'orWhere' : 'where';
                $query->{$method}(function ($assigned) use ($operatorUserIds, $operatorSearchValues): void {
                    $assigned->whereIn('assigned_owner_user_id', $operatorUserIds)
                        ->orWhere(function ($legacy) use ($operatorUserIds, $operatorSearchValues): void {
                            $legacy->whereNull('assigned_owner_user_id')
                                ->where(function ($fallback) use ($operatorUserIds, $operatorSearchValues): void {
                                    $fallback->whereHas('store', fn ($store) => $store->whereIn('owner_user_id', $operatorUserIds));
                                    if ($operatorSearchValues !== []) {
                                        $fallback->orWhereIn('operator_name', $operatorSearchValues);
                                    }
                                });
                        });
                });
            })
            ->orderBy('order_created_at')->get();
        $operatorNames = $operatorUsers->mapWithKeys(
            fn (AdminUser $user) => [$user->getKey() => (string) ($user->name ?: $user->username ?: '未分配')]
        );
        $mappingIds = $orders->flatMap(fn ($order) => $order->items->pluck('tongtu_sku_mapping_id'))->filter()->unique();
        $skuValues = $orders->flatMap(fn ($order) => $order->items->flatMap(fn ($item) => [trim((string) $item->shopify_sku), trim((string) $item->tongtu_sku), trim((string) $item->tongtu_product_code)]))->filter()->unique()->values();
        $mappingRows = LogisticsTongtuSkuMapping::query()->where(function ($query) use ($mappingIds, $skuValues): void {
            if ($mappingIds->isNotEmpty()) {
                $query->orWhereIn('id', $mappingIds);
            }
            if ($skuValues->isNotEmpty()) {
                $query->orWhereIn('shopify_sku', $skuValues)->orWhereIn('tongtu_sku', $skuValues)->orWhereIn('tongtu_product_code', $skuValues);
            }
        })->get();
        $mappings = $mappingRows->keyBy('id');
        $mappingByValue = [];
        foreach ($mappingRows as $mapping) {
            foreach ([$mapping->shopify_sku, $mapping->tongtu_sku, $mapping->tongtu_product_code] as $value) {
                $value = mb_strtolower(trim((string) $value));
                if ($value !== '') {
                    $mappingByValue[$value] = $mapping;
                }
            }
        }
        $groups = $missingGuide = $missingGuideSkus = $missingWeight = [];
        $logisticsOrderMetrics = [];
        $logisticsOwnerByShopifyOrder = [];
        foreach ($orders as $order) {
            $storeId = (int) ($order->assistant_domain_store_id ?? 0);
            if ($attributeByStoreOwnerHistory && $ownerTimelines->has($storeId)) {
                $orderedAt = $order->getRawOriginal('order_created_at') ?: $order->order_created_at;
                $operatorId = $this->ownerAttribution->resolve($ownerTimelines, $storeId, $orderedAt)['owner_user_id'];
                if (! in_array($operatorId, $operatorUserIds, true)) {
                    $operatorId = null;
                }
            } else {
                $operatorId = $this->resolveOperatorId($order, $operatorIdentityMap, $operatorUserIds);
            }
            if ($operatorId === null) {
                continue;
            }
            $normalizedShopifyOrderId = $this->normalizedShopifyOrderId($order->shopify_order_id);
            if ($normalizedShopifyOrderId !== '') {
                $logisticsOwnerByShopifyOrder[$normalizedShopifyOrderId] = $operatorId;
            }
            $domain = trim((string) ($order->store?->domain ?: $order->source_shop_domain ?: $order->store_name ?: '未知店铺'));
            $media = trim((string) ($order->media_source ?: LogisticsOrder::MEDIA_SOURCE_OTHER));
            $rowDate = Carbon::parse($order->getRawOriginal('order_created_at'), 'UTC')->timezone($timezone)->format('Y-m-d');
            $key = $rowDate."\x1F".$domain."\x1F".$media;
            $groups[$operatorId][$key] ??= $this->emptyRow($rowDate, (string) $operatorNames->get($operatorId, '未分配'), $domain, $media);
            $row = &$groups[$operatorId][$key];
            $amount = (float) ($order->paid_amount_usd ?? 0);
            $isSubscriptionFirst = $order->subscription_type === '订阅首次购买';
            $isNormalFirst = $order->subscription_type === '无订阅';
            $isRenewal = str_starts_with((string) $order->subscription_type, '订阅复购');
            if ($requireBothSubscriptionTags) {
                $payload = $order->shopify_payload;
                if (is_string($payload)) {
                    $payload = json_decode($payload, true);
                }
                $rawTags = is_array($payload) ? ($payload['tags'] ?? []) : [];
                $rawTags = is_string($rawTags) ? explode(',', $rawTags) : $rawTags;
                $tags = collect(is_array($rawTags) ? $rawTags : [])
                    ->filter(fn ($tag) => is_scalar($tag))
                    ->map(fn ($tag) => mb_strtolower(trim((string) $tag)));
                $isRenewal = $isRenewal || $tags->contains('ouyicart subscription recurring order');
                $isSubscriptionFirst = ! $isRenewal
                    && $tags->contains('subscription')
                    && $tags->contains('subscription first order');
                $isNormalFirst = ! $isRenewal && ! $isSubscriptionFirst && $isNormalFirst;
            }
            if ($isSubscriptionFirst) {
                $row['first_subscription_orders']++;
                $row['first_subscription_revenue'] += $amount;
            } elseif ($isNormalFirst) {
                $row['first_normal_orders']++;
                $row['first_normal_revenue'] += $amount;
            } elseif ($isRenewal) {
                $row['renewal_orders']++;
                $row['renewal_revenue'] += $amount;
            }
            $orderPurchaseCost = 0.0;
            $orderShippingCost = 0.0;
            if ($isSubscriptionFirst || $isNormalFirst) {
                $orderWeight = 0.0;
                foreach ($order->items as $item) {
                    $quantity = max(0.0, (float) $item->quantity);
                    $mapping = $item->tongtu_sku_mapping_id ? $mappings->get($item->tongtu_sku_mapping_id) : null;
                    if (! $mapping) {
                        foreach ([$item->shopify_sku, $item->tongtu_sku, $item->tongtu_product_code] as $value) {
                            $lookup = mb_strtolower(trim((string) $value));
                            if ($lookup !== '' && isset($mappingByValue[$lookup])) {
                                $mapping = $mappingByValue[$lookup];
                                break;
                            }
                        }
                    }
                    $guide = $mapping?->product_guide_cost_cny;
                    $weight = $item->sale_weight_gram ?: $item->tongtu_goods_weight_gram ?: $mapping?->goods_weight_gram;
                    if ($guide === null || (float) $guide <= 0) {
                        $missingGuide[$operatorId] = ($missingGuide[$operatorId] ?? 0) + 1;
                        $missingGuideSkus[$operatorId][] = $this->itemSku($item);
                    } else {
                        $itemPurchaseCost = (float) $guide * $quantity / self::CNY_PER_USD;
                        $row['first_purchase_cost'] += $itemPurchaseCost;
                        $orderPurchaseCost += $itemPurchaseCost;
                    }
                    if ($weight === null || (float) $weight <= 0) {
                        $missingWeight[$operatorId] = ($missingWeight[$operatorId] ?? 0) + 1;
                    } else {
                        $orderWeight += (float) $weight * $quantity;
                    }
                }
                $orderShippingCost = ($orderWeight / 1000 * 80 + 20) / self::CNY_PER_USD;
                $row['first_shipping_cost'] += $orderShippingCost;
            }
            if ($isSubscriptionFirst || $isNormalFirst || $isRenewal) {
                $logisticsOrderMetrics[] = [
                    'assistant_domain_store_id' => $storeId,
                    'shopify_order_id' => $normalizedShopifyOrderId,
                    'operator_id' => $operatorId,
                    'date' => $rowDate,
                    'shop_domain' => $domain,
                    'media_source' => $media,
                    'amount' => $amount,
                    'first_purchase_cost' => $orderPurchaseCost,
                    'first_shipping_cost' => $orderShippingCost,
                    'category' => $isSubscriptionFirst
                        ? AssistantShopifyStoreOrder::SUBSCRIPTION_CATEGORY_SUBSCRIBED
                        : ($isNormalFirst
                            ? AssistantShopifyStoreOrder::SUBSCRIPTION_CATEGORY_REGULAR
                            : AssistantShopifyStoreOrder::SUBSCRIPTION_CATEGORY_RECURRING),
                ];
            }
            unset($row);
        }

        // Prefer the Shopify analytics source once an order has reached it, but
        // immediately supplement it with logistics orders that have not reached
        // that source yet. This keeps the report current without double-counting
        // an order after the independent Shopify analytics synchronization runs.
        if ($useShopifySourceMetrics) {
            $groups = $this->mergeOrderMetricsWithShopifySource(
                $groups,
                $orders->pluck('assistant_domain_store_id')->map(fn ($id) => (int) $id)->all(),
                $logisticsOrderMetrics,
                $operatorUserIds,
                $operatorNames,
                $logisticsOwnerByShopifyOrder,
                $start,
                $end,
                $timezone,
            );
        }

        $results = [];
        $numericFields = ['first_subscription_orders', 'first_subscription_revenue', 'first_normal_orders', 'first_normal_revenue', 'first_total_orders', 'first_total_revenue', 'first_purchase_cost', 'first_shipping_cost', 'first_return_fee', 'renewal_orders', 'renewal_revenue', 'renewal_cost', 'total_orders', 'total_revenue'];
        foreach ($operatorUserIds as $operatorId) {
            $rows = array_values(array_map(function (array $row): array {
                $row['first_total_orders'] = $row['first_subscription_orders'] + $row['first_normal_orders'];
                $row['first_total_revenue'] = $row['first_subscription_revenue'] + $row['first_normal_revenue'];
                $row['first_return_fee'] = $row['first_total_revenue'] * 0.12;
                $row['renewal_cost'] = 0.0;
                $row['total_orders'] = $row['first_total_orders'] + $row['renewal_orders'];
                $row['total_revenue'] = $row['first_total_revenue'] + $row['renewal_revenue'];
                foreach ($row as $field => $value) {
                    if (is_float($value)) {
                        $row[$field] = round($value, 4);
                    }
                }

                return $row;
            }, $groups[$operatorId] ?? []));
            $rows = array_values(array_filter(
                $rows,
                static fn (array $row): bool => (int) ($row['total_orders'] ?? 0) > 0
                    || (float) ($row['first_purchase_cost'] ?? 0) > 0
                    || (float) ($row['first_shipping_cost'] ?? 0) > 0,
            ));
            usort($rows, fn (array $a, array $b) => [$a['date'], $a['shop_domain'], $a['media_source']] <=> [$b['date'], $b['shop_domain'], $b['media_source']]);
            $totals = array_fill_keys($numericFields, 0.0);
            foreach ($rows as $row) {
                foreach ($numericFields as $field) {
                    $totals[$field] += (float) $row[$field];
                }
            }
            $warnings = [];
            if (($missingGuide[$operatorId] ?? 0) > 0) {
                $warnings[] = $missingGuide[$operatorId].' 个首单商品缺少指导价，采购费未包含这些商品。对应 SKU：'.implode('、', array_unique($missingGuideSkus[$operatorId] ?? [])).'。';
            }
            if (($missingWeight[$operatorId] ?? 0) > 0) {
                $warnings[] = $missingWeight[$operatorId].' 个首单商品缺少重量，运费可能偏低。';
            }
            $results[$operatorId] = ['rows' => $rows, 'totals' => $totals, 'warnings' => $warnings];
        }

        return $results;
    }

    /**
     * @param  array<int,array<string,array<string,mixed>>>  $groups
     * @param  list<int>  $logisticsStoreIds
     * @param  list<array<string,mixed>>  $logisticsOrderMetrics
     * @param  list<int>  $operatorUserIds
     * @param  array<string,int>  $logisticsOwnerByShopifyOrder
     * @return array<int,array<string,array<string,mixed>>>
     */
    private function mergeOrderMetricsWithShopifySource(
        array $groups,
        array $logisticsStoreIds,
        array $logisticsOrderMetrics,
        array $operatorUserIds,
        $operatorNames,
        array $logisticsOwnerByShopifyOrder,
        Carbon $start,
        Carbon $end,
        string $timezone,
    ): array {
        $historyStoreIds = AssistantDomainStoreOwnerHistory::query()
            ->whereIn('owner_user_id', $operatorUserIds)
            ->pluck('assistant_domain_store_id')
            ->map(fn ($id): int => (int) $id);
        $currentStoreIds = AssistantDomainStore::query()
            ->whereIn('owner_user_id', $operatorUserIds)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id);
        $storeIds = collect($logisticsStoreIds)
            ->merge($historyStoreIds)
            ->merge($currentStoreIds)
            ->filter()
            ->unique()
            ->values();

        if ($storeIds->isEmpty()) {
            return $groups;
        }

        foreach ($groups as &$operatorGroups) {
            foreach ($operatorGroups as &$row) {
                foreach ([
                    'first_subscription_orders', 'first_subscription_revenue',
                    'first_normal_orders', 'first_normal_revenue',
                    'first_purchase_cost', 'first_shipping_cost',
                    'renewal_orders', 'renewal_revenue',
                ] as $field) {
                    $row[$field] = 0;
                }
            }
            unset($row);
        }
        unset($operatorGroups);

        $stores = AssistantDomainStore::query()
            ->whereIn('id', $storeIds->all())
            ->get(['id', 'domain', 'shop_name', 'owner_user_id'])
            ->keyBy(fn (AssistantDomainStore $store): int => (int) $store->getKey());
        $ownerTimelines = $this->ownerAttribution->timelines($storeIds);
        $operatorLookup = array_fill_keys($operatorUserIds, true);

        $sourceOrders = AssistantShopifyStoreOrder::query()
            ->whereIn('assistant_domain_store_id', $storeIds->all())
            ->where('shopify_created_at', '>=', $start)
            ->where('shopify_created_at', '<', $end->copy()->addSecond())
            ->where('is_test', false)
            ->whereIn('financial_status', AssistantShopifyStoreOrder::successfulFinancialStatuses())
            ->whereIn('subscription_category', AssistantShopifyStoreOrder::subscriptionCategories())
            ->orderBy('shopify_created_at')
            ->get();

        $logisticsMetricIndexesByOrder = [];
        foreach ($logisticsOrderMetrics as $index => $metric) {
            $normalizedOrderId = (string) ($metric['shopify_order_id'] ?? '');
            if ($normalizedOrderId !== '') {
                $logisticsMetricIndexesByOrder[(int) ($metric['assistant_domain_store_id'] ?? 0)."\x1F".$normalizedOrderId] = $index;
            }
        }
        $consumedLogisticsMetricIndexes = [];
        foreach ($sourceOrders as $sourceOrder) {
            $storeId = (int) $sourceOrder->assistant_domain_store_id;
            $store = $stores->get($storeId);
            if (! $store) {
                continue;
            }

            $normalizedOrderId = $this->normalizedShopifyOrderId($sourceOrder->shopify_order_id);
            $operatorId = $normalizedOrderId !== ''
                ? ($logisticsOwnerByShopifyOrder[$normalizedOrderId] ?? null)
                : null;
            if ($operatorId === null) {
                $attribution = $this->ownerAttribution->resolve(
                    $ownerTimelines,
                    $storeId,
                    $sourceOrder->getRawOriginal('shopify_created_at') ?: $sourceOrder->shopify_created_at,
                );
                $operatorId = $attribution['owner_user_id'] ?? $store->owner_user_id;
            }
            $operatorId = $operatorId === null ? null : (int) $operatorId;
            if ($operatorId === null || ! isset($operatorLookup[$operatorId])) {
                continue;
            }

            $rowDate = Carbon::parse(
                $sourceOrder->getRawOriginal('shopify_created_at'),
                'UTC',
            )->timezone($timezone)->format('Y-m-d');
            $domain = trim((string) ($store->domain ?: $store->shop_name)) ?: '未知店铺';
            $media = LogisticsOrder::mediaSourceFromTags($sourceOrder->shopify_tags);
            $key = $rowDate."\x1F".$domain."\x1F".$media;
            $groups[$operatorId][$key] ??= $this->emptyRow(
                $rowDate,
                (string) $operatorNames->get($operatorId, '未分配'),
                $domain,
                $media,
            );
            $row = &$groups[$operatorId][$key];
            $amount = $this->referenceRates->convertToUsd(
                $sourceOrder->original_currency_code,
                $sourceOrder->original_total_price,
            ) ?? 0.0;

            if ($sourceOrder->subscription_category === AssistantShopifyStoreOrder::SUBSCRIPTION_CATEGORY_SUBSCRIBED) {
                $row['first_subscription_orders']++;
                $row['first_subscription_revenue'] += $amount;
            } elseif ($sourceOrder->subscription_category === AssistantShopifyStoreOrder::SUBSCRIPTION_CATEGORY_REGULAR) {
                $row['first_normal_orders']++;
                $row['first_normal_revenue'] += $amount;
            } elseif ($sourceOrder->subscription_category === AssistantShopifyStoreOrder::SUBSCRIPTION_CATEGORY_RECURRING) {
                $row['renewal_orders']++;
                $row['renewal_revenue'] += $amount;
            }

            $orderKey = $normalizedOrderId === '' ? null : $storeId."\x1F".$normalizedOrderId;
            $logisticsMetricIndex = $orderKey === null ? null : ($logisticsMetricIndexesByOrder[$orderKey] ?? null);
            if ($logisticsMetricIndex !== null) {
                $matchedMetric = $logisticsOrderMetrics[$logisticsMetricIndex];
                $row['first_purchase_cost'] += (float) ($matchedMetric['first_purchase_cost'] ?? 0);
                $row['first_shipping_cost'] += (float) ($matchedMetric['first_shipping_cost'] ?? 0);
                $consumedLogisticsMetricIndexes[$logisticsMetricIndex] = true;
            }
            unset($row);
        }

        foreach ($logisticsOrderMetrics as $index => $metric) {
            if (isset($consumedLogisticsMetricIndexes[$index])) {
                continue;
            }

            $operatorId = (int) ($metric['operator_id'] ?? 0);
            if (! isset($operatorLookup[$operatorId])) {
                continue;
            }

            $rowDate = (string) ($metric['date'] ?? '');
            $domain = (string) ($metric['shop_domain'] ?? '未知店铺');
            $media = (string) ($metric['media_source'] ?? LogisticsOrder::MEDIA_SOURCE_OTHER);
            $key = $rowDate."\x1F".$domain."\x1F".$media;
            $groups[$operatorId][$key] ??= $this->emptyRow(
                $rowDate,
                (string) $operatorNames->get($operatorId, '未分配'),
                $domain,
                $media,
            );
            $row = &$groups[$operatorId][$key];
            $amount = (float) ($metric['amount'] ?? 0);
            $category = (string) ($metric['category'] ?? '');

            if ($category === AssistantShopifyStoreOrder::SUBSCRIPTION_CATEGORY_SUBSCRIBED) {
                $row['first_subscription_orders']++;
                $row['first_subscription_revenue'] += $amount;
            } elseif ($category === AssistantShopifyStoreOrder::SUBSCRIPTION_CATEGORY_REGULAR) {
                $row['first_normal_orders']++;
                $row['first_normal_revenue'] += $amount;
            } elseif ($category === AssistantShopifyStoreOrder::SUBSCRIPTION_CATEGORY_RECURRING) {
                $row['renewal_orders']++;
                $row['renewal_revenue'] += $amount;
            }
            $row['first_purchase_cost'] += (float) ($metric['first_purchase_cost'] ?? 0);
            $row['first_shipping_cost'] += (float) ($metric['first_shipping_cost'] ?? 0);
            unset($row);
        }

        return $groups;
    }

    private function normalizedShopifyOrderId(mixed $value): string
    {
        $value = trim((string) $value);

        return $value === '' ? '' : (string) preg_replace('~^.*/~', '', $value);
    }

    /** @return array{summary:array<string,float|int|string>,orders:list<array<string,mixed>>,pagination:array<string,int>} */
    public function orderDetails(string $date, int $operatorUserId, string $shopDomain, string $mediaSource, string $type, int $page = 1, int $perPage = 10): array
    {
        return $this->orderDetailsRange($date, $date, $operatorUserId, $type, $page, $perPage, $shopDomain, $mediaSource);
    }

    /** @return array{summary:array<string,float|int|string>,orders:list<array<string,mixed>>,pagination:array<string,int>} */
    public function orderDetailsRange(string $dateFrom, string $dateTo, int $operatorUserId, string $type, int $page = 1, int $perPage = 10, ?string $shopDomain = null, ?string $mediaSource = null, string $timezone = self::REPORT_TIMEZONE): array
    {
        $start = Carbon::createFromFormat('Y-m-d H:i:s', $dateFrom.' 00:00:00', $timezone)->utc();
        $end = Carbon::createFromFormat('Y-m-d H:i:s', $dateTo.' 23:59:59', $timezone)->utc();
        $operator = AdminUser::query()->find($operatorUserId);
        $operatorUsers = $operator ? collect([$operator]) : collect();
        $operatorIdentityMap = $this->operatorIdentityMap($operatorUsers);
        $operatorSearchValues = $this->operatorSearchValues($operatorUsers);
        $orders = LogisticsOrder::query()
            ->with(['items.skuMapping', 'store'])
            ->whereBetween('order_created_at', [$start, $end])
            ->where(function ($query) use ($operatorUserId, $operatorSearchValues): void {
                $query->where('assigned_owner_user_id', $operatorUserId)
                    ->orWhere(function ($legacy) use ($operatorUserId, $operatorSearchValues): void {
                        $legacy->whereNull('assigned_owner_user_id')
                            ->where(function ($fallback) use ($operatorUserId, $operatorSearchValues): void {
                                $fallback->whereHas('store', fn ($store) => $store->where('owner_user_id', $operatorUserId));
                                if ($operatorSearchValues !== []) {
                                    $fallback->orWhereIn('operator_name', $operatorSearchValues);
                                }
                            });
                    });
            })
            ->orderBy('order_created_at')
            ->get()
            ->filter(function (LogisticsOrder $order) use ($shopDomain, $mediaSource, $type, $operatorIdentityMap, $operatorUserId): bool {
                if ($this->resolveOperatorId($order, $operatorIdentityMap, [$operatorUserId]) !== $operatorUserId) {
                    return false;
                }
                $domain = trim((string) ($order->store?->domain ?: $order->source_shop_domain ?: $order->store_name ?: '未知店铺'));
                $media = trim((string) ($order->media_source ?: LogisticsOrder::MEDIA_SOURCE_OTHER));
                if ($shopDomain !== null && $domain !== $shopDomain) {
                    return false;
                }
                if ($mediaSource !== null && $media !== $mediaSource) {
                    return false;
                }

                $subscriptionType = (string) $order->subscription_type;

                return match ($type) {
                    'first_subscription' => $subscriptionType === '订阅首次购买',
                    'first_normal' => $subscriptionType === '无订阅',
                    'first_total' => in_array($subscriptionType, ['订阅首次购买', '无订阅'], true),
                    'renewal' => str_starts_with($subscriptionType, '订阅复购'),
                    'total' => in_array($subscriptionType, ['订阅首次购买', '无订阅'], true) || str_starts_with($subscriptionType, '订阅复购'),
                    default => false,
                };
            })->values();

        $mappingIds = $orders->flatMap(fn ($order) => $order->items->pluck('tongtu_sku_mapping_id'))->filter()->unique();
        $skuValues = $orders->flatMap(fn ($order) => $order->items->flatMap(fn ($item) => [
            trim((string) $item->shopify_sku), trim((string) $item->tongtu_sku), trim((string) $item->tongtu_product_code),
        ]))->filter()->unique()->values();
        $mappingRows = LogisticsTongtuSkuMapping::query()->where(function ($query) use ($mappingIds, $skuValues): void {
            if ($mappingIds->isNotEmpty()) {
                $query->orWhereIn('id', $mappingIds);
            }
            if ($skuValues->isNotEmpty()) {
                $query->orWhereIn('shopify_sku', $skuValues)
                    ->orWhereIn('tongtu_sku', $skuValues)
                    ->orWhereIn('tongtu_product_code', $skuValues);
            }
        })->get();
        $mappings = $mappingRows->keyBy('id');
        $mappingByValue = [];
        foreach ($mappingRows as $mapping) {
            foreach ([$mapping->shopify_sku, $mapping->tongtu_sku, $mapping->tongtu_product_code] as $value) {
                $value = mb_strtolower(trim((string) $value));
                if ($value !== '') {
                    $mappingByValue[$value] = $mapping;
                }
            }
        }

        $details = $orders->map(function (LogisticsOrder $order) use ($mappings, $mappingByValue, $timezone): array {
            $isFirst = in_array((string) $order->subscription_type, ['订阅首次购买', '无订阅'], true);
            $purchaseCost = 0.0;
            $totalWeight = 0.0;
            $items = $order->items->map(function (LogisticsOrderItem $item) use ($isFirst, $mappings, $mappingByValue, &$purchaseCost, &$totalWeight): array {
                $quantity = max(0.0, (float) $item->quantity);
                $mapping = $item->tongtu_sku_mapping_id ? $mappings->get($item->tongtu_sku_mapping_id) : null;
                if (! $mapping) {
                    foreach ([$item->shopify_sku, $item->tongtu_sku, $item->tongtu_product_code] as $value) {
                        $lookup = mb_strtolower(trim((string) $value));
                        if ($lookup !== '' && isset($mappingByValue[$lookup])) {
                            $mapping = $mappingByValue[$lookup];
                            break;
                        }
                    }
                }
                $guideCost = $mapping?->product_guide_cost_cny;
                $guideCost = $guideCost !== null && (float) $guideCost > 0 ? (float) $guideCost : null;
                $unitWeight = $item->sale_weight_gram ?: $item->tongtu_goods_weight_gram ?: $mapping?->goods_weight_gram;
                $unitWeight = $unitWeight !== null && (float) $unitWeight > 0 ? (float) $unitWeight : null;
                $itemPurchaseCost = $isFirst && $guideCost !== null ? $guideCost * $quantity / self::CNY_PER_USD : 0.0;
                $itemWeight = $isFirst && $unitWeight !== null ? $unitWeight * $quantity : 0.0;
                $purchaseCost += $itemPurchaseCost;
                $totalWeight += $itemWeight;

                return [
                    'product_name' => trim((string) ($item->product_title ?: $item->tongtu_product_name)) ?: '未填写商品名称',
                    'sku' => $this->itemSku($item),
                    'image_url' => $item->display_product_image_url,
                    'quantity' => $quantity,
                    'guide_cost_cny' => $guideCost,
                    'purchase_cost_usd' => round($itemPurchaseCost, 4),
                    'unit_weight_gram' => $unitWeight,
                    'total_weight_gram' => round($itemWeight, 4),
                ];
            })->values()->all();
            $revenue = (float) ($order->paid_amount_usd ?? 0);
            $shippingCost = $isFirst ? ($totalWeight / 1000 * 80 + 20) / self::CNY_PER_USD : 0.0;

            return [
                'id' => (int) $order->getKey(),
                'shopify_order_id' => trim((string) $order->shopify_order_id) ?: '—',
                'order_number' => trim((string) ($order->shopify_order_number ?: $order->tongtu_order_number)) ?: '—',
                'shop_domain' => trim((string) ($order->store?->domain ?: $order->source_shop_domain ?: $order->store_name)) ?: '未知店铺',
                'media_source' => trim((string) ($order->media_source ?: LogisticsOrder::MEDIA_SOURCE_OTHER)),
                'ordered_at' => Carbon::parse($order->getRawOriginal('order_created_at'), 'UTC')->timezone($timezone)->format('Y-m-d H:i:s'),
                'subscription_type' => (string) $order->subscription_type,
                'revenue' => round($revenue, 4),
                'purchase_cost' => round($purchaseCost, 4),
                'total_weight_gram' => round($totalWeight, 4),
                'shipping_cost' => round($shippingCost, 4),
                'return_fee' => round($isFirst ? $revenue * 0.12 : 0.0, 4),
                'items' => $items,
            ];
        })->all();

        $total = count($details);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $lastPage);
        $mediaBreakdown = collect($details)
            ->groupBy(fn (array $order) => (string) $order['media_source'])
            ->map(fn ($mediaOrders, string $media) => [
                'media_source' => $media,
                'order_count' => $mediaOrders->count(),
                'revenue' => round((float) $mediaOrders->sum('revenue'), 4),
            ])
            ->sortByDesc('order_count')
            ->values()
            ->all();
        $shopDomains = collect($details)
            ->pluck('shop_domain')
            ->filter()
            ->unique(fn (string $domain) => mb_strtolower($domain))
            ->sort()
            ->values()
            ->all();

        return [
            'summary' => [
                'date' => $dateFrom === $dateTo ? $dateFrom : $dateFrom.' 至 '.$dateTo,
                'shop_domain' => $shopDomain ?? '全部店铺',
                'media_source' => $mediaSource ?? '全部媒体',
                'order_count' => count($details),
                'revenue' => round((float) collect($details)->sum('revenue'), 4),
                'purchase_cost' => round((float) collect($details)->sum('purchase_cost'), 4),
                'shipping_cost' => round((float) collect($details)->sum('shipping_cost'), 4),
                'return_fee' => round((float) collect($details)->sum('return_fee'), 4),
                'media_breakdown' => $mediaBreakdown,
                'shop_domains' => $shopDomains,
            ],
            'orders' => array_values(array_slice($details, ($page - 1) * $perPage, $perPage)),
            'pagination' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
                'from' => $total > 0 ? (($page - 1) * $perPage) + 1 : 0,
                'to' => min($page * $perPage, $total),
            ],
        ];
    }

    /**
     * @return list<array{media_source:string,first_revenue:float,actual_ad_spend:float,first_roi:?float}>
     */
    public function mediaRoiSummary(array $metricRows, array $advertisingRows): array
    {
        $groups = [];
        foreach ($metricRows as $row) {
            $name = trim((string) ($row['media_source'] ?? '')) ?: LogisticsOrder::MEDIA_SOURCE_OTHER;
            $key = mb_strtolower($name);
            $groups[$key] ??= ['media_source' => $name, 'first_revenue' => 0.0, 'actual_ad_spend' => 0.0];
            $groups[$key]['first_revenue'] += (float) ($row['first_total_revenue'] ?? 0);
        }
        foreach ($advertisingRows as $row) {
            $name = trim((string) ($row['media_source'] ?? '')) ?: LogisticsOrder::MEDIA_SOURCE_OTHER;
            $key = mb_strtolower($name);
            $groups[$key] ??= ['media_source' => $name, 'first_revenue' => 0.0, 'actual_ad_spend' => 0.0];
            $groups[$key]['actual_ad_spend'] += (float) ($row['actual_spend'] ?? 0);
        }

        $rows = array_map(static function (array $row): array {
            $row['first_revenue'] = round($row['first_revenue'], 4);
            $row['actual_ad_spend'] = round($row['actual_ad_spend'], 4);
            $row['first_roi'] = $row['actual_ad_spend'] > 0
                ? round($row['first_revenue'] / $row['actual_ad_spend'], 4)
                : null;

            return $row;
        }, array_values($groups));
        usort($rows, fn (array $a, array $b) => strcasecmp($a['media_source'], $b['media_source']));

        return $rows;
    }

    /** @return array<string,int|null> 规范化姓名/账号到运营用户 ID；null 表示同名冲突。 */
    private function operatorIdentityMap(iterable $users): array
    {
        $map = [];
        foreach ($users as $user) {
            foreach ([$user->name, $user->username] as $identity) {
                $key = $this->normalizeOperatorIdentity((string) $identity);
                if ($key === '') {
                    continue;
                }
                $userId = (int) $user->getKey();
                if (array_key_exists($key, $map) && $map[$key] !== $userId) {
                    $map[$key] = null;
                } else {
                    $map[$key] = $userId;
                }
            }
        }

        return $map;
    }

    /** @return list<string> */
    private function operatorSearchValues(iterable $users): array
    {
        $values = [];
        foreach ($users as $user) {
            foreach ([$user->name, $user->username] as $identity) {
                $identity = trim((string) $identity);
                if ($identity !== '') {
                    $values[] = $identity;
                }
            }
        }

        return array_values(array_unique($values));
    }

    /**
     * 订单真实归属人快照是报表归属的权威数据源。
     * 只有历史订单尚未生成真实归属人快照时，才回退到旧的运营姓名和店铺负责人逻辑。
     *
     * @param  array<string,int|null>  $identityMap
     * @param  list<int>  $allowedOperatorIds
     */
    private function resolveOperatorId(LogisticsOrder $order, array $identityMap, array $allowedOperatorIds): ?int
    {
        $assignedOwnerId = (int) ($order->assigned_owner_user_id ?? 0);
        if ($assignedOwnerId > 0) {
            return in_array($assignedOwnerId, $allowedOperatorIds, true) ? $assignedOwnerId : null;
        }

        $identity = $this->normalizeOperatorIdentity((string) $order->operator_name);
        if ($identity !== '') {
            if (array_key_exists($identity, $identityMap) && $identityMap[$identity] !== null) {
                return in_array($identityMap[$identity], $allowedOperatorIds, true) ? $identityMap[$identity] : null;
            }
            // 非空快照明确属于其他运营时，不能按店铺现负责人重新归属历史订单。
            if (! array_key_exists($identity, $identityMap)) {
                return null;
            }
        }

        $storeOwnerId = (int) ($order->store?->owner_user_id ?? 0);

        return in_array($storeOwnerId, $allowedOperatorIds, true) ? $storeOwnerId : null;
    }

    private function normalizeOperatorIdentity(string $value): string
    {
        return mb_strtolower((string) preg_replace('/[\s　]+/u', '', trim($value)));
    }

    private function itemSku(object $item): string
    {
        foreach ([$item->shopify_sku, $item->tongtu_sku, $item->tongtu_product_code] as $sku) {
            $sku = trim((string) $sku);
            if ($sku !== '') {
                return $sku;
            }
        }

        return '未填写SKU（商品明细ID：'.$item->getKey().'）';
    }

    private function emptyRow(string $date, string $operator, string $domain, string $media): array
    {
        return [
            'date' => $date, 'operator_name' => $operator, 'shop_domain' => $domain, 'media_source' => $media,
            'first_subscription_orders' => 0, 'first_subscription_revenue' => 0.0,
            'first_normal_orders' => 0, 'first_normal_revenue' => 0.0,
            'first_purchase_cost' => 0.0, 'first_shipping_cost' => 0.0,
            'renewal_orders' => 0, 'renewal_revenue' => 0.0,
        ];
    }
}
