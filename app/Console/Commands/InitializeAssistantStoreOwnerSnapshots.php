<?php

namespace App\Console\Commands;

use App\Models\AssistantDomainStore;
use App\Portals\Assistant\AssistantStoreOwnerTimelineService;
use Illuminate\Console\Command;

class InitializeAssistantStoreOwnerSnapshots extends Command
{
    protected $signature = 'assistant:store-owners:initialize-snapshots {--apply : Actually write baseline records and order snapshots}';

    protected $description = 'Initialize store ownership timeline and immutable logistics-order ownership snapshots from current confirmed owners';

    public function handle(AssistantStoreOwnerTimelineService $timeline): int
    {
        $apply = (bool) $this->option('apply');
        $stores = AssistantDomainStore::query()->with('ownerUser:id,name,username')->orderBy('id')->get();
        $historyCreated = 0;
        $ordersUpdated = 0;

        foreach ($stores as $store) {
            if (!$apply) continue;
            $before = $store->ownerHistories()->exists();
            $timeline->ensureBaseline($store);
            if (!$before) $historyCreated++;
            $ordersUpdated += $timeline->refreshSnapshots($store);
        }

        $this->info($apply
            ? "已初始化：{$stores->count()} 家店铺，新增 {$historyCreated} 条归属基线，写入 {$ordersUpdated} 条订单归属快照。"
            : "检查完成：共 {$stores->count()} 家店铺。使用 --apply 才会写入归属基线和订单快照。"
        );

        return self::SUCCESS;
    }
}
