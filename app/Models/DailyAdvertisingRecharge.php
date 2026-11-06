<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyAdvertisingRecharge extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSED = 'processed';

    protected $fillable = [
        'report_date', 'operator_user_id', 'operator_name', 'operation_department_id',
        'operation_department_name', 'entries', 'total_amount', 'status',
        'processed_by', 'processed_by_name', 'processed_at',
    ];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));
        parent::__construct($attributes);
    }

    protected function casts(): array
    {
        return [
            'report_date' => 'date',
            'operator_user_id' => 'integer',
            'operation_department_id' => 'integer',
            'entries' => 'array',
            'total_amount' => 'decimal:2',
            'processed_by' => 'integer',
            'processed_at' => 'datetime',
        ];
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'operator_user_id');
    }

    public function statusLabel(): string
    {
        return $this->status === self::STATUS_PROCESSED ? '已处理' : '未处理';
    }
}
