<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssistantShopifyAnalyticsBackfill extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'assistant_domain_store_id',
        'shop_timezone',
        'from_date',
        'to_date',
        'next_date',
        'last_finalized_date',
        'status',
        'attempts',
        'last_error',
        'next_attempt_at',
        'started_at',
        'last_processed_at',
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
            'from_date' => 'date',
            'to_date' => 'date',
            'next_date' => 'date',
            'last_finalized_date' => 'date',
            'next_attempt_at' => 'datetime',
            'started_at' => 'datetime',
            'last_processed_at' => 'datetime',
            'completed_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(AssistantDomainStore::class, 'assistant_domain_store_id');
    }
}
