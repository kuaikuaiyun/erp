<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PortalChangeLog extends Model
{
    public const EVENT_PORTAL_CREATED = 'portal_created';

    public const EVENT_PORTAL_UPDATED = 'portal_updated';

    public const EVENT_PORTAL_STATUS_CHANGED = 'portal_status_changed';

    public const EVENT_DEPARTMENT_GRANTED = 'department_granted';

    public const EVENT_DEPARTMENT_REVOKED = 'department_revoked';

    public const EVENT_DEPARTMENT_REVOKED_ON_DISSOLUTION = 'department_revoked_on_dissolution';

    protected $fillable = [
        'portal_id',
        'department_id',
        'department_name_snapshot',
        'event',
        'payload',
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
            'payload' => 'array',
        ];
    }

    public function portal(): BelongsTo
    {
        return $this->belongsTo(Portal::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'actor_id');
    }
}


