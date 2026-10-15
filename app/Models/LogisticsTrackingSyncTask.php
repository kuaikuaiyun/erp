<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LogisticsTrackingSyncTask extends Model
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_WAITING = 'waiting';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_FAILED = 'failed';

    public const STAGE_REGISTER = 'register';
    public const STAGE_QUERY = 'query';

    protected $fillable = [
        'logistics_tracking_sync_batch_id', 'logistics_order_id', 'tracking_number', 'courier_code',
        'source', 'stage', 'status', 'attempt_count', 'due_at', 'last_error', 'started_at', 'completed_at',
    ];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));

        parent::__construct($attributes);
    }

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(LogisticsTrackingSyncBatch::class, 'logistics_tracking_sync_batch_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(LogisticsOrder::class, 'logistics_order_id');
    }

    /** @return list<string> */
    public static function activeStatuses(): array
    {
        return [self::STATUS_QUEUED, self::STATUS_PROCESSING, self::STATUS_WAITING];
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [self::STATUS_SUCCESS, self::STATUS_SKIPPED, self::STATUS_FAILED], true);
    }
}
