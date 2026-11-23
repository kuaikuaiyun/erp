<?php

namespace App\Portals\AssetManager\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\AssetManager\AccountAuthorizationService;
use App\Portals\AssetManager\AssetComputerDepartmentBindingService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DepartmentController extends Controller
{
    public function __construct(private PortalEntryController $entries, private AssetComputerDepartmentBindingService $bindings, private AccountAuthorizationService $authorization) {}

    private function context(Request $request, bool $write = false): array
    {
        $c = $this->entries->portalContext($request);
        abort_if($c instanceof Response, 403);
        abort_unless($c['portal']->code === 'asset-manager' && $request->routeIs('asset-manager.departments.*'), 404);
        $menu = $c['portal']->menus->firstWhere('code', 'asset_manager_departments');
        abort_unless($menu && $menu->is_enabled && ($c['user']->isAdministrator() || $c['user']->can('asset-manager.computers')), 403);
        if ($write) abort_unless($this->authorization->canManageOrganization($c['user']), 403);
        app()->setLocale('zh-CN'); return $c;
    }

    private function query($actor, bool $write = false)
    {
        return Department::business()->whereIn('id', $this->bindings->departmentIds($actor, $write));
    }

    public function index(Request $request)
    {
        $c = $this->context($request);
        $data = $request->validate(['search' => 'nullable|string|max:100','page' => 'nullable|integer|min:1','per_page' => 'nullable|in:20,50,100'], __('asset_accounts.validation'), __('asset_accounts.attributes'));
        $query = $this->query($c['user'])->with('parent')->withCount('departmentMembers');
        if (filled($data['search'] ?? null)) $query->where(fn ($q) => $q->where('name', 'like', '%'.$data['search'].'%')->orWhere('slug', 'like', '%'.$data['search'].'%'));
        $rows = $query->orderByRaw('COALESCE(parent_id, id)')->orderBy('sort')->orderBy('id')->paginate((int) ($data['per_page'] ?? 20))->withQueryString();
        return view('portals.asset-manager.departments', $c + ['rows' => $rows, 'filters' => $data, 'writeIds' => $this->bindings->departmentIds($c['user'], true)]);
    }

    public function show(Request $request, int $department)
    {
        $c = $this->context($request);
        $record = $this->query($c['user'])->with('parent:id,name')->findOrFail($department);
        return response()->json(['record' => $record->only(['id','name','short_name','slug','parent_id','sort','updated_at']) + ['parent_name' => $record->parent?->name]])->header('Cache-Control', 'no-store');
    }

    public function update(Request $request, int $department)
    {
        $c = $this->context($request, true);
        $data = $request->validate(['name' => 'required|string|max:50','short_name' => 'nullable|string|max:50','sort' => 'required|integer|min:0|max:999999',
            'slug' => 'prohibited', 'parent_id' => 'prohibited', 'permissions' => 'prohibited'], __('asset_accounts.validation'), __('asset_accounts.department_fields'));
        (new Department)->getConnection()->transaction(function () use ($c, $data, $department) {
            $record = $this->query($c['user'], true)->whereKey($department)->lockForUpdate()->firstOrFail();
            $organization = new \App\Services\DepartmentOrganizationService($this->authorization, app(\App\Portal\Services\PortalService::class));
            $organization->updateDepartment($record, $data, $c['user']);
        });
        return response()->json(['message' => __('asset_accounts.department_saved')]);
    }

    public function members(Request $request, int $department)
    {
        $c = $this->context($request);
        $record = $this->query($c['user'])->findOrFail($department);
        $request->validate(['page' => 'nullable|integer|min:1', 'search' => 'nullable|string|max:100'], __('asset_accounts.validation'), __('asset_accounts.attributes'));
        $query = $record->departmentMembers()->with('company');
        if ($request->filled('search')) $query->where(fn ($q) => $q->where('name','like','%'.$request->input('search').'%')->orWhere('username','like','%'.$request->input('search').'%'));
        $rows = $query->orderBy('id')->paginate(20);
        $rows->setCollection($rows->getCollection()->map(fn ($user) => ['id' => $user->id,'name' => $user->name,'username' => $user->username,'position' => $user->pivot->position,'company' => $user->company?->name,'status' => (int) $user->status]));
        return response()->json($rows)->header('Cache-Control', 'no-store');
    }
}
