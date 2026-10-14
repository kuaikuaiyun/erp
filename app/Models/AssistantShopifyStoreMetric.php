<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssistantShopifyStoreMetric extends Model
{
    public const STATUS_SUCCESS = 'success';

    public const STATUS_NOT_SYNCED = 'not_synced';

    public const STATUS_NOT_CONFIGURED = 'not_configured';

    public const STATUS_PERMISSION_DENIED = 'permission_denied';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'assistant_domain_store_id',
        'shop_timezone',
        'report_date',
        'currency_code',
        'original_currency_code',
        'total_sales',
        'original_total_sales',
        'orders_count',
        'returning_customer_rate',
        'fulfilled_orders_count',
        'refund_amount',
        'net_sales',
        'average_order_value',
        'sync_status',
        'sync_message',
        'synced_at',
        'last_successful_synced_at',
        'orders_initial_backfilled_at',
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
            'report_date' => 'date',
            'total_sales' => 'decimal:2',
            'original_total_sales' => 'decimal:2',
            'orders_count' => 'integer',
            'returning_customer_rate' => 'decimal:4',
            'fulfilled_orders_count' => 'integer',
            'refund_amount' => 'decimal:2',
            'net_sales' => 'decimal:2',
            'average_order_value' => 'decimal:2',
            'synced_at' => 'datetime',
            'last_successful_synced_at' => 'datetime',
            'orders_initial_backfilled_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(AssistantDomainStore::class, 'assistant_domain_store_id');
    }

    /** @return array<string, string> */
    public static function statusLabels(): array
    {
        return [
            self::STATUS_SUCCESS => __('assistant.shopify_analytics.statuses.success'),
            self::STATUS_NOT_SYNCED => __('assistant.shopify_analytics.statuses.not_synced'),
            self::STATUS_NOT_CONFIGURED => __('assistant.shopify_analytics.statuses.not_configured'),
            self::STATUS_PERMISSION_DENIED => __('assistant.shopify_analytics.statuses.permission_denied'),
            self::STATUS_FAILED => __('assistant.shopify_analytics.statuses.failed'),
        ];
    }

    public static function statusClass(?string $status): string
    {
        return match ($status) {
            self::STATUS_SUCCESS => 'label-success',
            self::STATUS_PERMISSION_DENIED, self::STATUS_FAILED => 'label-danger',
            self::STATUS_NOT_CONFIGURED => 'label-warning',
            default => 'label-default',
        };
    }
}
