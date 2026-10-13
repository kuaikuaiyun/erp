<?php

namespace App\Jobs;

use App\Models\AdminUser;
use App\Models\LogisticsOrder;
use App\Models\LogisticsSyncLog;
use App\Models\LogisticsTongtuMatchTask;
use App\Portals\Logistics\TongtuOrderMatchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class MatchTongtuOrderJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 180;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 120];

    public function __construct(public readonly int $taskId)
    {
    }

    public function handle(TongtuOrderMatchService $tongtu): void
    {
        $task = LogisticsTongtuMatchTask::query()->with(['batch', 'order'])->find($this->taskId);
        if (!$task || $task->isTerminal()) {
            return;
        }

        $batch = $task->batch;
        $order = $task->order;
        if (!$batch || !$order) {
            $this->completeAsFailed($task, '匹配任务缺少批次或订单记录。');

            return;
        }

        $actor = $batch->actor_id ? AdminUser::query()->find($batch->actor_id) : null;
        if (!$actor) {
            $this->completeAsFailed($task, '发起匹配任务的用户不存在或已被删除。');

            return;
        }

        $task->forceFill([
            'status' => LogisticsTongtuMatchTask::STATUS_PROCESSING,
            'attempt_count' => (int) $task->attempt_count + 1,
            'last_error' => null,
            'started_at' => $task->started_at ?: now(),
        ])->save();
        $batch->refreshProgress();

        $summary = $tongtu->match([$order], $actor);
        $status = match (true) {
            $summary['matched'] > 0 => LogisticsTongtuMatchTask::STATUS_MATCHED,
            $summary['sku_exception'] > 0 => LogisticsTongtuMatchTask::STATUS_SKU_EXCEPTION,
            $summary['not_found'] > 0 => LogisticsTongtuMatchTask::STATUS_NOT_FOUND,
            default => LogisticsTongtuMatchTask::STATUS_FAILED,
        };
        $errors = $summary['errors'] ?? [];
        $error = $status === LogisticsTongtuMatchTask::STATUS_FAILED
            ? (is_array($errors) && filled($errors[(int) $order->getKey()] ?? null)
                ? (string) $errors[(int) $order->getKey()]
                : $this->latestFailureReason($order->getKey()))
            : null;

        $task->forceFill([
            'status' => $status,
            'last_error' => $error,
            'completed_at' => now(),
        ])->save();
        $batch->refreshProgress();
    }

    public function failed(Throwable $exception): void
    {
        $task = LogisticsTongtuMatchTask::query()->with(['batch', 'order'])->find($this->taskId);
        if (!$task || $task->isTerminal()) {
            return;
        }

        if ($task->order instanceof LogisticsOrder) {
            $task->order->fill([
                'data_status' => LogisticsOrder::STATUS_TONGTU_FAILED,
            ])->save();
        }

        $this->completeAsFailed($task, mb_substr($exception->getMessage(), 0, 1000));
    }

    private function completeAsFailed(LogisticsTongtuMatchTask $task, string $message): void
    {
        $task->forceFill([
            'status' => LogisticsTongtuMatchTask::STATUS_FAILED,
            'last_error' => $message,
            'completed_at' => now(),
        ])->save();

        if ($task->batch) {
            $task->batch->forceFill(['last_error' => $message])->save();
            $task->batch->refreshProgress();
        }
    }

    private function latestFailureReason(int $orderId): string
    {
        $message = LogisticsSyncLog::query()
            ->where('logistics_order_id', $orderId)
            ->where('source', 'tongtu')
            ->where('action', 'match')
            ->where('status', 'failed')
            ->latest('id')
            ->value('message');

        return filled($message) ? (string) $message : '通途接口请求失败，请查看该订单的匹配日志。';
    }
}
