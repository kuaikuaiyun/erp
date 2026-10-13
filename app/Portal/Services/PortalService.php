<?php

namespace App\Portal\Services;

use App\Models\AdminUser;
use App\Models\Department;
use App\Models\Portal;
use App\Models\PortalChangeLog;
use App\Models\PortalDepartment;
use App\Models\PortalMenu;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PortalService
{
    public function rootDepartmentOptions(): EloquentCollection
    {
        return Department::business()
            ->whereNull('parent_id')
            ->orderBy('sort')
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);
    }

    /**
     * @param array{name:string, code:string, remark?:string|null, status:string} $attributes
     * @param array<int|string, int|string> $departmentIds
     */
    public function create(array $attributes, array $departmentIds, AdminUser $actor): Portal
    {
        return DB::connection($this->connectionName())->transaction(function () use ($attributes, $departmentIds, $actor): Portal {
            $portal = Portal::create([
                'name' => $attributes['name'],
                'code' => $attributes['code'],
                'remark' => $attributes['remark'] ?? null,
                'status' => $attributes['status'],
            ]);

            $this->createDefaultMenus($portal);

            $this->record($portal, $actor, PortalChangeLog::EVENT_PORTAL_CREATED, [
                'name' => $portal->name,
                'code' => $portal->code,
                'remark' => $portal->remark,
                'status' => $portal->status,
            ]);
            $this->syncDepartmentGrants($portal, $departmentIds, $actor);

            return $portal;
        });
    }

    /**
     * @param array{name:string, remark?:string|null, status:string} $attributes
     * @param array<int|string, int|string> $departmentIds
     */
    public function update(Portal $portal, array $attributes, array $departmentIds, AdminUser $actor): Portal
    {
        return DB::connection($this->connectionName())->transaction(function () use ($portal, $attributes, $departmentIds, $actor): Portal {
            $portal = Portal::query()->lockForUpdate()->findOrFail($portal->getKey());
            $before = [
                'name' => $portal->name,
                'remark' => $portal->remark,
                'status' => $portal->status,
            ];
            $portal->fill([
                'name' => $attributes['name'],
                'remark' => $attributes['remark'] ?? null,
                'status' => $attributes['status'],
            ]);
            $portal->save();
            $after = [
                'name' => $portal->name,
                'remark' => $portal->remark,
                'status' => $portal->status,
            ];

            if ($before['name'] !== $after['name'] || $before['remark'] !== $after['remark']) {
                $this->record($portal, $actor, PortalChangeLog::EVENT_PORTAL_UPDATED, [
                    'before' => $before,
                    'after' => $after,
                ]);
            }

            if ($before['status'] !== $after['status']) {
                $this->record($portal, $actor, PortalChangeLog::EVENT_PORTAL_STATUS_CHANGED, [
                    'before_status' => $before['status'],
                    'after_status' => $after['status'],
                ]);
            }

            $this->syncDepartmentGrants($portal, $departmentIds, $actor);

            return $portal;
        });
    }

    /**
     * Change only a portal's availability state without touching its data or department grants.
     */
    public function updateStatus(Portal $portal, string $status, AdminUser $actor): Portal
    {
        return DB::connection($this->connectionName())->transaction(function () use ($portal, $status, $actor): Portal {
            $portal = Portal::query()->lockForUpdate()->findOrFail($portal->getKey());
            $beforeStatus = $portal->status;

            if ($beforeStatus === $status) {
                return $portal;
            }

            $portal->status = $status;
            $portal->save();

            $this->record($portal, $actor, PortalChangeLog::EVENT_PORTAL_STATUS_CHANGED, [
                'before_status' => $beforeStatus,
                'after_status' => $portal->status,
            ]);

            return $portal;
        });
    }

    public function revokeDepartmentGrantsForDissolution(Department $department, AdminUser $actor): void
    {
        $grants = PortalDepartment::query()
            ->where('department_id', $department->getKey())
            ->lockForUpdate()
            ->with('portal')
            ->get();

        foreach ($grants as $grant) {
            if ($grant->portal) {
                $this->record($grant->portal, $actor, PortalChangeLog::EVENT_DEPARTMENT_REVOKED_ON_DISSOLUTION, [
                    'scope' => 'descendants',
                    'reason' => 'department_dissolved',
                ], $department);
            }

            $grant->delete();
        }
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function authorizationHistory(Portal $portal): Collection
    {
        $logs = $portal->changeLogs()
            ->whereIn('event', [
                PortalChangeLog::EVENT_DEPARTMENT_GRANTED,
                PortalChangeLog::EVENT_DEPARTMENT_REVOKED,
                PortalChangeLog::EVENT_DEPARTMENT_REVOKED_ON_DISSOLUTION,
            ])
            ->orderBy('id')
            ->get();
        $rows = collect();

        foreach ($logs as $log) {
            $departmentId = (int) $log->department_id;

            if ($log->event === PortalChangeLog::EVENT_DEPARTMENT_GRANTED) {
                $rows->push([
                    'department_id' => $departmentId,
                    'department_name' => $log->department_name_snapshot,
                    'scope' => (string) data_get($log->payload, 'scope', 'descendants'),
                    'granted_at' => $log->created_at,
                    'granted_by' => $log->actor_name_snapshot,
                    'revoked_at' => null,
                    'revoked_by' => null,
                    'status' => 'active',
                ]);

                continue;
            }

            $rowIndex = $rows
                ->keys()
                ->reverse()
                ->first(fn ($index) => (int) $rows[$index]['department_id'] === $departmentId
                    && $rows[$index]['revoked_at'] === null);

            if ($rowIndex === null) {
                continue;
            }

            $row = $rows[$rowIndex];
            $row['revoked_at'] = $log->created_at;
            $row['revoked_by'] = $log->actor_name_snapshot;
            $row['status'] = 'revoked';
            $rows[$rowIndex] = $row;
        }

        return $rows
            ->sortByDesc('granted_at')
            ->values();
    }

    /**
     * @param array<int|string, int|string> $departmentIds
     */
    private function syncDepartmentGrants(Portal $portal, array $departmentIds, AdminUser $actor): void
    {
        $departmentIds = collect($departmentIds)
            ->filter(fn ($id) => is_numeric($id))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
        $departments = $this->rootDepartmentsForIds($departmentIds);
        $existingGrants = PortalDepartment::query()
            ->where('portal_id', $portal->getKey())
            ->lockForUpdate()
            ->get()
            ->keyBy('department_id');
        $desiredIds = $departments->pluck('id')->map(fn ($id) => (int) $id);

        foreach ($existingGrants as $departmentId => $grant) {
            if ($desiredIds->contains((int) $departmentId)) {
                continue;
            }

            $department = Department::business()->find((int) $departmentId);
            $this->record($portal, $actor, PortalChangeLog::EVENT_DEPARTMENT_REVOKED, [
                'scope' => 'descendants',
            ], $department);
            $grant->delete();
        }

        foreach ($departments as $department) {
            if ($existingGrants->has((int) $department->getKey())) {
                continue;
            }

            PortalDepartment::create([
                'portal_id' => $portal->getKey(),
                'department_id' => $department->getKey(),
                'includes_descendants' => true,
                'granted_by' => $actor->getKey(),
                'granted_at' => now(),
            ]);
            $this->record($portal, $actor, PortalChangeLog::EVENT_DEPARTMENT_GRANTED, [
                'scope' => 'descendants',
            ], $department);
        }
    }

    /**
     * @param Collection<int, int> $departmentIds
     * @return EloquentCollection<int, Department>
     */
    private function rootDepartmentsForIds(Collection $departmentIds): EloquentCollection
    {
        if ($departmentIds->isEmpty()) {
            return new EloquentCollection();
        }

        $departments = Department::business()
            ->whereIn('id', $departmentIds->all())
            ->whereNull('parent_id')
            ->lockForUpdate()
            ->get();

        if ($departments->count() !== $departmentIds->count()) {
            throw ValidationException::withMessages([
                'department_ids' => [__('portal.validation.invalid_department')],
            ]);
        }

        return $departments;
    }

    private function record(
        Portal $portal,
        AdminUser $actor,
        string $event,
        array $payload = [],
        ?Department $department = null,
        ?string $departmentNameSnapshot = null,
    ): void {
        PortalChangeLog::create([
            'portal_id' => $portal->getKey(),
            'department_id' => $department?->getKey(),
            'department_name_snapshot' => $department?->name ?? $departmentNameSnapshot,
            'event' => $event,
            'payload' => $payload,
            'actor_id' => $actor->getKey(),
            'actor_name_snapshot' => $actor->name,
        ]);
    }

    private function createDefaultMenus(Portal $portal): void
    {
        PortalMenu::create([
            'portal_id' => $portal->getKey(),
            'parent_id' => 0,
            'code' => 'home',
            'title_key' => 'portal.menu.home',
            'path' => '/',
            'icon' => 'fa-home',
            'sort' => 1,
            'is_enabled' => true,
        ]);

        $management = PortalMenu::create([
            'portal_id' => $portal->getKey(),
            'parent_id' => 0,
            'code' => 'backend_management',
            'title_key' => 'portal.menu.backend_management',
            'path' => '#',
            'icon' => 'fa-cogs',
            'sort' => 900,
            'is_enabled' => true,
        ]);

        PortalMenu::create([
            'portal_id' => $portal->getKey(),
            'parent_id' => $management->getKey(),
            'code' => 'menu_configuration',
            'title_key' => 'portal.menu.configuration',
            'path' => '/management/menus',
            'icon' => 'fa-list',
            'sort' => 1,
            'is_enabled' => true,
        ]);
    }

    private function connectionName(): ?string
    {
        return config('admin.database.connection') ?: config('database.default');
    }
}


