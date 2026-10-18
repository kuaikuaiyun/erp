<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LogisticsOrderItem extends Model
{
    protected $fillable = [
        'logistics_order_id', 'source_key', 'shopify_line_item_id', 'shopify_product_id', 'shopify_variant_id',
        'product_title', 'variant_title', 'product_image_url', 'shopify_sku', 'tongtu_sku', 'tongtu_product_code', 'tongtu_product_name', 'tongtu_product_type',
        'quantity', 'unit_price_usd', 'line_revenue_usd', 'product_cost_usd', 'handling_fee_usd',
        'total_shipping_usd', 'proportional_refund_usd', 'profit_usd', 'profit_rate', 'sale_weight_gram',
    ];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));

        parent::__construct($attributes);
    }

    protected function casts(): array
    {
        return array_merge(
            array_fill_keys([
                'quantity', 'unit_price_usd', 'line_revenue_usd', 'product_cost_usd', 'handling_fee_usd',
                'total_shipping_usd', 'proportional_refund_usd', 'profit_usd', 'profit_rate', 'sale_weight_gram',
            ], 'decimal:4'),
            ['created_at' => 'datetime', 'updated_at' => 'datetime'],
        );
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(LogisticsOrder::class, 'logistics_order_id');
    }
}
