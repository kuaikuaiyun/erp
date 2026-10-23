<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WarehouseSkuWeightException extends Model
{
    protected $table = 'warehouse_sku_weight_exceptions';

    protected $guarded = ['id'];

    protected $casts = [
        'original_weight_gram' => 'float',
        'corrected_weight_gram' => 'float',
        'resolved_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeResolved($query)
    {
        return $query->where('status', 'resolved');
    }
}
