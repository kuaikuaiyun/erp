<?php

namespace App\Portals\Logistics;

use App\Models\LogisticsOrder;
use App\Models\ProductRequestLinkSku;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class AbnormalOrderService
{
    /** @var array{skus:list<string>,product_codes:list<string>}|null */
    private ?array $cachedKeys = null;

    /** @return array{skus:list<string>,product_codes:list<string>} */
    public function nonRunnableKeys(): array
    {
        if ($this->cachedKeys !== null) return $this->cachedKeys;
        $records = ProductRequestLinkSku::query()->where('is_runnable', false)
            ->get(['sku_values', 'skus', 'pcl_product_no']);
        $normalize = static fn (mixed $value): string => Str::lower(trim((string) $value));

        return $this->cachedKeys = [
            'skus' => $records->flatMap(static fn (ProductRequestLinkSku $record): array => array_merge(
                array_keys((array) $record->sku_values),
                preg_split('/[\r\n,，;；]+/u', (string) $record->skus, -1, PREG_SPLIT_NO_EMPTY) ?: [],
            ))->map($normalize)->filter()->unique()->values()->all(),
            'product_codes' => $records->pluck('pcl_product_no')->map($normalize)->filter()->unique()->values()->all(),
        ];
    }

    public function query(): Builder
    {
        $keys = $this->nonRunnableKeys();
        $query = LogisticsOrder::query()
            ->whereIn('subscription_type', ['订阅首次购买', '无订阅'])
            ->whereHas('items', function (Builder $items) use ($keys): void {
                $items->where(function (Builder $match) use ($keys): void {
                    if ($keys['skus'] !== []) {
                        $match->whereIn(\DB::raw('LOWER(TRIM(shopify_sku))'), $keys['skus'])
                            ->orWhereIn(\DB::raw('LOWER(TRIM(tongtu_sku))'), $keys['skus']);
                    }
                    if ($keys['product_codes'] !== []) {
                        $method = $keys['skus'] === [] ? 'whereIn' : 'orWhereIn';
                        $match->{$method}(\DB::raw('LOWER(TRIM(tongtu_product_code))'), $keys['product_codes']);
                    }
                });
            });
        if ($keys['skus'] === [] && $keys['product_codes'] === []) $query->whereRaw('1 = 0');
        return $query;
    }

    /** @return list<array{product_name:string,shopify_sku:string,tongtu_sku:string,product_code:string}> */
    public function details(LogisticsOrder $order): array
    {
        $keys = $this->nonRunnableKeys();
        $skus = array_flip($keys['skus']);
        $codes = array_flip($keys['product_codes']);
        return $order->items->filter(function ($item) use ($skus, $codes): bool {
            return isset($skus[Str::lower(trim((string) $item->shopify_sku))])
                || isset($skus[Str::lower(trim((string) $item->tongtu_sku))])
                || isset($codes[Str::lower(trim((string) $item->tongtu_product_code))]);
        })->map(fn ($item): array => [
            'product_name' => trim((string) ($item->tongtu_product_name ?: $item->product_title)) ?: '未填写',
            'shopify_sku' => trim((string) $item->shopify_sku) ?: '未填写',
            'tongtu_sku' => trim((string) $item->tongtu_sku) ?: '未匹配',
            'product_code' => trim((string) $item->tongtu_product_code) ?: '未匹配',
        ])->values()->all();
    }

    public function messageContent(LogisticsOrder $order, array $details): string
    {
        $lines = [
            '订单中包含 PCL SKU 管理标记为不可跑的商品，请及时处理。',
            '系统订单 ID：'.$order->id,
            'Shopify 订单号：'.($order->shopify_order_number ?: '未填写'),
            '店铺：'.($order->store_name ?: '未填写'),
            '店铺域名：'.($order->store?->domain ?: $order->source_shop_domain ?: '未填写'),
            '订单类型：'.$order->subscription_type,
            '', '不可跑商品：',
        ];
        foreach ($details as $index => $detail) {
            $lines[] = ($index + 1).'. '.$detail['product_name'];
            $lines[] = '   原始 SKU：'.$detail['shopify_sku'].'；通途 SKU：'.$detail['tongtu_sku'].'；PCL：'.$detail['product_code'];
        }
        return implode("\n", $lines);
    }
}
