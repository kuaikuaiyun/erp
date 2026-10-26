<?php

namespace App\Portals\Payment;

use App\Models\AdminUser;
use App\Models\Department;
use App\Models\PaymentProductAlertAssignment;
use App\Models\PaymentProductAlertAssignmentLog;
use App\Models\Portal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentProductAlertAssignmentService
{
    /** @return array{can_assign:bool,view:string,departments:array<int,array{id:int,name:string,members:array}>} */
    public function context(Portal $portal, AdminUser $user): array
    {
        $departmentIds = $this->authorizedDepartmentIds($portal);

        return [
            'can_assign' => $this->canAssign($user, $departmentIds),
            'view' => ($user->isAdministrator() || $this->canAssign($user, $departmentIds)) ? 'management' : 'member',
            'departments' => $this->candidateDepartments($departmentIds),
        ];
    }

    public function assign(Portal $portal, AdminUser $actor, string $alertCode, array $data): PaymentProductAlertAssignment
    {
        $departmentIds = $this->authorizedDepartmentIds($portal);
        if (!$this->canAssign($actor, $departmentIds)) {
            throw new AuthorizationException(__('payment.product_alerts.assignment.forbidden'));
        }

        $departmentId = (int) $data['department_id'];
        $assigneeId = (int) $data['assignee_id'];
        if (!in_array($departmentId, $departmentIds, true)) {
            throw ValidationException::withMessages(['department_id' => __('payment.product_alerts.assignment.invalid_department')]);
        }

        $department = Department::business()->find($departmentId);
        $assignee = AdminUser::query()
            ->whereKey($assigneeId)
            ->where('status', AdminUser::STATUS_ACTIVE)
            ->whereHas('departments', fn ($query) => $query->where(config('admin.database.roles_table').'.id', $departmentId))
            ->first();
        if (!$department || !$assignee) {
            throw ValidationException::withMessages(['assignee_id' => __('payment.product_alerts.assignment.invalid_assignee')]);
        }

        $membership = $assignee->departments()->whereKey($departmentId)->first();
        $connection = config('admin.database.connection') ?: config('database.default');

        return DB::connection($connection)->transaction(function () use ($alertCode, $data, $department, $assignee, $membership, $actor): PaymentProductAlertAssignment {
            $current = PaymentProductAlertAssignment::query()->where('alert_code', $alertCode)->lockForUpdate()->first();
            $fromId = $current?->assignee_id;
            $fromName = $current?->assignee_name_snapshot;
            $assignment = $current ?: new PaymentProductAlertAssignment(['alert_code' => $alertCode]);
            $assignment->fill([
                'status' => 'in_progress',
                'department_id' => $department->getKey(),
                'department_name_snapshot' => $department->name,
                'assignee_id' => $assignee->getKey(),
                'assignee_name_snapshot' => $assignee->name,
                'assignee_position' => $membership?->pivot?->position ?: Department::POSITION_MEMBER,
                'deadline_at' => $data['deadline_at'],
                'assignment_note' => trim((string) ($data['assignment_note'] ?? '')) ?: null,
                'assigned_by' => $actor->getKey(),
                'assigned_by_name_snapshot' => $actor->name,
                'assigned_at' => now(),
            ])->save();

            PaymentProductAlertAssignmentLog::query()->create([
                'alert_code' => $alertCode,
                'event' => $fromId ? 'reassigned' : 'assigned',
                'from_assignee_id' => $fromId,
                'from_assignee_name' => $fromName,
                'to_assignee_id' => $assignee->getKey(),
                'to_assignee_name' => $assignee->name,
                'department_id' => $department->getKey(),
                'department_name_snapshot' => $department->name,
                'actor_id' => $actor->getKey(),
                'actor_name_snapshot' => $actor->name,
                'note' => trim((string) ($data['assignment_note'] ?? '')) ?: null,
            ]);

            return $assignment;
        });
    }

    /** @return list<int> */
    private function authorizedDepartmentIds(Portal $portal): array
    {
        $departments = Department::business()->get(['id', 'parent_id']);
        $children = $departments->groupBy(fn (Department $department): int => (int) ($department->parent_id ?: 0));
        $ids = [];
        foreach ($portal->departmentGrants()->get(['department_id', 'includes_descendants']) as $grant) {
            $rootId = (int) $grant->department_id;
            $ids[] = $rootId;
            if (!$grant->includes_descendants) {
                continue;
            }
            $append = function (int $parentId) use (&$append, $children, &$ids): void {
                foreach ($children->get($parentId, collect()) as $child) {
                    $ids[] = (int) $child->getKey();
                    $append((int) $child->getKey());
                }
            };
            $append($rootId);
        }

        return array_values(array_unique($ids));
    }

    /** @param list<int> $departmentIds */
    private function canAssign(AdminUser $user, array $departmentIds): bool
    {
        if ($user->isAdministrator()) {
            return true;
        }
        if ($departmentIds === []) {
            return false;
        }

        return $user->departments()
            ->whereIn(config('admin.database.roles_table').'.id', $departmentIds)
            ->wherePivotIn('position', [Department::POSITION_MANAGER, Department::POSITION_DEPUTY])
            ->exists();
    }

    /** @param list<int> $departmentIds */
    private function candidateDepartments(array $departmentIds): array
    {
        if ($departmentIds === []) {
            return [];
        }

        return Department::business()->whereIn('id', $departmentIds)->orderBy('sort')->orderBy('name')
            ->get()->map(function (Department $department): array {
                $members = $department->departmentMembers()
                    ->where('status', AdminUser::STATUS_ACTIVE)->orderBy('name')->get()
                    ->map(fn (AdminUser $user): array => [
                        'id' => (int) $user->getKey(),
                        'name' => (string) $user->name,
                        'position' => (string) ($user->pivot?->position ?: Department::POSITION_MEMBER),
                    ])->values()->all();

                return ['id' => (int) $department->getKey(), 'name' => (string) $department->name, 'members' => $members];
            })->filter(fn (array $department): bool => $department['members'] !== [])->values()->all();
    }
}
