<?php

namespace App\Console\Commands;

use App\Portals\Logistics\LogisticsTrackingService;
use Illuminate\Console\Command;

class QueueLogisticsTrackingHistory extends Command
{
    protected $signature = 'logistics:tracking:queue-history {--limit=0 : 限制历史订单数，0 表示全部}';

    protected $description = '为现有包含物流单号的订单创建一次性 51Tracking 历史补查任务';

    public function handle(LogisticsTrackingService $tracking): int
    {
        $batch = $tracking->queueHistorical(null, (int) $this->option('limit'));

        $this->info(sprintf('已创建历史补查批次 #%d，共 %d 条订单。', $batch->getKey(), $batch->total_count));

        return self::SUCCESS;
    }
}
