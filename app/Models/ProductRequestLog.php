<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductRequestLog extends Model
{
    protected $guarded = [];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));

        parent::__construct($attributes);
    }

    public function productRequest(): BelongsTo
    {
        return $this->belongsTo(ProductRequest::class);
    }
}
