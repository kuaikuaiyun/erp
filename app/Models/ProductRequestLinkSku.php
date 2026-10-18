<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductRequestLinkSku extends Model
{
    protected $guarded = [];

    protected $casts = [
        'sku_values' => 'array',
        'images' => 'array',
    ];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));

        parent::__construct($attributes);
    }
}
