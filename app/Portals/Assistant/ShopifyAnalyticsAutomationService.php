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
    private const AUTO_SYNC_INTERVAL_HOURS = 4;

    public function __construct(private readonly ShopifyStoreAnalyticsService $analytics)
    {
    }

    /** @return array{processed: int, succeeded: int, failed: int, finalised: int} */
    public function runAutomatic(int $limit = 10): array
    {
        $result = ['processed' => 0, 'succeeded' => 0, 'failed' => 0, 'finalised' => 0];
        $dueBefore = now()->subHours(self::AUTO_SYNC_INTERVAL_HOURS);

        $stores = $this->eligibleStores()
            ->where(function (Builder $query) use ($dueBefore): void {
                $query->whereDoesntHave('shopifyAnalyticsMetric')
                    ->orWhereHas('shopifyAnalyticsMetric', function (Builder $metric) use ($dueBefore): void {
                        $metric->whereNull('synced_at')->orWhere('synced_at', '<=', $dueBefore);
                    });
            })
            ->orderBy('id')
            ->limit($this->limit($limit, 10, 50))
            ->get();

        foreach ($stores as $store) {
            $result['processed']++;
            $metric = $this->analytics->refresh($store);

            if ($metric->sync_status !== AssistantShopifyStoreMetric::STATUS_SUCCESS) {
                $result['failed']++;

                continue;
            }

            $result['succeeded']++;

            if ($this->finalisePreviousReportingDay($store)) {
                $result['finalised']++;
            }
        }

        return $result;
    }

    /** @return array{processed: int, succeeded: int, failed: int, completed: int, history_rows: int} */
    public function runInitialBackfill(int $limit = 5): array
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
            ->limit($this->limit($limit, 5, 20))
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
                $toDate = $task->next_date?->toDateString();
                if ($fromDate === null || $toDate === null) {
                    $this->completeTask($task);
                    $result['completed']++;

                    continue;
                }

                $result['history_rows'] += $this->analytics->refreshHistoricalRange(
                    $task->store,
                    $task->shop_timezone,
                    $fromDate,
                    $toDate,
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

    private function finalisePreviousReportingDay(AssistantDomainStore $store): bool
    {
        $task = AssistantShopifyAnalyticsBackfill::query()
            ->where('assistant_domain_store_id', $store->getKey())
            ->first();
        if (!$task) {
            return false;
        }

        $timezone = (string) $task->shop_timezone;
        if (!in_array($timezone, timezone_identifiers_list(), true) || now($timezone)->hour < 1) {
            return false;
        }

        $previousDate = now($timezone)->subDay()->toDateString();
        $lastFinalisedDate = $task->last_finalized_date?->toDateString();
        if ($lastFinalisedDate !== null && $lastFinalisedDate >= $previousDate) {
            return false;
        }

        try {
            $this->analytics->refreshHistoricalDate($store, $timezone, $previousDate);
            $this->markFinalised($task, $previousDate);

            return true;
        } catch (\Throwable $exception) {
            report($exception);
            $this->deferTask($task, Str::limit($exception->getMessage(), 1000), null);

            return false;
        }
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

    private function markFinalised(AssistantShopifyAnalyticsBackfill $task, string $reportDate): void
    {
        $attributes = [
            'last_finalized_date' => $reportDate,
            'last_error' => null,
            'last_processed_at' => now(),
        ];

        if ($task->next_date?->toDateString() === $reportDate) {
            $current = CarbonImmutable::createFromFormat('!Y-m-d', $reportDate, $task->shop_timezone);
            $next = $current->subDay();
            $from = $task->from_date?->toDateString();
            $isComplete = $from === null || $next->toDateString() < $from;

            $attributes['next_date'] = $isComplete ? null : $next->toDateString();
            $attributes['status'] = $isComplete
                ? AssistantShopifyAnalyticsBackfill::STATUS_COMPLETED
                : AssistantShopifyAnalyticsBackfill::STATUS_PENDING;
            $attributes['attempts'] = 0;
            $attributes['next_attempt_at'] = null;
            $attributes['completed_at'] = $isComplete ? now() : null;
        }

        $task->fill($attributes)->save();
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
