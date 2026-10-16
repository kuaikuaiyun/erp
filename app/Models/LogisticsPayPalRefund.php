<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LogisticsPayPalRefund extends Model
{

    protected $table = 'logistics_paypal_refunds';

    protected $fillable = [
        'logistics_paypal_account_id', 'logistics_order_id', 'refund_transaction_id',
        'original_transaction_id', 'event_code', 'status', 'refund_amount_usd',
        'original_amount', 'currency_code', 'invoice_id', 'refund_reason',
        'manual_reason', 'manual_reason_updated_by', 'manual_reason_updated_by_name', 'manual_reason_updated_at',
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
            'manual_reason_updated_at' => 'datetime',
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

    public function disputeLinks(): HasMany
    {
        return $this->hasMany(LogisticsPayPalDisputeRefund::class, 'logistics_paypal_refund_id');
    }

    public static function eventLabelFor(mixed $eventCode): string
    {
        $eventCode = strtoupper(trim((string) ($eventCode ?? '')));
        if ($eventCode === '') {
            return __('logistics.common.not_available');
        }

        $translationKey = 'logistics.paypal_refund.events.'.$eventCode;
        $label = __($translationKey);

        return $label === $translationKey
            ? __('logistics.paypal_refund.unknown_event')
            : $label;
    }

    public static function statusLabelFor(mixed $status): string
    {
        $status = strtoupper(trim((string) ($status ?? '')));
        if ($status === '') {
            return __('logistics.common.not_available');
        }

        $translationKey = 'logistics.paypal_refund.statuses.'.$status;
        $label = __($translationKey);

        return $label === $translationKey
            ? __('logistics.paypal_refund.unknown_status')
            : $label;
    }

    public function eventLabel(): string
    {
        return self::eventLabelFor($this->event_code);
    }

    public function statusLabel(): string
    {
        return self::statusLabelFor($this->status);
    }
}
