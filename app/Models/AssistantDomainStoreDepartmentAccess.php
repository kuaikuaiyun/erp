<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssistantDomainStoreDepartmentAccess extends Model
{
    protected $fillable = [
        'domain_store_id',
        'department_id',
    ];

    public function __construct(array $attributes = [])
    {
        $this->setConnection(config('admin.database.connection') ?: config('database.default'));

        parent::__construct($attributes);
    }

    public function domainStore(): BelongsTo
    {
        return $this->belongsTo(AssistantDomainStore::class, 'domain_store_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }
}
