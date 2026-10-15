<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductRequest extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_INSPECTION_PASSED = 'inspection_passed';
    public const STATUS_APPROVED = 'approved';

    public const REVIEW_UNFILLED = 'unfilled';
    public const REVIEW_PENDING = 'pending';
    public const REVIEW_REJECTED = 'rejected';
    public const REVIEW_APPROVED = 'approved';

    public const WAREHOUSE_PENDING_REVIEW = 'pending_review';
    public const WAREHOUSE_PASSED = 'passed';
    public const WAREHOUSE_REJECTED = 'rejected';

    public const WAREHOUSE_REJECT_REASON_QUALITY = 'quality';
    public const WAREHOUSE_REJECT_REASON_WEIGHT = 'weight';
    public const WAREHOUSE_REJECT_REASON_BOTH = 'both';

    public const RECTIFICATION_NONE = 'none';
    public const RECTIFICATION_PENDING = 'pending';
    public const RECTIFICATION_DONE = 'done';

    protected $guarded = [];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));

        parent::__construct($attributes);
    }

    protected function casts(): array
    {
        return [
            'reported_at' => 'date',
            'inspected_at' => 'datetime',
            'warehouse_processed_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ProductRequestAttachment::class)->orderBy('stage')->orderBy('sort')->orderBy('id');
    }

    public function placementReviews(): HasMany
    {
        return $this->hasMany(ProductRequestPlacementReview::class)->latest('id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(ProductRequestLog::class)->latest('id');
    }

    public function attachmentsFor(string $stage)
    {
        return $this->attachments->where('stage', $stage)->values();
    }
}
