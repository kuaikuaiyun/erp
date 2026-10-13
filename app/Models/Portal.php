<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Portal extends Model
{
    public const STATUS_ENABLED = 'enabled';

    public const STATUS_MAINTENANCE = 'maintenance';

    public const STATUS_DISABLED = 'disabled';

    protected $fillable = [
        'name',
        'code',
        'remark',
        'status',
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
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function departmentGrants(): HasMany
    {
        return $this->hasMany(PortalDepartment::class);
    }

    public function changeLogs(): HasMany
    {
        return $this->hasMany(PortalChangeLog::class);
    }

    public function menus(): HasMany
    {
        return $this->hasMany(PortalMenu::class)->orderBy('sort')->orderBy('id');
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ENABLED);
    }

    public function isEnabled(): bool
    {
        return $this->status === self::STATUS_ENABLED;
    }

    public function isMaintenance(): bool
    {
        return $this->status === self::STATUS_MAINTENANCE;
    }

    public function isDisabled(): bool
    {
        return $this->status === self::STATUS_DISABLED;
    }
}


