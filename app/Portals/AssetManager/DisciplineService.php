<?php

namespace App\Portals\AssetManager;

use App\Models\AdminUser;
use App\Models\Department;
use App\Models\DisciplineRecord;
use App\Models\DisciplineRecordLog;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class DisciplineService
{
    public function __construct(private AssetComputerDepartmentBindingService $bindings) {}

    public function managedIds(AdminUser $user): Collection
    {
        return collect($this->bindings->departmentIds($user, true));
    }

    public function canManage(AdminUser $user): bool
    {
        return $user->isAdministrator() || $this->managedIds($user)->isNotEmpty();
    }

    public function filters(Request $request): array
    {
        $data = $request->validate([
            'month' => ['nullable', 'date_format:Y-m', 'after_or_equal:1900-01', 'before_or_equal:9999-12'],
            'department_id' => ['nullable', 'integer', 'min:1'], 'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'in:30,50,100'],
        ], __('discipline.validation'), __('discipline.attributes'));
        $data['month'] = $data['month'] ?? now('Asia/Shanghai')->format('Y-m');

        return $data;
    }

    public function days(string $month): Collection
    {
        $first = CarbonImmutable::createFromFormat('!Y-m', $month, 'Asia/Shanghai');

        return collect(range(1, $first->daysInMonth))->map(function ($day) use ($first) {
            $date = $first->day($day);

            return ['date' => $date->format('Y-m-d'), 'day' => $day, 'weekday' => __('discipline.weekdays.'.$date->dayOfWeek), 'weekend' => $date->isWeekend(), 'today' => $date->isToday()];
        });
    }

    public function visibleRecords(AdminUser $user): Builder
    {
        $query = DisciplineRecord::query();
        if (! $user->isAdministrator()) {
            $query->whereIn('department_id', $this->bindings->departmentIds($user));
        }

        return $query;
    }

    public function monthRecords(AdminUser $user, array $filters): Builder
    {
        $first = $filters['month'].'-01';
        $last = CarbonImmutable::parse($first, 'Asia/Shanghai')->endOfMonth()->format('Y-m-d');
        $query = $this->visibleRecords($user)->whereBetween('record_date', [$first, $last]);
        if (! empty($filters['department_id'])) {
            $query->where('department_id', $filters['department_id']);
        }

        return $query;
    }

    /** Union includes deleted accounts using immutable record snapshots. */
    public function membersQuery(AdminUser $user, array $filters, bool $eligibleOnly = false): \Illuminate\Database\Query\Builder
    {
        $db = (new DisciplineRecord)->getConnection();
        $users = (new AdminUser)->getTable();
        $pivot = config('admin.database.role_users_table');
        $ids = collect($this->bindings->departmentIds($user, $eligibleOnly));
        if (! empty($filters['department_id'])) {
            $ids = $ids->intersect([(int) $filters['department_id']]);
        }
        $history = $this->monthRecords($user, $filters)->select('member_id');
        $current = $db->table($users.' as u')->select('u.id', 'u.name', 'u.username');
        $current->where(function ($outer) use ($user, $ids, $pivot, $filters, $history, $eligibleOnly) {
            $outer->where(function ($active) use ($user, $ids, $pivot, $filters, $eligibleOnly) {
                $active->where('u.employment_status', 'employed');
                if ($eligibleOnly || ! $user->isAdministrator()) {
                    $active->whereExists(function ($p) use ($pivot, $ids) {
                        $p->selectRaw('1')->from($pivot.' as membership')->whereColumn('membership.user_id', 'u.id')->whereIn('membership.role_id', $ids);
                    });
                }
                if (! empty($filters['department_id'])) {
                    $active->whereExists(fn ($p) => $p->selectRaw('1')->from($pivot.' as filtered_membership')->whereColumn('filtered_membership.user_id', 'u.id')->where('filtered_membership.role_id', $filters['department_id']));
                }
            });
            if (! $eligibleOnly) {
                $outer->orWhereIn('u.id', $history);
            }
        });
        if (! empty($filters['search'])) {
            // Literal substring matching; wildcard characters are not interpreted as patterns.
            $needle = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['search']);
            $current->where(fn ($q) => $q->whereRaw("u.name LIKE ? ESCAPE '!'", ['%'.$needle.'%'])->orWhereRaw("u.username LIKE ? ESCAPE '!'", ['%'.$needle.'%']));
        }
        if ($eligibleOnly) {
            return $current->orderBy('u.id');
        }
        $deleted = $this->monthRecords($user, $filters)->toBase()
            ->selectRaw("member_id as id, MAX(member_name) as name, '' as username")
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from($users.' as existing')->whereColumn('existing.id', 'discipline_records.member_id'))
            ->groupBy('member_id');
        if (! empty($filters['search'])) {
            $deleted->whereRaw("member_name LIKE ? ESCAPE '!'", ['%'.$needle.'%']);
        }

        return $db->query()->fromSub($current->unionAll($deleted), 'members')->orderBy('id');
    }

    public function departments(AdminUser $user, bool $write = false): Collection
    {
        $query = Department::business();
        if (! $user->isAdministrator()) {
            $query->whereIn('id', $this->bindings->departmentIds($user, $write));
        }

        return $query->orderBy('name')->get(['id', 'name']);
    }

    public function memberOptions(AdminUser $user, Collection $members, ?int $departmentId = null): array
    {
        $departments = $this->departments($user, true)->keyBy('id');
        if ($departmentId !== null) {
            $departments = $departments->filter(fn ($department) => (int) $department->id === $departmentId);
        }
        $activeIds = AdminUser::whereIn('id', $members->pluck('id'))->where('employment_status', 'employed')->pluck('id');
        $pivot = (new DisciplineRecord)->getConnection()->table(config('admin.database.role_users_table'))
            ->whereIn('user_id', $members->pluck('id'))->whereIn('role_id', $departments->keys())->get()->groupBy('user_id');

        return $members->map(function ($member) use ($pivot, $departments, $activeIds) {
            $choices = $activeIds->contains($member->id)
                ? $pivot->get($member->id, collect())->pluck('role_id')->unique()->map(fn ($id) => ['id' => (int) $id, 'name' => $departments[$id]->name])->values()
                : collect();

            return ['id' => (int) $member->id, 'name' => $member->name, 'username' => $member->username, 'departments' => $choices];
        })->all();
    }

    public function summaries(AdminUser $user, array $filters, array $memberIds): Collection
    {
        $groups = $this->monthRecords($user, $filters)->whereIn('member_id', $memberIds)
            ->selectRaw('member_id, record_date, COUNT(*) as total, MIN(id) as first_id')->groupBy('member_id', 'record_date')->get();
        $first = DisciplineRecord::whereIn('id', $groups->pluck('first_id'))->pluck('remarks', 'id');

        return $groups->map(fn ($row) => ['member_id' => $row->member_id, 'date' => $row->record_date->format('Y-m-d'), 'count' => (int) $row->total, 'summary' => mb_substr($first[$row->first_id], 0, 65)]);
    }

    public function writable(AdminUser $user, DisciplineRecord $record): bool
    {
        return $user->isAdministrator() || $this->managedIds($user)->contains((int) $record->department_id);
    }

    public function payload(DisciplineRecord $record, AdminUser $user): array
    {
        return [
            'id' => $record->id, 'member_id' => $record->member_id, 'member_name' => $record->member_name,
            'department_id' => $record->department_id, 'department_name' => $record->department_name,
            'record_date' => $record->record_date->format('Y-m-d'), 'remarks' => $record->remarks,
            'created_at' => $record->created_at->timezone('Asia/Shanghai')->format('Y-m-d H:i:s'),
            'updated_at' => $record->updated_at->timezone('Asia/Shanghai')->format('Y-m-d H:i:s'),
            'writable' => $this->writable($user, $record), 'version' => $record->version,
        ];
    }

    public function create(AdminUser $user, array $data): DisciplineRecord
    {
        abort_unless($this->canManage($user), 403);

        return (new DisciplineRecord)->getConnection()->transaction(function () use ($user, $data) {
            $department = $this->departments($user, true)->firstWhere('id', (int) $data['department_id']);
            abort_unless($department, 403);
            $member = AdminUser::where('employment_status', 'employed')->whereKey($data['member_id'])->firstOrFail();
            abort_unless($member->departments()->whereKey($department->id)->exists(), 422, __('discipline.member_department_invalid'));
            $record = DisciplineRecord::create([
                'member_id' => $member->id, 'member_name' => $member->name, 'department_id' => $department->id,
                'department_name' => $department->name, 'record_date' => $data['record_date'], 'remarks' => $data['remarks'],
                'version' => (string) Str::uuid(),
                'created_by' => $user->id, 'updated_by' => $user->id, 'created_at' => now('UTC'), 'updated_at' => now('UTC'),
            ]);
            $this->log($user, $record, 'create', null);

            return $record;
        });
    }

    /** Existing member/department snapshots are never rewritten during editing. */
    public function change(AdminUser $user, int $id, array $data, bool $delete = false): void
    {
        (new DisciplineRecord)->getConnection()->transaction(function () use ($user, $id, $data, $delete) {
            $record = $this->visibleRecords($user)->whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless($this->writable($user, $record), 403);
            abort_unless(hash_equals($record->version, $data['version']), 409, __('discipline.conflict'));
            $before = $record->toArray();
            $record->updated_by = $user->id;
            $record->version = (string) Str::uuid();
            $record->updated_at = now('UTC');
            if (! $delete) {
                $record->fill(['record_date' => $data['record_date'], 'remarks' => $data['remarks']]);
            }
            $record->save();
            if ($delete) {
                $record->delete();
            }
            $this->log($user, $record, $delete ? 'delete' : 'update', $before);
        });
    }

    private function log(AdminUser $user, DisciplineRecord $record, string $action, ?array $before): void
    {
        DisciplineRecordLog::create([
            'record_id' => $record->id, 'action' => $action, 'actor_id' => $user->id, 'actor_name' => $user->name,
            'before_data' => $before, 'after_data' => $record->fresh()->toArray(), 'created_at' => now('UTC'),
        ]);
    }
}
