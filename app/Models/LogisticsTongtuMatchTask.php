<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LogisticsTongtuMatchTask extends Model
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_RETRYING = 'retrying';
    public const STATUS_MATCHED = 'matched';
    public const STATUS_SKU_EXCEPTION = 'sku_exception';
    public const STATUS_NOT_FOUND = 'not_found';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'logistics_tongtu_match_batch_id', 'logistics_order_id', 'status', 'attempt_count',
        'last_error', 'started_at', 'completed_at', 'priority', 'source', 'stage',
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

    public function batch(): BelongsTo
    {
        return $this->belongsTo(LogisticsTongtuMatchBatch::class, 'logistics_tongtu_match_batch_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(LogisticsOrder::class, 'logistics_order_id');
    }

    public static function activeStatuses(): array
    {
        return [self::STATUS_QUEUED, self::STATUS_PROCESSING, self::STATUS_RETRYING];
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_MATCHED,
            self::STATUS_SKU_EXCEPTION,
            self::STATUS_NOT_FOUND,
            self::STATUS_FAILED,
        ], true);
    }
}
