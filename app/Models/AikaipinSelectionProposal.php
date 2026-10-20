<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AikaipinSelectionProposal extends Model
{
    protected $fillable = [
        'proposal_code',
        'product_name',
        'link_1688',
        'competitor_url',
        'remark',
        'created_by',
        'created_by_name',
        'updated_by',
        'updated_by_name',
        'withdrawn_at',
        'withdrawn_by',
        'withdrawn_by_name',
    ];

    protected $casts = [
        'withdrawn_at' => 'datetime',
    ];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));

        parent::__construct($attributes);
    }

    public function tags(): HasMany
    {
        return $this->hasMany(AikaipinSelectionProposalTag::class, 'proposal_id')
            ->orderBy('type')
            ->orderBy('sort')
            ->orderBy('id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(AikaipinSelectionProposalImage::class, 'proposal_id')
            ->orderBy('sort')
            ->orderBy('id');
    }
}
