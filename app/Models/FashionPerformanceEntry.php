<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FashionPerformanceEntry extends Model
{
    protected $fillable = ['store_name', 'domain', 'revenue_usd', 'order_count', 'screenshot_path', 'remark'];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));
        parent::__construct($attributes);
    }
}
