<?php

namespace App\Portals\AssetManager;

use App\Models\AdminUser;
use App\Models\DepartmentChangeLog;
use App\Portal\Services\PortalService;
use App\Services\DepartmentOrganizationService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class AccountService
{
    private DepartmentOrganizationService $organization;

    public function __construct(private AccountAuthorizationService $authorization)
    {
        // Use a dedicated organization service instance; global authorization is unchanged.
        $this->organization = new DepartmentOrganizationService($authorization, app(PortalService::class));
    }

    public function departments(AdminUser $actor, ?AdminUser $account = null): array
    {
        return $this->organization->departmentOptionsForAccount($actor, $account)->values()->all();
    }

    public function payload(AdminUser $actor, AdminUser $account, ?Collection $writeIds = null): array
    {
        $writeIds ??= $this->authorization->managedDepartmentIds($actor);
        $rows = $account->relationLoaded('roles') ? $account->roles->where('slug', '!=', 'administrator')->map(fn ($role) => (object) [
            'department_id' => $role->id, 'department_name' => $role->name, 'position' => $role->pivot->position,
        ]) : $this->organization->membershipsForAccount($account);
        $memberships = $rows->map(fn ($m) => [
            'department_id' => (int) $m->department_id, 'department_name' => $m->department_name,
            'position' => $m->position ?: 'member', 'editable' => $actor->isAdministrator() || $writeIds->contains((int) $m->department_id),
        ])->values()->all();

        return [
            'id' => $account->id, 'username' => $account->username, 'name' => $account->name,
            'hired_at' => $account->hired_at?->format('Y-m-d'), 'resigned_at' => $account->resigned_at?->format('Y-m-d'),
            'employment_status' => $account->employment_status, 'status' => (int) $account->status,
            'administrator' => $account->isAdministrator(), 'memberships' => $memberships,
            'updated_at' => $account->updated_at?->timezone('Asia/Shanghai')->format('Y-m-d H:i:s'),
            'writable' => $actor->isAdministrator() || (! $account->isAdministrator() && collect($memberships)->contains('editable', true)),
        ];
    }

    public function save(Request $request, AdminUser $actor, ?int $id = null): AdminUser
    {
        abort_unless($this->authorization->canManageOrganization($actor), 403);
        $account = $id ? $this->authorization->accounts($actor, true)->findOrFail($id) : null;
        $system = $account?->isAdministrator() ?? false;
        $data = $request->validate([
            'username' => [$system ? 'nullable' : 'required', 'string', 'max:190', Rule::unique(AdminUser::class, 'username')->ignore($id)],
            'name' => ['required', 'string', 'max:255'],
            'password' => [$id ? 'nullable' : 'required', 'string', 'min:8', 'confirmed'],
            'status' => ['required', 'boolean'], 'employment_status' => ['required', Rule::in(['employed', 'resigned'])],
            'hired_at' => ['nullable', 'date_format:Y-m-d'], 'resigned_at' => ['nullable', 'date_format:Y-m-d'],
            'memberships' => [$system ? 'nullable' : 'required', 'array'],
            'memberships.*.department_id' => ['required', 'integer', 'distinct'],
            'memberships.*.position' => ['required', Rule::in(['manager', 'deputy', 'member'])],
        ], __('asset_accounts.validation'), __('asset_accounts.attributes'));

        return (new AdminUser)->getConnection()->transaction(function () use ($id, $account, $actor, $system, $data) {
            $current = $account ? $this->authorization->accounts($actor, true)->whereKey($id)->lockForUpdate()->firstOrFail() : new AdminUser;
            $before = $current->exists ? $this->safeSnapshot($current) : null;
            $current->name = $data['name'];
            $current->hired_at = $data['hired_at'] ?? null;
            $current->resigned_at = $data['resigned_at'] ?? null;
            $current->employment_status = $data['employment_status'];
            if (! $system) {
                $current->username = $data['username'];
                $current->status = (int) $data['status'];
            }
            if (! empty($data['password'])) {
                $current->password = Hash::make($data['password']);
            }
            $current->save();
            if (! $system) {
                $this->organization->syncAccountMemberships($current, $data['memberships'], $actor);
            }
            DepartmentChangeLog::create([
                'actor_id' => $actor->id, 'department_id' => null, 'event' => $id ? 'hr_account_updated' : 'hr_account_created',
                'payload' => json_encode(['user_id' => $current->id, 'before' => $before, 'after' => $this->safeSnapshot($current), 'password_changed' => ! empty($data['password'])], JSON_UNESCAPED_UNICODE),
            ]);

            return $current;
        });
    }

    private function safeSnapshot(AdminUser $account): array
    {
        return $account->only(['id', 'username', 'name', 'status', 'employment_status', 'hired_at', 'resigned_at']);
    }

    public function delete(AdminUser $actor, int $id): void
    {
        abort_unless($this->authorization->canManageOrganization($actor), 403);
        (new AdminUser)->getConnection()->transaction(function () use ($actor, $id) {
            $account = $this->authorization->accounts($actor, true)->whereKey($id)->lockForUpdate()->firstOrFail();
            $this->organization->deleteAccount($account, $actor);
        });
    }

    public function permissions(AdminUser $account): array
    {
        return ['tree' => $this->organization->pagePermissionTree(), 'overrides' => $this->organization->assignedUserPageOverrides($account)];
    }

    public function savePermissions(AdminUser $actor, AdminUser $account, array $overrides): void
    {
        abort_unless($actor->isAdministrator() && ! $account->isAdministrator(), 403);
        $this->organization->saveUserPageOverrides($account, $overrides, $actor);
    }
}
