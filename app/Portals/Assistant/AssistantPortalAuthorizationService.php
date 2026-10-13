<?php

namespace App\Portals\Assistant;

use App\Models\AdminUser;
use App\Models\Department;
use App\Models\Portal;
use App\Portal\Services\PortalAccessService;
use Illuminate\Support\Collection;

class AssistantPortalAuthorizationService
{
    public const PORTAL_CODE = 'zhuli';

    public const EDITOR_DEPARTMENT_CODE = 'dept-000004';

    public function __construct(private readonly PortalAccessService $portalAccess)
    {
    }

    public function assertAssistantPortal(Portal $portal): void
    {
        abort_unless($portal->code === self::PORTAL_CODE, 404);
    }

    public function canManage(AdminUser $user): bool
    {
        if ($user->isAdministrator()) {
            return true;
        }

        $departmentId = $this->editorDepartmentId();

        return $departmentId !== null
            && $this->portalAccess->authorizedRootDepartmentIds($user)->contains($departmentId);
    }

    public function ensureCanManage(AdminUser $user): void
    {
        abort_unless($this->canManage($user), 403);
    }

    public function isAssistantDepartmentMember(AdminUser $user): bool
    {
        if ($user->isAdministrator()) {
            return true;
        }

        $departmentId = $this->editorDepartmentId();

        return $departmentId !== null
            && $user->departments()
                ->where(function ($query) use ($departmentId): void {
                    $query->whereKey($departmentId)
                        ->orWhere('parent_id', $departmentId);
                })
                ->exists();
    }

    /**
     * System administrators and the Assistant primary manager can manage every
     * record, including records that are temporarily not shared with any child department.
     */
    public function canManageAllDomainStores(AdminUser $user): bool
    {
        return $this->canManageQuickOptions($user);
    }

    /**
     * A non-manager Assistant account may operate records only through exactly
     * one direct child department under the Assistant primary department.
     */
    public function assistantChildDepartmentFor(AdminUser $user): ?Department
    {
        if ($user->isAdministrator()) {
            return null;
        }

        $departmentId = $this->editorDepartmentId();

        if ($departmentId === null) {
            return null;
        }

        $departments = $user->departments()
            ->where('parent_id', $departmentId)
            ->orderBy('sort')
            ->orderBy('name')
            ->get();

        return $departments->count() === 1 ? $departments->first() : null;
    }

    public function canManageDomainStores(AdminUser $user): bool
    {
        return $this->canManageAllDomainStores($user)
            || ($this->canManage($user) && $this->assistantChildDepartmentFor($user) !== null);
    }

    public function ensureCanManageDomainStores(AdminUser $user): void
    {
        abort_unless($this->canManageDomainStores($user), 403);
    }

    /** @return Collection<int, Department> */
    public function assistantChildDepartments(): Collection
    {
        $departmentId = $this->editorDepartmentId();

        if ($departmentId === null) {
            return collect();
        }

        return Department::business()
            ->where('parent_id', $departmentId)
            ->orderBy('sort')
            ->orderBy('name')
            ->get();
    }

    public function canManageQuickOptions(AdminUser $user): bool
    {
        if ($user->isAdministrator()) {
            return true;
        }

        $departmentId = $this->editorDepartmentId();

        return $departmentId !== null
            && $this->canManage($user)
            && $user->departments()
                ->whereKey($departmentId)
                ->wherePivot('position', Department::POSITION_MANAGER)
                ->exists();
    }

    public function ensureCanManageQuickOptions(AdminUser $user): void
    {
        abort_unless($this->canManageQuickOptions($user), 403);
    }

    private function editorDepartmentId(): ?int
    {
        $departmentId = Department::business()
            ->where('slug', self::EDITOR_DEPARTMENT_CODE)
            ->whereNull('parent_id')
            ->value('id');

        return $departmentId === null ? null : (int) $departmentId;
    }
}
