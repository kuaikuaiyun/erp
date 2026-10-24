<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LogisticsTongtuSkuMapping extends Model
{
    /** Presentation-only state when a Shopify SKU has no mapping row yet. */
    public const STATUS_UNMATCHED = 'unmatched';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_MATCHED = 'matched';
    public const STATUS_NOT_FOUND = 'not_found';
    public const STATUS_CONFLICT = 'conflict';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'shopify_sku', 'status', 'not_found_attempts', 'next_retry_at', 'refresh_lock_until',
        'tongtu_product_id', 'tongtu_product_code', 'tongtu_sku', 'tongtu_goods_detail_id', 'tongtu_product_type',
        'tongtu_product_name', 'tongtu_product_packing_name',
        'goods_current_cost_cny', 'goods_average_cost_cny', 'package_cost_cny', 'product_guide_cost_cny', 'cost_currency',
        'goods_weight_gram', 'package_weight_gram',
        'product_length_cm', 'product_width_cm', 'product_height_cm',
        'package_length_cm', 'package_width_cm', 'package_height_cm',
        'tongtu_primary_image_url', 'tongtu_images', 'tongtu_updated_at',
        'last_requested_at', 'last_synced_at', 'last_error', 'last_response',
    ];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));

        parent::__construct($attributes);
    }

    protected function casts(): array
    {
        return array_merge(
            array_fill_keys([
                'goods_current_cost_cny', 'goods_average_cost_cny', 'package_cost_cny', 'product_guide_cost_cny',
                'goods_weight_gram', 'package_weight_gram',
                'product_length_cm', 'product_width_cm', 'product_height_cm',
                'package_length_cm', 'package_width_cm', 'package_height_cm',
            ], 'decimal:4'),
            [
                'next_retry_at' => 'datetime',
                'refresh_lock_until' => 'datetime',
                'tongtu_updated_at' => 'datetime',
                'last_requested_at' => 'datetime',
                'last_synced_at' => 'datetime',
                'created_at' => 'datetime',
                'updated_at' => 'datetime',
            ],
        );
    }

    public static function normalizedSku(string $sku): string
    {
        return mb_strtolower(trim($sku));
    }

    public function isFresh(?\DateTimeInterface $now = null): bool
    {
        if ($this->status !== self::STATUS_MATCHED) {
            return false;
        }

        $now ??= now();
        if ($this->next_retry_at && $this->next_retry_at->lessThanOrEqualTo($now)) {
            return false;
        }
        $lastRefreshAt = $this->last_requested_at ?: $this->last_synced_at;
        if (!$lastRefreshAt) {
            return false;
        }

        return $lastRefreshAt->greaterThan($now->copy()->subHours(6));
    }
}
