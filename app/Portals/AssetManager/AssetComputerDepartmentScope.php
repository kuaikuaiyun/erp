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

    public function owner(AdminUser $user, array $input): int
    {
        $options = $this->departments($user);
        if (! $user->isAdministrator() && ! array_key_exists('owner_department_id', $input) && count($options) === 1) {
            return (int) array_key_first($options);
        }
        $data = validator($input, ['owner_department_id' => ['required', 'integer', Rule::in(array_keys($options))]], [], ['owner_department_id' => __('asset_manager.fields.owner_department_id')])->validate();

        return (int) $data['owner_department_id'];
    }
}
