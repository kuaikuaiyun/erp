<?php

namespace App\Portals\AssetManager;

use App\Models\AdminUser;
use App\Models\Department;
use App\Models\Portal;
use App\Services\DepartmentAuthorizationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssetComputerDepartmentBindingService
{
    public function database()
    {
        return DB::connection(config('admin.database.connection') ?: config('database.default'));
    }

    public function sourceDepartments(): Collection
    {
        $portal = Portal::where('code', 'asset-manager')->first();
        if (! $portal) {
            return collect();
        }
        $granted = $portal->departmentGrants()->pluck('department_id')->map(fn ($id) => (int) $id);
        $departments = $this->targets();
        $children = $departments->groupBy('parent_id');

        return $departments->filter(fn ($d) => ! $d->parent_id && $granted->contains((int) $d->id))
            ->flatMap(fn ($root) => $children->get($root->id, collect())->isNotEmpty() ? $children[$root->id] : collect([$root]))->values();
    }

    public function targets(): Collection
    {
        return Department::business()->with('parent:id,name')->orderBy('sort')->orderBy('name')->get();
    }

    public function label(Department $department): string
    {
        return ($department->parent ? $department->parent->name.' / ' : '').$department->name;
    }

    public function selectedIds(int $sourceId): array
    {
        return $this->database()->table('asset_computer_department_bindings')->where('source_department_id', $sourceId)
            ->orderBy('target_department_id')->pluck('target_department_id')->map(fn ($id) => (int) $id)->all();
    }

    public function version(int $sourceId): int
    {
        return (int) $this->database()->table('asset_computer_department_binding_logs')->where('source_department_id', $sourceId)->max('id');
    }

    public function departmentIds(AdminUser $user, bool $write = false): array
    {
        $departments = $this->targets();
        if ($user->isAdministrator()) {
            return $departments->pluck('id')->map(fn ($id) => (int) $id)->all();
        }
        $managed = app(DepartmentAuthorizationService::class)->managedDepartmentIds($user);
        $memberships = $write ? $managed : $user->departments()->pluck('id')->merge($managed);
        $sources = $memberships->intersect($this->sourceDepartments()->pluck('id'))->unique();
        if ($sources->isEmpty()) {
            return [];
        }
        $bound = $this->database()->table('asset_computer_department_bindings')->whereIn('source_department_id', $sources)
            ->pluck('target_department_id')->map(fn ($id) => (int) $id);
        // Expand target roots once. Bindings belonging to a target department are never followed.
        $rootTargets = $departments->filter(fn ($d) => ! $d->parent_id && $bound->contains((int) $d->id))->pluck('id');

        return $departments->filter(fn ($d) => $bound->contains((int) $d->id) || ($d->parent_id && $rootTargets->contains((int) $d->parent_id)))
            ->pluck('id')->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    public function save(AdminUser $actor, int $sourceId, array $ids, int $version): void
    {
        abort_unless($actor->isAdministrator(), 403);
        $this->database()->transaction(function () use ($actor, $sourceId, $ids, $version) {
            // Lock the department even when its configuration is initially empty.
            $source = Department::business()->whereKey($sourceId)->lockForUpdate()->firstOrFail();
            if (! $this->sourceDepartments()->contains('id', $sourceId)) {
                throw ValidationException::withMessages(['source_department_id' => __('asset_manager.bindings.source_invalid')]);
            }
            abort_unless($version === $this->version($sourceId), 409, __('asset_manager.bindings.conflict'));
            $targets = $this->targets()->keyBy('id');
            $ids = collect($ids)->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
            if (array_diff($ids, $targets->keys()->all())) {
                throw ValidationException::withMessages(['department_ids' => __('asset_manager.bindings.target_invalid')]);
            }
            $before = $this->selectedIds($sourceId);
            $labels = fn ($selected) => collect($selected)->mapWithKeys(fn ($id) => [$id => isset($targets[$id]) ? $this->label($targets[$id]) : '#'.$id])->all();
            $db = $this->database();
            $db->table('asset_computer_department_bindings')->where('source_department_id', $sourceId)->delete();
            foreach ($ids as $id) {
                $db->table('asset_computer_department_bindings')->insert([
                    'source_department_id' => $sourceId, 'target_department_id' => $id,
                    'updated_by' => $actor->id, 'created_at' => now('UTC'), 'updated_at' => now('UTC'),
                ]);
            }
            $db->table('asset_computer_department_binding_logs')->insert([
                'source_department_id' => $sourceId, 'source_name' => $this->label($source),
                'before_ids' => json_encode($before), 'after_ids' => json_encode($ids),
                'before_labels' => json_encode($labels($before), JSON_UNESCAPED_UNICODE),
                'after_labels' => json_encode($labels($ids), JSON_UNESCAPED_UNICODE),
                'actor_id' => $actor->id, 'actor_name' => $actor->name ?: $actor->username, 'created_at' => now('UTC'),
            ]);
        });
    }
}
