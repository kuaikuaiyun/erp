<?php

namespace App\Portals\Assistant;

use App\Models\AssistantDomainStore;
use App\Models\AssistantShopifyStoreMetric;
use Illuminate\Database\Eloquent\Builder;

class ShopifyAnalyticsAutomationService
{
    private const AUTO_SYNC_INTERVAL_MINUTES = 30;

    public function __construct(private readonly ShopifyStoreAnalyticsService $analytics)
    {
    }

    /** @return array{processed: int, succeeded: int, failed: int} */
    public function runAutomatic(int $limit = 0): array
    {
        $result = ['processed' => 0, 'succeeded' => 0, 'failed' => 0];
        $dueBefore = now()->subMinutes(self::AUTO_SYNC_INTERVAL_MINUTES);

        $stores = $this->eligibleStores()
            // Automatic work must never create an initial history pull for
            // every configured store. A store enters the recurring schedule
            // only after a user explicitly submitted its first sync.
            ->whereHas('shopifyAnalyticsMetric', function (Builder $metric) use ($dueBefore): void {
                $metric->whereNotNull('orders_initial_backfilled_at')
                    ->where(function (Builder $query) use ($dueBefore): void {
                        $query->whereNull('last_successful_synced_at')
                            ->orWhere('last_successful_synced_at', '<=', $dueBefore);
                    });
            })
            ->orderBy('id');

        if ($limit > 0) {
            $stores->limit($limit);
        }

        $stores = $stores->get();

        foreach ($stores as $store) {
            $result['processed']++;
            $metric = $this->analytics->refresh($store);

            if ($metric->sync_status !== AssistantShopifyStoreMetric::STATUS_SUCCESS) {
                $result['failed']++;

                continue;
            }

            $result['succeeded']++;
        }

        return $result;
    }

    /** @return array{processed: int, succeeded: int, failed: int, completed: int, history_rows: int} */
    public function runInitialBackfill(int $limit = 10): array
    {
        // Initial history is now synchronized in the explicit user-selected
        // store task itself, using order detail rows. This legacy command is
        // intentionally retained as a harmless no-op for old BaoTa entries.
        return ['processed' => 0, 'succeeded' => 0, 'failed' => 0, 'completed' => 0, 'history_rows' => 0];
    }

    /** @return array{processed: int, succeeded: int, failed: int, completed: int, history_rows: int} */
    public function runAllInitialBackfills(): array
    {
        $result = ['processed' => 0, 'succeeded' => 0, 'failed' => 0, 'completed' => 0, 'history_rows' => 0];

        return $result;
    }

    /** @return Builder<AssistantDomainStore> */
    private function eligibleStores(): Builder
    {
        return AssistantDomainStore::query()
            ->where('site_builder', 'shopify')
            ->where('shop_status', AssistantDomainStore::SHOP_STATUS_IN_USE)
            ->whereNotNull('shopify_client_id')
            ->where('shopify_client_id', '<>', '')
            ->whereNotNull('shopify_client_secret')
            ->where('shopify_client_secret', '<>', '');
    }

}
