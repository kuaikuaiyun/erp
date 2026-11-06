<?php

namespace App\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\PortalHomeFolder;
use App\Models\PortalHomeFolderPortal;
use App\Models\Portal;
use App\Portal\Services\PortalAccessService;
use App\Portal\Services\PortalDomainService;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HomeController extends Controller
{
    public function __construct(
        private readonly PortalAccessService $access,
        private readonly PortalDomainService $domains,
    ) {
    }

    public function index(Content $content)
    {
        /** @var AdminUser $administrator */
        $administrator = Admin::user();
        $hour = now('Asia/Shanghai')->hour;
        $greetingKey = $hour < 12
            ? 'admin.greeting.morning'
            : ($hour < 18 ? 'admin.greeting.afternoon' : 'admin.greeting.evening');
        $systems = $this->access
            ->accessiblePortalsFor($administrator)
            ->map(function ($portal) use ($administrator): array {
                return [
                    'id' => (int) $portal->id,
                    'name' => $portal->name,
                    'remark' => $portal->remark,
                    'status' => $portal->status,
                    'url' => $this->domains->portalUrl($portal),
                    'can_enter' => $portal->isEnabled()
                        || ($portal->isMaintenance() && $administrator->isAdministrator()),
                ];
            })->values();
        $canManageFolders = $administrator->isAdministrator();
        $folderGroups = $this->folderGroups($systems, $canManageFolders);
        $assignedPortalIds = $folderGroups->flatMap(static fn (array $folder): array => collect($folder['systems'])->pluck('id')->all())->all();
        $ungroupedSystems = $systems->reject(static fn (array $system): bool => in_array($system['id'], $assignedPortalIds, true))->values();

        return $content
            ->title(__('portal.home.title'))
            ->description(__('portal.home.description'))
            ->body(view('admin.portal.dashboard', [
                'administrator' => $administrator,
                'greeting' => trans($greetingKey, ['name' => $administrator->name]),
                'today' => now('Asia/Shanghai')->format('Y-m-d'),
                'systems' => $systems,
                'folderGroups' => $folderGroups,
                'ungroupedSystems' => $ungroupedSystems,
                'canManageFolders' => $canManageFolders,
            ])->render());
    }

    public function storeFolder(Request $request): JsonResponse
    {
        $this->requireSystemAdministrator();
        $validated = $request->validate(['name' => ['required', 'string', 'max:100']]);
        $folder = PortalHomeFolder::query()->create([
            'name' => trim((string) $validated['name']),
            'sort' => ((int) PortalHomeFolder::query()->max('sort')) + 1,
        ]);

        return response()->json(['folder' => ['id' => $folder->id, 'name' => $folder->name]]);
    }

    public function updateFolder(Request $request, PortalHomeFolder $folder): JsonResponse
    {
        $this->requireSystemAdministrator();
        $validated = $request->validate(['name' => ['required', 'string', 'max:100']]);
        $folder->update(['name' => trim((string) $validated['name'])]);

        return response()->json(['folder' => ['id' => $folder->id, 'name' => $folder->name]]);
    }

    public function destroyFolder(PortalHomeFolder $folder): JsonResponse
    {
        $this->requireSystemAdministrator();
        DB::connection($folder->getConnectionName())->transaction(function () use ($folder): void {
            PortalHomeFolderPortal::query()->where('folder_id', $folder->id)->delete();
            $folder->delete();
        });

        return response()->json(['success' => true]);
    }

    public function saveFolderLayout(Request $request): JsonResponse
    {
        $this->requireSystemAdministrator();
        $validated = $request->validate([
            'folders' => ['nullable', 'array'],
            'folders.*.id' => ['required', 'integer', 'distinct'],
            'folders.*.portal_ids' => ['nullable', 'array'],
            'folders.*.portal_ids.*' => ['integer', 'distinct'],
            'ungrouped_portal_ids' => ['nullable', 'array'],
            'ungrouped_portal_ids.*' => ['integer', 'distinct'],
        ]);
        $folders = collect($validated['folders'] ?? []);
        $folderIds = $folders->pluck('id')->map(static fn ($id): int => (int) $id);
        abort_unless($folderIds->count() === PortalHomeFolder::query()->whereIn('id', $folderIds)->count(), 422);
        $portalIds = $folders->flatMap(static fn (array $folder): array => $folder['portal_ids'] ?? [])
            ->merge($validated['ungrouped_portal_ids'] ?? [])->map(static fn ($id): int => (int) $id);
        abort_unless($portalIds->count() === $portalIds->unique()->count(), 422);
        abort_unless($portalIds->count() === Portal::query()->whereIn('id', $portalIds)->count(), 422);

        DB::connection(config('admin.database.connection') ?: config('database.default'))->transaction(function () use ($folders, $portalIds): void {
            PortalHomeFolderPortal::query()->whereIn('portal_id', $portalIds)->delete();
            foreach ($folders as $folder) {
                foreach (array_values($folder['portal_ids'] ?? []) as $sort => $portalId) {
                    PortalHomeFolderPortal::query()->create(['folder_id' => $folder['id'], 'portal_id' => $portalId, 'sort' => $sort]);
                }
            }
        });

        return response()->json(['success' => true]);
    }

    /** @param \Illuminate\Support\Collection<int,array<string,mixed>> $systems */
    private function folderGroups($systems, bool $includeEmpty)
    {
        $systemMap = $systems->keyBy('id');
        return PortalHomeFolder::query()->with('portalAssignments')->orderBy('sort')->orderBy('id')->get()
            ->map(function (PortalHomeFolder $folder) use ($systemMap): array {
                return ['folder' => $folder, 'systems' => $folder->portalAssignments
                    ->map(fn (PortalHomeFolderPortal $assignment) => $systemMap->get((int) $assignment->portal_id))
                    ->filter()->values()];
            })->filter(fn (array $group): bool => $includeEmpty || $group['systems']->isNotEmpty())->values();
    }

    private function requireSystemAdministrator(): void
    {
        abort_unless(Admin::user()?->isAdministrator(), 403);
    }
}
