<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperationTaskRectification extends Model
{
    protected $guarded = [];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));
        parent::__construct($attributes);
    }

    protected function casts(): array
    {
        return [
            'stop_confirmed_at' => 'datetime',
            'resubmitted_at' => 'datetime',
            'restored_at' => 'datetime',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(OperationTestTask::class, 'task_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(OperationTaskAsset::class, 'asset_id');
    }

    public function sourceReview(): BelongsTo
    {
        return $this->belongsTo(OperationTaskReview::class, 'source_review_id');
    }

    public function resubmittedAsset(): BelongsTo
    {
        return $this->belongsTo(OperationTaskAsset::class, 'resubmitted_asset_id');
    }
}
