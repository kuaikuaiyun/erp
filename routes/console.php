<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Models\LogisticsOrder;
use App\Portals\Logistics\LogisticsOrderItemService;
use App\Portals\Logistics\TongtuSkuMappingService;
use App\Portals\Logistics\SkuDatabaseLookupService;
use App\Portals\Operation\OperationReportMetricsService;

require __DIR__.'/assistant_shopify_analytics_schedule.php';

Schedule::command('logistics:tracking:process --limit=80')
    ->everyMinute()
    ->withoutOverlapping(10)
    ->runInBackground();

Schedule::command('logistics:tongtu-skus:refresh --limit=200')
    ->everyTenMinutes()
    ->withoutOverlapping(5)
    ->runInBackground();

Schedule::command('logistics:sku-database:backfill-guide-costs')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
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

Artisan::command('logistics:sku-database:backfill-guide-costs {--limit=0 : 最多处理的 SKU 数，0 表示全部} {--batch=100 : 每批请求数量，最大 100}', function (OperationReportMetricsService $metrics, SkuDatabaseLookupService $database): int {
    $rows = collect($metrics->missingGuideSkuSummary());
    $skus = $rows->pluck('sku')
        ->map(fn ($sku): string => trim((string) $sku))
        ->reject(fn (string $sku): bool => $sku === '' || $sku === '未填写 SKU')
        ->unique(fn (string $sku): string => mb_strtolower($sku))
        ->values();
    $limit = max(0, (int) $this->option('limit'));
    if ($limit > 0) $skus = $skus->take($limit);
    $batchSize = max(1, min(100, (int) $this->option('batch')));

    if ($skus->isEmpty()) {
        $this->info('当前没有需要回填指导价的 SKU。');
        return 0;
    }

    $this->info('准备回填 '.$skus->count().' 个 SKU，每批 '.$batchSize.' 个。');
    $returned = 0;
    $withGuideCost = 0;
    foreach ($skus->chunk($batchSize) as $index => $chunk) {
        $result = $database->lookup($chunk->all(), true);
        $returned += count($result);
        $withGuideCost += collect($result)->filter(fn (array $row): bool => ($row['guide_cost_cny'] ?? null) !== null && (float) $row['guide_cost_cny'] > 0)->count();
        $this->line('第 '.($index + 1).' 批完成：请求 '.$chunk->count().' 个，接口返回 '.count($result).' 个，有效指导价 '.collect($result)->filter(fn (array $row): bool => ($row['guide_cost_cny'] ?? null) !== null && (float) $row['guide_cost_cny'] > 0)->count().' 个。');
    }

    $remaining = count($metrics->missingGuideSkuSummary());
    $this->info('批量回填完成：接口共返回 '.$returned.' 个 SKU，其中 '.$withGuideCost.' 个有有效指导价；当前仍缺少 '.$remaining.' 个。');

    return 0;
})->purpose('通过私有 SKU 数据库批量回填运营报表缺失的指导价和重量');
