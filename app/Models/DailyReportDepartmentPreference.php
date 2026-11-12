<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DailyReportDepartmentPreference extends Model
{
    protected $fillable = [
        'user_id',
        'operation_department_id',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'operation_department_id' => 'integer',
        ];
    }

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));

        parent::__construct($attributes);
    }
}
