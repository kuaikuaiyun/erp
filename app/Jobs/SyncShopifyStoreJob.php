<?php

namespace App\Jobs;

use App\Models\AdminUser;
use App\Models\AssistantDomainStore;
use App\Models\LogisticsShopifySyncTask;
use App\Portals\Logistics\ShopifyOrderSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;

class SyncShopifyStoreJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 600;
    public int $tries = 1;

    public function __construct(public readonly int $taskId)
    {
    }

    public function handle(ShopifyOrderSyncService $shopify): void
    {
        $task = LogisticsShopifySyncTask::query()->with('batch')->find($this->taskId);
        if (!$task || $task->isTerminal()) {
            return;
        }

        $batch = $task->batch;
        if (!$batch) {
            $this->completeAsFailed($task, '同步任务缺少对应批次。');

            return;
        }
        if ($batch->cancel_requested_at) {
            $task->forceFill([
                'status' => LogisticsShopifySyncTask::STATUS_CANCELLED,
                'completed_at' => now(),
            ])->save();
            $batch->refreshProgress();

            return;
        }

        $actor = $batch->actor_id ? AdminUser::query()->find($batch->actor_id) : null;
        $store = AssistantDomainStore::query()->find($task->assistant_domain_store_id);
        if (!$actor) {
            $this->completeAsFailed($task, '发起同步任务的用户不存在或已被删除。');

            return;
        }
        if (!$store
            || $store->site_builder !== 'shopify'
            || $store->shop_status !== AssistantDomainStore::SHOP_STATUS_IN_USE) {
            $this->completeAsFailed($task, '店铺不存在，或已不再是可同步的在用 Shopify 店铺。');

            return;
        }

        $task->forceFill([
            'status' => LogisticsShopifySyncTask::STATUS_PROCESSING,
            'attempt_count' => (int) $task->attempt_count + 1,
            'last_error' => null,
            'started_at' => $task->started_at ?: now(),
        ])->save();
        $batch->refreshProgress();

        try {
            $summary = $shopify->syncStore($store, $actor, (int) $batch->sync_days);
        } catch (Throwable $exception) {
            $this->completeAsFailed($task, Str::limit($exception->getMessage(), 1000));

            return;
        }

        $task->forceFill([
            'status' => LogisticsShopifySyncTask::STATUS_SUCCESS,
            'created_order_count' => (int) ($summary['created'] ?? 0),
            'updated_order_count' => (int) ($summary['updated'] ?? 0),
            'sku_exception_count' => (int) ($summary['sku_exception'] ?? 0),
            'sku_item_count' => (int) ($summary['sku_item_count'] ?? 0),
            'sku_missing_item_count' => (int) ($summary['sku_missing_item_count'] ?? 0),
            'sku_missing_details' => $summary['sku_missing_details'] ?? [],
            'skipped_unpaid_count' => (int) ($summary['skipped_unpaid'] ?? 0),
            'webhook_active' => (bool) ($summary['webhook_active'] ?? false),
            'webhook_error' => filled($summary['webhook_error'] ?? null)
                ? Str::limit((string) $summary['webhook_error'], 1000)
                : null,
            'completed_at' => now(),
        ])->save();
        $batch->refreshProgress();
    }

    public function failed(Throwable $exception): void
    {
        $task = LogisticsShopifySyncTask::query()->with('batch')->find($this->taskId);
        if (!$task || $task->isTerminal()) {
            return;
        }

        $this->completeAsFailed($task, Str::limit($exception->getMessage(), 1000));
    }

    private function completeAsFailed(LogisticsShopifySyncTask $task, string $message): void
    {
        $task->forceFill([
            'status' => LogisticsShopifySyncTask::STATUS_FAILED,
            'last_error' => $message,
            'completed_at' => now(),
        ])->save();

        if ($task->batch) {
            $task->batch->forceFill(['last_error' => $message])->save();
            $task->batch->refreshProgress();
        }
    }
}
