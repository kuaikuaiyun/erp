<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class ControlShoplineFashionSync extends Command
{
    private const PAUSE_KEY = 'fashion-logistics:shopline-sync-paused';

    protected $signature = 'fashion-logistics:shopline-sync-control
        {action : status, pause, or resume}';

    protected $description = 'Pause, resume, or inspect scheduled Shopline synchronization only.';

    public function handle(): int
    {
        $action = strtolower(trim((string) $this->argument('action')));

        if ($action === 'pause') {
            Cache::forever(self::PAUSE_KEY, true);
            $this->info('Scheduled Shopline sync is paused. XShoppy and other schedules are unchanged.');

            return self::SUCCESS;
        }

        if ($action === 'resume') {
            Cache::forget(self::PAUSE_KEY);
            $this->info('Scheduled Shopline sync is resumed.');

            return self::SUCCESS;
        }

        if ($action === 'status') {
            $this->line(Cache::has(self::PAUSE_KEY) ? 'Scheduled Shopline sync: paused' : 'Scheduled Shopline sync: active');

            return self::SUCCESS;
        }

        $this->error('Action must be one of: status, pause, resume.');

        return self::INVALID;
    }
}
