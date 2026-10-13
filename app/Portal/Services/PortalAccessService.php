<?php

namespace App\Portal\Services;

use App\Models\AdminUser;
use App\Models\Department;
use App\Models\Portal;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

class PortalAccessService
{
    public function accessiblePortalsFor(AdminUser $user): EloquentCollection
    {
        if (!$this->isActiveAccount($user)) {
            return new EloquentCollection();
        }

        $query = Portal::query()
            ->with('menus')
            ->orderBy('name');

        if ($user->isAdministrator()) {
            return $query
                ->whereIn('status', [Portal::STATUS_ENABLED, Portal::STATUS_MAINTENANCE])
                ->get();
        }

        $rootDepartmentIds = $this->authorizedRootDepartmentIds($user);

        if ($rootDepartmentIds->isEmpty()) {
            return new EloquentCollection();
        }

        return $query
            ->whereIn('status', [Portal::STATUS_ENABLED, Portal::STATUS_MAINTENANCE])
            ->whereHas('departmentGrants', function ($grantQuery) use ($rootDepartmentIds): void {
                $grantQuery->whereIn('department_id', $rootDepartmentIds->all());
            })
            ->get();
    }

    public function canEnter(Portal $portal, AdminUser $user): bool
    {
        if (!$this->isActiveAccount($user) || $portal->isDisabled()) {
            return false;
        }

        if ($portal->isMaintenance()) {
            return $user->isAdministrator();
        }

        if (!$portal->isEnabled()) {
            return false;
        }

        if ($user->isAdministrator()) {
            return true;
        }

        $rootDepartmentIds = $this->authorizedRootDepartmentIds($user);

        return $rootDepartmentIds->isNotEmpty()
            && $portal->departmentGrants()
                ->whereIn('department_id', $rootDepartmentIds->all())
                ->exists();
    }

    public function isActiveAccount(AdminUser $user): bool
    {
        return !isset($user->status) || (int) $user->status === AdminUser::STATUS_ACTIVE;
    }

    /**
     * Converts every direct department membership into its first-level root.
     * A portal grant is always stored against that root department.
     */
    public function authorizedRootDepartmentIds(AdminUser $user): Collection
    {
        $departments = $user->departments()
            ->get(['id', 'parent_id']);

        return $departments
            ->map(fn (Department $department): int => $department->parent_id
                ? (int) $department->parent_id
                : (int) $department->getKey())
            ->unique()
            ->values();
    }
}

