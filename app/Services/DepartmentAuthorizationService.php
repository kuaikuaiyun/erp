<?php

namespace App\Services;

use App\Models\AdminUser;
use App\Models\Department;
use App\Models\DepartmentPermissionScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DepartmentAuthorizationService
{
    /**
     * A department manager is a manager or deputy manager in at least one
     * business department. System administrators bypass this check.
     */
    public function canManageOrganization(AdminUser $user): bool
    {
        return $user->isAdministrator() || $this->managedDepartmentIds($user)->isNotEmpty();
    }

    /**
     * Get every business department the user may manage.
     *
     * A level-one manager manages its own department and all descendants.
     * A deputy manager, or a level-two manager, manages only the department
     * in which they hold that position.
     */
    public function managedDepartmentIds(AdminUser $user): Collection
    {
        if ($user->isAdministrator()) {
            return Department::business()->pluck('id')->map(fn ($id) => (int) $id)->values();
        }

        $leadershipDepartments = $user->departments()
            ->wherePivotIn('position', [Department::POSITION_MANAGER, Department::POSITION_DEPUTY])
            ->get(['id', 'parent_id']);

        if ($leadershipDepartments->isEmpty()) {
            return collect();
        }

        $departments = Department::business()->get(['id', 'parent_id'])->keyBy('id');
        $childrenByParent = $departments->groupBy(fn (Department $department) => $department->parent_id ?: 0);
        $managedIds = collect();

        $appendDescendants = function (int $parentId) use (&$appendDescendants, $childrenByParent, $managedIds): void {
            foreach ($childrenByParent->get($parentId, collect()) as $child) {
                $managedIds->push((int) $child->getKey());
                $appendDescendants((int) $child->getKey());
            }
        };

        foreach ($leadershipDepartments as $department) {
            $managedIds->push((int) $department->getKey());

            if (!$department->parent_id && $department->pivot->position === Department::POSITION_MANAGER) {
                $appendDescendants((int) $department->getKey());
            }
        }

        return $managedIds->unique()->values();
    }

    public function canManageDepartment(AdminUser $user, Department $department): bool
    {
        return $this->managedDepartmentIds($user)->contains((int) $department->getKey());
    }

    /**
     * Only a level-one department manager may create a second-level child.
     */
    public function canCreateChildDepartment(AdminUser $user, Department $department): bool
    {
        if ($user->isAdministrator()) {
            return true;
        }

        if ($department->parent_id) {
            return false;
        }

        return $user->departments()
            ->whereKey($department->getKey())
            ->wherePivot('position', Department::POSITION_MANAGER)
            ->exists();
    }

    /**
     * An account is in scope when it belongs to at least one department the
     * current manager is responsible for.
     */
    public function canManageAccount(AdminUser $user, AdminUser $account): bool
    {
        if ($user->isAdministrator()) {
            return true;
        }

        if ($account->isAdministrator()) {
            return false;
        }

        $departmentIds = $this->managedDepartmentIds($user);

        return $departmentIds->isNotEmpty()
            && $account->departments()->whereIn('id', $departmentIds)->exists();
    }

    /**
     * Return direct and inherited page permissions for a non-system account.
     */
    public function permissionsFor(AdminUser $user): Collection
    {
        $directDepartmentIds = $this->directDepartmentIds($user);
        $permissionIds = collect();

        if ($directDepartmentIds->isNotEmpty()) {
            $permissionIds = DB::connection($this->connectionName())
                ->table(config('admin.database.role_permissions_table'))
                ->whereIn('role_id', $directDepartmentIds)
                ->pluck('permission_id');

            $ancestorIds = $this->ancestorDepartmentIds($directDepartmentIds);

            if ($ancestorIds->isNotEmpty()) {
                $permissionIds = $permissionIds->merge(
                    DB::connection($this->connectionName())
                        ->table('admin_department_permission_scopes')
                        ->whereIn('role_id', $ancestorIds)
                        ->where('scope', DepartmentPermissionScope::SCOPE_DESCENDANTS)
                        ->pluck('permission_id'),
                );
            }
        }

        $permissionIds = $permissionIds
            ->merge($user->permissions()->pluck('id'))
            ->filter()
            ->unique()
            ->values();

        if ($permissionIds->isEmpty()) {
            return collect();
        }

        $permissionModel = config('admin.database.permissions_model');

        return $permissionModel::query()
            ->whereIn('id', $permissionIds)
            ->get();
    }

    public function hasPermission(AdminUser $user, string $permission): bool
    {
        if (in_array($permission, ['organization.accounts', 'organization.departments'], true)) {
            return $this->canManageOrganization($user);
        }

        $slugs = $this->permissionsFor($user)->pluck('slug');

        return $slugs->contains('*') || $slugs->contains($permission);
    }

    /**
     * Menu visibility uses role slugs. A parent role is included only when it
     * actually delegates one or more pages to descendants. This keeps a child
     * department from seeing an empty menu group for a parent-only page.
     */
    public function effectiveRoleSlugs(AdminUser $user): Collection
    {
        $departments = $this->directDepartments($user);

        if ($departments->isEmpty()) {
            return $user->roles()->pluck('slug')->unique()->values();
        }

        $ancestors = $this->ancestorsWithDescendantPermissions(
            $this->ancestorDepartments($departments->pluck('id')),
        );

        return $departments
            ->pluck('slug')
            ->merge($ancestors->pluck('slug'))
            ->merge($user->roles()->where('slug', Department::SYSTEM_SLUG)->pluck('slug'))
            ->unique()
            ->values();
    }

    public function directDepartmentIds(AdminUser $user): Collection
    {
        return $this->directDepartments($user)->pluck('id')->values();
    }

    public function ancestorDepartmentIds(Collection $departmentIds): Collection
    {
        return $this->ancestorDepartments($departmentIds)->pluck('id')->values();
    }

    private function directDepartments(AdminUser $user): Collection
    {
        $rolesTable = (new Department())->getTable();

        return $user->roles()
            ->where('slug', '!=', Department::SYSTEM_SLUG)
            ->get(["{$rolesTable}.id", "{$rolesTable}.slug", "{$rolesTable}.parent_id"]);
    }

    private function ancestorDepartments(Collection $departmentIds): Collection
    {
        if ($departmentIds->isEmpty()) {
            return collect();
        }

        $departments = Department::business()
            ->get(['id', 'slug', 'parent_id'])
            ->keyBy('id');
        $ancestorIds = collect();

        foreach ($departmentIds as $departmentId) {
            $current = $departments->get((int) $departmentId);
            $visited = [];

            while ($current && $current->parent_id && !in_array($current->parent_id, $visited, true)) {
                $visited[] = (int) $current->parent_id;
                $current = $departments->get((int) $current->parent_id);

                if ($current) {
                    $ancestorIds->push($current->id);
                }
            }
        }

        return $ancestorIds
            ->unique()
            ->map(fn (int $id) => $departments->get($id))
            ->filter()
            ->values();
    }

    private function ancestorsWithDescendantPermissions(Collection $ancestors): Collection
    {
        if ($ancestors->isEmpty()) {
            return collect();
        }

        $delegatingDepartmentIds = DB::connection($this->connectionName())
            ->table('admin_department_permission_scopes')
            ->whereIn('role_id', $ancestors->pluck('id'))
            ->where('scope', DepartmentPermissionScope::SCOPE_DESCENDANTS)
            ->pluck('role_id')
            ->unique();

        return $ancestors
            ->whereIn('id', $delegatingDepartmentIds)
            ->values();
    }

    private function connectionName(): ?string
    {
        return config('admin.database.connection') ?: config('database.default');
    }
}


