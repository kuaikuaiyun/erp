<?php

namespace App\Portals\Assistant;

use App\Models\AssistantDomainStore;
use App\Models\AssistantShopifyAnalyticsBackfill;
use App\Models\AssistantShopifyStoreMetric;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class ShopifyAnalyticsAutomationService
{
    private const AUTO_SYNC_INTERVAL_MINUTES = 30;

    public function __construct(private readonly ShopifyStoreAnalyticsService $analytics)
    {
    }

    /** @return array{processed: int, succeeded: int, failed: int} */
    public function runAutomatic(int $limit = 20): array
    {
        $result = ['processed' => 0, 'succeeded' => 0, 'failed' => 0];
        $dueBefore = now()->subMinutes(self::AUTO_SYNC_INTERVAL_MINUTES);

        $stores = $this->eligibleStores()
            ->where(function (Builder $query) use ($dueBefore): void {
                $query->whereDoesntHave('shopifyAnalyticsMetric')
                    ->orWhereHas('shopifyAnalyticsMetric', function (Builder $metric) use ($dueBefore): void {
                        $metric->whereNull('last_successful_synced_at')
                            ->orWhere('last_successful_synced_at', '<=', $dueBefore);
                    });
            })
            ->orderBy('id')
            ->limit($this->limit($limit, 20, 50))
            ->get();

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
        $result = ['processed' => 0, 'succeeded' => 0, 'failed' => 0, 'completed' => 0, 'history_rows' => 0];

        $tasks = AssistantShopifyAnalyticsBackfill::query()
            ->with('store')
            ->where('status', AssistantShopifyAnalyticsBackfill::STATUS_PENDING)
            ->whereNotNull('next_date')
            ->where(function (Builder $query): void {
                $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
            })
            ->orderBy('next_attempt_at')
            ->orderBy('id')
            ->limit($this->limit($limit, 10, 20))
            ->get();

        foreach ($tasks as $task) {
            $result['processed']++;

            if (!$this->canBackfill($task)) {
                $this->deferTask($task, 'store_is_not_ready', 360);
                $result['failed']++;

                continue;
            }

            try {
                $fromDate = $task->from_date?->toDateString();
                $toDate = $task->to_date?->toDateString();
                if ($fromDate === null || $toDate === null) {
                    $this->completeTask($task);
                    $result['completed']++;

                    continue;
                }

                $shopTimezone = (string) $task->shop_timezone;
                if (!in_array($shopTimezone, timezone_identifiers_list(), true)) {
                    throw new \RuntimeException('The Shopify store timezone is invalid.');
                }

                // The backfill range is defined by the store's own local
                // calendar; it is converted to UTC only for hour storage.
                $fromUtc = CarbonImmutable::createFromFormat('!Y-m-d', $fromDate, $shopTimezone)
                    ->startOfDay()
                    ->setTimezone('UTC')
                    ->startOfHour();
                $scheduledEndUtc = CarbonImmutable::createFromFormat('!Y-m-d', $toDate, $shopTimezone)
                    ->addDay()
                    ->startOfDay()
                    ->setTimezone('UTC')
                    ->subHour();
                $currentHourUtc = CarbonImmutable::now('UTC')->startOfHour();
                $toUtc = $scheduledEndUtc->lessThan($currentHourUtc) ? $scheduledEndUtc : $currentHourUtc;

                if ($toUtc->lessThan($fromUtc)) {
                    $this->completeTask($task);
                    $result['completed']++;

                    continue;
                }

                $result['history_rows'] += $this->analytics->refreshUtcHours(
                    $task->store,
                    $fromUtc,
                    $toUtc,
                    shopTimezone: $shopTimezone,
                );

                $this->completeTask($task);
                $result['succeeded']++;
                $result['completed']++;
            } catch (\Throwable $exception) {
                report($exception);
                $this->deferTask($task, Str::limit($exception->getMessage(), 1000), null);
                $result['failed']++;
            }
        }

        return $result;
    }

    /** @return array{processed: int, succeeded: int, failed: int, completed: int, history_rows: int} */
    public function runAllInitialBackfills(): array
    {
        $result = ['processed' => 0, 'succeeded' => 0, 'failed' => 0, 'completed' => 0, 'history_rows' => 0];

        do {
            $batch = $this->runInitialBackfill(20);
            foreach ($result as $key => $value) {
                $result[$key] = $value + $batch[$key];
            }
        } while ($batch['processed'] > 0);

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

    private function canBackfill(AssistantShopifyAnalyticsBackfill $task): bool
    {
        $store = $task->store;

        return $store instanceof AssistantDomainStore
            && $store->site_builder === 'shopify'
            && $store->shop_status === AssistantDomainStore::SHOP_STATUS_IN_USE
            && filled($store->shopify_client_id)
            && filled($store->shopify_client_secret);
    }

    private function completeTask(AssistantShopifyAnalyticsBackfill $task): void
    {
        $task->fill([
            'next_date' => null,
            'status' => AssistantShopifyAnalyticsBackfill::STATUS_COMPLETED,
            'next_attempt_at' => null,
            'last_processed_at' => now(),
            'completed_at' => now(),
        ])->save();
    }

    private function deferTask(AssistantShopifyAnalyticsBackfill $task, string $message, ?int $minutes): void
    {
        $attempts = (int) $task->attempts + 1;
        $delay = $minutes ?? min(360, max(5, $attempts * 5));

        $task->fill([
            'status' => AssistantShopifyAnalyticsBackfill::STATUS_PENDING,
            'attempts' => $attempts,
            'last_error' => Str::limit($message, 1000),
            'next_attempt_at' => now()->addMinutes($delay),
            'started_at' => $task->started_at ?? now(),
        ])->save();
    }

    private function limit(int $value, int $fallback, int $maximum): int
    {
        return max(1, min($maximum, $value ?: $fallback));
    }
}
