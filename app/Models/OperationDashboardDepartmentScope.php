<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OperationDashboardDepartmentScope extends Model
{
    protected $fillable = [
        'user_id',
        'department_id',
        'created_by',
    ];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));

        parent::__construct($attributes);
    }
}
