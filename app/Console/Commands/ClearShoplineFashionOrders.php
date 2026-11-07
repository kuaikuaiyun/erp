<?php

namespace App\Console\Commands;

use App\Portals\FashionLogistics\ShoplineOrderSyncService;
use Illuminate\Console\Command;

class ClearShoplineFashionOrders extends Command
{
    protected $signature = 'fashion-logistics:clear-shopline-orders
        {--confirm : Actually delete Shopline orders, items, and Shopline sync states}';

    protected $description = 'Preview or clear Shopline order data only; XShoppy data is never touched.';

    public function handle(ShoplineOrderSyncService $sync): int
    {
        $counts = $sync->clearableDataCounts();
        $this->table(['Shopline orders', 'Order items', 'Shopline sync states'], [[
            $counts['orders'],
            $counts['items'],
            $counts['sync_states'],
        ]]);

        if (! $this->option('confirm')) {
            $this->warn('Preview only. No data was changed. Re-run with --confirm to clear Shopline data.');

            return self::SUCCESS;
        }

        $cleared = $sync->clearShoplineData();
        $this->info("Cleared Shopline data: {$cleared['orders']} orders, {$cleared['items']} items, {$cleared['sync_states']} sync states.");

        return self::SUCCESS;
    }
}
