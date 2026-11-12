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
        if ($channel === null || $departmentIds === []) return ['recaps' => 0, 'tests' => 0, 'rejected_recaps' => $rejectedRecaps, 'rejected_tests' => $rejectedTests];

        $recaps = OperationTaskRecap::query()
            ->where('workflow_status', $channel === 'leader' ? 'pending_leader' : 'pending_department')
            ->whereIn('department_id', $departmentIds)
            ->count();
        $tests = OperationTestTask::query()
            ->whereIn('department_id', $departmentIds)
            ->whereDoesntHave('reviews', fn ($query) => $query
                ->where('target_type', 'task')
                ->whereColumn('target_version', 'operation_test_tasks.version')
                ->where('channel', $channel))
            ->count();

        return ['recaps' => $recaps, 'tests' => $tests, 'rejected_recaps' => $rejectedRecaps, 'rejected_tests' => $rejectedTests];
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
