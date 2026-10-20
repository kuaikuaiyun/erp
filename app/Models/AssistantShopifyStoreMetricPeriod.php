<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssistantShopifyStoreMetricPeriod extends Model
{
    public const STATUS_SUCCESS = AssistantShopifyStoreMetric::STATUS_SUCCESS;

    protected $fillable = [
        'assistant_domain_store_id',
        'shop_timezone',
        'report_timezone',
        'from_date',
        'to_date',
        'currency_code',
        'total_sales',
        'original_currency_code',
        'original_total_sales',
        'original_refund_amount',
        'original_net_sales',
        'orders_count',
        'regular_orders_count',
        'regular_total_sales',
        'regular_original_total_sales',
        'subscribed_orders_count',
        'subscribed_total_sales',
        'subscribed_original_total_sales',
        'recurring_orders_count',
        'recurring_total_sales',
        'recurring_original_total_sales',
        'returning_customer_rate',
        'fulfilled_orders_count',
        'refund_amount',
        'net_sales',
        'average_order_value',
        'sync_status',
        'sync_message',
        'synced_at',
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
            'total_sales' => 'decimal:2',
            'original_total_sales' => 'decimal:2',
            'original_refund_amount' => 'decimal:2',
            'original_net_sales' => 'decimal:2',
            'orders_count' => 'integer',
            'regular_orders_count' => 'integer',
            'regular_total_sales' => 'decimal:2',
            'regular_original_total_sales' => 'decimal:2',
            'subscribed_orders_count' => 'integer',
            'subscribed_total_sales' => 'decimal:2',
            'subscribed_original_total_sales' => 'decimal:2',
            'recurring_orders_count' => 'integer',
            'recurring_total_sales' => 'decimal:2',
            'recurring_original_total_sales' => 'decimal:2',
            'returning_customer_rate' => 'decimal:4',
            'fulfilled_orders_count' => 'integer',
            'refund_amount' => 'decimal:2',
            'net_sales' => 'decimal:2',
            'average_order_value' => 'decimal:2',
            'synced_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(AssistantDomainStore::class, 'assistant_domain_store_id');
    }
}
