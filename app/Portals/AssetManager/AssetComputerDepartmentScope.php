<?php

namespace App\Portals\AssetManager;

use App\Models\AdminUser;
use App\Models\Department;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class AssetComputerDepartmentScope
{
    public function __construct(private AssetComputerDepartmentBindingService $bindings) {}

    public function departments(AdminUser $user): array
    {
        $query = Department::business()->with('parent')->orderBy('sort')->orderBy('name');
        if (! $user->isAdministrator()) {
            $query->whereIn('id', $this->bindings->departmentIds($user));
        }

        return $query->get()->mapWithKeys(fn ($d) => [$d->id => ($d->parent ? $d->parent->name.' / ' : '').$d->name])->all();
    }

    public function apply(Builder $query, AdminUser $user): Builder
    {
        return $user->isAdministrator() ? $query : $query->whereIn($query->getModel()->qualifyColumn('owner_department_id'), array_keys($this->departments($user)));
    }

    public function writableDepartmentIds(AdminUser $user): array
    {
        if ($user->isAdministrator()) {
            return array_keys($this->departments($user));
        }

        return $this->bindings->departmentIds($user, true);
    }

    public function canManage(AdminUser $user): bool
    {
        return $user->isAdministrator() || $this->writableDepartmentIds($user) !== [];
    }

    public function users(AdminUser $user, ?int $departmentId = null, bool $write = false): array
    {
        $ids = $write ? $this->writableDepartmentIds($user) : array_keys($this->departments($user));
        if ($departmentId !== null) {
            $ids = array_values(array_intersect($ids, [$departmentId]));
        }
        $query = AdminUser::query();
        if (! $user->isAdministrator() || $departmentId !== null) {
            $query->whereHas('departments', fn ($q) => $q->whereIn('id', $ids));
        }

        return $query->orderBy('name')->orderBy('id')->get(['id', 'name', 'username'])
            ->mapWithKeys(fn ($member) => [$member->id => $member->name.' ('.$member->username.')'])->all();
    }

    public function userDepartments(AdminUser $user, array $userIds): array
    {
        return $this->bindings->database()->table(config('admin.database.role_users_table'))
            ->whereIn('user_id', $userIds)->whereIn('role_id', array_keys($this->departments($user)))
            ->get(['user_id', 'role_id'])->groupBy('user_id')
            ->map(fn ($rows) => $rows->pluck('role_id')->map(fn ($id) => (int) $id)->unique()->values()->all())->all();
    }

    public function validateUser(AdminUser $user, ?int $userId, int $departmentId, ?array $options = null): void
    {
        if ($userId !== null && ! array_key_exists($userId, $options ?? $this->users($user, $departmentId, true))) {
            throw ValidationException::withMessages(['user_id' => __('asset_manager.computer_user_department_invalid')]);
        }
    }

    public function ensureWritable(AdminUser $user, ?int $departmentId): void
    {
        abort_unless($user->isAdministrator() || in_array($departmentId, $this->writableDepartmentIds($user), true), 403);
    }

    public function owner(AdminUser $user, array $input): int
    {
        $options = array_intersect_key($this->departments($user), array_flip($this->writableDepartmentIds($user)));
        if (! $user->isAdministrator() && ! array_key_exists('owner_department_id', $input) && count($options) === 1) {
            return (int) array_key_first($options);
        }
        $data = validator($input, ['owner_department_id' => ['required', 'integer', Rule::in(array_keys($options))]], [], ['owner_department_id' => __('asset_manager.fields.owner_department_id')])->validate();

        return (int) $data['owner_department_id'];
    }
}
