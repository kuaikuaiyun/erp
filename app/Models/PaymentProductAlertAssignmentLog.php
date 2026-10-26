<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentProductAlertAssignmentLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'alert_code', 'event', 'from_assignee_id', 'from_assignee_name',
        'to_assignee_id', 'to_assignee_name', 'department_id',
        'department_name_snapshot', 'actor_id', 'actor_name_snapshot', 'note',
    ];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));
        parent::__construct($attributes);
    }
}
