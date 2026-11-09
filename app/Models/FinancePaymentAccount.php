<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
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

    #[Scope]
    protected function configuredPayPal(Builder $query): void
    {
        $query->where('account_type', 'paypal')->where('status', 'enabled');
        foreach (['relay_url', 'relay_certificate_sha1', 'relay_hmac_key_id', 'relay_hmac_secret'] as $field) {
            $query->whereNotNull($field)->where($field, '!=', '');
        }
    }

    protected function casts(): array
    {
        return ['relay_hmac_secret' => 'encrypted'];
    }
}
