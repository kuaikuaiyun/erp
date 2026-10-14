<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductRequestAttachment extends Model
{
    public const STAGE_OPERATION = 'operation';
    public const STAGE_PROCUREMENT = 'procurement';
    public const STAGE_WAREHOUSE = 'warehouse';
    public const STAGE_REVIEW = 'review';

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
