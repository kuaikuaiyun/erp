<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LogisticsTongtuMatchBatch extends Model
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'actor_id', 'actor_name', 'status', 'total_count', 'processed_count', 'matched_count',
        'sku_exception_count', 'not_found_count', 'failed_count', 'last_error', 'started_at', 'completed_at',
    ];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));

        parent::__construct($attributes);
    }

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
        return $this->hasMany(LogisticsTongtuMatchTask::class, 'logistics_tongtu_match_batch_id');
    }

    public function refreshProgress(): void
    {
        $counts = array_fill_keys([
            LogisticsTongtuMatchTask::STATUS_QUEUED,
            LogisticsTongtuMatchTask::STATUS_PROCESSING,
            LogisticsTongtuMatchTask::STATUS_RETRYING,
            LogisticsTongtuMatchTask::STATUS_MATCHED,
            LogisticsTongtuMatchTask::STATUS_SKU_EXCEPTION,
            LogisticsTongtuMatchTask::STATUS_NOT_FOUND,
            LogisticsTongtuMatchTask::STATUS_FAILED,
        ], 0);

        $this->tasks()
            ->selectRaw('status, COUNT(*) as aggregate_count')
            ->groupBy('status')
            ->get()
            ->each(function (LogisticsTongtuMatchTask $task) use (&$counts): void {
                $counts[$task->status] = (int) $task->aggregate_count;
            });

        $processed = $counts[LogisticsTongtuMatchTask::STATUS_MATCHED]
            + $counts[LogisticsTongtuMatchTask::STATUS_SKU_EXCEPTION]
            + $counts[LogisticsTongtuMatchTask::STATUS_NOT_FOUND]
            + $counts[LogisticsTongtuMatchTask::STATUS_FAILED];
        $isCompleted = $this->total_count > 0 && $processed >= $this->total_count;
        $hasStarted = $this->started_at !== null
            || $processed > 0
            || $counts[LogisticsTongtuMatchTask::STATUS_PROCESSING] > 0
            || $counts[LogisticsTongtuMatchTask::STATUS_RETRYING] > 0;

        $this->forceFill([
            'processed_count' => $processed,
            'matched_count' => $counts[LogisticsTongtuMatchTask::STATUS_MATCHED],
            'sku_exception_count' => $counts[LogisticsTongtuMatchTask::STATUS_SKU_EXCEPTION],
            'not_found_count' => $counts[LogisticsTongtuMatchTask::STATUS_NOT_FOUND],
            'failed_count' => $counts[LogisticsTongtuMatchTask::STATUS_FAILED],
            'status' => $isCompleted ? self::STATUS_COMPLETED : ($hasStarted ? self::STATUS_PROCESSING : self::STATUS_QUEUED),
            'started_at' => $hasStarted ? ($this->started_at ?: now()) : null,
            'completed_at' => $isCompleted ? ($this->completed_at ?: now()) : null,
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
            'matched' => (int) $this->matched_count,
            'sku_exception' => (int) $this->sku_exception_count,
            'not_found' => (int) $this->not_found_count,
            'failed' => (int) $this->failed_count,
            'percent' => $percent,
            'completed' => $this->status === self::STATUS_COMPLETED,
            'last_error' => $this->last_error,
        ];
    }
}
