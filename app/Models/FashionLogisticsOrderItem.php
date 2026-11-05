<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FashionLogisticsOrderItem extends Model
{
    protected $fillable = [
        'fashion_logistics_order_id', 'external_item_id', 'product_id', 'variation_id', 'product_name',
        'sku', 'quantity', 'subtotal', 'total', 'total_tax', 'image_url', 'meta_data',
    ];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));
        parent::__construct($attributes);
    }

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'subtotal' => 'decimal:4', 'total' => 'decimal:4', 'total_tax' => 'decimal:4', 'meta_data' => 'array'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(FashionLogisticsOrder::class, 'fashion_logistics_order_id');
    }
}
