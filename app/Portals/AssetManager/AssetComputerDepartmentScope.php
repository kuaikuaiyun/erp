<?php

namespace App\Portals\AssetManager;

use App\Models\AdminUser;
use App\Models\Department;
use App\Services\DepartmentAuthorizationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

final class AssetComputerDepartmentScope
{
    public function __construct(private DepartmentAuthorizationService $authorization) {}

    public function departments(AdminUser $user): array
    {
        $query = Department::business()->with('parent')->orderBy('sort')->orderBy('name');
        if (! $user->isAdministrator()) {
            $ids = $user->departments()->pluck('id')->merge($this->authorization->managedDepartmentIds($user))->unique()->all();
            $query->whereIn('id', $ids);
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

        return $user->can('asset-manager.computers.manage')
            ? $this->authorization->managedDepartmentIds($user)->all() : [];
    }

    public function canManage(AdminUser $user): bool
    {
        return $user->isAdministrator() || $this->writableDepartmentIds($user) !== [];
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
