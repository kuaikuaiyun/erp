<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AikaipinProductPage extends Model
{
    protected $guarded = [];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));
        parent::__construct($attributes);
    }

    protected function casts(): array
    {
        return [
            'shopify_data' => 'array',
            'is_default' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(AikaipinProduct::class, 'aikaipin_product_id');
    }

    /** @return array<string, mixed> */
    public function shopifyData(): array
    {
        return is_array($this->shopify_data) ? $this->shopify_data : [];
    }
}
