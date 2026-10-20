<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AikaipinSelectionProposalImage extends Model
{
    protected $fillable = [
        'proposal_id',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size',
        'sha256',
        'sort',
    ];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));

        parent::__construct($attributes);
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(AikaipinSelectionProposal::class, 'proposal_id');
    }
}
