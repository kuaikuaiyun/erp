<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AikaipinPlacementEvaluationRecord extends Model
{
    protected $fillable = [
        'evaluation_id',
        'platform',
        'currency',
        'actual_spend',
        'orders',
        'sort',
    ];

    protected $casts = [
        'actual_spend' => 'decimal:2',
        'orders' => 'integer',
        'sort' => 'integer',
    ];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));

        parent::__construct($attributes);
    }

    public function evaluation(): BelongsTo
    {
        return $this->belongsTo(AikaipinPlacementEvaluation::class, 'evaluation_id');
    }
}
