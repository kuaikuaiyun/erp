<?php

namespace App\Console\Commands;

use App\Portals\FashionLogistics\ShoplineOrderSyncService;
use Illuminate\Console\Command;

class SyncShoplineFashionOrders extends Command
{
    protected $signature = 'fashion-logistics:sync-shopline-orders
        {--store= : 仅同步指定的 Shopline 店铺 ID}
        {--days=60 : 首次同步或重新回填时的回溯天数}
        {--full : 忽略增量游标，重新同步指定时间范围}
        {--all : 从历史起点同步可读取的全部订单}';

    protected $description = '从 Shopline Admin REST API 增量同步订单到服装订单表';

    public function handle(ShoplineOrderSyncService $sync): int
    {
        $storeId = $this->option('store') !== null ? (int) $this->option('store') : null;
        $result = $sync->syncAll(
            $storeId ?: null,
            max(1, (int) $this->option('days')),
            (bool) $this->option('full') || (bool) $this->option('all'),
            (bool) $this->option('all'),
        );

        $this->info("Shopline 同步完成：店铺 {$result['stores']} 个，成功 {$result['success']} 个，失败 {$result['failed']} 个，新增 {$result['created']} 单，更新 {$result['updated']} 单。");
        foreach ($result['errors'] as $error) {
            $this->error($error);
        }

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
