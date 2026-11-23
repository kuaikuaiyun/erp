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
        $query = $this->query($c['user']);
        if (filled($data['search'] ?? null)) {
            $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $data['search']).'%';
            $query->where(fn ($q) => $q->whereRaw("name LIKE ? ESCAPE '!'", [$term])->orWhereRaw("slug LIKE ? ESCAPE '!'", [$term]));
        }
        // Paginate whole department groups so children never become orphaned on another page.
        $groupSql = 'CASE WHEN parent_id IS NULL OR parent_id = 0 THEN id ELSE parent_id END';
        $rows = (new Department)->getConnection()->query()->fromSub((clone $query)->selectRaw($groupSql.' as group_id')->distinct(), 'department_groups')
            ->orderBy('group_id')->paginate((int) ($data['per_page'] ?? 20))->withQueryString();
        $groupIds = $rows->pluck('group_id')->all();
        $pivot = config('admin.database.role_users_table');
        $records = (clone $query)->whereIn(\Illuminate\Support\Facades\DB::raw($groupSql), $groupIds)->with('parent:id,name')->withCount([
            'children',
            'departmentMembers as manager_count' => fn ($q) => $q->where($pivot.'.position', 'manager'),
            'departmentMembers as deputy_count' => fn ($q) => $q->where($pivot.'.position', 'deputy'),
            'departmentMembers as member_count' => fn ($q) => $q->where($pivot.'.position', 'member'),
        ])->orderBy('sort')->orderBy('name')->get();
        $parents = Department::business()->whereIn('id', $groupIds)->get(['id','name']);
        $treeGroups = collect($groupIds)->map(function ($id) use ($records, $parents) {
            return ['id' => (int) $id, 'root' => $records->firstWhere('id', $id), 'context' => $parents->firstWhere('id', $id),
                'children' => $records->where('parent_id', $id)->values()];
        });
        return view('portals.asset-manager.departments', $c + ['rows' => $rows, 'treeGroups' => $treeGroups, 'filters' => $data, 'writeIds' => $this->bindings->departmentIds($c['user'], true)]);
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

    public function candidates(Request $request, int $department)
    {
        $c = $this->context($request, true);
        return response()->json(app(\App\Portals\AssetManager\DepartmentMemberService::class)->candidates($c['user'], $department, $request->all()))->header('Cache-Control','no-store');
    }

    public function addMembers(Request $request, int $department)
    {
        $c = $this->context($request, true);
        return response()->json(app(\App\Portals\AssetManager\DepartmentMemberService::class)->add($c['user'], $department, $request->all()))->header('Cache-Control','no-store');
    }

    public function members(Request $request, int $department)
    {
        $c = $this->context($request);
        $record = $this->query($c['user'])->findOrFail($department);
        $request->validate(['page' => 'nullable|integer|min:1', 'search' => 'nullable|string|max:100'], __('asset_accounts.validation'), __('asset_accounts.attributes'));
        $allowed = $this->bindings->departmentIds($c['user']);
        $query = $record->departmentMembers()->with(['company','departments'=>fn ($q)=>$q->whereIn('id',$allowed)]);
        if ($request->filled('search')) $query->where(fn ($q) => $q->where('name','like','%'.$request->input('search').'%')->orWhere('username','like','%'.$request->input('search').'%'));
        $rows = $query->orderBy('id')->paginate(20);
        $rows->setCollection($rows->getCollection()->map(fn ($user) => ['id' => $user->id,'name' => $user->name,'username' => $user->username,'position' => $user->pivot->position,'company' => $user->company?->name,'status' => (int) $user->status, 'departments'=>$user->departments->where('id','!=',$department)->map(fn ($d)=>['id'=>$d->id,'name'=>$d->name])->values()->all()]));
        $hasChildren = $record->children()->exists();
        $writable = $this->authorization->managedDepartmentIds($c['user'])->contains($department);
        $pivot = config('admin.database.role_users_table');
        $counts = $record->departmentMembers()->selectRaw($pivot.'.position as member_position, COUNT(*) as member_count')->groupBy($pivot.'.position')->pluck('member_count','member_position');
        return response()->json($rows->toArray() + ['department'=>['id'=>$record->id,'name'=>$record->name,'can_add'=>$writable && !$hasChildren,'has_children'=>$hasChildren,'writable'=>$writable,'counts'=>$counts]])->header('Cache-Control', 'no-store');
    }
}
