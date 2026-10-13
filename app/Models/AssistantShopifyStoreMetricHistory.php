<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssistantShopifyStoreMetricHistory extends Model
{
    protected $fillable = [
        'assistant_domain_store_id',
        'shop_timezone',
        'report_date',
        'currency_code',
        'total_sales',
        'orders_count',
        'returning_customer_rate',
        'fulfilled_orders_count',
        'refund_amount',
        'net_sales',
        'average_order_value',
        'sync_status',
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
            'report_date' => 'date',
            'total_sales' => 'decimal:2',
            'orders_count' => 'integer',
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
