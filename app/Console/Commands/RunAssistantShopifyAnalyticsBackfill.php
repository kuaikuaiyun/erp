<?php

namespace App\Console\Commands;

use App\Portals\Assistant\ShopifyAnalyticsAutomationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class RunAssistantShopifyAnalyticsBackfill extends Command
{
    protected $signature = 'assistant:shopify-analytics:backfill
                            {--days=180 : Recent store-local calendar days to re-read; maximum 365}
                            {--limit=10 : Maximum stores to process unless --all is supplied}
                            {--all : Process every eligible Shopify store in this one command}';

    protected $description = 'Re-sync Shopify analytics source orders for a bounded history window';

    public function handle(ShopifyAnalyticsAutomationService $automation): int
    {
        $lock = Cache::lock('assistant-shopify-analytics-backfill', 14400);
        if (!$lock->get()) {
            $this->warn('Another Shopify analytics history backfill is already running.');

            return self::SUCCESS;
        }

        try {
            $days = max(1, min(365, (int) $this->option('days')));
            $limit = $this->option('all') ? 0 : max(1, (int) $this->option('limit'));
            $result = $automation->runHistoryResync($days, $limit);
        } finally {
            $lock->release();
        }

        $this->info(sprintf(
            'History re-sync (%d days) processed: %d; succeeded: %d; failed: %d; completed: %d.',
            $days,
            $result['processed'],
            $result['succeeded'],
            $result['failed'],
            $result['completed'],
        ));

        return self::SUCCESS;
    }
}
