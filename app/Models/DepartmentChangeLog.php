<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DepartmentChangeLog extends Model
{
    protected $table = 'admin_department_change_logs';

    protected $fillable = ['actor_id', 'department_id', 'event', 'payload'];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));

        parent::__construct($attributes);
    }
}


