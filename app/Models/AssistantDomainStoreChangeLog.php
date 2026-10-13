<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssistantDomainStoreChangeLog extends Model
{
    protected $fillable = [
        'domain_store_id',
        'event',
        'changes',
        'actor_id',
        'actor_name_snapshot',
    ];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));

        parent::__construct($attributes);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function domainStore(): BelongsTo
    {
        return $this->belongsTo(AssistantDomainStore::class, 'domain_store_id');
    }
}


