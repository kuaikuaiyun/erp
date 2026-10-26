<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductRequestLinkSku extends Model
{
    /** 不可跑原因选项 */
    public const NOT_RUNNABLE_REASONS = [
        'mismatch'        => '货不对版',
        'size_exceed'     => '尺寸超标',
        'complaint'       => '客诉高',
        'infringement'    => '侵权',
        'not_runnable'    => '不可跑产品',
        'xiaohai'         => '小黑',
        'other'           => '其他',
    ];

    protected $guarded = [];

    protected $casts = [
        'sku_values' => 'array',
        'images' => 'array',
        'is_runnable' => 'boolean',
    ];

    public function getNotRunnableReasonLabelAttribute(): string
    {
        return self::NOT_RUNNABLE_REASONS[$this->not_runnable_reason] ?? ($this->not_runnable_reason ?: '—');
    }

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));

        parent::__construct($attributes);
    }
}
