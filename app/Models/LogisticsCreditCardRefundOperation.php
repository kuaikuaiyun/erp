<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LogisticsCreditCardRefundOperation extends Model
{
    protected $fillable = ['logistics_credit_card_refund_id', 'operator_id', 'operator_name', 'action', 'payload'];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));
        parent::__construct($attributes);
    }

    protected function casts(): array
    {
        return ['payload' => 'array', 'created_at' => 'datetime'];
    }

    public function refund(): BelongsTo
    {
        return $this->belongsTo(LogisticsCreditCardRefund::class, 'logistics_credit_card_refund_id');
    }
}
