<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentProductAlertRule extends Model
{
    protected $fillable = [
        'code', 'name', 'yellow_condition', 'red_condition', 'minimum_sample',
        'action_text', 'is_enabled', 'updated_by', 'updated_by_name',
    ];

    protected $casts = [
        'minimum_sample' => 'integer',
        'is_enabled' => 'boolean',
    ];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));
        parent::__construct($attributes);
    }
}
