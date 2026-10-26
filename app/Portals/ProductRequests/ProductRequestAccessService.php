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

    public const PROCUREMENT_TREE_ROOT_SLUG = 'dept-000015';
    public const PROCUREMENT_REVIEW_DEPARTMENT_SLUG = 'dept-000017';
    public const PROCUREMENT_ENTRY_DEPARTMENT_SLUG = 'dept-000016';

    /** 运营部门根节点（含厦门/郑州/西安/深圳运营等子部门） */
    public const OPERATION_DEPARTMENT_ROOT_SLUG = 'dept-000022';
    /** 雨果团队（运营部门下独立团队，不可见已有产品库） */
    public const HUGO_DEPARTMENT_SLUG = 'dept-000031';

    public function assertPortal(Portal $portal, string $expectedCode): void
    {
        abort_unless($portal->code === $expectedCode, 404);

        app()->setLocale('zh-CN');
    }

    public function primaryDepartment(AdminUser $user): ?Department
    {
        $rolesTable = config('admin.database.roles_table');
        $departments = $user->departments()
            ->get(["{$rolesTable}.id", "{$rolesTable}.name", "{$rolesTable}.short_name", "{$rolesTable}.slug", "{$rolesTable}.parent_id"]);

        return $departments->first(static fn (Department $department): bool => (bool) ($department->pivot->is_primary ?? false))
            ?: $departments->sortBy('id')->first();
    }

    /** @return array{user_id:int,user_name:string,department_id:?int,department_name:?string,department_short_name:?string,display_name:string,root_department_id:?int} */
    public function actorSnapshot(AdminUser $user): array
    {
        $department = $this->primaryDepartment($user);
        $departmentName = $department?->name;
        $departmentShortName = trim((string) $department?->short_name) ?: $departmentName;

        return [
            'user_id' => (int) $user->getKey(),
            'user_name' => (string) $user->name,
            'department_id' => $department ? (int) $department->getKey() : null,
            'department_name' => $departmentName,
            'department_short_name' => $departmentShortName,
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
            && $productRequest->placement_review_status !== ProductRequest::REVIEW_PENDING
            && $productRequest->placement_review_status !== ProductRequest::REVIEW_APPROVED;
    }

    public function canInspectProcurement(AdminUser $user): bool
    {
        return $this->isProcurementRootManager($user)
            || $this->belongsToProcurementTree($user);
    }

    public function canReviewPlacement(AdminUser $user): bool
    {
        return $this->isProcurementRootManager($user)
            || $this->hasDirectDepartmentSlug($user, self::PROCUREMENT_REVIEW_DEPARTMENT_SLUG);
    }

    /** PCL SKU 管理：管理员、入录审核部门树内主管/副主管（含审核主管）、录入部门内的录入人员可见可用 */
    public function canManageLinkSkus(AdminUser $user): bool
    {
        if ($user->isAdministrator() || $this->isProcurementTreeSupervisor($user)) {
            return true;
        }

        // 录入人员：录入部门（含其后代部门）内的成员
        $entryIds = $this->assignableDepartmentIds();

        return $entryIds !== []
            && $user->departments()->whereIn('id', $entryIds)->exists();
    }

    /** 导入基础数据：boss 账号与入录审核部门树内主管/副主管（含录入主管、审核主管）可用 */
    public function canImportLinkSkus(AdminUser $user): bool
    {
        return (string) $user->username === 'kky_boss'
            || $this->isProcurementTreeSupervisor($user);
    }

    /** 已有产品库（运营系统）：运营部门树内用户可见，雨果团队（dept-000031）不可见 */
    public function canViewExistingProductLibrary(AdminUser $user): bool
    {
        if ($user->isAdministrator()) {
            return true;
        }

        // 雨果团队不可见
        if ($this->hasDirectDepartmentSlug($user, self::HUGO_DEPARTMENT_SLUG)) {
            return false;
        }

        // 运营部门树内（含厦门/郑州/西安/深圳运营子部门）可见
        $root = Department::business()->where('slug', self::OPERATION_DEPARTMENT_ROOT_SLUG)->first(['id']);
        if (!$root) {
            return false;
        }

        return $user->departments()
            ->whereIn('id', $this->descendantDepartmentIds((int) $root->getKey()))
            ->exists();
    }

    /** 是否入录审核部门树（dept-000015 及后代，含录入部门、审核部门）内的主管/副主管 */
    private function isProcurementTreeSupervisor(AdminUser $user): bool
    {
        $root = Department::business()->where('slug', self::PROCUREMENT_TREE_ROOT_SLUG)->first(['id']);
        if (!$root) {
            return false;
        }

        return $user->departments()
            ->whereIn('id', $this->descendantDepartmentIds((int) $root->getKey()))
            ->wherePivotIn('position', [Department::POSITION_MANAGER, Department::POSITION_DEPUTY])
            ->exists();
    }

    /** 入录审核部门树内的所有部门都可查看运营部门提报的数据，不再按“录入部门 => 运营部门”一对一映射 */
    public function applyProcurementVisibility(Builder $query, AdminUser $user): Builder
    {
        if ($this->belongsToProcurementTree($user)) {
            return $query;
        }

        return $query->whereRaw('1 = 0');
    }

    public function canViewProcurementRequest(ProductRequest $productRequest, AdminUser $user): bool
    {
        return $this->applyProcurementVisibility(
            ProductRequest::query()->whereKey($productRequest->getKey()),
            $user,
        )->exists();
    }

    /** 商品信息检验是否可分配录入人员：管理员与入录审核部门树内任意部门的主管 */
    public function canAssignProcurement(AdminUser $user): bool
    {
        if ($user->isAdministrator()) {
            return true;
        }

        $root = Department::business()->where('slug', self::PROCUREMENT_TREE_ROOT_SLUG)->first(['id']);
        if (!$root) {
            return false;
        }

        return $user->departments()
            ->whereIn('id', $this->descendantDepartmentIds((int) $root->getKey()))
            ->wherePivot('position', Department::POSITION_MANAGER)
            ->exists();
    }

    /**
     * 商品信息检验（分配）可见性：管理员与树内主管可查看全部记录；
     * 树内普通成员仅可查看已分配给自己的记录（待分配记录对其不可见）；树外用户无数据。
     */
    public function applyInspectionVisibility(Builder $query, AdminUser $user): Builder
    {
        if ($this->canAssignProcurement($user)) {
            return $query;
        }

        if ($this->belongsToProcurementTree($user)) {
            return $query->where('procurement_assignee_id', $user->getKey());
        }

        return $query->whereRaw('1 = 0');
    }

    public function canViewInspectionRequest(ProductRequest $productRequest, AdminUser $user): bool
    {
        return $this->applyInspectionVisibility(
            ProductRequest::query()->whereKey($productRequest->getKey()),
            $user,
        )->exists();
    }

    /** 是否可被分配为录入人员：仅「录入部门」（含其后代部门）内的普通成员，主管/副主管与管理员不参与分配 */
    public function isAssignableUser(AdminUser $user): bool
    {
        if ($user->isAdministrator()) {
            return false;
        }

        $departmentIds = $this->assignableDepartmentIds();
        if ($departmentIds === []) {
            return false;
        }

        return $user->departments()
            ->whereIn('id', $departmentIds)
            ->wherePivot('position', Department::POSITION_MEMBER)
            ->exists();
    }

    /** @return list<array{id:int,name:string,display_name:string,department_name:?string}> 「录入部门」内可被分配的录入人员（仅普通成员） */
    public function assignableUsers(): array
    {
        $departmentIds = $this->assignableDepartmentIds();
        if ($departmentIds === []) {
            return [];
        }

        $users = AdminUser::query()
            ->where('status', AdminUser::STATUS_ACTIVE)
            ->whereHas('departments', function ($query) use ($departmentIds): void {
                $query->whereIn(config('admin.database.roles_table').'.id', $departmentIds);
            })
            ->orderBy('id')
            ->get();

        return $users
            ->filter(fn (AdminUser $user): bool => $this->isAssignableUser($user))
            ->map(function (AdminUser $user): array {
                $snapshot = $this->actorSnapshot($user);

                return [
                    'id' => $snapshot['user_id'],
                    'name' => $snapshot['user_name'],
                    'display_name' => $snapshot['display_name'],
                    'department_name' => $snapshot['department_name'],
                ];
            })
            ->values()
            ->all();
    }

    /** @return list<int> 可作为分配对象的部门：录入部门及其所有后代部门 */
    private function assignableDepartmentIds(): array
    {
        $entry = Department::business()->where('slug', self::PROCUREMENT_ENTRY_DEPARTMENT_SLUG)->first(['id']);

        return $entry ? $this->descendantDepartmentIds((int) $entry->getKey()) : [];
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

    /**
     * 用户是否属于入录审核部门（dept-000015）树：本部门或其任意后代部门的成员均视为树内成员。
     * 树内成员可查看运营部门提报的全部数据，无需再按城市维护部门映射。
     */
    private function belongsToProcurementTree(AdminUser $user): bool
    {
        if ($user->isAdministrator()) {
            return true;
        }

        $root = Department::business()->where('slug', self::PROCUREMENT_TREE_ROOT_SLUG)->first(['id']);
        if (!$root) {
            return false;
        }

        return $user->departments()
            ->whereIn('id', $this->descendantDepartmentIds((int) $root->getKey()))
            ->exists();
    }

    private function isProcurementRootManager(AdminUser $user): bool
    {
        if ($user->isAdministrator()) {
            return true;
        }

        $root = Department::business()->where('slug', self::PROCUREMENT_TREE_ROOT_SLUG)->first(['id']);
        if (!$root) {
            return false;
        }

        return $user->departments()
            ->whereKey($root->getKey())
            ->wherePivot('position', Department::POSITION_MANAGER)
            ->exists();
    }
}
