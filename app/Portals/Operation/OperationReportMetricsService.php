<?php

namespace App\Portals\Operation;

use App\Models\AdminUser;
use App\Models\LogisticsOrder;
use App\Models\LogisticsOrderItem;
use App\Models\LogisticsTongtuSkuMapping;
use Carbon\Carbon;

class OperationReportMetricsService
{
    /** @return list<array{sku:string,product_name:string,item_count:int,quantity:float,latest_order_at:?string}> */
    public function missingGuideSkuSummary(): array
    {
        $mappedValues = [];
        LogisticsTongtuSkuMapping::query()
            ->whereNotNull('product_guide_cost_cny')
            ->where('product_guide_cost_cny', '>', 0)
            ->get(['shopify_sku', 'tongtu_sku', 'tongtu_product_code'])
            ->each(function (LogisticsTongtuSkuMapping $mapping) use (&$mappedValues): void {
                foreach ([$mapping->shopify_sku, $mapping->tongtu_sku, $mapping->tongtu_product_code] as $value) {
                    $value = mb_strtolower(trim((string) $value));
                    if ($value !== '') $mappedValues[$value] = true;
                }
            });

        return LogisticsOrderItem::query()
            ->join('logistics_orders', 'logistics_orders.id', '=', 'logistics_order_items.logistics_order_id')
            ->whereIn('logistics_orders.subscription_type', ['订阅首次购买', '无订阅'])
            ->select([
                'logistics_order_items.shopify_sku',
                'logistics_order_items.tongtu_sku',
                'logistics_order_items.tongtu_product_code',
            ])
            ->selectRaw('MAX(logistics_order_items.product_title) as product_name')
            ->selectRaw('COUNT(*) as item_count')
            ->selectRaw('SUM(logistics_order_items.quantity) as total_quantity')
            ->selectRaw('MAX(logistics_orders.order_created_at) as latest_order_at')
            ->groupBy('logistics_order_items.shopify_sku', 'logistics_order_items.tongtu_sku', 'logistics_order_items.tongtu_product_code')
            ->get()
            ->filter(function ($row) use ($mappedValues): bool {
                foreach ([$row->shopify_sku, $row->tongtu_sku, $row->tongtu_product_code] as $value) {
                    $value = mb_strtolower(trim((string) $value));
                    if ($value !== '' && isset($mappedValues[$value])) return false;
                }
                return true;
            })
            ->map(function ($row): array {
                $sku = collect([$row->shopify_sku, $row->tongtu_sku, $row->tongtu_product_code])
                    ->map(fn ($value) => trim((string) $value))->first(fn ($value) => $value !== '');

                return [
                    'sku' => $sku ?: '未填写 SKU',
                    'product_name' => trim((string) $row->product_name) ?: '—',
                    'item_count' => (int) $row->item_count,
                    'quantity' => (float) $row->total_quantity,
                    'latest_order_at' => $row->latest_order_at
                        ? Carbon::parse($row->latest_order_at, 'UTC')->timezone('Asia/Shanghai')->format('Y-m-d H:i:s')
                        : null,
                ];
            })
            ->sortByDesc('latest_order_at')
            ->values()
            ->all();
    }

    /** @return array{rows:list<array<string,mixed>>,totals:array<string,float|int>,warnings:list<string>} */
    public function build(string $date, int $operatorUserId): array
    {
        $start = Carbon::createFromFormat('Y-m-d H:i:s', $date.' 00:00:00', 'Asia/Shanghai')->utc();
        $end = Carbon::createFromFormat('Y-m-d H:i:s', $date.' 23:59:59', 'Asia/Shanghai')->utc();
        $orders = LogisticsOrder::query()
            ->with(['items', 'store'])
            ->whereBetween('order_created_at', [$start, $end])
            ->whereHas('store', fn ($query) => $query->where('owner_user_id', $operatorUserId))
            ->orderBy('order_created_at')
            ->get();

        $operator = AdminUser::query()->find($operatorUserId);
        $operatorName = (string) ($operator?->name ?: $operator?->username ?: '未分配');

        $mappingIds = $orders->flatMap(fn ($order) => $order->items->pluck('tongtu_sku_mapping_id'))->filter()->unique();
        $skuValues = $orders->flatMap(fn ($order) => $order->items->flatMap(fn ($item) => [
            trim((string) $item->shopify_sku), trim((string) $item->tongtu_sku), trim((string) $item->tongtu_product_code),
        ]))->filter()->unique()->values();
        $mappingRows = LogisticsTongtuSkuMapping::query()
            ->where(function ($query) use ($mappingIds, $skuValues): void {
                if ($mappingIds->isNotEmpty()) $query->orWhereIn('id', $mappingIds);
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
                if ($value !== '') $mappingByValue[$value] = $mapping;
            }
        }
        $groups = [];
        $missingGuide = 0;
        $missingGuideSkus = [];
        $missingWeight = 0;

        foreach ($orders as $order) {
            $domain = trim((string) ($order->source_shop_domain ?: $order->store?->domain ?: $order->store_name ?: '未知店铺'));
            $media = trim((string) ($order->media_source ?: LogisticsOrder::MEDIA_SOURCE_OTHER));
            $key = $domain."\x1F".$media;
            $groups[$key] ??= $this->emptyRow($date, $operatorName, $domain, $media);
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
                        $row['first_purchase_cost'] += (float) $guide * $quantity;
                    }
                    if ($weight === null || (float) $weight <= 0) {
                        $missingWeight++;
                    } else {
                        $orderWeight += (float) $weight * $quantity;
                    }
                }
                $row['first_shipping_cost'] += $orderWeight / 1000 * 80 + 20;
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
        usort($rows, fn (array $a, array $b) => [$a['shop_domain'], $a['media_source']] <=> [$b['shop_domain'], $b['media_source']]);

        $numericFields = ['first_subscription_orders','first_subscription_revenue','first_normal_orders','first_normal_revenue','first_total_orders','first_total_revenue','first_purchase_cost','first_shipping_cost','first_return_fee','renewal_orders','renewal_revenue','renewal_cost','total_orders','total_revenue'];
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
        if ($missingWeight > 0) $warnings[] = $missingWeight.' 个首单商品缺少重量，运费可能偏低。';

        return ['rows' => $rows, 'totals' => $totals, 'warnings' => $warnings];
    }

    private function itemSku(object $item): string
    {
        foreach ([$item->shopify_sku, $item->tongtu_sku, $item->tongtu_product_code] as $sku) {
            $sku = trim((string) $sku);
            if ($sku !== '') return $sku;
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
