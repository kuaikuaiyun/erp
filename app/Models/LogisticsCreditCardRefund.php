<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LogisticsCreditCardRefund extends Model
{
    protected $table = 'logistics_credit_card_refunds';

    protected $fillable = [
        'logistics_order_id', 'bill_number', 'refund_reference', 'refund_amount_usd', 'refund_reason',
        'imported_by', 'imported_by_name', 'last_updated_by', 'last_updated_by_name', 'last_imported_at',
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
            'last_imported_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(LogisticsOrder::class, 'logistics_order_id');
    }
}
