<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AliExpressSelectionProduct extends Model
{
    protected $table = 'aliexpress_selection_products';

    protected $fillable = ['supplier_product_id', 'supplier_url', 'name', 'sku_prefix', 'remark', 'created_by', 'updated_by'];

    public function getConnectionName()
    {
        return parent::getConnectionName() ?? (config('admin.database.connection') ?: config('database.default'));
    }

    public function skus(): HasMany
    {
        return $this->hasMany(AliExpressSelectionSku::class, 'product_id')->orderBy('id');
    }
}
