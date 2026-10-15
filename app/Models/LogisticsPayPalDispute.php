<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LogisticsPayPalDispute extends Model
{
    protected $table = 'logistics_paypal_disputes';

    protected $fillable = [
        'logistics_paypal_account_id', 'logistics_order_id', 'dispute_id',
        'disputed_transaction_id', 'reason', 'status', 'dispute_state',
        'life_cycle_stage', 'channel', 'dispute_amount_usd', 'original_amount',
        'currency_code', 'dispute_created_at', 'dispute_updated_at',
        'seller_response_due_at', 'outcome_code', 'raw_payload', 'synced_at',
    ];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));
        parent::__construct($attributes);
    }

    protected function casts(): array
    {
        return [
            'dispute_amount_usd' => 'decimal:4',
            'original_amount' => 'decimal:4',
            'dispute_created_at' => 'datetime',
            'dispute_updated_at' => 'datetime',
            'seller_response_due_at' => 'datetime',
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

    public function refundLinks(): HasMany
    {
        return $this->hasMany(LogisticsPayPalDisputeRefund::class, 'logistics_paypal_dispute_id');
    }
}
