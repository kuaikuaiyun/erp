<?php

namespace App\Console\Commands;

use App\Models\AdminUser;
use App\Portals\Assistant\AssistantEmailPurchaseSyncService;
use Illuminate\Console\Command;
use Throwable;

class SyncAssistantEmailPurchases extends Command
{
    protected $signature = 'assistant:email-purchases:sync';

    protected $description = 'Synchronise purchased mailbox statuses for Assistant domain stores';

    public function handle(AssistantEmailPurchaseSyncService $emailPurchases): int
    {
        $actor = new AdminUser();
        $actor->forceFill(['name' => '系统自动同步']);

        try {
            $result = $emailPurchases->synchronize($actor);
        } catch (Throwable $exception) {
            $this->error('Email purchase status synchronisation failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Source: %d; purchased stores: %d; other stores: %d.',
            $result['source_count'],
            $result['matched_store_count'],
            $result['unmatched_store_count'],
        ));

        return self::SUCCESS;
    }
}
