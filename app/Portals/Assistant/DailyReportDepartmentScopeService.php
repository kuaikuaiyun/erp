<?php

namespace App\Portals\Assistant;

use App\Models\AdminUser;
use App\Models\DailyReportDepartmentPreference;
use App\Models\Department;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DailyReportDepartmentScopeService
{
    public function __construct(
        private readonly AssistantPortalAuthorizationService $authorization,
        private readonly AssistantStoreOwnerService $storeOwners,
    ) {}

    public function canConfigure(AdminUser $user): bool
    {
        return ! $user->isAdministrator()
            && strcasecmp((string) $user->username, 'kky_boss') !== 0
            && $this->authorization->isAssistantDepartmentMember($user);
    }

    public function canAccessSettings(AdminUser $user): bool
    {
        return $user->isAdministrator()
            || strcasecmp((string) $user->username, 'kky_boss') === 0
            || $this->authorization->isAssistantDepartmentMember($user);
    }

    /** @return list<int>|null null means unrestricted BOSS or administrator access. */
    public function visibleIdsForReview(AdminUser $user): ?array
    {
        if ($user->isAdministrator() || strcasecmp((string) $user->username, 'kky_boss') === 0) {
            return null;
        }

        return $this->selectedIds($user);
    }

    /** @return list<int> */
    public function allowedIds(AdminUser $user): array
    {
        return $this->canConfigure($user)
            ? $this->storeOwners->visibleOperationDepartmentIds($user)
            : [];
    }

    /** @return Collection<int, Department> */
    public function allowedDepartments(AdminUser $user): Collection
    {
        return Department::business()->whereIn('id', $this->allowedIds($user))
            ->with('parent:id,name')->orderBy('sort')->orderBy('name')
            ->get(['id', 'parent_id', 'name']);
    }

    /** @return list<int> */
    public function selectedIds(AdminUser $user): array
    {
        $allowedIds = $this->allowedIds($user);
        if ($allowedIds === []) return [];

        $query = DailyReportDepartmentPreference::query()->where('user_id', $user->getKey());
        $hasSavedPreference = (clone $query)->exists();
        $savedIds = $query->pluck('operation_department_id')->map(fn ($id): int => (int) $id)
            ->intersect($allowedIds)->unique()->values();

        return $hasSavedPreference ? $savedIds->all() : $allowedIds;
    }

    /** @param list<int> $departmentIds */
    public function replace(AdminUser $user, array $departmentIds): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        DB::connection($connection)->transaction(function () use ($user, $departmentIds): void {
            DailyReportDepartmentPreference::query()->where('user_id', $user->getKey())->delete();
            $now = now();
            DailyReportDepartmentPreference::query()->insert(collect($departmentIds)->unique()->map(fn (int $departmentId): array => [
                'user_id' => $user->getKey(), 'operation_department_id' => $departmentId,
                'created_at' => $now, 'updated_at' => $now,
            ])->all());
        });
    }

    /** @return list<int> */
    public function selectedAssistantDepartmentIds(AdminUser $user): array
    {
        return collect($this->selectedIds($user))
            ->flatMap(fn (int $departmentId): array => $this->storeOwners->assistantDepartmentIdsForOperationDepartment($departmentId))
            ->map(fn ($id): int => (int) $id)->unique()->values()->all();
    }
}
