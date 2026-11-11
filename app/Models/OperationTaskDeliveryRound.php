<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperationTaskDeliveryRound extends Model
{
    protected $guarded = [];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));
        parent::__construct($attributes);
    }

    protected function casts(): array
    {
        return ['launched_at' => 'datetime', 'stopped_at' => 'datetime'];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(OperationTestTask::class, 'task_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(OperationTaskAsset::class, 'asset_id');
    }
}
