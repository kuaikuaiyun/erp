<?php

namespace App\Console\Commands;

use App\Portals\FashionLogistics\XShoppyOrderSyncService;
use Illuminate\Console\Command;

class SyncFashionLogisticsOrders extends Command
{
    protected $signature = 'fashion-logistics:sync-orders
        {--store= : 仅同步指定店铺 ID}
        {--days=60 : 使用 --full 时的回溯天数}
        {--full : 忽略增量游标，重新回填指定时间范围内的历史订单}
        {--all : 从历史起点分页同步接口中的全部订单}
        {--clear : 同步前清空服装订单数据}';

    protected $description = '从 XShoppy 店铺接口同步已支付订单到独立服装订单表（新店铺从最大历史起点同步）';

    public function handle(XShoppyOrderSyncService $sync): int
    {
        $storeId = $this->option('store') !== null ? (int) $this->option('store') : null;

        if ((bool) $this->option('clear')) {
            $deleted = $sync->clearOrders($storeId ?: null);
            $this->warn("已清空 {$deleted} 条服装订单，开始重新同步已支付订单。");
        }

        $result = $sync->syncAll(
            $storeId ?: null,
            max(1, (int) $this->option('days')),
            (bool) $this->option('full') || (bool) $this->option('all'),
            (bool) $this->option('all'),
        );

        $this->info("同步完成：店铺 {$result['stores']} 个，成功 {$result['success']} 个，失败 {$result['failed']} 个，新增 {$result['created']} 单，更新 {$result['updated']} 单。");
        foreach ($result['errors'] as $error) $this->error($error);

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
