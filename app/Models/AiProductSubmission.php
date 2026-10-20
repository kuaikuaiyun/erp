<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiProductSubmission extends Model
{
    public const REVIEW_PENDING = 'pending';

    public const REVIEW_APPROVED = 'approved';

    public const REVIEW_REJECTED = 'rejected';

    protected $guarded = [];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));

        parent::__construct($attributes);
    }

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function aiProduct(): BelongsTo
    {
        return $this->belongsTo(AiProduct::class);
    }

    public static function statusLabels(): array
    {
        return [
            self::REVIEW_PENDING => __('ai_products.status.pending'),
            self::REVIEW_APPROVED => __('ai_products.status.approved'),
            self::REVIEW_REJECTED => __('ai_products.status.rejected'),
        ];
    }
}
