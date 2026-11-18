<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DisciplineRecordLog extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['before_data' => 'array', 'after_data' => 'array', 'created_at' => 'datetime'];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));
        parent::__construct($attributes);
    }
}
