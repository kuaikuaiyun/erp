<?php

namespace App\Models;

use App\Services\DepartmentAuthorizationService;
use Encore\Admin\Auth\Database\Administrator;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;

class AdminUser extends Administrator
{
    public const STATUS_BANNED = 0;

    public const STATUS_ACTIVE = 1;

    public function roles(): BelongsToMany
    {
        return parent::roles()->withPivot(['position', 'position_slot', 'is_primary'])->withTimestamps();
    }

    public function departments(): BelongsToMany
    {
        return $this->roles()->where('slug', '!=', Department::SYSTEM_SLUG);
    }

    public function allPermissions(): Collection
    {
        if ($this->isAdministrator()) {
            return parent::allPermissions();
        }

        return app(DepartmentAuthorizationService::class)->permissionsFor($this);
    }

    public function can($ability, $arguments = []): bool
    {
        if (empty($ability) || $this->isAdministrator()) {
            return true;
        }

        return app(DepartmentAuthorizationService::class)->hasPermission($this, (string) $ability);
    }

    public function inRoles(array $roles = []): bool
    {
        if (empty($roles)) {
            return false;
        }

        return app(DepartmentAuthorizationService::class)
            ->effectiveRoleSlugs($this)
            ->intersect($roles)
            ->isNotEmpty();
    }

    public function visible(array $roles = []): bool
    {
        if (empty($roles) || $this->isAdministrator()) {
            return true;
        }

        $roleSlugs = collect($roles)->pluck('slug');

        if ($roleSlugs->contains(Department::SYSTEM_SLUG)
            && app(DepartmentAuthorizationService::class)->canManageOrganization($this)) {
            return true;
        }

        return $this->inRoles($roleSlugs->all());
    }
}
