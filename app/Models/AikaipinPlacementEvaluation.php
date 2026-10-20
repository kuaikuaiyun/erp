<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AikaipinPlacementEvaluation extends Model
{
    protected $fillable = [
        'evaluation_code',
        'product_id',
        'product_code',
        'product_name',
        'test_started_on',
        'data_ended_on',
        'remark',
        'created_by',
        'created_by_name',
        'updated_by',
        'updated_by_name',
    ];

    protected $casts = [
        'test_started_on' => 'date',
        'data_ended_on' => 'date',
    ];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));

        parent::__construct($attributes);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(AikaipinProduct::class, 'product_id');
    }

    public function records(): HasMany
    {
        return $this->hasMany(AikaipinPlacementEvaluationRecord::class, 'evaluation_id')
            ->orderBy('sort')
            ->orderBy('id');
    }
}
