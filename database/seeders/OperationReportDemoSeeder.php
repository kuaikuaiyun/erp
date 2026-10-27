<?php

namespace Database\Seeders;

use App\Models\AssistantDomainStore;
use App\Models\LogisticsOrder;
use App\Models\LogisticsOrderItem;
use App\Models\LogisticsTongtuSkuMapping;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class OperationReportDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('运营报表演示订单只能写入 local/testing 环境。');
        }

        $stores = AssistantDomainStore::query()
            ->whereNotNull('owner_user_id')
            ->with('ownerUser')
            ->orderBy('owner_user_id')
            ->orderBy('id')
            ->get()
            ->groupBy('owner_user_id')
            ->sortByDesc(fn ($items) => $items->count())
            ->first()?->take(2)->values();

        if (! $stores || $stores->isEmpty()) {
            throw new RuntimeException('没有找到已分配给运营的店铺，无法生成演示订单。');
        }

        $connection = config('admin.database.connection') ?: config('database.default');
        DB::connection($connection)->transaction(function () use ($stores): void {
            $mappings = collect([
                ['sku' => 'CODEX-DEMO-SKU-A', 'name' => '演示按摩仪', 'guide' => 13.50, 'weight' => 320],
                ['sku' => 'CODEX-DEMO-SKU-B', 'name' => '演示收纳盒', 'guide' => 8.80, 'weight' => 510],
                ['sku' => 'CODEX-DEMO-SKU-C', 'name' => '演示清洁刷', 'guide' => 5.20, 'weight' => 180],
            ])->mapWithKeys(function (array $row): array {
                $mapping = LogisticsTongtuSkuMapping::query()->updateOrCreate(
                    ['shopify_sku' => $row['sku']],
                    [
                        'status' => LogisticsTongtuSkuMapping::STATUS_MATCHED,
                        'tongtu_product_code' => $row['sku'],
                        'tongtu_sku' => $row['sku'],
                        'tongtu_product_name' => $row['name'],
                        'product_guide_cost_cny' => $row['guide'],
                        'goods_weight_gram' => $row['weight'],
                        'cost_currency' => 'CNY',
                        'last_synced_at' => now(),
                    ],
                );

                return [$row['sku'] => $mapping];
            });

            $rows = [
                [0, 'Facebook', '订阅首次购买', 59.90, 'CODEX-DEMO-SKU-A', 2, 9, 15],
                [0, 'Facebook', '无订阅',       39.90, 'CODEX-DEMO-SKU-B', 1, 10, 25],
                [0, 'TikTok',   '订阅首次购买', 72.00, 'CODEX-DEMO-SKU-C', 3, 11, 35],
                [0, 'TikTok',   '无订阅',       28.50, 'CODEX-DEMO-SKU-A', 1, 12, 45],
                [0, 'Facebook', '订阅复购',     49.90, 'CODEX-DEMO-SKU-A', 1, 13, 5],
                [1, 'Google',   '订阅首次购买', 88.00, 'CODEX-DEMO-SKU-B', 2, 14, 15],
                [1, 'Google',   '无订阅',       45.60, 'CODEX-DEMO-SKU-C', 2, 15, 25],
                [1, 'Pinterest','订阅复购',     52.00, 'CODEX-DEMO-SKU-B', 1, 16, 5],
                [1, 'Google',   '无订阅',       26.80, 'CODEX-DEMO-NO-GUIDE', 1, 17, 35],
            ];
            $dateKey = now('Asia/Shanghai')->format('Ymd');

            foreach ($rows as $index => [$storeIndex, $media, $type, $amount, $sku, $quantity, $hour, $minute]) {
                $store = $stores->get($storeIndex) ?: $stores->first();
                $orderId = 'CODEX-DEMO-'.$dateKey.'-'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT);
                $createdAt = Carbon::createFromFormat(
                    'Y-m-d H:i',
                    sprintf('%s %02d:%02d', now('Asia/Shanghai')->format('Y-m-d'), $hour, $minute),
                    'Asia/Shanghai',
                )->utc();
                $mapping = $mappings->get($sku);
                $weight = $mapping?->goods_weight_gram ?: 240;
                $operator = $store->ownerUser?->name ?: $store->ownerUser?->username ?: '测试运营';

                $order = LogisticsOrder::query()->updateOrCreate(
                    ['assistant_domain_store_id' => $store->getKey(), 'shopify_order_id' => $orderId],
                    [
                        'source_shop_domain' => $store->domain,
                        'shopify_order_number' => '#DEMO'.($index + 1),
                        'store_name' => $store->shop_name,
                        'operator_name' => $operator,
                        'media_source' => $media,
                        'buyer_name' => '本地测试客户'.($index + 1),
                        'buyer_email' => 'demo'.($index + 1).'@example.test',
                        'subscription_type' => $type,
                        'payment_status' => 'paid',
                        'payment_method' => '测试支付',
                        'paid_amount_usd' => $amount,
                        'paid_at' => $createdAt,
                        'order_created_at' => $createdAt,
                        'data_status' => LogisticsOrder::STATUS_NORMAL,
                        'time_zone' => 'Asia/Shanghai',
                        'shopify_payload' => json_encode(['tags' => [$media, 'CODEX 本地演示数据']], JSON_UNESCAPED_UNICODE),
                    ],
                );

                LogisticsOrderItem::query()->updateOrCreate(
                    ['logistics_order_id' => $order->getKey(), 'source_key' => 'demo-item-1'],
                    [
                        'shopify_line_item_id' => $orderId.'-ITEM-1',
                        'product_title' => $mapping?->tongtu_product_name ?: '演示缺指导价商品',
                        'shopify_sku' => $sku,
                        'tongtu_sku_mapping_id' => $mapping?->getKey(),
                        'tongtu_sku' => $sku,
                        'tongtu_product_code' => $sku,
                        'tongtu_product_name' => $mapping?->tongtu_product_name,
                        'quantity' => $quantity,
                        'unit_price_usd' => round($amount / $quantity, 4),
                        'line_revenue_usd' => $amount,
                        'sale_weight_gram' => $weight,
                    ],
                );
            }
        });

        $this->command?->info('已生成今天的 9 条运营报表演示订单（CODEX-DEMO-*）。');
    }
}
