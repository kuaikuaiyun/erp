<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinanceCreditAccountGroup extends Model
{
    protected $fillable = ['name'];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));
        parent::__construct($attributes);
    }
}
