<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Models\LogisticsOrder;
use App\Portals\Logistics\LogisticsOrderItemService;
use App\Portals\Logistics\TongtuSkuMappingService;

require __DIR__.'/assistant_shopify_analytics_schedule.php';

Schedule::command('logistics:tracking:process --limit=80')
    ->everyMinute()
    ->withoutOverlapping(10)
    ->runInBackground();

Schedule::command('logistics:tongtu-skus:refresh --limit=200')
    ->everyTenMinutes()
    ->withoutOverlapping(5)
    ->runInBackground();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('logistics:backfill-order-items {--chunk=100 : '.__('logistics.sku_mapping_commands.order_item_backfill_chunk').' }', function (LogisticsOrderItemService $items): int {
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

    $this->info(__('logistics.sku_mapping_commands.order_item_backfill_done', ['orders' => $processed, 'items' => $itemRows]));

    return 0;
})->purpose(__('logistics.sku_mapping_commands.order_item_backfill_purpose'));

Artisan::command('logistics:tongtu-skus:refresh {--limit=200 : '.__('logistics.sku_mapping_commands.refresh_limit').' }', function (TongtuSkuMappingService $mappings): int {
    $count = $mappings->scheduleDueRefreshes((int) $this->option('limit'));
    $this->info(__('logistics.sku_mapping_commands.refresh_queued', ['count' => $count]));

    return 0;
})->purpose(__('logistics.sku_mapping_commands.refresh_purpose'));

Artisan::command('logistics:tongtu-skus:backfill {--limit=100 : '.__('logistics.sku_mapping_commands.backfill_limit').' }', function (TongtuSkuMappingService $mappings): int {
    $count = $mappings->queueHistoricalMappings((int) $this->option('limit'));
    $this->info(__('logistics.sku_mapping_commands.backfill_queued', ['count' => $count]));

    return 0;
})->purpose(__('logistics.sku_mapping_commands.backfill_purpose'));
