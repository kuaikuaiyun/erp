<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssetIpfoxyProxy extends Model
{
    protected $table = 'asset_ipfoxy_proxies';

    protected $guarded = ['id'];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));
        parent::__construct($attributes);
    }

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'missing' => 'boolean'];
    }
}
