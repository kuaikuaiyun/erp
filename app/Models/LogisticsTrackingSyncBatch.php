<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LogisticsTrackingSyncBatch extends Model
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'actor_id', 'actor_name', 'source', 'status', 'total_count', 'processed_count',
        'success_count', 'skipped_count', 'failed_count', 'current_tracking_number',
        'last_error', 'started_at', 'completed_at',
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
        return $this->hasMany(LogisticsTrackingSyncTask::class, 'logistics_tracking_sync_batch_id');
    }

    public function refreshProgress(): void
    {
        $counts = $this->tasks()
            ->selectRaw("COUNT(*) as total_count")
            ->selectRaw("SUM(status IN ('success', 'skipped', 'failed')) as processed_count")
            ->selectRaw("SUM(status = 'success') as success_count")
            ->selectRaw("SUM(status = 'skipped') as skipped_count")
            ->selectRaw("SUM(status = 'failed') as failed_count")
            ->first();

        $total = (int) ($counts?->total_count ?: 0);
        $processed = (int) ($counts?->processed_count ?: 0);
        // A manual batch can legitimately have no eligible tracking numbers.
        // Finish it immediately rather than leaving the UI in a queued state.
        $completed = $total === 0 || $processed >= $total;
        $current = $this->tasks()
            ->whereIn('status', LogisticsTrackingSyncTask::activeStatuses())
            ->orderBy('id')
            ->value('tracking_number');

        $this->forceFill([
            'total_count' => $total,
            'processed_count' => $processed,
            'success_count' => (int) ($counts?->success_count ?: 0),
            'skipped_count' => (int) ($counts?->skipped_count ?: 0),
            'failed_count' => (int) ($counts?->failed_count ?: 0),
            'current_tracking_number' => $current,
            'status' => $completed ? self::STATUS_COMPLETED : ($processed > 0 ? self::STATUS_PROCESSING : self::STATUS_QUEUED),
            'started_at' => $this->started_at ?: now(),
            'completed_at' => $completed ? ($this->completed_at ?: now()) : null,
        ])->save();
    }

    /** @return array{status:string,total:int,processed:int,success:int,skipped:int,failed:int,percent:int,completed:bool,current:string} */
    public function progressPayload(): array
    {
        $total = max(0, (int) $this->total_count);
        $processed = min($total, max(0, (int) $this->processed_count));

        return [
            'status' => (string) $this->status,
            'total' => $total,
            'processed' => $processed,
            'success' => (int) $this->success_count,
            'skipped' => (int) $this->skipped_count,
            'failed' => (int) $this->failed_count,
            'percent' => $total === 0 ? 100 : (int) floor($processed / $total * 100),
            'completed' => $this->status === self::STATUS_COMPLETED,
            'current' => (string) ($this->current_tracking_number ?: ''),
        ];
    }
}
