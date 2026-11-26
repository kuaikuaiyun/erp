<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AliExpressSelectionSku extends Model
{
    public const PREFIX = 'SMT-';

    protected $table = 'aliexpress_selection_skus';

    protected $fillable = ['sku', 'sku_key', 'pinyin', 'attribute', 'package_label', 'supplier_sku', 'is_enabled'];

    protected $casts = ['is_enabled' => 'boolean'];

    public function getConnectionName()
    {
        return parent::getConnectionName() ?? (config('admin.database.connection') ?: config('database.default'));
    }

    public static function keyFor(string $sku): string
    {
        return hash('sha256', $sku);
    }
}
