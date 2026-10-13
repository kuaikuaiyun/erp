<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssistantShopifyAnalyticsSyncTask extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'assistant_shopify_analytics_sync_batch_id',
        'assistant_domain_store_id',
        'store_name',
        'status',
        'attempt_count',
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

    public function batch(): BelongsTo
    {
        return $this->belongsTo(AssistantShopifyAnalyticsSyncBatch::class, 'assistant_shopify_analytics_sync_batch_id');
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
        return in_array($this->status, [self::STATUS_SUCCESS, self::STATUS_FAILED], true);
    }
}
