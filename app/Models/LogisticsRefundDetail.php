<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LogisticsRefundDetail extends Model
{
    protected $fillable = [
        'logistics_order_id', 'refund_number', 'refund_amount_usd', 'refund_type', 'refund_reason',
        'remark', 'fingerprint', 'is_void', 'imported_by', 'imported_by_name', 'voided_by',
        'voided_by_name', 'voided_at',
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
            'is_void' => 'boolean',
            'voided_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(LogisticsOrder::class, 'logistics_order_id');
    }
}
