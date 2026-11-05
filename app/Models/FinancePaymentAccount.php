<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class FinancePaymentAccount extends Model
{
    use SoftDeletes;

    protected $guarded = ['id', 'active_account_name'];

    protected $hidden = ['relay_hmac_secret', 'active_account_name'];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));
        parent::__construct($attributes);
    }

    protected function casts(): array
    {
        return ['relay_hmac_secret' => 'encrypted'];
    }
}
