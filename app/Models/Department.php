<?php

namespace App\Models;

use Encore\Admin\Auth\Database\Role;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Department extends Role
{
    public const SYSTEM_SLUG = 'administrator';

    public const POSITION_MANAGER = 'manager';

    public const POSITION_DEPUTY = 'deputy';

    public const POSITION_MEMBER = 'member';

    protected $fillable = ['name', 'short_name', 'slug', 'parent_id', 'sort'];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort')->orderBy('name');
    }

    public function departmentMembers(): BelongsToMany
    {
        $relatedModel = config('admin.database.users_model');

        return $this->belongsToMany(
            $relatedModel,
            config('admin.database.role_users_table'),
            'role_id',
            'user_id',
        )->withPivot(['position', 'position_slot'])->withTimestamps();
    }

    public function permissionScopes(): HasMany
    {
        return $this->hasMany(DepartmentPermissionScope::class, 'role_id');
    }

    public function isSystemDepartment(): bool
    {
        return $this->slug === self::SYSTEM_SLUG;
    }

    public function isBranch(): bool
    {
        return $this->relationLoaded('children')
            ? $this->children->isNotEmpty()
            : $this->children()->exists();
    }

    public function allowsPosition(string $position): bool
    {
        if ($this->isSystemDepartment()) {
            return false;
        }

        return !$this->isBranch() || $position === self::POSITION_MANAGER;
    }

    public static function business(): \Illuminate\Database\Eloquent\Builder
    {
        return static::query()->where('slug', '!=', self::SYSTEM_SLUG);
    }

    protected static function boot(): void
    {
        parent::boot();

        static::deleting(function (self $department): void {
            DB::connection($department->getConnectionName())
                ->table('admin_department_permission_scopes')
                ->where('role_id', $department->getKey())
                ->delete();

            $department->menus()->detach();
        });
    }
}


