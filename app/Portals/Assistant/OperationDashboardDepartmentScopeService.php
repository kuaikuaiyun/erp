<?php

namespace App\Portals\Assistant;

use App\Models\AdminUser;
use App\Models\Department;
use App\Models\OperationDashboardDepartmentScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class OperationDashboardDepartmentScopeService
{
    /** @return list<int> */
    public function allDepartmentIds(): array
    {
        return Department::business()->pluck('id')
            ->map(fn ($id): int => (int) $id)->unique()->values()->all();
    }

    /** @return list<int> */
    public function visibleDepartmentIds(AdminUser $user): array
    {
        $allIds = $this->allDepartmentIds();
        if ($this->canManage($user)) {
            return $allIds;
        }

        $configuredIds = OperationDashboardDepartmentScope::query()
            ->where('user_id', $user->getKey())->pluck('department_id')
            ->map(fn ($id): int => (int) $id)->all();

        // Keep existing accounts backward compatible: no saved rows means unrestricted.
        return $configuredIds === [] ? $allIds : array_values(array_intersect($allIds, $configuredIds));
    }

    /** @return list<int> */
    public function selectedDepartmentIds(int $userId): array
    {
        return OperationDashboardDepartmentScope::query()->where('user_id', $userId)
            ->pluck('department_id')->map(fn ($id): int => (int) $id)->all();
    }

    public function configurableUsers(string $keyword = ''): Collection
    {
        return AdminUser::query()->where('status', AdminUser::STATUS_ACTIVE)
            ->when($keyword !== '', function ($query) use ($keyword): void {
                $query->where(function ($search) use ($keyword): void {
                    $search->where('username', 'like', '%'.$keyword.'%')
                        ->orWhere('name', 'like', '%'.$keyword.'%');
                });
            })
            ->orderBy('name')->orderBy('username')->get()
            ->reject(fn (AdminUser $user): bool => $this->canManage($user))->values();
    }

    /** @param list<int> $departmentIds */
    public function save(AdminUser $target, array $departmentIds, AdminUser $actor): void
    {
        $departmentIds = collect($departmentIds)->map(fn ($id): int => (int) $id)->unique()->values();
        abort_unless($departmentIds->diff($this->allDepartmentIds())->isEmpty(), 422, '选择的部门不在可配置范围内。');

        DB::connection(config('admin.database.connection') ?: config('database.default'))
            ->transaction(function () use ($target, $departmentIds, $actor): void {
                OperationDashboardDepartmentScope::query()->where('user_id', $target->getKey())->delete();
                foreach ($departmentIds as $departmentId) {
                    OperationDashboardDepartmentScope::query()->create([
                        'user_id' => $target->getKey(),
                        'department_id' => $departmentId,
                        'created_by' => $actor->getKey(),
                    ]);
                }
            });
    }

    public function canManage(AdminUser $user): bool
    {
        return strcasecmp((string) $user->username, 'kky_boss') === 0;
    }
}
