<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentProductAlertAssignment extends Model
{
    protected $fillable = [
        'alert_code', 'status', 'department_id', 'department_name_snapshot',
        'assignee_id', 'assignee_name_snapshot', 'assignee_position', 'deadline_at',
        'assignment_note', 'assigned_by', 'assigned_by_name_snapshot', 'assigned_at',
    ];

    protected $casts = ['deadline_at' => 'datetime', 'assigned_at' => 'datetime'];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));
        parent::__construct($attributes);
    }
}
