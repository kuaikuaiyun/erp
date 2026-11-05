<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FashionLogisticsOrder extends Model
{
    protected $fillable = [
        'assistant_domain_store_id', 'store_name', 'source_domain', 'external_order_id', 'order_number',
        'status', 'currency', 'total_amount', 'shipping_amount', 'discount_amount', 'tax_amount',
        'payment_method', 'payment_method_title', 'transaction_id', 'customer_id', 'customer_name',
        'customer_email', 'customer_phone', 'shipping_country', 'shipping_state', 'shipping_city',
        'shipping_address', 'customer_note', 'order_created_at', 'order_modified_at', 'paid_at',
        'completed_at', 'source_payload', 'source_synced_at',
    ];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));
        parent::__construct($attributes);
    }

    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:4', 'shipping_amount' => 'decimal:4', 'discount_amount' => 'decimal:4',
            'tax_amount' => 'decimal:4', 'source_payload' => 'array', 'order_created_at' => 'datetime',
            'order_modified_at' => 'datetime', 'paid_at' => 'datetime', 'completed_at' => 'datetime',
            'source_synced_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(AssistantDomainStore::class, 'assistant_domain_store_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(FashionLogisticsOrderItem::class);
    }
}
