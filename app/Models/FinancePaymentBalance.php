<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinancePaymentBalance extends Model
{
    protected $guarded = ['id'];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));
        parent::__construct($attributes);
    }

    protected function casts(): array
    {
        return [
            'withdrawable' => 'decimal:4', 'pending' => 'decimal:4', 'reserve' => 'decimal:4',
            'usd_rate' => 'decimal:8', 'as_of' => 'immutable_datetime',
        ];
    }
}
