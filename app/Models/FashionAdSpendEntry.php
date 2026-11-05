<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FashionAdSpendEntry extends Model
{
    protected $fillable = ['agency', 'media', 'account_name', 'account_number', 'book_spend_usd', 'actual_spend_usd', 'screenshot_path', 'remark'];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));
        parent::__construct($attributes);
    }
}
