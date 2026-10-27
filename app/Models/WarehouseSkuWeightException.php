<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WarehouseSkuWeightException extends Model
{
    protected $table = 'warehouse_sku_weight_exceptions';

    protected $guarded = ['id'];

    protected $casts = [
        'original_weight_gram' => 'float',
        'corrected_weight_gram' => 'float',
        'resolved_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /** 驳回理由选项：code => 中文名 */
    public const REJECT_REASONS = [
        'weight' => '重量不符',
        'image' => '图片不符',
        'other' => '其他',
    ];

    public static function rejectReasonLabel(?string $code): string
    {
        $code = trim((string) $code);

        if ($code === '') {
            return '';
        }

        $codes = array_values(array_unique(array_filter(array_map('trim', explode(',', $code)))));

        return implode('、', array_map(
            static fn (string $reason): string => self::REJECT_REASONS[$reason] ?? $reason,
            $codes,
        ));
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeResolved($query)
    {
        return $query->where('status', 'resolved');
    }
}
