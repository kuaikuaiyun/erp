<?php

namespace App\Console\Commands;

use App\Portals\FashionLogistics\NbcOrderSyncService;
use Illuminate\Console\Command;

class SyncNbcFashionOrders extends Command
{
    public function __construct()
    {
        $this->signature = 'fashion-logistics:sync-nbc-orders
            {--store= : '.__('nbc_orders.command.store').' }
            {--full : '.__('nbc_orders.command.full').' }
            {--all : '.__('nbc_orders.command.all').' }';
        $this->description = __('nbc_orders.command.description');
        parent::__construct();
    }

    public function handle(NbcOrderSyncService $sync): int
    {
        $store = $this->option('store');
        if ($store !== null && (! ctype_digit((string) $store) || (int) $store < 1)) {
            $this->error(__('nbc_orders.command.invalid_store'));

            return self::FAILURE;
        }
        $result = $sync->syncAll($store !== null ? (int) $store : null, (bool) $this->option('full'), (bool) $this->option('all'));
        $this->info(__('nbc_orders.command.result', array_intersect_key($result, array_flip(['stores', 'success', 'failed', 'created', 'updated']))));
        foreach ($result['errors'] as $error) {
            $this->error($error);
        }
        if ($store !== null && $result['stores'] === 0) {
            $this->error(__('nbc_orders.command.not_found'));

            return self::FAILURE;
        }

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
