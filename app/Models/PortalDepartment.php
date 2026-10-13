<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PortalDepartment extends Model
{
    protected $fillable = [
        'portal_id',
        'department_id',
        'includes_descendants',
        'granted_by',
        'granted_at',
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
            'includes_descendants' => 'boolean',
            'granted_at' => 'datetime',
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

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'granted_by');
    }
}


