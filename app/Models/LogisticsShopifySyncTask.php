<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LogisticsShopifySyncTask extends Model
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'logistics_shopify_sync_batch_id', 'assistant_domain_store_id', 'store_name', 'status',
        'attempt_count', 'created_order_count', 'updated_order_count', 'sku_exception_count',
        'sku_item_count', 'sku_missing_item_count', 'sku_missing_details',
        'skipped_unpaid_count', 'webhook_active', 'webhook_error', 'last_error',
        'started_at', 'completed_at',
    ];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));

        parent::__construct($attributes);
    }

    protected function casts(): array
    {
        return [
            'webhook_active' => 'boolean',
            'sku_missing_details' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(LogisticsShopifySyncBatch::class, 'logistics_shopify_sync_batch_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(AssistantDomainStore::class, 'assistant_domain_store_id');
    }

    /** @return list<string> */
    public static function activeStatuses(): array
    {
        return [self::STATUS_QUEUED, self::STATUS_PROCESSING];
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_SUCCESS,
            self::STATUS_FAILED,
            self::STATUS_CANCELLED,
        ], true);
    }
}
