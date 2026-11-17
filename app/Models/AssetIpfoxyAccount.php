<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssetIpfoxyAccount extends Model
{
    protected $table = 'asset_ipfoxy_accounts';

    protected $guarded = ['id'];

    protected $hidden = ['api_token'];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));
        parent::__construct($attributes);
    }

    protected function casts(): array
    {
        return ['api_token' => 'encrypted', 'last_synced_at' => 'datetime'];
    }
}
