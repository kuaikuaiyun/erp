<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class FinancePaymentAccount extends Model
{
    use SoftDeletes;

    protected $guarded = ['id', 'active_account_name'];

    protected $hidden = ['relay_hmac_secret', 'active_account_name'];

    protected static function booted(): void
    {
        static::saved(function (self $account): void {
            if (! $account->wasChanged(['activity_account', 'account_name', 'account_type', 'card_platform', 'deleted_at'])) return;
            $transactions = app(\App\Portals\Finance\FinanceTransactions::class);
            foreach (array_unique([$account->getRawOriginal('activity_account'), $account->activity_account]) as $name) {
                if (filled($name)) $transactions->reclassifyAccount($name);
            }
        });
        static::deleted(fn (self $account) => app(\App\Portals\Finance\FinanceTransactions::class)->reclassifyAccount($account->activity_account));
    }

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));
        parent::__construct($attributes);
    }

    public function save(array $options = [])
    {
        if (! app(\App\Portals\Finance\FinanceTransactions::class)->installed()) return parent::save($options);
        return $this->getConnection()->transaction(fn () => parent::save($options), 3);
    }

    public function delete()
    {
        if (! app(\App\Portals\Finance\FinanceTransactions::class)->installed()) return parent::delete();
        return $this->getConnection()->transaction(fn () => parent::delete(), 3);
    }

    public function creditGroup(): BelongsTo
    {
        return $this->belongsTo(FinanceCreditAccountGroup::class, 'card_group_id');
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
