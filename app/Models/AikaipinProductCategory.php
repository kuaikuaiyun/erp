<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class AikaipinProductCategory extends Model
{
    protected $fillable = [
        'name',
        'sort',
        'created_by',
    ];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));

        parent::__construct($attributes);
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(
            AikaipinProduct::class,
            'aikaipin_product_category_assignments',
            'category_id',
            'product_id',
        )->withTimestamps();
    }
}
