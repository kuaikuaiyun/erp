<?php

namespace App\Portals\AssetManager;

use App\Models\AdminUser;
use App\Services\DepartmentAuthorizationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class AccountAuthorizationService extends DepartmentAuthorizationService
{
    public function __construct(private AssetComputerDepartmentBindingService $bindings) {}

    public function managedDepartmentIds(AdminUser $user): Collection
    {
        return collect($this->bindings->departmentIds($user, true));
    }

    public function accounts(AdminUser $actor, bool $write = false): Builder
    {
        $query = AdminUser::query();
        if (! $actor->isAdministrator()) {
            $ids = $this->bindings->departmentIds($actor, $write);
            $query->whereDoesntHave('roles', fn ($q) => $q->where('slug', 'administrator'))
                ->whereHas('departments', fn ($q) => $q->whereIn('id', $ids));
        }

        return $query;
    }

    public function canManageAccount(AdminUser $user, AdminUser $account): bool
    {
        return $user->isAdministrator() || (! $account->isAdministrator() && $this->accounts($user, true)->whereKey($account->id)->exists());
    }

    public function canManageOrganization(AdminUser $user): bool
    {
        return $user->isAdministrator() || $this->managedDepartmentIds($user)->isNotEmpty();
    }
}
