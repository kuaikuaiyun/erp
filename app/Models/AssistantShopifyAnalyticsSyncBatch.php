<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssistantShopifyAnalyticsSyncBatch extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'actor_id',
        'actor_name',
        'status',
        'total_count',
        'processed_count',
        'success_count',
        'failed_count',
        'current_store_id',
        'current_store_name',
        'last_error',
        'started_at',
        'completed_at',
    ];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));

        parent::__construct($attributes);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(AssistantShopifyAnalyticsSyncTask::class, 'assistant_shopify_analytics_sync_batch_id');
    }

    public function isTerminal(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function refreshProgress(): void
    {
        $counts = array_fill_keys([
            AssistantShopifyAnalyticsSyncTask::STATUS_QUEUED,
            AssistantShopifyAnalyticsSyncTask::STATUS_PROCESSING,
            AssistantShopifyAnalyticsSyncTask::STATUS_SUCCESS,
            AssistantShopifyAnalyticsSyncTask::STATUS_FAILED,
        ], 0);

        $this->tasks()
            ->get(['status'])
            ->each(function (AssistantShopifyAnalyticsSyncTask $task) use (&$counts): void {
                $counts[$task->status] = ($counts[$task->status] ?? 0) + 1;
            });

        $processed = $counts[AssistantShopifyAnalyticsSyncTask::STATUS_SUCCESS]
            + $counts[AssistantShopifyAnalyticsSyncTask::STATUS_FAILED];
        $processingTask = $this->tasks()
            ->where('status', AssistantShopifyAnalyticsSyncTask::STATUS_PROCESSING)
            ->oldest('started_at')
            ->first(['assistant_domain_store_id', 'store_name']);
        $isFinished = (int) $this->total_count > 0 && $processed >= (int) $this->total_count;
        $hasStarted = $this->started_at !== null
            || $processed > 0
            || $counts[AssistantShopifyAnalyticsSyncTask::STATUS_PROCESSING] > 0;

        $this->forceFill([
            'status' => $isFinished
                ? self::STATUS_COMPLETED
                : ($hasStarted ? self::STATUS_PROCESSING : self::STATUS_QUEUED),
            'processed_count' => $processed,
            'success_count' => $counts[AssistantShopifyAnalyticsSyncTask::STATUS_SUCCESS],
            'failed_count' => $counts[AssistantShopifyAnalyticsSyncTask::STATUS_FAILED],
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
            'total' => (int) $this->total_count,
            'processed' => (int) $this->processed_count,
            'pending' => $pending,
            'success' => (int) $this->success_count,
            'failed' => (int) $this->failed_count,
            'current_store' => $this->current_store_name,
            'percent' => $percent,
            'completed' => $this->isTerminal(),
            'last_error' => $this->last_error,
        ];
    }
}
