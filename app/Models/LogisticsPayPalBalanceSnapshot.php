<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LogisticsPayPalBalanceSnapshot extends Model
{
    protected $table = 'logistics_paypal_balance_snapshots';

    protected $fillable = [
        'logistics_paypal_account_id', 'logistics_paypal_import_id', 'upload_id',
        'merchant_id', 'currency_code', 'total_balance', 'available_balance',
        'withheld_balance', 'as_of_time', 'last_refresh_time', 'raw_payload',
    ];

    protected $casts = [
        'total_balance' => 'decimal:8', 'available_balance' => 'decimal:8',
        'withheld_balance' => 'decimal:8', 'as_of_time' => 'datetime',
        'last_refresh_time' => 'datetime', 'raw_payload' => 'array',
    ];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));
        parent::__construct($attributes);
    }
}
