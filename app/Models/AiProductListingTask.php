<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiProductListingTask extends Model
{
    public const STATUS_PENDING_ASSIGNMENT = 'pending_assignment';
    public const STATUS_ASSIGNED = 'assigned';
    public const STATUS_PROCESSED = 'processed';

    protected $guarded = [];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));
        parent::__construct($attributes);
    }

    protected function casts(): array
    {
        return ['assigned_at' => 'datetime', 'processed_at' => 'datetime'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(AikaipinProduct::class, 'aikaipin_product_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'manager_user_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'assignee_user_id');
    }
}
