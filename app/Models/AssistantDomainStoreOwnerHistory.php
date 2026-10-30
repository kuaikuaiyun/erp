<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssistantDomainStoreOwnerHistory extends Model
{
    public const EVENT_BASELINE = 'baseline';
    public const EVENT_ASSIGNED = 'assigned';
    public const EVENT_TRANSFERRED = 'transferred';
    public const EVENT_CLEARED = 'cleared';

    protected $fillable = ['assistant_domain_store_id', 'owner_user_id', 'owner_name_snapshot', 'event', 'effective_from', 'effective_to', 'actor_id', 'actor_name_snapshot'];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setConnection(config('admin.database.connection') ?: config('database.default'));
    }

    protected function casts(): array
    {
        return ['effective_from' => 'datetime', 'effective_to' => 'datetime', 'created_at' => 'datetime', 'updated_at' => 'datetime'];
    }

    public function store(): BelongsTo { return $this->belongsTo(AssistantDomainStore::class, 'assistant_domain_store_id'); }
    public function owner(): BelongsTo { return $this->belongsTo(AdminUser::class, 'owner_user_id'); }
}
