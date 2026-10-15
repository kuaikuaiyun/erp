<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductRequestLinkSku extends Model
{
    protected $guarded = [];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));

        parent::__construct($attributes);
    }
}
