<?php

namespace App\Portals\Aikaipin;

use App\Models\AdminUser;
use App\Models\Department;
use App\Models\Portal;

class AikaipinPortalAuthorizationService
{
    public const PORTAL_CODE = 'aikaipin';

    public const AI_PRODUCT_DEPARTMENT_SLUG = 'dept-000029';

    public const OPERATION_DEPARTMENT_SLUG = 'dept-000022';

    public function assertPortal(Portal $portal): void
    {
        abort_unless($portal->code === self::PORTAL_CODE, 404);

        app()->setLocale('zh-CN');
    }

    public function canManageProductLibrary(AdminUser $user): bool
    {
        if ($user->isAdministrator()) {
            return true;
        }

        $department = Department::business()
            ->where('slug', self::AI_PRODUCT_DEPARTMENT_SLUG)
            ->first(['id']);

        if (!$department) {
            return false;
        }

        return $user->departments()
            ->whereIn('id', $this->departmentTreeIds((int) $department->getKey()))
            ->exists();
    }

    /** @return array{name:?string,code:string,is_available:bool} */
    public function operationVisibilitySource(): array
    {
        $department = Department::business()
            ->where('slug', self::OPERATION_DEPARTMENT_SLUG)
            ->first(['name', 'short_name', 'slug']);

        return [
            'name' => $department
                ? (trim((string) $department->short_name) ?: (string) $department->name)
                : null,
            'code' => $department?->slug ?: self::OPERATION_DEPARTMENT_SLUG,
            'is_available' => $department !== null,
        ];
    }

    /** @return list<array{id:int,name:string,code:string}> */
    public function quickAuthorizationDepartments(): array
    {
        $operationDepartment = Department::business()
            ->where('slug', self::OPERATION_DEPARTMENT_SLUG)
            ->first(['id']);

        if (!$operationDepartment) {
            return [];
        }

        return Department::business()
            ->where('parent_id', $operationDepartment->getKey())
            ->orderBy('sort')
            ->orderBy('name')
            ->get(['id', 'name', 'slug'])
            ->map(static function (Department $department): array {
                return [
                    'id' => (int) $department->getKey(),
                    'name' => (string) $department->name,
                    'code' => (string) $department->slug,
                ];
            })
            ->all();
    }

    /** @return list<int> */
    private function departmentTreeIds(int $departmentId): array
    {
        $departments = Department::business()
            ->get(['id', 'parent_id'])
            ->groupBy(static fn (Department $department): int => (int) ($department->parent_id ?: 0));
        $ids = [];

        $append = function (int $parentId) use (&$append, $departments, &$ids): void {
            $ids[] = $parentId;

            foreach ($departments->get($parentId, collect()) as $child) {
                $append((int) $child->getKey());
            }
        };

        $append($departmentId);

        return array_values(array_unique($ids));
    }
}
