<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AliExpressProcurementItem extends Model
{
    protected $table = 'aliexpress_procurement_items';

    protected $casts = ['order_created_at' => 'datetime', 'quantity' => 'integer'];

    public function getConnectionName()
    {
        return parent::getConnectionName() ?? (config('admin.database.connection') ?: config('database.default'));
    }
}
