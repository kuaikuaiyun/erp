<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperationReport extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';

    protected $fillable = [
        'report_date', 'operator_user_id', 'operator_name', 'operation_department_id',
        'operation_department_name', 'metric_rows', 'advertising_rows',
        'first_purchase_cost', 'first_shipping_cost', 'first_return_fee', 'actual_ad_spend',
        'first_profit', 'renewal_net_revenue', 'overall_profit', 'status', 'submitted_by',
        'submitted_at', 'reviewed_by', 'reviewed_at',
    ];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));
        parent::__construct($attributes);
    }

    protected function casts(): array
    {
        return [
            'report_date' => 'date:Y-m-d',
            'metric_rows' => 'array',
            'advertising_rows' => 'array',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'operator_user_id');
    }

    public function operationDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'operation_department_id');
    }
}
