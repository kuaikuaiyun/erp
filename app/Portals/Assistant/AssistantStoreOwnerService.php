<?php

namespace App\Portals\Assistant;

use App\Models\AdminUser;
use App\Models\AssistantDepartmentOperationScope;
use App\Models\Department;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AssistantStoreOwnerService
{
    public const OPERATION_DEPARTMENT_CODE = 'dept-000022';

    /** @return Collection<int, Department> */
    public function operationDepartments(): Collection
    {
        $root = Department::business()->where('slug', self::OPERATION_DEPARTMENT_CODE)->first();

        if (!$root) {
            return collect();
        }

        $departments = Department::business()
            ->orderBy('sort')
            ->orderBy('name')
            ->get()
            ->keyBy('id');
        $result = collect();
        $this->appendDepartmentTree($result, $departments, $root, 0);

        return $result->values();
    }

    /** @return array<int, list<int>> */
    public function scopeMap(): array
    {
        return AssistantDepartmentOperationScope::query()
            ->orderBy('operation_department_id')
            ->get(['assistant_department_id', 'operation_department_id'])
            ->groupBy('assistant_department_id')
            ->map(fn (Collection $rows): array => $rows->pluck('operation_department_id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->values()
                ->all())
            ->all();
    }

    /**
     * @param array<int, list<int>> $scopeMap
     */
    public function replaceScopes(array $scopeMap, AdminUser $actor): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');

        DB::connection($connection)->transaction(function () use ($scopeMap, $actor): void {
            AssistantDepartmentOperationScope::query()->delete();
            $now = now();
            $rows = [];

            foreach ($scopeMap as $assistantDepartmentId => $operationDepartmentIds) {
                foreach (array_unique($operationDepartmentIds) as $operationDepartmentId) {
                    $rows[] = [
                        'assistant_department_id' => $assistantDepartmentId,
                        'operation_department_id' => $operationDepartmentId,
                        'last_operator_id' => $actor->getKey(),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            if ($rows !== []) {
                AssistantDepartmentOperationScope::query()->insert($rows);
            }
        });
    }

    /** @return Collection<int, AdminUser> */
    public function eligibleUsersForAssistantDepartment(int $assistantDepartmentId): Collection
    {
        $configuredIds = AssistantDepartmentOperationScope::query()
            ->where('assistant_department_id', $assistantDepartmentId)
            ->pluck('operation_department_id')
            ->map(static fn (mixed $id): int => (int) $id);

        if ($configuredIds->isEmpty()) {
            return collect();
        }

        $allowedDepartmentIds = $this->departmentIdsIncludingDescendants($configuredIds->all());

        return AdminUser::query()
            ->where('status', AdminUser::STATUS_ACTIVE)
            ->whereHas('departments', fn ($query) => $query->whereIn('admin_roles.id', $allowedDepartmentIds))
            ->with(['departments' => fn ($query) => $query
                ->whereIn('admin_roles.id', $allowedDepartmentIds)
                ->orderBy('admin_roles.sort')
                ->orderBy('admin_roles.name')])
            ->orderBy('name')
            ->orderBy('username')
            ->get()
            ->unique('id')
            ->values();
    }

    /** @param list<int> $assistantDepartmentIds
     *  @return Collection<int, AdminUser>
     */
    public function eligibleUsersForDepartments(array $assistantDepartmentIds): Collection
    {
        $departmentIds = collect($assistantDepartmentIds)->unique()->values();

        if ($departmentIds->isEmpty()) {
            return collect();
        }

        $sets = $departmentIds
            ->map(fn (int $departmentId): Collection => $this->eligibleUsersForAssistantDepartment($departmentId)->keyBy('id'));
        $eligibleIds = $sets->shift()?->keys() ?? collect();

        foreach ($sets as $set) {
            $eligibleIds = $eligibleIds->intersect($set->keys());
        }

        if ($eligibleIds->isEmpty()) {
            return collect();
        }

        return $this->eligibleUsersForAssistantDepartment((int) $departmentIds->first())
            ->whereIn('id', $eligibleIds->all())
            ->values();
    }

    /**
     * Existing assignments are preserved when a manager narrows a scope.
     * This only identifies assignments that should be shown with a warning.
     *
     * @param Collection<int, \App\Models\AssistantDomainStore> $stores
     * @return list<int>
     */
    public function outOfScopeStoreIds(Collection $stores): array
    {
        return $stores
            ->filter(static fn ($store): bool => $store->owner_user_id !== null && $store->owner_department_id !== null)
            ->groupBy('owner_department_id')
            ->flatMap(function (Collection $departmentStores, mixed $assistantDepartmentId): Collection {
                $eligibleUserIds = $this->eligibleUsersForAssistantDepartment((int) $assistantDepartmentId)
                    ->pluck('id')
                    ->map(static fn (mixed $id): int => (int) $id);

                return $departmentStores
                    ->reject(static fn ($store): bool => $eligibleUserIds->contains((int) $store->owner_user_id))
                    ->pluck('id');
            })
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    /** @param list<int> $departmentIds
     *  @return list<int>
     */
    private function departmentIdsIncludingDescendants(array $departmentIds): array
    {
        $all = Department::business()->get(['id', 'parent_id']);
        $selected = collect($departmentIds)->map(static fn (mixed $id): int => (int) $id)->unique();
        $result = $selected;

        do {
            $children = $all->whereIn('parent_id', $result->all())->pluck('id')->map(static fn (mixed $id): int => (int) $id);
            $expanded = $result->merge($children)->unique()->values();
            $changed = $expanded->count() !== $result->count();
            $result = $expanded;
        } while ($changed);

        return $result->all();
    }

    /** @param Collection<int, Department> $result
     *  @param Collection<int, Department> $departments
     */
    private function appendDepartmentTree(Collection $result, Collection $departments, Department $department, int $depth): void
    {
        $department->setAttribute('tree_depth', $depth);
        $result->push($department);

        foreach ($departments->where('parent_id', $department->getKey()) as $child) {
            $this->appendDepartmentTree($result, $departments, $child, $depth + 1);
        }
    }
}
