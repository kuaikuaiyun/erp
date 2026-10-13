<?php

namespace App\Console\Commands;

use App\Portals\Assistant\ShopifyAnalyticsAutomationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class RunAssistantShopifyAnalyticsBackfill extends Command
{
    protected $signature = 'assistant:shopify-analytics:backfill
                            {--limit=10 : Maximum stores to process in a normal scheduled run}
                            {--all : Process every currently due store backfill in this one command}';

    protected $description = 'Run resumable Assistant Shopify analytics history backfills';

    public function handle(ShopifyAnalyticsAutomationService $automation): int
    {
        $lock = Cache::lock('assistant-shopify-analytics-backfill', 14400);
        if (!$lock->get()) {
            $this->warn('Another Shopify analytics history backfill is already running.');

            return self::SUCCESS;
        }

        try {
            $result = $this->option('all')
                ? $automation->runAllInitialBackfills()
                : $automation->runInitialBackfill((int) $this->option('limit'));
        } finally {
            $lock->release();
        }

        $this->info(sprintf(
            'Stores processed: %d; succeeded: %d; failed: %d; completed: %d; UTC hour records written: %d.',
            $result['processed'],
            $result['succeeded'],
            $result['failed'],
            $result['completed'],
            $result['history_rows'],
        ));

        return self::SUCCESS;
    }
}
