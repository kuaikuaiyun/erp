<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FashionAdSpendEntry extends Model
{
    protected $fillable = ['agency', 'media', 'account_name', 'account_number', 'assistant_domain_store_id', 'store_domain', 'book_spend_usd', 'settlement_coefficient', 'actual_spend_usd', 'screenshot_path', 'remark'];

    protected function casts(): array
    {
        return ['book_spend_usd' => 'decimal:2', 'settlement_coefficient' => 'decimal:4', 'actual_spend_usd' => 'decimal:2'];
    }

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));
        parent::__construct($attributes);
    }
}
