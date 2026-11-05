<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinanceRecord extends Model
{
    protected $guarded = ['id'];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));
        parent::__construct($attributes);
    }

    protected function casts(): array
    {
        return ['payload' => 'array', 'occurred_at' => 'immutable_datetime'];
    }

    protected function asJson($value, $flags = 0)
    {
        // Keep original Unicode report headers addressable by JSON path queries.
        return parent::asJson($value, $flags | JSON_UNESCAPED_UNICODE);
    }
}
