<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LogisticsShopifyConnection extends Model
{
    public const STATUS_TOKEN_READY = 'token_ready';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'assistant_domain_store_id',
        'shop_domain',
        'access_token',
        'granted_scopes',
        'status',
        'state_token',
        'state_expires_at',
        'authorized_at',
        'token_obtained_at',
        'token_expires_at',
        'last_synced_at',
        'last_sync_status',
        'last_sync_message',
        'webhook_secret',
        'webhook_status',
        'webhook_registered_at',
        'webhook_last_error',
        'webhook_subscription_ids',
    ];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));

        parent::__construct($attributes);
    }

    protected function casts(): array
    {
        return [
            'state_expires_at' => 'datetime',
            'authorized_at' => 'datetime',
            'token_obtained_at' => 'datetime',
            'token_expires_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'webhook_registered_at' => 'datetime',
            'webhook_subscription_ids' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(AssistantDomainStore::class, 'assistant_domain_store_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(LogisticsSyncLog::class, 'shopify_connection_id');
    }

    public function hasReusableAccessToken(): bool
    {
        return $this->status === self::STATUS_TOKEN_READY
            && filled($this->access_token)
            && $this->token_expires_at?->isFuture();
    }

    public function webhookStatusClass(): string
    {
        return match ($this->webhook_status) {
            'active' => 'label-success',
            'failed' => 'label-danger',
            default => 'label-default',
        };
    }
}
