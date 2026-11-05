<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FashionLogisticsSyncState extends Model
{
    protected $fillable = ['assistant_domain_store_id', 'status', 'last_started_at', 'last_succeeded_at', 'last_cursor_at', 'last_created_count', 'last_updated_count', 'last_error'];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));
        parent::__construct($attributes);
    }

    protected function casts(): array
    {
        return ['last_started_at' => 'datetime', 'last_succeeded_at' => 'datetime', 'last_cursor_at' => 'datetime', 'last_created_count' => 'integer', 'last_updated_count' => 'integer'];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(AssistantDomainStore::class, 'assistant_domain_store_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(FashionLogisticsOrder::class, 'assistant_domain_store_id', 'assistant_domain_store_id');
    }
}
