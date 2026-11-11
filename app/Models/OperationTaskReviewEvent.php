<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OperationTaskReviewEvent extends Model
{
    protected $guarded = [];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));
        parent::__construct($attributes);
    }

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }
}
