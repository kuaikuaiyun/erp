<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LogisticsPayPalDisputeRefund extends Model
{
    protected $table = 'logistics_paypal_dispute_refunds';

    protected $fillable = [
        'logistics_paypal_dispute_id', 'logistics_paypal_refund_id', 'match_source',
        'matched_at', 'raw_reference',
    ];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));

        parent::__construct($attributes);
    }

    protected function casts(): array
    {
        return [
            'matched_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function dispute(): BelongsTo
    {
        return $this->belongsTo(LogisticsPayPalDispute::class, 'logistics_paypal_dispute_id');
    }

    public function refund(): BelongsTo
    {
        return $this->belongsTo(LogisticsPayPalRefund::class, 'logistics_paypal_refund_id');
    }
}
