<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OperationTaskRecapQuestion extends Model
{
    protected $guarded = [];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));
        parent::__construct($attributes);
    }

    protected function casts(): array
    {
        return ['is_required' => 'boolean', 'is_enabled' => 'boolean'];
    }
}
