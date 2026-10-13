<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LogisticsShopifySyncBatch extends Model
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_CANCELLING = 'cancelling';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'actor_id', 'actor_name', 'status', 'sync_days', 'total_count', 'processed_count',
        'success_count', 'failed_count', 'cancelled_count', 'created_order_count',
        'updated_order_count', 'sku_exception_count', 'skipped_unpaid_count',
        'sku_item_count', 'sku_missing_item_count',
        'webhook_warning_count', 'current_store_id', 'current_store_name', 'last_error',
        'cancel_requested_at', 'started_at', 'completed_at',
    ];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));

        parent::__construct($attributes);
    }

    protected function casts(): array
    {
        return [
            'cancel_requested_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(LogisticsShopifySyncTask::class, 'logistics_shopify_sync_batch_id');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [self::STATUS_CANCELLED, self::STATUS_COMPLETED], true);
    }

    public function refreshProgress(): void
    {
        $counts = array_fill_keys([
            LogisticsShopifySyncTask::STATUS_QUEUED,
            LogisticsShopifySyncTask::STATUS_PROCESSING,
            LogisticsShopifySyncTask::STATUS_SUCCESS,
            LogisticsShopifySyncTask::STATUS_FAILED,
            LogisticsShopifySyncTask::STATUS_CANCELLED,
        ], 0);
        $summary = [
            'created_order_count' => 0,
            'updated_order_count' => 0,
            'sku_exception_count' => 0,
            'sku_item_count' => 0,
            'sku_missing_item_count' => 0,
            'skipped_unpaid_count' => 0,
            'webhook_warning_count' => 0,
        ];

        $this->tasks()
            ->get([
                'status', 'created_order_count', 'updated_order_count', 'sku_exception_count',
                'sku_item_count', 'sku_missing_item_count', 'skipped_unpaid_count', 'webhook_active',
            ])
            ->each(function (LogisticsShopifySyncTask $task) use (&$counts, &$summary): void {
                $counts[$task->status] = ($counts[$task->status] ?? 0) + 1;
                foreach (['created_order_count', 'updated_order_count', 'sku_exception_count', 'sku_item_count', 'sku_missing_item_count', 'skipped_unpaid_count'] as $field) {
                    $summary[$field] += (int) $task->{$field};
                }
                if ($task->webhook_active === false) {
                    $summary['webhook_warning_count']++;
                }
            });

        $processed = $counts[LogisticsShopifySyncTask::STATUS_SUCCESS]
            + $counts[LogisticsShopifySyncTask::STATUS_FAILED]
            + $counts[LogisticsShopifySyncTask::STATUS_CANCELLED];
        $processingTask = $this->tasks()
            ->where('status', LogisticsShopifySyncTask::STATUS_PROCESSING)
            ->oldest('started_at')
            ->first(['assistant_domain_store_id', 'store_name']);
        $isFinished = (int) $this->total_count > 0 && $processed >= (int) $this->total_count;
        $hasStarted = $this->started_at !== null
            || $processed > 0
            || $counts[LogisticsShopifySyncTask::STATUS_PROCESSING] > 0;
        $status = $this->cancel_requested_at
            ? ($isFinished ? self::STATUS_CANCELLED : self::STATUS_CANCELLING)
            : ($isFinished ? self::STATUS_COMPLETED : ($hasStarted ? self::STATUS_PROCESSING : self::STATUS_QUEUED));

        $this->forceFill([
            'status' => $status,
            'processed_count' => $processed,
            'success_count' => $counts[LogisticsShopifySyncTask::STATUS_SUCCESS],
            'failed_count' => $counts[LogisticsShopifySyncTask::STATUS_FAILED],
            'cancelled_count' => $counts[LogisticsShopifySyncTask::STATUS_CANCELLED],
            ...$summary,
            'current_store_id' => $processingTask?->assistant_domain_store_id,
            'current_store_name' => $processingTask?->store_name,
            'started_at' => $hasStarted ? ($this->started_at ?: now()) : null,
            'completed_at' => $isFinished ? ($this->completed_at ?: now()) : null,
        ])->save();
    }

    /** @return array<string, int|string|bool|null> */
    public function progressPayload(): array
    {
        $pending = max(0, (int) $this->total_count - (int) $this->processed_count);
        $percent = $this->total_count > 0
            ? min(100, (int) round($this->processed_count / $this->total_count * 100))
            : 0;

        return [
            'id' => $this->getKey(),
            'status' => $this->status,
            'sync_days' => (int) $this->sync_days,
            'total' => (int) $this->total_count,
            'processed' => (int) $this->processed_count,
            'pending' => $pending,
            'success' => (int) $this->success_count,
            'failed' => (int) $this->failed_count,
            'cancelled' => (int) $this->cancelled_count,
            'created_orders' => (int) $this->created_order_count,
            'updated_orders' => (int) $this->updated_order_count,
            'sku_exceptions' => (int) $this->sku_exception_count,
            'sku_items' => (int) $this->sku_item_count,
            'sku_missing_items' => (int) $this->sku_missing_item_count,
            'skipped_unpaid' => (int) $this->skipped_unpaid_count,
            'webhook_warnings' => (int) $this->webhook_warning_count,
            'current_store' => $this->current_store_name,
            'percent' => $percent,
            'completed' => $this->isTerminal(),
            'can_cancel' => !$this->isTerminal() && $this->cancel_requested_at === null,
            'last_error' => $this->last_error,
        ];
    }
}
