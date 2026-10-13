<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Models\LogisticsOrder;
use App\Portals\Logistics\LogisticsOrderItemService;

require __DIR__.'/assistant_shopify_analytics_schedule.php';

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('logistics:backfill-order-items {--chunk=100 : 每批处理的订单数}', function (LogisticsOrderItemService $items): int {
    $chunk = max(1, min(1000, (int) $this->option('chunk')));
    $processed = 0;
    $itemRows = 0;

    LogisticsOrder::query()
        ->whereNotNull('shopify_payload')
        ->orderBy('id')
        ->chunkById($chunk, function ($orders) use ($items, &$processed, &$itemRows): void {
            foreach ($orders as $order) {
                $itemRows += $items->backfill($order);
                $processed++;
            }
        });

    $this->info("已检查 {$processed} 个物流订单，已处理 {$itemRows} 条订单商品明细。");

    return 0;
})->purpose('从已保存的 Shopify 与通途订单数据建立物流订单商品明细');
