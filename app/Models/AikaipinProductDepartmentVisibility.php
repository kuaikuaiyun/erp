<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AikaipinProductDepartmentVisibility extends Model
{
    protected $fillable = [
        'product_id',
        'department_id',
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

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }
}
