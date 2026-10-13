<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LogisticsPayPalRefund extends Model
{
    protected $table = 'logistics_paypal_refunds';

    protected $fillable = [
        'logistics_paypal_account_id', 'logistics_order_id', 'refund_transaction_id',
        'original_transaction_id', 'event_code', 'status', 'refund_amount_usd',
        'original_amount', 'currency_code', 'invoice_id', 'refund_reason',
        'refunded_at', 'raw_payload', 'synced_at',
    ];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));
        parent::__construct($attributes);
    }

    protected function casts(): array
    {
        return [
            'refund_amount_usd' => 'decimal:4',
            'original_amount' => 'decimal:4',
            'refunded_at' => 'datetime',
            'synced_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(LogisticsPayPalAccount::class, 'logistics_paypal_account_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(LogisticsOrder::class, 'logistics_order_id');
    }
}
