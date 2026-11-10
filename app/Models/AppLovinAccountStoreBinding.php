<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppLovinAccountStoreBinding extends Model
{
    protected $table = 'applovin_account_store_bindings';

    protected $fillable = ['account_key', 'assistant_domain_store_id', 'bound_by'];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));
        parent::__construct($attributes);
    }
}
