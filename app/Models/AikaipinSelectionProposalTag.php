<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AikaipinSelectionProposalTag extends Model
{
    public const TYPE_SPECIFICATION = 'specification';

    public const TYPE_ATTRIBUTE = 'attribute';

    protected $fillable = [
        'proposal_id',
        'type',
        'value',
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
