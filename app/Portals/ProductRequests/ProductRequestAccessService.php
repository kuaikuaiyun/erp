<?php

namespace App\Portals\ProductRequests;

use App\Models\AdminUser;
use App\Models\Department;
use App\Models\Portal;
use App\Models\ProductRequest;
use Illuminate\Database\Eloquent\Builder;

class ProductRequestAccessService
{
    public const OPERATION_PORTAL_CODE = 'yunying';
    public const PROCUREMENT_PORTAL_CODE = 'caigou';
    public const WAREHOUSE_PORTAL_CODE = 'cangku';

    public const PROCUREMENT_ENTRY_DEPARTMENT_SLUG = 'dept-000016';
    public const PROCUREMENT_REVIEW_DEPARTMENT_SLUG = 'dept-000017';

    /**
     * 采购录入部门与运营需求来源部门的固定对应关系。
     *
     * 后续新增城市时，在这里增加“录入部门编码 => 运营部门名称”的一项即可，
     * 不需要新增后台配置模块。
     *
     * @var array<string, list<string>>
     */
    private const PROCUREMENT_ENTRY_OPERATION_DEPARTMENT_NAMES = [
        self::PROCUREMENT_ENTRY_DEPARTMENT_SLUG => ['厦门运营部门'],
    ];

    public function assertPortal(Portal $portal, string $expectedCode): void
    {
        abort_unless($portal->code === $expectedCode, 404);

        app()->setLocale('zh-CN');
    }

    public function primaryDepartment(AdminUser $user): ?Department
    {
        $rolesTable = config('admin.database.roles_table');
        $departments = $user->departments()
            ->get(["{$rolesTable}.id", "{$rolesTable}.name", "{$rolesTable}.slug", "{$rolesTable}.parent_id"]);

        return $departments->first(static fn (Department $department): bool => (bool) ($department->pivot->is_primary ?? false))
            ?: $departments->sortBy('id')->first();
    }

    /** @return array{user_id:int,user_name:string,department_id:?int,department_name:?string,display_name:string,root_department_id:?int} */
    public function actorSnapshot(AdminUser $user): array
    {
        $department = $this->primaryDepartment($user);
        $departmentName = $department?->name;

        return [
            'user_id' => (int) $user->getKey(),
            'user_name' => (string) $user->name,
            'department_id' => $department ? (int) $department->getKey() : null,
            'department_name' => $departmentName,
            'display_name' => $departmentName
                ? $departmentName.' - '.$user->name
                : (string) $user->name,
            'root_department_id' => $department ? $this->rootDepartmentId($department) : null,
        ];
    }

    public function applyOperationVisibility(Builder $query, AdminUser $user): Builder
    {
        if ($user->isAdministrator()) {
            return $query;
        }

        $rolesTable = config('admin.database.roles_table');
        $memberships = $user->departments()
            ->get(["{$rolesTable}.id", "{$rolesTable}.parent_id"]);
        $rootManagerIds = [];
        $departmentManagerIds = [];

        foreach ($memberships as $department) {
            $position = (string) ($department->pivot->position ?? Department::POSITION_MEMBER);
            if ($department->parent_id === null && $position === Department::POSITION_MANAGER) {
                $rootManagerIds[] = (int) $department->getKey();
                continue;
            }

            if (in_array($position, [Department::POSITION_MANAGER, Department::POSITION_DEPUTY], true)) {
                $departmentManagerIds = array_merge(
                    $departmentManagerIds,
                    $this->descendantDepartmentIds((int) $department->getKey()),
                );
            }
        }

        $rootManagerIds = array_values(array_unique($rootManagerIds));
        $departmentManagerIds = array_values(array_unique($departmentManagerIds));

        return $query->where(function (Builder $visible) use ($user, $rootManagerIds, $departmentManagerIds): void {
            $visible->where('operation_user_id', $user->getKey());

            if ($rootManagerIds !== []) {
                $visible->orWhereIn('operation_root_department_id', $rootManagerIds);
            }

            if ($departmentManagerIds !== []) {
                $visible->orWhereIn('operation_department_id', $departmentManagerIds);
            }
        });
    }

    public function canViewOperationRequest(ProductRequest $productRequest, AdminUser $user): bool
    {
        return $this->applyOperationVisibility(ProductRequest::query()->whereKey($productRequest->getKey()), $user)->exists();
    }

    public function canEditPlacement(ProductRequest $productRequest, AdminUser $user): bool
    {
        return (int) $productRequest->operation_user_id === (int) $user->getKey()
            && $productRequest->status === ProductRequest::STATUS_INSPECTION_PASSED
            && $productRequest->placement_review_status !== ProductRequest::REVIEW_PENDING;
    }

    public function canInspectProcurement(AdminUser $user): bool
    {
        return $this->isProcurementRootManager($user)
            || $this->hasAnyDirectDepartmentSlug($user, $this->procurementEntryDepartmentSlugs());
    }

    public function canReviewPlacement(AdminUser $user): bool
    {
        return $this->isProcurementRootManager($user)
            || $this->hasDirectDepartmentSlug($user, self::PROCUREMENT_REVIEW_DEPARTMENT_SLUG);
    }

    public function applyProcurementVisibility(Builder $query, AdminUser $user): Builder
    {
        if ($this->canViewAllProcurementRequests($user)) {
            return $query;
        }

        $operationDepartmentIds = $this->mappedOperationDepartmentIds($user);

        if ($operationDepartmentIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('operation_department_id', $operationDepartmentIds);
    }

    public function canViewProcurementRequest(ProductRequest $productRequest, AdminUser $user): bool
    {
        return $this->applyProcurementVisibility(
            ProductRequest::query()->whereKey($productRequest->getKey()),
            $user,
        )->exists();
    }

    public function canViewRequestFromPortal(ProductRequest $productRequest, AdminUser $user, Portal $portal): bool
    {
        if ($user->isAdministrator()) {
            return true;
        }

        return match ($portal->code) {
            self::OPERATION_PORTAL_CODE => $this->canViewOperationRequest($productRequest, $user),
            self::PROCUREMENT_PORTAL_CODE => $this->canViewProcurementRequest($productRequest, $user),
            self::WAREHOUSE_PORTAL_CODE => true,
            default => false,
        };
    }

    /** @return list<int> */
    private function descendantDepartmentIds(int $departmentId): array
    {
        $departments = Department::business()->get(['id', 'parent_id']);
        $children = $departments->groupBy(static fn (Department $department): int => (int) ($department->parent_id ?: 0));
        $ids = [];

        $append = function (int $parentId) use (&$append, $children, &$ids): void {
            $ids[] = $parentId;
            foreach ($children->get($parentId, collect()) as $child) {
                $append((int) $child->getKey());
            }
        };

        $append($departmentId);

        return array_values(array_unique($ids));
    }

    private function rootDepartmentId(Department $department): int
    {
        $departments = Department::business()->get(['id', 'parent_id'])->keyBy('id');
        $current = $departments->get((int) $department->getKey()) ?: $department;
        $seen = [];

        while ($current->parent_id && !in_array((int) $current->parent_id, $seen, true)) {
            $seen[] = (int) $current->getKey();
            $parent = $departments->get((int) $current->parent_id);
            if (!$parent) {
                break;
            }
            $current = $parent;
        }

        return (int) $current->getKey();
    }

    private function hasDirectDepartmentSlug(AdminUser $user, string $slug): bool
    {
        return $user->departments()->where('slug', $slug)->exists();
    }

    /** @param list<string> $slugs */
    private function hasAnyDirectDepartmentSlug(AdminUser $user, array $slugs): bool
    {
        return $slugs !== [] && $user->departments()->whereIn('slug', $slugs)->exists();
    }

    /** @return list<string> */
    private function procurementEntryDepartmentSlugs(): array
    {
        return array_keys(self::PROCUREMENT_ENTRY_OPERATION_DEPARTMENT_NAMES);
    }

    private function canViewAllProcurementRequests(AdminUser $user): bool
    {
        return $this->isProcurementRootManager($user)
            || $this->hasDirectDepartmentSlug($user, self::PROCUREMENT_REVIEW_DEPARTMENT_SLUG);
    }

    /** @return list<int> */
    private function mappedOperationDepartmentIds(AdminUser $user): array
    {
        $entrySlugs = $user->departments()
            ->whereIn('slug', $this->procurementEntryDepartmentSlugs())
            ->pluck('slug')
            ->all();
        $operationDepartmentNames = [];

        foreach ($entrySlugs as $entrySlug) {
            $operationDepartmentNames = array_merge(
                $operationDepartmentNames,
                self::PROCUREMENT_ENTRY_OPERATION_DEPARTMENT_NAMES[$entrySlug] ?? [],
            );
        }

        if ($operationDepartmentNames === []) {
            return [];
        }

        $operationDepartmentIds = Department::business()
            ->whereIn('name', array_values(array_unique($operationDepartmentNames)))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        $scopedDepartmentIds = [];

        foreach ($operationDepartmentIds as $operationDepartmentId) {
            $scopedDepartmentIds = array_merge(
                $scopedDepartmentIds,
                $this->descendantDepartmentIds($operationDepartmentId),
            );
        }

        return array_values(array_unique($scopedDepartmentIds));
    }

    private function isProcurementRootManager(AdminUser $user): bool
    {
        if ($user->isAdministrator()) {
            return true;
        }

        $childDepartment = Department::query()
            ->whereIn('slug', [...$this->procurementEntryDepartmentSlugs(), self::PROCUREMENT_REVIEW_DEPARTMENT_SLUG])
            ->orderBy('id')
            ->first(['id', 'parent_id']);

        if (!$childDepartment || !$childDepartment->parent_id) {
            return false;
        }

        return $user->departments()
            ->whereKey($childDepartment->parent_id)
            ->wherePivot('position', Department::POSITION_MANAGER)
            ->exists();
    }
}
