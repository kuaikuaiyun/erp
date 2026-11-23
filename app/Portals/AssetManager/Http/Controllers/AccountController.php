<?php

namespace App\Portals\AssetManager\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\DingTalkCompany;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\AssetManager\AccountAuthorizationService;
use App\Portals\AssetManager\AccountDingTalkAccessService;
use App\Portals\AssetManager\AccountService;
use App\Portals\AssetManager\AssetComputerDepartmentBindingService;
use App\Portals\AssetManager\AssetComputerDepartmentScope;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class AccountController extends Controller
{
    public function __construct(private PortalEntryController $entries, private AccountAuthorizationService $authorization, private AccountService $service) {}

    public function context(Request $request, bool $write = false): array
    {
        $context = $this->entries->portalContext($request);
        if ($context instanceof Response) {
            abort($context->getStatusCode() === 200 ? 403 : $context->getStatusCode());
        }
        abort_unless($request->routeIs('asset-manager.accounts.*') && $context['portal']->code === 'asset-manager', 404);
        $menu = $context['portal']->menus->firstWhere('code', 'asset_manager_accounts');
        abort_unless($menu && $menu->is_enabled && ($context['user']->isAdministrator() || $context['user']->can('asset-manager.computers')), 403);
        if ($write) {
            abort_unless($this->authorization->canManageOrganization($context['user']), 403);
        }
        app()->setLocale('zh-CN');

        return $context;
    }

    public function index(Request $request)
    {
        $c = $this->context($request);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'], 'department_ids' => ['nullable', 'array', 'max:500'],
            'department_ids.*' => ['integer', 'distinct'], 'status' => ['nullable', Rule::in(['0', '1'])],
            'employment_status' => ['nullable', Rule::in(['employed', 'resigned'])], 'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'in:20,50,100'],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ], __('asset_accounts.validation'), __('asset_accounts.attributes'));
        $query = $this->authorization->accounts($c['user'])->with(['roles', 'company', 'dingTalkBindings.identity']);
        if ($request->filled('search')) {
            $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['search']).'%';
            $query->where(fn ($q) => $q->whereRaw("name LIKE ? ESCAPE '!'", [$term])->orWhereRaw("username LIKE ? ESCAPE '!'", [$term]));
        }
        if (! empty($filters['department_ids'])) {
            $visibleIds = app(AssetComputerDepartmentBindingService::class)->departmentIds($c['user']);
            $ids = array_intersect($visibleIds, array_map('intval', $filters['department_ids']));
            $query->whereHas('departments', fn ($q) => $q->whereIn('id', $ids));
        }
        foreach (['status', 'employment_status'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $filters[$field]);
            }
        }
        $rows = $query->orderBy('id', $filters['direction'] ?? 'desc')->paginate((int) ($filters['per_page'] ?? 20))->withQueryString();
        $access = new AccountDingTalkAccessService($c['user'], $this->authorization);
        $companyNames = DingTalkCompany::withTrashed()->pluck('name', 'corp_id');
        $writeIds = $this->authorization->managedDepartmentIds($c['user']);
        $canBind = $access->canAccess();
        $accounts = $rows->getCollection()->map(function ($account) use ($c, $access, $companyNames, $writeIds, $canBind) {
            $payload = $this->service->payload($c['user'], $account, $writeIds);
            $payload['bindings'] = $account->dingTalkBindings->filter(fn ($b) => $b->active && $b->identity?->active)->map(fn ($b) => ($companyNames[$b->identity->corp_id] ?? $b->identity->corp_id).' · '.$b->identity->name)->values()->all();
            $payload['can_bind'] = $payload['writable'] && $canBind && ($access->isOwner() || ! $payload['administrator']);

            return $payload;
        });

        return view('portals.asset-manager.accounts', $c + compact('rows', 'accounts', 'filters') + [
            'departments' => app(AssetComputerDepartmentScope::class)->departments($c['user']),
            'canManage' => $this->authorization->canManageOrganization($c['user']), 'owner' => $access->isOwner(),
        ]);
    }

    public function show(Request $request, int $account)
    {
        $c = $this->context($request);
        $row = $this->authorization->accounts($c['user'])->findOrFail($account);

        return response()->json(['account' => $this->service->payload($c['user'], $row), 'departments' => $this->service->departments($c['user'], $row)])->header('Cache-Control', 'no-store');
    }

    public function options(Request $request)
    {
        $c = $this->context($request, true);

        return response()->json(['departments' => $this->service->departments($c['user'])])->header('Cache-Control', 'no-store');
    }

    public function store(Request $request)
    {
        $c = $this->context($request, true);
        $this->service->save($request, $c['user']);

        return response()->json(['message' => __('asset_accounts.saved')]);
    }

    public function update(Request $request, int $account)
    {
        $c = $this->context($request, true);
        $this->service->save($request, $c['user'], $account);

        return response()->json(['message' => __('asset_accounts.saved')]);
    }

    public function destroy(Request $request, int $account)
    {
        $c = $this->context($request, true);
        $this->service->delete($c['user'], $account);

        return response()->json(['message' => __('asset_accounts.deleted')]);
    }

    public function permissions(Request $request, int $account)
    {
        $c = $this->context($request);
        abort_unless($c['user']->isAdministrator(), 403);
        $row = $this->authorization->accounts($c['user'])->findOrFail($account);
        abort_if($row->isAdministrator(), 403);

        return response()->json($this->service->permissions($row))->header('Cache-Control', 'no-store');
    }

    public function savePermissions(Request $request, int $account)
    {
        $c = $this->context($request, true);
        abort_unless($c['user']->isAdministrator(), 403);
        $data = $request->validate(['permissions' => ['present', 'array'], 'permissions.*' => [Rule::in(['allow', 'deny'])]], __('asset_accounts.validation'), __('asset_accounts.attributes'));
        $row = $this->authorization->accounts($c['user'])->findOrFail($account);
        $this->service->savePermissions($c['user'], $row, $data['permissions']);

        return response()->json(['message' => __('asset_accounts.saved')]);
    }
}
