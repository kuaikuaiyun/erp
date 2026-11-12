<?php

namespace App\Services;

use App\Models\AdminUser;
use App\Models\Department;
use App\Models\OperationTaskRecap;
use App\Models\OperationTaskRectification;
use App\Models\OperationTestTask;

class OperationTaskPendingCountService
{
    public function forUser(AdminUser $user): array
    {
        $rejectedRecaps = OperationTaskRecap::query()->where('user_id', $user->id)->where('workflow_status', 'rejected')->count();
        [$channel, $departmentIds] = $this->reviewScope($user);
        $rejectedTests = $channel === null
            ? OperationTaskRectification::query()
                ->where('status', 'required')
                ->whereHas('task', fn ($query) => $query->where('owner_user_id', $user->id))
                ->distinct('task_id')->count('task_id')
            : 0;
        $recaps = $this->pendingRecapCount($user);
        if (! $user->isAdministrator() && ($channel === null || $departmentIds === [])) return ['recaps' => $recaps, 'tests' => 0, 'rejected_recaps' => $rejectedRecaps, 'rejected_tests' => $rejectedTests];

        $tests = OperationTestTask::query()
            ->whereIn('department_id', $departmentIds)
            ->whereDoesntHave('reviews', fn ($query) => $query
                ->where('target_type', 'task')
                ->whereColumn('target_version', 'operation_test_tasks.version')
                ->where('channel', $channel))
            ->count();

        return ['recaps' => $recaps, 'tests' => $tests, 'rejected_recaps' => $rejectedRecaps, 'rejected_tests' => $rejectedTests];
    }

    private function pendingRecapCount(AdminUser $user): int
    {
        $query = OperationTaskRecap::query();
        if ($user->isAdministrator()) return $query->where('department_review_status', 'pending')->count();
        $leaderIds = $user->departments()->whereNotNull('parent_id')
            ->wherePivotIn('position', [Department::POSITION_MANAGER, Department::POSITION_DEPUTY])
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
        $rootIds = $user->departments()->whereNull('parent_id')->wherePivot('position', Department::POSITION_MANAGER)
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
        $departmentIds = [];
        if ($rootIds !== []) {
            $departments = Department::business()->get(['id', 'parent_id'])->keyBy('id');
            $departmentIds = $departments->filter(function (Department $department) use ($departments, $rootIds): bool {
                $current = $department; $visited = [];
                while ($current && ! in_array((int) $current->id, $visited, true)) {
                    if (in_array((int) $current->id, $rootIds, true)) return true;
                    $visited[] = (int) $current->id;
                    $current = $current->parent_id ? $departments->get((int) $current->parent_id) : null;
                }
                return false;
            })->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        }
        $departmentIds = array_values(array_diff($departmentIds, $leaderIds));
        return $query->where(function ($scope) use ($leaderIds, $departmentIds): void {
            $scope->whereRaw('1 = 0');
            if ($leaderIds !== []) $scope->orWhere(fn ($query) => $query->whereIn('department_id', $leaderIds)->where('leader_review_status', 'pending'));
            if ($departmentIds !== []) $scope->orWhere(fn ($query) => $query->whereIn('department_id', $departmentIds)->where('department_review_status', 'pending'));
        })->count();
    }

    private function reviewScope(AdminUser $user): array
    {
        $leaderIds = $user->departments()->whereNotNull('parent_id')
            ->wherePivotIn('position', [Department::POSITION_MANAGER, Department::POSITION_DEPUTY])
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($leaderIds !== []) return ['leader', $leaderIds];

        if (! $user->isAdministrator() && ! $user->departments()->whereNull('parent_id')->wherePivot('position', Department::POSITION_MANAGER)->exists()) {
            return [null, []];
        }

        return ['department', app(DepartmentAuthorizationService::class)->managedDepartmentIds($user)->map(fn ($id) => (int) $id)->all()];
    }
}
