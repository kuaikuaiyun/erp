<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AbnormalOrderRecord extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSED = 'processed';

    protected $fillable = [
        'logistics_order_id', 'status', 'processed_by_user_id', 'processed_by_name', 'processed_at',
        'last_notified_by_user_id', 'last_notified_by_name', 'last_notified_at',
    ];

    protected function casts(): array
    {
        return ['processed_at' => 'datetime', 'last_notified_at' => 'datetime'];
    }

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));
        parent::__construct($attributes);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(LogisticsOrder::class, 'logistics_order_id');
    }
}
