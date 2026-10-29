<?php

namespace App\Portals\Assistant;

use App\Models\AdminUser;
use App\Models\Department;
use Illuminate\Support\Collection;

class AssistantAdvertisingScopeService
{
    public function __construct(private readonly AssistantPortalAuthorizationService $authorization)
    {
    }

    /** @return Collection<int, Department> */
    public function departmentsFor(AdminUser $user): Collection
    {
        return $user->isAdministrator()
            ? $this->authorization->assistantChildDepartments()
            : $this->authorization->assistantChildDepartmentsFor($user);
    }

    public function selectedDepartment(AdminUser $user, mixed $requestedId = null): Department
    {
        $departments = $this->departmentsFor($user);
        abort_if($departments->isEmpty(), 403, '当前账号未归属可管理的助理部门。');
        $requestedId = (int) $requestedId;
        $selected = $requestedId > 0 ? $departments->firstWhere('id', $requestedId) : $departments->first();
        abort_unless($selected, 403, '无权访问该助理部门的广告配置。');

        return $selected;
    }

    public function canAccessDepartment(AdminUser $user, ?int $departmentId): bool
    {
        return $departmentId !== null
            && $this->departmentsFor($user)->contains(fn (Department $department): bool => (int) $department->id === $departmentId);
    }
}
