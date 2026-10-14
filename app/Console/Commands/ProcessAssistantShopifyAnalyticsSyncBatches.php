<?php

namespace App\Console\Commands;

use App\Models\AdminUser;
use App\Models\AssistantDomainStore;
use App\Models\AssistantShopifyAnalyticsSyncBatch;
use App\Models\AssistantShopifyAnalyticsSyncTask;
use App\Models\AssistantShopifyStoreMetric;
use App\Portals\Assistant\AssistantPortalAuthorizationService;
use App\Portals\Assistant\ShopifyStoreAnalyticsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class ProcessAssistantShopifyAnalyticsSyncBatches extends Command
{
    protected $signature = 'assistant:shopify-analytics:process-batches {--limit=20 : Maximum queued stores to process in this run}';

    protected $description = 'Process queued Assistant Shopify analytics synchronization tasks';

    public function handle(
        ShopifyStoreAnalyticsService $analytics,
        AssistantPortalAuthorizationService $authorization,
    ): int {
        $limit = max(1, min(100, (int) $this->option('limit')));
        // A first-time store sync can fetch about ninety local dates. Keep
        // the worker lock for the full expected window so cron cannot start a
        // second processor against the same batch mid-import.
        $lock = Cache::lock('assistant-shopify-analytics-sync-batches', 10800);

        if (!$lock->get()) {
            $this->line('Another Assistant Shopify analytics batch processor is already running.');

            return self::SUCCESS;
        }

        try {
            $this->requeueStalledTasks();

            $tasks = AssistantShopifyAnalyticsSyncTask::query()
                ->with(['batch', 'store'])
                ->where('status', AssistantShopifyAnalyticsSyncTask::STATUS_QUEUED)
                ->whereHas('batch', function ($query): void {
                    $query->whereIn('status', [
                        AssistantShopifyAnalyticsSyncBatch::STATUS_QUEUED,
                        AssistantShopifyAnalyticsSyncBatch::STATUS_PROCESSING,
                    ]);
                })
                ->orderBy('id')
                ->limit($limit)
                ->get();

            foreach ($tasks as $task) {
                $this->processTask($task, $analytics, $authorization);
            }

            $this->info('Processed '.$tasks->count().' Assistant Shopify analytics task(s).');

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }

    private function requeueStalledTasks(): void
    {
        $cutoff = now()->subHours(3);

        AssistantShopifyAnalyticsSyncTask::query()
            ->where('status', AssistantShopifyAnalyticsSyncTask::STATUS_PROCESSING)
            ->where('started_at', '<', $cutoff)
            ->update([
                'status' => AssistantShopifyAnalyticsSyncTask::STATUS_QUEUED,
                'started_at' => null,
                'updated_at' => now(),
            ]);
    }

    private function processTask(
        AssistantShopifyAnalyticsSyncTask $task,
        ShopifyStoreAnalyticsService $analytics,
        AssistantPortalAuthorizationService $authorization,
    ): void {
        $batch = $task->batch;

        if (!$batch instanceof AssistantShopifyAnalyticsSyncBatch || $task->status !== AssistantShopifyAnalyticsSyncTask::STATUS_QUEUED) {
            return;
        }

        $task->forceFill([
            'status' => AssistantShopifyAnalyticsSyncTask::STATUS_PROCESSING,
            'attempt_count' => (int) $task->attempt_count + 1,
            'last_error' => null,
            'started_at' => now(),
            'completed_at' => null,
        ])->save();
        $batch->refreshProgress();

        try {
            $actor = $batch->actor_id === null ? null : AdminUser::query()->find($batch->actor_id);
            $store = $task->store;

            if (!$actor instanceof AdminUser || !$authorization->canViewShopifyAnalytics($actor)) {
                $this->failTask($task, $batch, 'access_revoked');

                return;
            }

            if (!$store instanceof AssistantDomainStore
                || !$authorization->scopeDomainStores(AssistantDomainStore::query(), $actor)
                    ->whereKey($task->assistant_domain_store_id)
                    ->where('site_builder', 'shopify')
                    ->exists()) {
                $this->failTask($task, $batch, 'store_unavailable');

                return;
            }

            if (!$this->canRefresh($store)) {
                $this->failTask($task, $batch, $store->shop_status === AssistantDomainStore::SHOP_STATUS_IN_USE
                    ? 'credentials_missing'
                    : 'inactive_store');

                return;
            }

            // A batch task always synchronizes the Shopify store's own local
            // business day. The list filter timezone is only retained for the
            // browser return URL and must never alter Shopify data collection.
            $metric = $analytics->refresh($store, $actor);
            if ($metric->sync_status !== AssistantShopifyStoreMetric::STATUS_SUCCESS) {
                $this->failTask($task, $batch, $metric->sync_message ?: $metric->sync_status);

                return;
            }

            $task->forceFill([
                'status' => AssistantShopifyAnalyticsSyncTask::STATUS_SUCCESS,
                'last_error' => null,
                'completed_at' => now(),
            ])->save();
            $batch->refreshProgress();
        } catch (\Throwable $exception) {
            report($exception);
            $this->failTask($task, $batch, 'unknown');
        }
    }

    private function failTask(
        AssistantShopifyAnalyticsSyncTask $task,
        AssistantShopifyAnalyticsSyncBatch $batch,
        string $reason,
    ): void {
        $task->forceFill([
            'status' => AssistantShopifyAnalyticsSyncTask::STATUS_FAILED,
            'last_error' => $reason,
            'completed_at' => now(),
        ])->save();
        $batch->forceFill(['last_error' => $reason])->save();
        $batch->refreshProgress();
    }

    private function canRefresh(AssistantDomainStore $store): bool
    {
        return $store->shop_status === AssistantDomainStore::SHOP_STATUS_IN_USE
            && filled($store->shopify_client_id)
            && filled($store->shopify_client_secret);
    }
}
