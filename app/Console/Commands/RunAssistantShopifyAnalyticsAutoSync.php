<?php

namespace App\Console\Commands;

use App\Portals\Assistant\ShopifyAnalyticsAutomationService;
use Illuminate\Console\Command;

class RunAssistantShopifyAnalyticsAutoSync extends Command
{
    protected $signature = 'assistant:shopify-analytics:auto-sync {--limit=10 : Maximum stores to process in this run}';

    protected $description = 'Synchronise due Assistant Shopify analytics snapshots';

    public function handle(ShopifyAnalyticsAutomationService $automation): int
    {
        $result = $automation->runAutomatic((int) $this->option('limit'));

        $this->info(sprintf(
            'Processed: %d; succeeded: %d; failed: %d; previous days finalised: %d.',
            $result['processed'],
            $result['succeeded'],
            $result['failed'],
            $result['finalised'],
        ));

        return self::SUCCESS;
    }
}
