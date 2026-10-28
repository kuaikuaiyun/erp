<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentPayPalLedgerEntry extends Model
{
    protected $table = 'payment_paypal_ledger_entries';

    protected $fillable = [
        'logistics_paypal_account_id', 'logistics_paypal_import_id', 'upload_id',
        'merchant_id', 'transaction_id', 'reference_id', 'reference_id_type',
        'origin_payment_transaction_id', 'event_code', 'status', 'currency_code',
        'transaction_amount', 'fee_amount', 'net_amount', 'invoice_id', 'subject',
        'event_at', 'source_updated_at', 'raw_payload',
    ];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));
        parent::__construct($attributes);
    }

    protected function casts(): array
    {
        return [
            'transaction_amount' => 'decimal:4',
            'fee_amount' => 'decimal:4',
            'net_amount' => 'decimal:4',
            'event_at' => 'datetime',
            'source_updated_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(LogisticsPayPalAccount::class, 'logistics_paypal_account_id');
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(LogisticsPayPalImport::class, 'logistics_paypal_import_id');
    }
}
