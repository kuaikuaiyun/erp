<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AikaipinProductSku extends Model
{
    protected $fillable = [
        'product_id',
        'sku',
        'sort',
    ];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));

        parent::__construct($attributes);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(AikaipinProduct::class, 'product_id');
    }
}
