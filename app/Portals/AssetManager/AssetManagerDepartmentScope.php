<?php

namespace App\Portals\AssetManager;

use App\Models\AdminUser;
use App\Portals\Assistant\AssistantPortalAuthorizationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

final class AssetManagerDepartmentScope
{
    public function __construct(private AssistantPortalAuthorizationService $authorization) {}

    public function all(AdminUser $user): bool
    {
        return $this->authorization->canManageAllDomainStores($user);
    }

    public function departments(AdminUser $user): array
    {
        $departments = $this->all($user)
            ? $this->authorization->assistantChildDepartments()
            : collect([$this->authorization->assistantChildDepartmentFor($user)])->filter();

        return $departments->mapWithKeys(fn ($d) => [$d->id => $d->name])->all();
    }

    public function ensureMember(AdminUser $user): void
    {
        abort_unless($this->all($user) || $this->authorization->assistantChildDepartmentFor($user) !== null, 403);
    }

    public function apply(Builder $query, AdminUser $user, ?string $column = null): Builder
    {
        if ($this->all($user)) {
            return $query;
        }

        return $query->whereIn($column ?? $query->getModel()->qualifyColumn('owner_department_id'), array_keys($this->departments($user)));
    }

    public function owner(AdminUser $user, array $input): int
    {
        $options = $this->departments($user);
        // An ordinary member always creates within their own Assistant department.
        if (! $this->all($user) && ! array_key_exists('owner_department_id', $input) && count($options) === 1) {
            return (int) array_key_first($options);
        }
        $data = validator($input, [
            'owner_department_id' => ['required', 'integer', Rule::in(array_keys($options))],
        ], [], ['owner_department_id' => __('asset_manager.fields.owner_department_id')])->validate();

        return (int) $data['owner_department_id'];
    }
}
