<?php

namespace App\Console\Commands;

use App\Portals\FashionLogistics\XShoppyOrderSyncService;
use Illuminate\Console\Command;

class SyncFashionLogisticsOrders extends Command
{
    protected $signature = 'fashion-logistics:sync-orders
        {--store= : 仅同步指定店铺 ID}
        {--days=60 : 首次同步或全量回填的回溯天数}
        {--full : 忽略增量游标，重新回填指定时间范围内的历史订单}';

    protected $description = '从 XShoppy 店铺接口同步服装订单到独立订单表';

    public function handle(XShoppyOrderSyncService $sync): int
    {
        $storeId = $this->option('store') !== null ? (int) $this->option('store') : null;
        $result = $sync->syncAll(
            $storeId ?: null,
            max(1, (int) $this->option('days')),
            (bool) $this->option('full'),
        );

        $this->info("同步完成：店铺 {$result['stores']} 个，成功 {$result['success']} 个，失败 {$result['failed']} 个，新增 {$result['created']} 单，更新 {$result['updated']} 单。");
        foreach ($result['errors'] as $error) $this->error($error);

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
