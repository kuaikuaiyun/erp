<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LogisticsPayPalDisputeOperation extends Model
{
    protected $table = 'logistics_paypal_dispute_operations';

    protected $fillable = ['logistics_paypal_dispute_id', 'operator_id', 'operator_name', 'action', 'payload'];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));
        parent::__construct($attributes);
    }

    protected function casts(): array
    {
        return ['payload' => 'array', 'created_at' => 'datetime', 'updated_at' => 'datetime'];
    }

    public function dispute(): BelongsTo
    {
        return $this->belongsTo(LogisticsPayPalDispute::class, 'logistics_paypal_dispute_id');
    }
}
