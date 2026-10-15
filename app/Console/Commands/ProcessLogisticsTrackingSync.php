<?php

namespace App\Console\Commands;

use App\Portals\Logistics\LogisticsTrackingService;
use Illuminate\Console\Command;

class ProcessLogisticsTrackingSync extends Command
{
    protected $signature = 'logistics:tracking:process {--limit=80 : 本次最多处理的物流查询任务数}';

    protected $description = '执行 51Tracking 待登记、待查询和定时复查任务';

    public function handle(LogisticsTrackingService $tracking): int
    {
        $summary = $tracking->processDue((int) $this->option('limit'));

        $this->info(sprintf(
            '已处理 %d 个物流任务（成功 %d，失败 %d）。',
            $summary['processed'],
            $summary['success'],
            $summary['failed'],
        ));

        return self::SUCCESS;
    }
}
