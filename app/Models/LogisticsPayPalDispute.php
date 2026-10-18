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

    public static function typeLabelFor(mixed $channel): string
    {
        return self::enumLabel('channels', $channel, 'unknown_type');
    }

    public static function reasonLabelFor(mixed $reason): string
    {
        return self::enumLabel('reasons', $reason, 'unknown_reason');
    }

    public static function statusLabelFor(mixed $status): string
    {
        return self::enumLabel('statuses', $status, 'unknown_status');
    }

    public static function outcomeLabelFor(mixed $outcome): string
    {
        return self::enumLabel('outcomes', $outcome, 'unknown_outcome');
    }

    public function typeLabel(): string
    {
        return self::typeLabelFor($this->channel);
    }

    public function reasonLabel(): string
    {
        return self::reasonLabelFor($this->reason);
    }

    public function statusLabel(): string
    {
        return self::statusLabelFor($this->status);
    }

    public function outcomeLabel(): string
    {
        return self::outcomeLabelFor($this->outcome_code);
    }

    private static function enumLabel(string $group, mixed $value, string $unknownKey): string
    {
        $value = strtoupper(trim((string) ($value ?? '')));
        if ($value === '') {
            return __('logistics.common.not_available');
        }

        $translationKey = 'logistics.paypal_dispute.'.$group.'.'.$value;
        $label = __($translationKey);

        return $label === $translationKey
            ? __('logistics.paypal_dispute.'.$unknownKey)
            : $label;
    }
}
