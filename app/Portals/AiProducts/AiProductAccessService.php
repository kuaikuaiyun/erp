<?php

namespace App\Portals\AiProducts;

use App\Models\AdminUser;
use App\Models\Department;
use App\Models\Portal;
use Illuminate\Database\Eloquent\Builder;

class AiProductAccessService
{
    public const OPERATION_PORTAL_CODE = 'yunying';

    public const REVIEW_PORTAL_CODE = 'caigou';

    /** 入录审核部门树根节点 */
    public const PROCUREMENT_TREE_ROOT_SLUG = 'dept-000015';

    public function assertPortal(Portal $portal, string $expectedCode): void
    {
        abort_unless($portal->code === $expectedCode, 404);

        app()->setLocale('zh-CN');
    }

    /** @return array{user_id:int,user_name:string,department_id:?int,department_name:?string,display_name:string} */
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
        ];
    }

    /** 运营端列表可见性：管理员可见全部，普通用户仅可见自己提交的记录 */
    public function applyOperationVisibility(Builder $query, AdminUser $user): Builder
    {
        if ($user->isAdministrator()) {
            return $query;
        }

        return $query->where('operation_user_id', $user->getKey());
    }

    /** AI 产品投放链接审核权限：管理员或入录审核部门树内任意部门成员 */
    public function canReview(AdminUser $user): bool
    {
        return $user->isAdministrator() || $this->belongsToProcurementTree($user);
    }

    public function primaryDepartment(AdminUser $user): ?Department
    {
        $rolesTable = config('admin.database.roles_table');
        $departments = $user->departments()
            ->get(["{$rolesTable}.id", "{$rolesTable}.name", "{$rolesTable}.short_name", "{$rolesTable}.slug", "{$rolesTable}.parent_id"]);

        return $departments->first(static fn (Department $department): bool => (bool) ($department->pivot->is_primary ?? false))
            ?: $departments->sortBy('id')->first();
    }

    private function belongsToProcurementTree(AdminUser $user): bool
    {
        $root = Department::business()->where('slug', self::PROCUREMENT_TREE_ROOT_SLUG)->first(['id']);
        if (!$root) {
            return false;
        }

        return $user->departments()
            ->whereIn('id', $this->descendantDepartmentIds((int) $root->getKey()))
            ->exists();
    }

    /** @return list<int> 部门自身及全部后代部门 ID */
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
}
