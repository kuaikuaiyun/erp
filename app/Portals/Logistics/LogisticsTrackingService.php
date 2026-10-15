<?php

namespace App\Portals\Logistics;

use App\Models\AdminUser;
use App\Models\LogisticsOrder;
use App\Models\LogisticsTrackingSyncBatch;
use App\Models\LogisticsTrackingSyncTask;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class LogisticsTrackingService
{
    private const MAX_BATCH_SIZE = 40;

    /** @var array<string, array{code:?string,name:string}> */
    private const COURIER_RULES = [
        '/^DYJCN\d+CN$/i' => ['code' => 'dyj-express', 'name' => '达运佳'],
        '/^WY\d+$/i' => ['code' => 'wanyuyuntong', 'name' => '万宇运通'],
        '/^DHHL\d+CN$/i' => ['code' => null, 'name' => '星辰物流'],
        '/^FM\d+CN$/i' => ['code' => null, 'name' => '飞马物流'],
        '/^LX\d+$/i' => ['code' => 'lingxun', 'name' => '领讯物流'],
        '/^(UG|VR)\d+YP$/i' => ['code' => 'yanwen', 'name' => '燕文物流'],
        '/^4PX\d+CN$/i' => ['code' => '4px', 'name' => '递四方'],
        '/^LT\d+NL$/i' => ['code' => 'postnl-parcels', 'name' => 'PostNL'],
        '/^SPXCLT\d+$/i' => ['code' => 'speedx', 'name' => 'SpeedX'],
    ];

    public function queue(array $orderIds, ?AdminUser $actor = null, string $source = 'manual'): LogisticsTrackingSyncBatch
    {
        $ids = collect($orderIds)
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        $batch = LogisticsTrackingSyncBatch::query()->create([
            'actor_id' => $actor?->getKey(),
            'actor_name' => $actor?->name,
            'source' => $source,
            'status' => LogisticsTrackingSyncBatch::STATUS_QUEUED,
        ]);

        $queuedCount = 0;
        $ids->chunk(500)->each(function ($chunk) use ($batch, $source, &$queuedCount): void {
            LogisticsOrder::query()
                ->whereIn('id', $chunk->all())
                ->orderBy('id')
                ->each(function (LogisticsOrder $order) use ($batch, $source, &$queuedCount): void {
                    $this->queueOrder($order, $batch, $source);
                    $queuedCount++;
                });
        });

        $batch->forceFill(['total_count' => $queuedCount])->save();
        $batch->refreshProgress();

        return $batch->fresh();
    }

    public function isConfigured(): bool
    {
        return filled(config('logistics.tracking.api_key'));
    }

    public function queueHistorical(?AdminUser $actor = null, int $limit = 0): LogisticsTrackingSyncBatch
    {
        $query = LogisticsOrder::query()
            ->whereNotNull('tracking_number')
            ->where('tracking_number', '!=', '')
            ->orderBy('id');
        if ($limit > 0) {
            $query->limit($limit);
        }

        return $this->queue($query->pluck('id')->all(), $actor, 'history');
    }

    /**
     * Shopify 同步到新单号时仅登记单号；真实物流状态只由 51Tracking 查询覆盖。
     */
    public function scheduleForOrder(LogisticsOrder $order): void
    {
        $trackingNumber = $this->normaliseTrackingNumber((string) $order->tracking_number);
        if ($trackingNumber === '') {
            $order->forceFill([
                'logistics_status' => LogisticsOrder::LOGISTICS_PENDING,
                'tracking_courier_code' => null,
                'tracking_courier_name' => null,
                'tracking_next_sync_at' => null,
                'tracking_last_error' => null,
                'tracking_status_source' => '51tracking',
            ])->save();

            return;
        }

        $courier = $this->courierFor($trackingNumber);
        $state = $courier === null
            ? LogisticsOrder::LOGISTICS_COURIER_UNMATCHED
            : ($courier['code'] === null ? LogisticsOrder::LOGISTICS_COURIER_UNSUPPORTED : LogisticsOrder::LOGISTICS_WAITING_QUERY);
        $order->forceFill([
            'tracking_number' => $trackingNumber,
            'logistics_status' => $state,
            'tracking_courier_code' => $courier['code'] ?? null,
            'tracking_courier_name' => $courier['name'] ?? null,
            'tracking_next_sync_at' => $courier !== null && $courier['code'] !== null ? now() : null,
            'tracking_last_error' => null,
            'tracking_status_source' => '51tracking',
        ])->save();
    }

    /** @return array{processed:int, success:int, failed:int} */
    public function processDue(int $limit = 80): array
    {
        if (!$this->isConfigured()) {
            return ['processed' => 0, 'success' => 0, 'failed' => 0];
        }

        $limit = max(1, $limit);
        $this->recoverStaleProcessingTasks();
        $this->queueScheduledOrders($limit);
        $tasks = LogisticsTrackingSyncTask::query()
            ->whereIn('status', [LogisticsTrackingSyncTask::STATUS_QUEUED, LogisticsTrackingSyncTask::STATUS_WAITING])
            ->where(function ($query): void {
                $query->whereNull('due_at')->orWhere('due_at', '<=', now());
            })
            ->orderBy('due_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $summary = ['processed' => 0, 'success' => 0, 'failed' => 0];
        $tasks->groupBy('stage')->each(function (Collection $group, string $stage) use (&$summary): void {
            $group->chunk(self::MAX_BATCH_SIZE)->each(function (Collection $chunk) use ($stage, &$summary): void {
                if ($stage === LogisticsTrackingSyncTask::STAGE_REGISTER) {
                    $this->register($chunk, $summary);
                } else {
                    $this->query($chunk, $summary);
                }
            });
        });

        return $summary;
    }

    /** @return array{code:string,name:string}|array{code:null,name:string}|null */
    public function courierFor(string $trackingNumber): ?array
    {
        foreach (self::COURIER_RULES as $pattern => $courier) {
            if (preg_match($pattern, $trackingNumber) === 1) {
                return $courier;
            }
        }

        return null;
    }

    private function queueOrder(LogisticsOrder $order, LogisticsTrackingSyncBatch $batch, string $source): void
    {
        $trackingNumber = $this->normaliseTrackingNumber((string) $order->tracking_number);
        $courier = $trackingNumber === '' ? null : $this->courierFor($trackingNumber);
        $active = LogisticsTrackingSyncTask::query()
            ->where('logistics_order_id', $order->getKey())
            ->whereIn('status', LogisticsTrackingSyncTask::activeStatuses())
            ->exists();

        if ($trackingNumber === '') {
            $this->finishSkippedTask($batch, $order, null, null, $source, '订单没有物流单号，不纳入查询。');
            $this->scheduleForOrder($order);

            return;
        }
        if ($courier === null) {
            $this->finishSkippedTask($batch, $order, $trackingNumber, null, $source, '物流单号未匹配已配置的物流商规则。');
            $this->scheduleForOrder($order);

            return;
        }
        if ($courier['code'] === null) {
            $this->finishSkippedTask($batch, $order, $trackingNumber, null, $source, '该物流商暂未接入 51Tracking。');
            $this->scheduleForOrder($order);

            return;
        }
        if ($active) {
            $this->finishSkippedTask($batch, $order, $trackingNumber, $courier['code'], $source, '该订单已有物流查询任务在处理中。');

            return;
        }

        $this->scheduleForOrder($order);
        LogisticsTrackingSyncTask::query()->create([
            'logistics_tracking_sync_batch_id' => $batch->getKey(),
            'logistics_order_id' => $order->getKey(),
            'tracking_number' => $trackingNumber,
            'courier_code' => $courier['code'],
            'source' => $source,
            'stage' => LogisticsTrackingSyncTask::STAGE_REGISTER,
            'status' => LogisticsTrackingSyncTask::STATUS_QUEUED,
            'due_at' => now(),
        ]);
    }

    private function finishSkippedTask(LogisticsTrackingSyncBatch $batch, LogisticsOrder $order, ?string $trackingNumber, ?string $courierCode, string $source, string $reason): void
    {
        LogisticsTrackingSyncTask::query()->create([
            'logistics_tracking_sync_batch_id' => $batch->getKey(),
            'logistics_order_id' => $order->getKey(),
            'tracking_number' => $trackingNumber,
            'courier_code' => $courierCode,
            'source' => $source,
            'stage' => LogisticsTrackingSyncTask::STAGE_QUERY,
            'status' => LogisticsTrackingSyncTask::STATUS_SKIPPED,
            'last_error' => $reason,
            'completed_at' => now(),
        ]);
    }

    private function queueScheduledOrders(int $limit): void
    {
        LogisticsOrder::query()
            ->whereNotNull('tracking_courier_code')
            ->whereNotNull('tracking_number')
            ->where('tracking_number', '!=', '')
            ->where('logistics_status', '!=', LogisticsOrder::LOGISTICS_DELIVERED)
            ->whereNotNull('tracking_next_sync_at')
            ->where('tracking_next_sync_at', '<=', now())
            ->orderBy('tracking_next_sync_at')
            ->limit($limit)
            ->get()
            ->each(function (LogisticsOrder $order): void {
                $active = LogisticsTrackingSyncTask::query()
                    ->where('logistics_order_id', $order->getKey())
                    ->whereIn('status', LogisticsTrackingSyncTask::activeStatuses())
                    ->exists();
                if ($active) {
                    return;
                }

                LogisticsTrackingSyncTask::query()->create([
                    'logistics_order_id' => $order->getKey(),
                    'tracking_number' => $this->normaliseTrackingNumber((string) $order->tracking_number),
                    'courier_code' => $order->tracking_courier_code,
                    'source' => 'auto',
                    'stage' => $order->tracking_registered_at ? LogisticsTrackingSyncTask::STAGE_QUERY : LogisticsTrackingSyncTask::STAGE_REGISTER,
                    'status' => LogisticsTrackingSyncTask::STATUS_QUEUED,
                    'due_at' => now(),
                ]);
            });
    }

    private function recoverStaleProcessingTasks(): void
    {
        // 进程被面板终止、网络连接异常中断等情况下，任务不应永久卡在
        // processing 并阻塞同一订单的后续复查。15 分钟后将其安全放回队列。
        LogisticsTrackingSyncTask::query()
            ->where('status', LogisticsTrackingSyncTask::STATUS_PROCESSING)
            ->where('updated_at', '<=', now()->subMinutes(15))
            ->update([
                'status' => LogisticsTrackingSyncTask::STATUS_WAITING,
                'due_at' => now(),
                'last_error' => '上一次物流查询任务未完成，已自动重新排队。',
                'updated_at' => now(),
            ]);
    }

    /** @param Collection<int, LogisticsTrackingSyncTask> $tasks @param array{processed:int,success:int,failed:int} $summary */
    private function register(Collection $tasks, array &$summary): void
    {
        $tasks->each(fn (LogisticsTrackingSyncTask $task) => $task->forceFill([
            'status' => LogisticsTrackingSyncTask::STATUS_PROCESSING,
            'started_at' => $task->started_at ?: now(),
        ])->save());

        try {
            $response = Http::acceptJson()
                ->timeout((int) config('logistics.tracking.timeout', 30))
                ->withHeaders(['Tracking-Api-Key' => (string) config('logistics.tracking.api_key')])
                ->post($this->apiUrl('/trackings/batch'), $tasks->map(static function (LogisticsTrackingSyncTask $task): array {
                    $orderNumber = trim((string) ($task->order?->shopify_order_number ?? ''));

                    return [
                        'tracking_number' => $task->tracking_number,
                        'courier_code' => $task->courier_code,
                        // 51Tracking 把该值作为外部订单参考号保存；使用真实 Shopify
                        // 订单号，方便后续在 51Tracking 后台反查，不影响物流状态判断。
                        'order_number' => $orderNumber !== '' ? $orderNumber : $task->tracking_number,
                        'language' => 'zh',
                    ];
                })->values()->all());
        } catch (ConnectionException $exception) {
            $this->retryTasks($tasks, '51Tracking 登记请求连接失败：'.$exception->getMessage(), 360);

            return;
        }

        if (!$response->successful()) {
            $this->handleHttpFailure($tasks, $response->status(), Str::limit($response->body(), 1000), $summary);

            return;
        }

        $body = $response->json();
        $code = (int) data_get($body, 'meta.code', 0);
        if ($code !== 200 && $code !== 4101) {
            $this->retryTasks($tasks, '51Tracking 登记失败：'.(string) (data_get($body, 'meta.message') ?: '未知接口响应'), 360);

            return;
        }

        $registrationErrors = collect(data_get($body, 'data.error', []))
            ->filter(static fn ($item): bool => is_array($item) && filled($item['tracking_number'] ?? null))
            ->keyBy(static fn (array $item): string => strtoupper(trim((string) $item['tracking_number'])));
        $tasks->each(function (LogisticsTrackingSyncTask $task) use ($code, $registrationErrors, &$summary): void {
            $trackingKey = strtoupper(trim((string) $task->tracking_number));
            $registrationError = $registrationErrors->get($trackingKey);
            $errorCode = is_array($registrationError) ? (int) ($registrationError['errorCode'] ?? 0) : 0;
            if ($errorCode > 0 && $errorCode !== 4101) {
                $this->failTasks(
                    collect([$task]),
                    '51Tracking 登记失败：'.((string) ($registrationError['errorMessage'] ?? '未知接口响应')),
                    $summary,
                );

                return;
            }

            $delay = ($code === 4101 || $errorCode === 4101)
                ? 0
                : max(1, (int) config('logistics.tracking.initial_query_delay_minutes', 2));
            $order = $task->order;
            if ($order instanceof LogisticsOrder) {
                $order->forceFill([
                    'tracking_registered_at' => $order->tracking_registered_at ?: now(),
                    'tracking_next_sync_at' => now()->addMinutes($delay),
                    'tracking_last_error' => null,
                ])->save();
            }
            $task->forceFill([
                'stage' => LogisticsTrackingSyncTask::STAGE_QUERY,
                'status' => LogisticsTrackingSyncTask::STATUS_WAITING,
                'attempt_count' => $task->attempt_count + 1,
                'due_at' => now()->addMinutes($delay),
                'last_error' => null,
            ])->save();
            $summary['processed']++;
        });
        $this->refreshBatches($tasks);
    }

    /** @param Collection<int, LogisticsTrackingSyncTask> $tasks @param array{processed:int,success:int,failed:int} $summary */
    private function query(Collection $tasks, array &$summary): void
    {
        $tasks->each(fn (LogisticsTrackingSyncTask $task) => $task->forceFill([
            'status' => LogisticsTrackingSyncTask::STATUS_PROCESSING,
            'started_at' => $task->started_at ?: now(),
        ])->save());

        try {
            $response = Http::acceptJson()
                ->timeout((int) config('logistics.tracking.timeout', 30))
                ->withHeaders(['Tracking-Api-Key' => (string) config('logistics.tracking.api_key')])
                ->get($this->apiUrl('/trackings/get'), [
                    'tracking_numbers' => $tasks->pluck('tracking_number')->implode(','),
                ]);
        } catch (ConnectionException $exception) {
            $this->retryTasks($tasks, '51Tracking 查询请求连接失败：'.$exception->getMessage(), 360);

            return;
        }

        if (!$response->successful()) {
            $this->handleHttpFailure($tasks, $response->status(), Str::limit($response->body(), 1000), $summary);

            return;
        }

        $body = $response->json();
        if ((int) data_get($body, 'meta.code', 0) !== 200) {
            $this->retryTasks($tasks, '51Tracking 查询失败：'.(string) (data_get($body, 'meta.message') ?: '未知接口响应'), 360);

            return;
        }

        $items = collect(data_get($body, 'data.success', []))
            ->filter(static fn ($item): bool => is_array($item) && filled($item['tracking_number'] ?? null))
            ->keyBy(static fn (array $item): string => strtoupper(trim((string) $item['tracking_number'])));

        $tasks->each(function (LogisticsTrackingSyncTask $task) use ($items, &$summary): void {
            $item = $items->get(strtoupper(trim((string) $task->tracking_number)));
            if (!is_array($item)) {
                $this->retryTasks(collect([$task]), '51Tracking 暂未返回该单号的查询结果。', 360);

                return;
            }

            $order = $task->order;
            if (!$order instanceof LogisticsOrder) {
                $task->forceFill([
                    'status' => LogisticsTrackingSyncTask::STATUS_FAILED,
                    'last_error' => '物流订单不存在，无法保存查询结果。',
                    'completed_at' => now(),
                ])->save();
                $summary['processed']++;
                $summary['failed']++;

                return;
            }

            $deliveryStatus = strtolower(trim((string) ($item['delivery_status'] ?? 'pending')));
            [$status, $nextMinutes] = $this->statusSchedule($deliveryStatus);
            $checkpoint = $this->parseCheckpointTime($item['latest_checkpoint_time'] ?? null);
            $order->forceFill([
                'logistics_status' => $status,
                'tracking_api_status' => $deliveryStatus ?: null,
                'tracking_api_substatus' => $item['substatus'] ?? null,
                'tracking_latest_event' => $item['latest_event'] ?? null,
                'tracking_latest_checkpoint_at' => $checkpoint,
                'tracking_signed_by' => $item['signed_by'] ?? null,
                'tracking_last_checked_at' => now(),
                'tracking_next_sync_at' => $nextMinutes === null ? null : now()->addMinutes($nextMinutes),
                'tracking_attempts' => (int) $order->tracking_attempts + 1,
                'tracking_last_error' => null,
                'tracking_raw_payload' => json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'tracking_status_source' => '51tracking',
            ])->save();
            $task->forceFill([
                'status' => LogisticsTrackingSyncTask::STATUS_SUCCESS,
                'attempt_count' => $task->attempt_count + 1,
                'last_error' => null,
                'completed_at' => now(),
            ])->save();
            $summary['processed']++;
            $summary['success']++;
        });
        $this->refreshBatches($tasks);
    }

    /** @return array{0:string,1:?int} */
    private function statusSchedule(string $deliveryStatus): array
    {
        return match ($deliveryStatus) {
            'delivered' => [LogisticsOrder::LOGISTICS_DELIVERED, null],
            'pickup', 'transit' => [LogisticsOrder::LOGISTICS_IN_TRANSIT, 240],
            'undelivered', 'exception' => [LogisticsOrder::LOGISTICS_EXCEPTION, 720],
            'expired' => [LogisticsOrder::LOGISTICS_EXPIRED, 1440],
            'inforeceived' => [LogisticsOrder::LOGISTICS_INFORMATION_RECEIVED, 360],
            'notfound' => [LogisticsOrder::LOGISTICS_NO_INFORMATION, 360],
            default => [LogisticsOrder::LOGISTICS_WAITING_QUERY, 360],
        };
    }

    /** @param Collection<int, LogisticsTrackingSyncTask> $tasks */
    private function retryTasks(Collection $tasks, string $message, int $minutes): void
    {
        $tasks->each(function (LogisticsTrackingSyncTask $task) use ($message, $minutes): void {
            $task->forceFill([
                'status' => LogisticsTrackingSyncTask::STATUS_WAITING,
                'attempt_count' => $task->attempt_count + 1,
                'due_at' => now()->addMinutes($minutes),
                'last_error' => $message,
            ])->save();
            $order = $task->order;
            if ($order instanceof LogisticsOrder) {
                $order->forceFill([
                    'tracking_last_error' => $message,
                    'tracking_next_sync_at' => now()->addMinutes($minutes),
                ])->save();
            }
        });
        $this->refreshBatches($tasks);
    }

    /** @param Collection<int, LogisticsTrackingSyncTask> $tasks @param array{processed:int,success:int,failed:int} $summary */
    private function handleHttpFailure(Collection $tasks, int $status, string $body, array &$summary): void
    {
        $message = '51Tracking HTTP '.$status.'：'.($body ?: '接口未返回详细错误。');
        if (in_array($status, [400, 401, 403, 422], true)) {
            $this->failTasks($tasks, $message, $summary);

            return;
        }

        $this->retryTasks($tasks, $message, 360);
    }

    /** @param Collection<int, LogisticsTrackingSyncTask> $tasks @param array{processed:int,success:int,failed:int} $summary */
    private function failTasks(Collection $tasks, string $message, array &$summary): void
    {
        $tasks->each(function (LogisticsTrackingSyncTask $task) use ($message, &$summary): void {
            $task->forceFill([
                'status' => LogisticsTrackingSyncTask::STATUS_FAILED,
                'attempt_count' => $task->attempt_count + 1,
                'last_error' => $message,
                'completed_at' => now(),
            ])->save();
            $order = $task->order;
            if ($order instanceof LogisticsOrder) {
                // 鉴权或请求格式错误不会自行恢复。暂停自动调度，避免每分钟
                // 重复请求；修正 ENV 或数据后可由用户再次批量查询。
                $order->forceFill([
                    'tracking_last_error' => $message,
                    'tracking_next_sync_at' => null,
                ])->save();
            }
            $summary['processed']++;
            $summary['failed']++;
        });
        $this->refreshBatches($tasks);
    }

    /** @param Collection<int, LogisticsTrackingSyncTask> $tasks */
    private function refreshBatches(Collection $tasks): void
    {
        $tasks->pluck('logistics_tracking_sync_batch_id')
            ->filter()
            ->unique()
            ->each(static function (int $batchId): void {
                $batch = LogisticsTrackingSyncBatch::query()->find($batchId);
                $batch?->refreshProgress();
            });
    }

    private function normaliseTrackingNumber(string $value): string
    {
        $first = preg_split('/[\s,;]+/', trim($value))[0] ?? '';

        return strtoupper(trim((string) $first));
    }

    private function apiUrl(string $path): string
    {
        return rtrim((string) config('logistics.tracking.base_url'), '/').$path;
    }

    private function parseCheckpointTime(mixed $value): ?Carbon
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
