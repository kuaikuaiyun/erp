<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentPayPalAccountRiskSnapshot extends Model
{
    protected $table = 'payment_paypal_account_risk_snapshots';

    protected $fillable = [
        'logistics_paypal_account_id', 'snapshot_date', 'window_start', 'window_end',
        'order_count', 'dispute_order_count', 'dispute_rate',
    ];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));
        parent::__construct($attributes);
    }

    protected function casts(): array
    {
        return [
            'snapshot_date' => 'date',
            'window_start' => 'datetime',
            'window_end' => 'datetime',
            'order_count' => 'integer',
            'dispute_order_count' => 'integer',
            'dispute_rate' => 'float',
        ];
    }
}
