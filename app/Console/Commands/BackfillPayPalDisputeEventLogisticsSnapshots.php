<?php

namespace App\Console\Commands;

use App\Portals\Logistics\PayPalDisputeEventLogisticsSnapshotService;
use Illuminate\Console\Command;

class BackfillPayPalDisputeEventLogisticsSnapshots extends Command
{
    protected $signature = 'logistics:paypal-disputes:backfill-event-logistics
        {--limit=0 : 最多处理多少条未留存争议，0 表示全部}
        {--dry-run : 仅统计，不写入数据库}';

    protected $description = '从 51Tracking 原始轨迹回填 PayPal 争议发生时的物流状态快照（仅处理未留存记录）';

    public function handle(PayPalDisputeEventLogisticsSnapshotService $snapshots): int
    {
        $summary = $snapshots->backfill(max(0, (int) $this->option('limit')), (bool) $this->option('dry-run'));
        $mode = $this->option('dry-run') ? '演练完成，未写入数据' : '回填完成';
        $this->info(sprintf('%s：检查 %d 条，写入 %d 条，无物流原始数据 %d 条，无可用历史节点 %d 条，原始数据无效 %d 条。', $mode, $summary['examined'], $summary['updated'], $summary['missing_tracking'], $summary['missing_checkpoint'], $summary['invalid_payload']));

        return self::SUCCESS;
    }
}
