<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssistantDepartmentOperationScope extends Model
{
    protected $table = 'assistant_dept_operation_scopes';

    protected $fillable = [
        'assistant_department_id',
        'operation_department_id',
        'last_operator_id',
    ];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));

        parent::__construct($attributes);
    }

    public function assistantDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'assistant_department_id');
    }

    public function operationDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'operation_department_id');
    }
}
