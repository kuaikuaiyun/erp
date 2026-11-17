<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssetResource extends Model
{
    protected $table = 'asset_resources';

    protected $guarded = ['id'];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));
        parent::__construct($attributes);
    }

    protected function casts(): array
    {
        return ['price' => 'decimal:2'];
    }
}
