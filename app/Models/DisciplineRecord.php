<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DisciplineRecord extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    protected $casts = ['record_date' => 'date:Y-m-d'];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));
        parent::__construct($attributes);
    }
}
