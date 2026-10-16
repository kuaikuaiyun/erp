<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable-at-source PayPal payment ledger.
 *
 * A row is retained even while no ERP / Shopify order can safely be matched.
 * This is what makes later reverse reconciliation possible.
 */
class LogisticsPayPalTransaction extends Model
{
    protected $table = 'logistics_paypal_transactions';

    protected $fillable = [
        'logistics_paypal_account_id', 'logistics_order_id', 'payment_transaction_id',
        'invoice_id', 'event_code', 'status', 'payment_amount_usd', 'original_amount',
        'currency_code', 'payment_subject', 'paid_at', 'match_source', 'matched_at',
        'raw_payload', 'synced_at',
    ];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));
        parent::__construct($attributes);
    }

    protected function casts(): array
    {
        return [
            'payment_amount_usd' => 'decimal:4',
            'original_amount' => 'decimal:4',
            'paid_at' => 'datetime',
            'matched_at' => 'datetime',
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
