<?php

namespace App\Services;

use App\Models\AdminUser;
use App\Models\Department;
use App\Models\DepartmentChangeLog;
use App\Models\DepartmentPermissionScope;
use App\Portal\Services\PortalService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DepartmentOrganizationService
{
    private const ASSISTANT_ROOT_DEPARTMENT_CODE = 'dept-000004';

    public function __construct(
        private readonly DepartmentAuthorizationService $authorization,
        private readonly PortalService $portals,
    ) {
    }

    /** @var array<string, int|null> */
    private array $positionSlots = [
        Department::POSITION_MANAGER => 1,
        Department::POSITION_DEPUTY => null,
        Department::POSITION_MEMBER => null,
    ];

    public function departmentTree(): array
    {
        $departments = Department::business()
            ->orderBy('sort')
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'parent_id', 'sort', 'created_at', 'updated_at']);
        $counts = $this->membershipCounts();
        $pageCounts = $this->pageCounts();
        $byParent = $departments->groupBy(fn (Department $department) => $department->parent_id ?: 0);

        $build = function (int $parentId, int $level) use (&$build, $byParent, $counts, $pageCounts): array {
            return $byParent->get($parentId, collect())
                ->map(function (Department $department) use (&$build, $level, $counts, $pageCounts): array {
                    $children = $build((int) $department->id, $level + 1);

                    return [
                        'department' => $department,
                        'level' => $level,
                        'children' => $children,
                        'counts' => $counts->get((int) $department->id, $this->emptyCounts()),
                        'page_count' => (int) $pageCounts->get((int) $department->id, 0),
                        'is_branch' => $children !== [],
                    ];
                })
                ->all();
        };

        return $build(0, 1);
    }

    public function departmentOptions(): Collection
    {
        $departments = Department::business()
            ->orderBy('sort')
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'parent_id', 'sort']);
        $byParent = $departments->groupBy(fn (Department $department) => $department->parent_id ?: 0);
        $options = collect();

        $append = function (int $parentId, int $level, string $prefix = '') use (&$append, $byParent, $options): void {
            foreach ($byParent->get($parentId, collect()) as $department) {
                $hasChildren = $byParent->has((int) $department->id);
                $options->push([
                    'id' => (int) $department->id,
                    'name' => $department->name,
                    'label' => $prefix.$department->name,
                    'level' => $level,
                    'is_branch' => $hasChildren,
                    'parent_id' => $department->parent_id ? (int) $department->parent_id : null,
                ]);
                $append((int) $department->id, $level + 1, $prefix.'— ');
            }
        };

        $append(0, 1);

        return $options;
    }

    public function departmentOptionsFor(AdminUser $actor): Collection
    {
        if ($actor->isAdministrator()) {
            return $this->departmentOptions();
        }

        $managedDepartmentIds = $this->authorization->managedDepartmentIds($actor);

        return $this->departmentOptions()
            ->filter(fn (array $option) => $managedDepartmentIds->contains((int) $option['id']))
            ->map(function (array $option) use ($managedDepartmentIds): array {
                if ($option['parent_id'] && !$managedDepartmentIds->contains((int) $option['parent_id'])) {
                    $option['label'] = $option['name'];
                }

                return $option;
            })
            ->values();
    }

    /**
     * Supplies the account form with the same department scope as the rest of
     * the organization module, plus a snapshot of which positions can be
     * selected. The submitted data is still checked again inside the database
     * transaction, so this only improves the selection experience.
     */
    public function departmentOptionsForAccount(AdminUser $actor, ?AdminUser $account = null): Collection
    {
        return $this->withPositionAvailability(
            $this->departmentOptionsFor($actor),
            $account?->getKey() ? (int) $account->getKey() : null,
        );
    }

    public function findBusinessDepartment(int $departmentId): Department
    {
        return Department::business()->findOrFail($departmentId);
    }

    public function affectedMembersForSplit(Department $department): Collection
    {
        return $this->membershipRowsForDepartment($department, true);
    }

    public function membershipsForAccount(AdminUser $user): Collection
    {
        return DB::connection($this->connectionName())
            ->table(config('admin.database.role_users_table').' as role_user')
            ->join(config('admin.database.roles_table').' as department', 'department.id', '=', 'role_user.role_id')
            ->where('role_user.user_id', $user->getKey())
            ->where('department.slug', '!=', Department::SYSTEM_SLUG)
            ->orderBy('department.sort')
            ->orderBy('department.name')
            ->get([
                'role_user.role_id as department_id',
                'role_user.position',
                'role_user.position_slot',
                'department.name as department_name',
                'department.parent_id',
            ]);
    }

    public function accountList(string $search = ''): EloquentCollection
    {
        $userModel = config('admin.database.users_model');

        return $userModel::query()
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($nested) use ($search): void {
                    $nested->where('username', 'like', '%'.$search.'%')
                        ->orWhere('name', 'like', '%'.$search.'%');
                });
            })
            ->orderBy('id')
            ->get();
    }

    public function createDepartment(array $attributes, array $reassignments, AdminUser $actor): Department
    {
        return DB::connection($this->connectionName())->transaction(function () use ($attributes, $reassignments, $actor): Department {
            $parent = null;
            $parentId = Arr::get($attributes, 'parent_id');

            if ($parentId) {
                $parent = Department::business()->lockForUpdate()->findOrFail((int) $parentId);

                if ($parent->parent_id) {
                    throw ValidationException::withMessages([
                        'parent_id' => [__('organization.departments.second_level_only')],
                    ]);
                }
            }

            $affectedMembers = $parent ? $this->lockedAffectedMembers($parent) : collect();
            $firstChild = $parent && !Department::business()
                ->where('parent_id', $parent->getKey())
                ->lockForUpdate()
                ->exists();

            if ($firstChild && $affectedMembers->isNotEmpty()) {
                $this->validateSplitReassignments($affectedMembers, $reassignments, $parent);
            }

            $department = Department::create([
                'name' => $attributes['name'],
                'short_name' => $attributes['short_name'] ?? null,
                'slug' => $this->temporaryDepartmentCode(),
                'parent_id' => $parent?->getKey(),
                'sort' => (int) ($attributes['sort'] ?? 0),
            ]);
            $department->slug = $this->generatedDepartmentCode($department);
            $department->save();

            if ($firstChild && $affectedMembers->isNotEmpty()) {
                foreach ($affectedMembers as $membership) {
                    $assignment = $reassignments[(string) $membership->user_id] ?? [];
                    $targetId = ($assignment['department_id'] ?? null) === 'new'
                        ? (int) $department->getKey()
                        : (int) ($assignment['department_id'] ?? 0);
                    $target = $targetId === (int) $department->getKey()
                        ? $department
                        : Department::business()->lockForUpdate()->findOrFail($targetId);

                    if ($targetId !== (int) $department->getKey()
                        && !$this->authorization->canManageDepartment($actor, $target)) {
                        throw ValidationException::withMessages([
                            'reassignments' => [__('organization.authorization.department_out_of_scope')],
                        ]);
                    }

                    $this->upsertMembership(
                        (int) $membership->user_id,
                        $target,
                        (string) $assignment['position'],
                    );

                    DB::connection($this->connectionName())
                        ->table(config('admin.database.role_users_table'))
                        ->where('role_id', $parent->getKey())
                        ->where('user_id', $membership->user_id)
                        ->delete();
                }
            }

            $this->record($actor, $department, $firstChild ? 'department_split_created' : 'department_created', [
                'parent_id' => $parent?->getKey(),
                'reassigned_user_ids' => $affectedMembers->pluck('user_id')->values()->all(),
            ]);

            return $department;
        });
    }

    public function updateDepartment(Department $department, array $attributes, AdminUser $actor): Department
    {
        if ($department->isSystemDepartment()) {
            throw ValidationException::withMessages(['department' => [__('organization.departments.system_department_locked')]]);
        }

        $department->fill([
            'name' => $attributes['name'],
            'short_name' => $attributes['short_name'] ?? null,
            'sort' => (int) ($attributes['sort'] ?? 0),
        ])->save();

        $this->record($actor, $department, 'department_updated', [
            'name' => $department->name,
            'short_name' => $department->short_name,
            'slug' => $department->slug,
            'sort' => $department->sort,
        ]);

        return $department;
    }

    public function deleteDepartment(Department $department, AdminUser $actor): void
    {
        if ($department->isSystemDepartment()) {
            throw ValidationException::withMessages(['department' => [__('organization.departments.system_department_locked')]]);
        }

        DB::connection($this->connectionName())->transaction(function () use ($department, $actor): void {
            $department = Department::business()->lockForUpdate()->findOrFail($department->getKey());

            if ($department->children()->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['department' => [__('organization.departments.cannot_delete_with_children')]]);
            }

            $hasMember = DB::connection($this->connectionName())
                ->table(config('admin.database.role_users_table'))
                ->where('role_id', $department->getKey())
                ->lockForUpdate()
                ->exists();

            if ($hasMember) {
                throw ValidationException::withMessages(['department' => [__('organization.departments.cannot_delete_members')]]);
            }

            $departmentId = (int) $department->getKey();
            $this->portals->revokeDepartmentGrantsForDissolution($department, $actor);
            $department->delete();
            $this->record($actor, null, 'department_deleted', ['department_id' => $departmentId]);
        });
    }

    /**
     * Build the read-only data required to dissolve a populated leaf
     * department. The target department snapshot is advisory only; the final
     * operation locks and revalidates everything inside one transaction.
     *
     * @return array<string, mixed>
     */
    public function dissolutionContext(Department $department): array
    {
        $department = Department::business()->findOrFail($department->getKey());

        if ($department->children()->exists()) {
            throw ValidationException::withMessages([
                'department' => [__('organization.dissolution.has_children')],
            ]);
        }

        $parent = $department->parent_id ? $this->dissolutionParent($department) : null;
        $parentBecomesLeaf = $parent ? !$this->hasOtherChildren($department, $parent) : false;
        $members = $this->membershipRowsForDepartment($department);
        $otherMemberships = $this->otherMembershipsForUsers(
            $members->pluck('user_id')->map(fn ($id) => (int) $id),
            (int) $department->getKey(),
        );
        $targetDepartments = $this->departmentOptions()
            ->reject(fn (array $option) => (int) $option['id'] === (int) $department->getKey())
            ->map(function (array $option) use ($parent, $parentBecomesLeaf): array {
                $option['is_parent'] = $parent && (int) $option['id'] === (int) $parent->getKey();

                if ($option['is_parent'] && $parentBecomesLeaf) {
                    $option['is_branch'] = false;
                }

                return $option;
            })
            ->values();

        return [
            'department' => $department,
            'parent' => $parent,
            'parentBecomesLeaf' => $parentBecomesLeaf,
            'members' => $members->map(function ($member) use ($otherMemberships): array {
                $otherDepartments = $otherMemberships->get((int) $member->user_id, collect());

                return [
                    'user_id' => (int) $member->user_id,
                    'username' => (string) $member->username,
                    'name' => (string) $member->name,
                    'position' => (string) $member->position,
                    'other_departments' => $otherDepartments->map(fn ($membership): array => [
                        'id' => (int) $membership->department_id,
                        'name' => (string) $membership->department_name,
                        'position' => (string) $membership->position,
                    ])->values()->all(),
                    'existing_department_ids' => $otherDepartments->pluck('department_id')->map(fn ($id) => (int) $id)->values()->all(),
                ];
            })->values(),
            'targetDepartments' => $this->withPositionAvailability($targetDepartments, null),
            'positions' => $this->positionOptions(),
        ];
    }

    /**
     * Move or detach every member of a leaf department and remove the
     * department as one atomic change. A member may be detached only when an
     * existing membership in another business department remains.
     *
     * @param array<int|string, array{target_id:int|string, position?:string}> $reassignments
     */
    public function dissolveDepartment(Department $department, array $reassignments, AdminUser $actor): void
    {
        DB::connection($this->connectionName())->transaction(function () use ($department, $reassignments, $actor): void {
            $department = Department::business()->lockForUpdate()->findOrFail($department->getKey());
            if ($department->children()->lockForUpdate()->exists()) {
                throw ValidationException::withMessages([
                    'department' => [__('organization.dissolution.has_children')],
                ]);
            }

            $parent = $department->parent_id ? $this->dissolutionParent($department, true) : null;
            $parentBecomesLeaf = $parent ? !$this->hasOtherChildren($department, $parent, true) : false;
            $members = $this->membershipRowsForDepartment($department, false, true);
            $otherMemberships = $this->otherMembershipsForUsers(
                $members->pluck('user_id')->map(fn ($id) => (int) $id),
                (int) $department->getKey(),
                true,
            );
            $targetIds = collect($reassignments)
                ->pluck('target_id')
                ->filter(fn ($targetId) => is_numeric($targetId) && (int) $targetId !== (int) $department->getKey())
                ->map(fn ($targetId) => (int) $targetId)
                ->unique()
                ->values();
            $targets = $targetIds->isEmpty()
                ? collect()
                : Department::business()
                    ->whereIn('id', $targetIds)
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');
            $assignments = $this->validateDissolutionReassignments(
                $department,
                $parent,
                $parentBecomesLeaf,
                $members,
                $otherMemberships,
                $targets,
                $reassignments,
            );
            $roleUsersTable = config('admin.database.role_users_table');
            $departmentId = (int) $department->getKey();

            DB::connection($this->connectionName())
                ->table($roleUsersTable)
                ->where('role_id', $departmentId)
                ->delete();
            $this->portals->revokeDepartmentGrantsForDissolution($department, $actor);
            $department->delete();

            foreach ($assignments as $assignment) {
                if ($assignment['target_id'] === null) {
                    continue;
                }

                /** @var Department $target */
                $target = $targets->get($assignment['target_id']);
                $this->upsertMembership($assignment['user_id'], $target, $assignment['position']);
            }

            $this->record($actor, null, 'department_dissolved', [
                'department_id' => $departmentId,
                'parent_id' => $parent ? (int) $parent->getKey() : null,
                'parent_becomes_leaf' => $parentBecomesLeaf,
                'assignments' => $assignments,
            ]);
        });
    }

    /**
     * @param array<int, array{department_id:int|string, position:string}> $memberships
     */
    public function syncAccountMemberships(AdminUser $user, array $memberships, AdminUser $actor): void
    {
        if ($user->isAdministrator()) {
            throw ValidationException::withMessages(['memberships' => [__('organization.accounts.system_account_locked')]]);
        }

        $normalized = $this->normalizeMemberships($memberships);

        DB::connection($this->connectionName())->transaction(function () use ($user, $normalized, $actor): void {
            $roleUsersTable = config('admin.database.role_users_table');
            $connection = DB::connection($this->connectionName());
            $existingMemberships = $connection->table($roleUsersTable)
                ->where('user_id', $user->getKey())
                ->whereIn('role_id', Department::business()->pluck('id'))
                ->lockForUpdate()
                ->get(['role_id']);

            $managedDepartmentIds = $actor->isAdministrator()
                ? Department::business()->pluck('id')->map(fn ($id) => (int) $id)->values()
                : $this->authorization->managedDepartmentIds($actor);

            if ($managedDepartmentIds->isEmpty()) {
                throw ValidationException::withMessages(['memberships' => [__('organization.authorization.department_manager_only')]]);
            }

            if ($normalized->pluck('department_id')->diff($managedDepartmentIds)->isNotEmpty()) {
                throw ValidationException::withMessages(['memberships' => [__('organization.accounts.membership_out_of_scope')]]);
            }

            $existingUnmanagedDepartmentIds = $actor->isAdministrator()
                ? collect()
                : $existingMemberships
                    ->pluck('role_id')
                    ->map(fn ($id) => (int) $id)
                    ->reject(fn (int $id) => $managedDepartmentIds->contains($id));

            if ($normalized->isEmpty() && $existingUnmanagedDepartmentIds->isEmpty()) {
                throw ValidationException::withMessages(['memberships' => [__('organization.accounts.memberships_required')]]);
            }

            $departmentIds = $normalized->pluck('department_id')->unique()->values();
            $departments = Department::business()
                ->whereIn('id', $departmentIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($departments->count() !== $departmentIds->count()) {
                throw ValidationException::withMessages(['memberships' => [__('organization.accounts.invalid_membership')]]);
            }

            $this->ensureSingleAssistantChildDepartment(
                $user,
                $existingMemberships->pluck('role_id')->map(fn ($id) => (int) $id),
                $managedDepartmentIds,
                $departments,
            );

            foreach ($normalized as $membership) {
                $this->upsertMembership(
                    (int) $user->getKey(),
                    $departments->get($membership['department_id']),
                    $membership['position'],
                );
            }

            $removableMemberships = $connection->table($roleUsersTable)
                ->where('user_id', $user->getKey())
                ->whereIn('role_id', $managedDepartmentIds);

            if ($departmentIds->isEmpty()) {
                $removableMemberships->delete();
            } else {
                $removableMemberships->whereNotIn('role_id', $departmentIds)->delete();
            }

            $user->unsetRelation('roles')->unsetRelation('permissions');
            $this->record($actor, null, 'account_memberships_saved', [
                'user_id' => $user->getKey(),
                'memberships' => $normalized->values()->all(),
            ]);
        });
    }

    public function deleteAccount(AdminUser $user, AdminUser $actor): void
    {
        if ($user->isAdministrator()) {
            throw ValidationException::withMessages(['account' => [__('organization.accounts.system_account_locked')]]);
        }

        DB::connection($this->connectionName())->transaction(function () use ($user, $actor): void {
            $userModel = config('admin.database.users_model');
            /** @var AdminUser $account */
            $account = $userModel::query()->lockForUpdate()->findOrFail($user->getKey());
            $departmentNames = $this->membershipsForAccount($account)->pluck('department_name')->values()->all();
            $accountId = (int) $account->getKey();
            $account->delete();

            $this->record($actor, null, 'account_deleted', [
                'user_id' => $accountId,
                'departments' => $departmentNames,
            ]);
        });
    }

    /**
     * @param array<int, array{permission_id:int, scope:string}> $permissions
     */
    public function savePagePermissions(Department $department, array $permissions, AdminUser $actor): void
    {
        if ($department->isSystemDepartment()) {
            throw ValidationException::withMessages(['department' => [__('organization.departments.system_department_locked')]]);
        }

        $validPermissionIds = $this->assignablePermissionIds();
        $permissions = collect($permissions)
            ->filter(fn (array $permission) => isset($permission['permission_id']))
            ->map(fn (array $permission) => [
                'permission_id' => (int) $permission['permission_id'],
                'scope' => (string) ($permission['scope'] ?? DepartmentPermissionScope::SCOPE_SELF),
            ])
            ->unique('permission_id')
            ->values();

        if ($permissions->pluck('permission_id')->diff($validPermissionIds)->isNotEmpty()) {
            throw ValidationException::withMessages(['permissions' => [__('organization.page_permissions.invalid_permission')]]);
        }

        if ($permissions->contains(fn (array $permission) => !in_array($permission['scope'], [
            DepartmentPermissionScope::SCOPE_SELF,
            DepartmentPermissionScope::SCOPE_DESCENDANTS,
        ], true))) {
            throw ValidationException::withMessages(['permissions' => [__('organization.page_permissions.invalid_permission')]]);
        }

        DB::connection($this->connectionName())->transaction(function () use ($department, $permissions, $actor): void {
            $department = Department::business()->lockForUpdate()->findOrFail($department->getKey());
            $permissionIds = $permissions->pluck('permission_id')->all();
            $department->permissions()->sync($permissionIds);

            DB::connection($this->connectionName())
                ->table('admin_department_permission_scopes')
                ->where('role_id', $department->getKey())
                ->delete();

            if ($permissions->isNotEmpty()) {
                $timestamp = now();
                DB::connection($this->connectionName())
                    ->table('admin_department_permission_scopes')
                    ->insert($permissions->map(fn (array $permission) => [
                        'role_id' => $department->getKey(),
                        'permission_id' => $permission['permission_id'],
                        'scope' => $permission['scope'],
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ])->all());
            }

            $permissionModel = config('admin.database.permissions_model');
            $slugs = $permissionModel::query()
                ->whereIn('id', $permissionIds)
                ->pluck('slug');
            $menuIds = $this->assignableMenus()
                ->filter(fn (array $menu) => in_array($menu['permission_slug'], $slugs->all(), true))
                ->pluck('id')
                ->all();
            $department->menus()->sync($this->menuIdsWithAncestors($menuIds));

            $this->record($actor, $department, 'department_page_permissions_saved', [
                'permissions' => $permissions->all(),
            ]);
        });
    }

    public function pagePermissionTree(): array
    {
        $menuModel = config('admin.database.menu_model');
        $menus = $menuModel::query()->orderBy('order')->orderBy('id')->get();
        $permissionModel = config('admin.database.permissions_model');
        $permissions = $permissionModel::query()->get(['id', 'slug', 'name'])->keyBy('slug');
        $byParent = $menus->groupBy(fn ($menu) => $menu->parent_id ?: 0);

        $build = function (int $parentId) use (&$build, $byParent, $permissions): array {
            return $byParent->get($parentId, collect())
                ->map(function ($menu) use (&$build, $permissions): ?array {
                    if ($this->isSystemMenu($menu->uri)) {
                        return null;
                    }

                    $children = $build((int) $menu->id);
                    $slug = $menu->permission ?: ($menu->uri === '/' ? 'dashboard' : null);
                    $permission = $slug ? $permissions->get($slug) : null;

                    if (!$permission && $children === []) {
                        return null;
                    }

                    return [
                        'id' => (int) $menu->id,
                        'title' => $menu->title,
                        'permission_id' => $permission?->id,
                        'permission_slug' => $permission?->slug,
                        'children' => $children,
                    ];
                })
                ->filter()
                ->values()
                ->all();
        };

        return $build(0);
    }

    public function assignedPagePermissions(Department $department): Collection
    {
        $direct = DB::connection($this->connectionName())
            ->table(config('admin.database.role_permissions_table').' as role_permission')
            ->leftJoin('admin_department_permission_scopes as scope', function ($join): void {
                $join->on('scope.role_id', '=', 'role_permission.role_id')
                    ->on('scope.permission_id', '=', 'role_permission.permission_id');
            })
            ->where('role_permission.role_id', $department->getKey())
            ->get([
                'role_permission.permission_id',
                DB::raw("COALESCE(scope.scope, 'self') as scope"),
            ])
            ->keyBy('permission_id');

        return $direct;
    }

    public function inheritedPagePermissionIds(Department $department): Collection
    {
        $ancestorIds = $this->ancestorIdsForDepartment($department);

        if ($ancestorIds->isEmpty()) {
            return collect();
        }

        return DB::connection($this->connectionName())
            ->table('admin_department_permission_scopes')
            ->whereIn('role_id', $ancestorIds)
            ->where('scope', DepartmentPermissionScope::SCOPE_DESCENDANTS)
            ->pluck('permission_id')
            ->unique()
            ->values();
    }

    public function positionOptions(): array
    {
        return [
            Department::POSITION_MANAGER => __('organization.positions.manager'),
            Department::POSITION_DEPUTY => __('organization.positions.deputy'),
            Department::POSITION_MEMBER => __('organization.positions.member'),
        ];
    }

    /**
     * @param Collection<int, array{id:int, is_branch:bool}> $options
     */
    private function withPositionAvailability(Collection $options, ?int $exceptUserId): Collection
    {
        $departmentIds = $options->pluck('id')->map(fn ($id) => (int) $id)->values();

        if ($departmentIds->isEmpty()) {
            return $options;
        }

        $countsQuery = DB::connection($this->connectionName())
            ->table(config('admin.database.role_users_table'))
            ->selectRaw('role_id, position, COUNT(*) as total')
            ->whereIn('role_id', $departmentIds->all())
            ->whereIn('position', [
                Department::POSITION_MANAGER,
                Department::POSITION_DEPUTY,
                Department::POSITION_MEMBER,
            ]);

        if ($exceptUserId) {
            $countsQuery->where('user_id', '!=', $exceptUserId);
        }

        $counts = $countsQuery
            ->groupBy('role_id', 'position')
            ->get()
            ->groupBy('role_id')
            ->map(fn (Collection $rows) => $rows->pluck('total', 'position')->map(fn ($total) => (int) $total)->all());

        return $options->map(function (array $option) use ($counts): array {
            $positionCounts = $counts->get((int) $option['id'], []);
            $managerUsed = (int) ($positionCounts[Department::POSITION_MANAGER] ?? 0);
            $deputyUsed = (int) ($positionCounts[Department::POSITION_DEPUTY] ?? 0);
            $isBranch = (bool) $option['is_branch'];
            $managerAvailable = $managerUsed < 1;
            $deputyAvailable = !$isBranch && $deputyUsed < 2;

            $option['position_availability'] = [
                Department::POSITION_MANAGER => ['enabled' => $managerAvailable],
                Department::POSITION_DEPUTY => ['enabled' => $deputyAvailable],
                Department::POSITION_MEMBER => ['enabled' => !$isBranch],
            ];

            if ($isBranch) {
                $option['position_notice'] = $managerAvailable
                    ? __('organization.accounts.branch_position_only_manager')
                    : __('organization.accounts.branch_manager_full');

                return $option;
            }

            $notices = [];

            if (!$managerAvailable) {
                $notices[] = __('organization.accounts.manager_position_full');
            }

            if (!$deputyAvailable) {
                $notices[] = __('organization.accounts.deputy_position_full');
            }

            $option['position_notice'] = implode(' ', $notices);

            return $option;
        })->values();
    }

    private function dissolutionParent(Department $department, bool $lock = false): Department
    {
        if (!$department->parent_id) {
            throw ValidationException::withMessages([
                'department' => [__('organization.departments.invalid_parent')],
            ]);
        }

        $query = Department::business();

        if ($lock) {
            $query->lockForUpdate();
        }

        $parent = $query->find((int) $department->parent_id);

        if (!$parent) {
            throw ValidationException::withMessages([
                'department' => [__('organization.departments.invalid_parent')],
            ]);
        }

        return $parent;
    }

    private function hasOtherChildren(Department $department, Department $parent, bool $lock = false): bool
    {
        $query = Department::business()
            ->where('parent_id', $parent->getKey())
            ->where('id', '!=', $department->getKey());

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->exists();
    }

    /**
     * @param Collection<int, int> $userIds
     * @return Collection<int, Collection<int, object>>
     */
    private function otherMembershipsForUsers(Collection $userIds, int $exceptDepartmentId, bool $lock = false): Collection
    {
        $userIds = $userIds->filter()->map(fn ($id) => (int) $id)->unique()->values();

        if ($userIds->isEmpty()) {
            return collect();
        }

        $query = DB::connection($this->connectionName())
            ->table(config('admin.database.role_users_table').' as role_user')
            ->join(config('admin.database.roles_table').' as department', 'department.id', '=', 'role_user.role_id')
            ->whereIn('role_user.user_id', $userIds)
            ->where('role_user.role_id', '!=', $exceptDepartmentId)
            ->where('department.slug', '!=', Department::SYSTEM_SLUG)
            ->orderBy('department.sort')
            ->orderBy('department.name');

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get([
            'role_user.user_id',
            'role_user.role_id as department_id',
            'role_user.position',
            'department.name as department_name',
        ])->groupBy('user_id');
    }

    /**
     * @param Collection<int, object> $members
     * @param Collection<int, Collection<int, object>> $otherMemberships
     * @param Collection<int, Department> $targets
     * @param array<int|string, array{target_id:int|string, position?:string}> $reassignments
     * @return array<int, array{user_id:int, target_id:int|null, position:string|null}>
     */
    private function validateDissolutionReassignments(
        Department $department,
        ?Department $parent,
        bool $parentBecomesLeaf,
        Collection $members,
        Collection $otherMemberships,
        Collection $targets,
        array $reassignments,
    ): array {
        $assignments = [];

        foreach ($members as $member) {
            $userId = (int) $member->user_id;
            $assignment = $reassignments[$userId] ?? $reassignments[(string) $userId] ?? null;

            if (!is_array($assignment)) {
                throw ValidationException::withMessages([
                    'reassignments' => [__('organization.dissolution.incomplete_reassignments')],
                ]);
            }

            $targetValue = $assignment['target_id'] ?? null;
            $otherDepartments = $otherMemberships->get($userId, collect());

            if ($targetValue === 'remove') {
                if ($otherDepartments->isEmpty()) {
                    throw ValidationException::withMessages([
                        'reassignments' => [__('organization.dissolution.remove_requires_other_membership')],
                    ]);
                }

                $assignments[] = [
                    'user_id' => $userId,
                    'target_id' => null,
                    'position' => null,
                ];

                continue;
            }

            if (!is_numeric($targetValue)) {
                throw ValidationException::withMessages([
                    'reassignments' => [__('organization.dissolution.invalid_target')],
                ]);
            }

            $targetId = (int) $targetValue;

            if ($targetId === (int) $department->getKey() || !$targets->has($targetId)) {
                throw ValidationException::withMessages([
                    'reassignments' => [__('organization.dissolution.invalid_target')],
                ]);
            }

            if ($otherDepartments->pluck('department_id')->map(fn ($id) => (int) $id)->contains($targetId)) {
                throw ValidationException::withMessages([
                    'reassignments' => [__('organization.dissolution.target_already_assigned')],
                ]);
            }

            $position = (string) ($assignment['position'] ?? '');

            if (!array_key_exists($position, $this->positionSlots)) {
                throw ValidationException::withMessages([
                    'reassignments' => [__('organization.dissolution.invalid_position')],
                ]);
            }

            /** @var Department $target */
            $target = $targets->get($targetId);
            $isParentBecomingLeaf = $parent
                && $targetId === (int) $parent->getKey()
                && $parentBecomesLeaf;

            if (!$isParentBecomingLeaf && !$target->allowsPosition($position)) {
                throw ValidationException::withMessages([
                    'reassignments' => [__('organization.accounts.position_not_allowed')],
                ]);
            }

            $assignments[] = [
                'user_id' => $userId,
                'target_id' => $targetId,
                'position' => $position,
            ];
        }

        return $assignments;
    }

    /**
     * The Assistant portal scopes business records by a direct child department.
     * Keeping this cardinality at one prevents a normal account from joining two
     * isolated Assistant datasets through its organization memberships.
     *
     * @param Collection<int, int> $existingDepartmentIds
     * @param Collection<int, int> $managedDepartmentIds
     * @param EloquentCollection<int, Department> $submittedDepartments
     */
    private function ensureSingleAssistantChildDepartment(
        AdminUser $user,
        Collection $existingDepartmentIds,
        Collection $managedDepartmentIds,
        EloquentCollection $submittedDepartments,
    ): void {
        $assistantRootId = Department::business()
            ->where('slug', self::ASSISTANT_ROOT_DEPARTMENT_CODE)
            ->whereNull('parent_id')
            ->value('id');

        if ($assistantRootId === null) {
            return;
        }

        $existingChildDepartmentIds = Department::business()
            ->whereIn('id', $existingDepartmentIds->all())
            ->where('parent_id', $assistantRootId)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id);
        $retainedChildDepartmentIds = $existingChildDepartmentIds
            ->reject(fn (int $id): bool => $managedDepartmentIds->contains($id));
        $submittedChildDepartmentIds = $submittedDepartments
            ->filter(fn (Department $department): bool => (int) $department->parent_id === (int) $assistantRootId)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id);
        $finalChildDepartmentIds = $retainedChildDepartmentIds
            ->merge($submittedChildDepartmentIds)
            ->unique()
            ->values();

        if ($finalChildDepartmentIds->count() > 1) {
            throw ValidationException::withMessages([
                'memberships' => [__('organization.accounts.assistant_child_department_limit')],
            ]);
        }
    }

    private function normalizeMemberships(array $memberships): Collection
    {
        $normalized = collect($memberships)
            ->filter(fn ($membership) => is_array($membership) && filled($membership['department_id'] ?? null))
            ->map(fn (array $membership) => [
                'department_id' => (int) $membership['department_id'],
                'position' => (string) ($membership['position'] ?? ''),
            ])
            ->values();

        if ($normalized->pluck('department_id')->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages(['memberships' => [__('organization.accounts.duplicate_department')]]);
        }

        if ($normalized->contains(fn (array $membership) => !array_key_exists($membership['position'], $this->positionSlots))) {
            throw ValidationException::withMessages(['memberships' => [__('organization.accounts.invalid_membership')]]);
        }

        return $normalized;
    }

    private function upsertMembership(int $userId, Department $department, string $position): void
    {
        if (!array_key_exists($position, $this->positionSlots)) {
            throw ValidationException::withMessages(['memberships' => [__('organization.accounts.invalid_membership')]]);
        }

        if (!$department->allowsPosition($position)) {
            throw ValidationException::withMessages(['memberships' => [__('organization.accounts.position_not_allowed')]]);
        }

        $connection = DB::connection($this->connectionName());
        $roleUsersTable = config('admin.database.role_users_table');
        $existing = $connection->table($roleUsersTable)
            ->where('role_id', $department->getKey())
            ->where('user_id', $userId)
            ->lockForUpdate()
            ->first();
        $slot = $this->positionSlot($department, $position, $userId);

        $values = [
            'position' => $position,
            'position_slot' => $slot,
            // Retained for database compatibility only. It no longer has business meaning.
            'is_primary' => false,
            'updated_at' => now(),
        ];

        try {
            if ($existing) {
                $connection->table($roleUsersTable)
                    ->where('role_id', $department->getKey())
                    ->where('user_id', $userId)
                    ->update($values);
            } else {
                $connection->table($roleUsersTable)->insert([
                    'role_id' => $department->getKey(),
                    'user_id' => $userId,
                    ...$values,
                    'created_at' => now(),
                ]);
            }
        } catch (QueryException) {
            throw ValidationException::withMessages(['memberships' => [__('organization.accounts.position_full')]]);
        }
    }

    private function positionSlot(Department $department, string $position, int $userId): ?int
    {
        if ($position === Department::POSITION_MEMBER) {
            return null;
        }

        if ($position === Department::POSITION_MANAGER) {
            $used = DB::connection($this->connectionName())
                ->table(config('admin.database.role_users_table'))
                ->where('role_id', $department->getKey())
                ->where('position', Department::POSITION_MANAGER)
                ->where('user_id', '!=', $userId)
                ->exists();

            if ($used) {
                throw ValidationException::withMessages(['memberships' => [__('organization.accounts.position_full')]]);
            }

            return 1;
        }

        $usedSlots = DB::connection($this->connectionName())
            ->table(config('admin.database.role_users_table'))
            ->where('role_id', $department->getKey())
            ->where('position', Department::POSITION_DEPUTY)
            ->where('user_id', '!=', $userId)
            ->pluck('position_slot')
            ->filter()
            ->map(fn ($slot) => (int) $slot)
            ->all();

        foreach ([1, 2] as $slot) {
            if (!in_array($slot, $usedSlots, true)) {
                return $slot;
            }
        }

        throw ValidationException::withMessages(['memberships' => [__('organization.accounts.position_full')]]);
    }

    private function lockedAffectedMembers(Department $department): Collection
    {
        return $this->membershipRowsForDepartment($department, true, true);
    }

    private function membershipRowsForDepartment(Department $department, bool $onlyNonManagers = false, bool $lock = false): Collection
    {
        $connection = DB::connection($this->connectionName());
        $roleUsersTable = config('admin.database.role_users_table');
        $userModel = config('admin.database.users_model');
        $query = $connection->table($roleUsersTable.' as role_user')
            ->join((new $userModel())->getTable().' as administrator', 'administrator.id', '=', 'role_user.user_id')
            ->where('role_user.role_id', $department->getKey())
            ->orderBy('administrator.name');

        if ($onlyNonManagers) {
            $query->where('role_user.position', '!=', Department::POSITION_MANAGER);
        }

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get([
            'role_user.user_id',
            'role_user.position',
            'role_user.position_slot',
            'administrator.username',
            'administrator.name',
            'administrator.status',
        ]);
    }

    private function validateSplitReassignments(Collection $affectedMembers, array $reassignments, Department $parent): void
    {
        foreach ($affectedMembers as $member) {
            $assignment = $reassignments[(string) $member->user_id] ?? null;

            if (!is_array($assignment) || blank($assignment['department_id'] ?? null) || blank($assignment['position'] ?? null)) {
                throw ValidationException::withMessages(['reassignments' => [__('organization.split.invalid_reassignment')]]);
            }

            if (!array_key_exists((string) $assignment['position'], $this->positionSlots)) {
                throw ValidationException::withMessages(['reassignments' => [__('organization.split.invalid_reassignment')]]);
            }

            if ((string) $assignment['department_id'] !== 'new' && (int) $assignment['department_id'] === (int) $parent->getKey()) {
                throw ValidationException::withMessages(['reassignments' => [__('organization.split.invalid_reassignment')]]);
            }
        }
    }

    private function membershipCounts(): Collection
    {
        return DB::connection($this->connectionName())
            ->table(config('admin.database.role_users_table'))
            ->selectRaw('role_id, position, COUNT(*) as total')
            ->groupBy('role_id', 'position')
            ->get()
            ->groupBy('role_id')
            ->map(function (Collection $rows): array {
                $counts = $this->emptyCounts();

                foreach ($rows as $row) {
                    $counts[$row->position] = (int) $row->total;
                }

                return $counts;
            });
    }

    private function pageCounts(): Collection
    {
        return DB::connection($this->connectionName())
            ->table(config('admin.database.role_permissions_table'))
            ->selectRaw('role_id, COUNT(*) as total')
            ->groupBy('role_id')
            ->pluck('total', 'role_id')
            ->map(fn ($total) => (int) $total);
    }

    private function emptyCounts(): array
    {
        return [
            Department::POSITION_MANAGER => 0,
            Department::POSITION_DEPUTY => 0,
            Department::POSITION_MEMBER => 0,
        ];
    }

    private function temporaryDepartmentCode(): string
    {
        return 'pending-'.strtolower((string) Str::ulid());
    }

    private function generatedDepartmentCode(Department $department): string
    {
        $baseCode = 'dept-'.str_pad((string) $department->getKey(), 6, '0', STR_PAD_LEFT);
        $code = $baseCode;
        $suffix = 1;

        while (Department::query()
            ->where('slug', $code)
            ->whereKeyNot($department->getKey())
            ->exists()) {
            $code = $baseCode.'-'.$suffix;
            $suffix++;
        }

        return $code;
    }

    private function assignablePermissionIds(): Collection
    {
        return $this->assignableMenus()
            ->pluck('permission_id')
            ->filter()
            ->unique()
            ->values();
    }

    private function assignableMenus(): Collection
    {
        $menuModel = config('admin.database.menu_model');
        $permissionModel = config('admin.database.permissions_model');
        $permissions = $permissionModel::query()->get(['id', 'slug'])->keyBy('slug');

        return $menuModel::query()->get()
            ->filter(fn ($menu) => !$this->isSystemMenu($menu->uri))
            ->map(function ($menu) use ($permissions): ?array {
                $slug = $menu->permission ?: ($menu->uri === '/' ? 'dashboard' : null);
                $permission = $slug ? $permissions->get($slug) : null;

                return $permission ? [
                    'id' => (int) $menu->id,
                    'permission_id' => (int) $permission->id,
                    'permission_slug' => $permission->slug,
                ] : null;
            })
            ->filter()
            ->values();
    }

    /**
     * Keep the menu path visible when a leaf page is assigned. Laravel-admin
     * evaluates menu roles level by level, so attaching only the leaf would
     * leave an otherwise authorized page hidden below a collapsed parent.
     *
     * @param array<int, int> $menuIds
     * @return array<int, int>
     */
    private function menuIdsWithAncestors(array $menuIds): array
    {
        if ($menuIds === []) {
            return [];
        }

        $menuModel = config('admin.database.menu_model');
        $menus = $menuModel::query()->get(['id', 'parent_id'])->keyBy('id');
        $resolved = collect($menuIds)->map(fn ($id) => (int) $id);

        foreach ($menuIds as $menuId) {
            $current = $menus->get((int) $menuId);
            $visited = [];

            while ($current && $current->parent_id && !in_array((int) $current->parent_id, $visited, true)) {
                $parentId = (int) $current->parent_id;
                $visited[] = $parentId;
                $resolved->push($parentId);
                $current = $menus->get($parentId);
            }
        }

        return $resolved->unique()->values()->all();
    }

    private function isSystemMenu(?string $uri): bool
    {
        return str_starts_with((string) $uri, 'organization/')
            || str_starts_with((string) $uri, 'auth/')
            || str_starts_with((string) $uri, 'portal/');
    }

    private function ancestorIdsForDepartment(Department $department): Collection
    {
        $ids = collect();
        $current = $department;
        $visited = [];

        while ($current->parent_id && !in_array((int) $current->parent_id, $visited, true)) {
            $visited[] = (int) $current->parent_id;
            $current = Department::business()->find((int) $current->parent_id);

            if (!$current) {
                break;
            }

            $ids->push((int) $current->getKey());
        }

        return $ids->unique()->values();
    }

    private function record(AdminUser $actor, ?Department $department, string $event, array $payload): void
    {
        DepartmentChangeLog::create([
            'actor_id' => $actor->getKey(),
            'department_id' => $department?->getKey(),
            'event' => $event,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    private function connectionName(): ?string
    {
        return config('admin.database.connection') ?: config('database.default');
    }
}
