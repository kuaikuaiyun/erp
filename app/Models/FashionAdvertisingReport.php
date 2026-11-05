<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FashionAdvertisingReport extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = ['report_date', 'submitter_id', 'submitter_name', 'total_revenue_usd', 'total_order_count', 'total_ad_spend_usd', 'roi', 'status', 'reviewed_by', 'reviewer_name', 'reviewed_at', 'review_reason'];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));
        parent::__construct($attributes);
    }

    protected function casts(): array
    {
        return ['report_date' => 'date:Y-m-d', 'total_revenue_usd' => 'decimal:2', 'total_ad_spend_usd' => 'decimal:2', 'roi' => 'decimal:4', 'reviewed_at' => 'datetime'];
    }

    public function performanceEntries(): HasMany
    {
        return $this->hasMany(FashionPerformanceEntry::class)->orderBy('id');
    }

    public function adSpendEntries(): HasMany
    {
        return $this->hasMany(FashionAdSpendEntry::class)->orderBy('id');
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_APPROVED => '审核通过',
            self::STATUS_REJECTED => '审核不通过',
            default => '待审核',
        };
    }
}
