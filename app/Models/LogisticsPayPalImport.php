<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LogisticsPayPalImport extends Model
{
    protected $table = 'logistics_paypal_imports';

    protected $fillable = [
        'logistics_paypal_account_id', 'upload_id', 'merchant_id', 'environment',
        'source_file', 'payload_sha256', 'storage_path', 'period_from', 'period_to',
        'generated_at', 'status', 'transaction_count', 'refund_count',
        'dispute_count', 'matched_count', 'unmatched_count', 'error_count', 'message',
    ];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));
        parent::__construct($attributes);
    }

    protected function casts(): array
    {
        return [
            'period_from' => 'datetime',
            'period_to' => 'datetime',
            'generated_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(LogisticsPayPalAccount::class, 'logistics_paypal_account_id');
    }
}
