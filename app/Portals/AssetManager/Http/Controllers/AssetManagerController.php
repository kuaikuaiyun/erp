<?php

namespace App\Portals\AssetManager\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AssetIpfoxyAccount;
use App\Models\AssetIpfoxyProxy;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\AssetManager\AssetComputerDepartmentScope;
use App\Portals\AssetManager\AssetManagerCatalog as Catalog;
use App\Portals\AssetManager\AssetManagerDepartmentScope;
use App\Portals\AssetManager\AssetManagerService;
use App\Portals\AssetManager\AssetManagerSpreadsheetService;
use App\Portals\AssetManager\IpfoxyService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class AssetManagerController extends Controller
{
    public function __construct(private PortalEntryController $entries, private AssetManagerService $service, private AssetManagerSpreadsheetService $sheets, private IpfoxyService $ipfoxy, private AssetManagerDepartmentScope $scope, private AssetComputerDepartmentScope $computerScope) {}

    public function retired(): never
    {
        abort(404);
    }

    private function context(Request $request, string $kind, bool $write = false): array
    {
        Catalog::fields($kind);
        $c = $this->entries->portalContext($request);
        if ($c instanceof Response) {
            abort($c->getStatusCode() === 200 ? 403 : $c->getStatusCode());
        }
        $moved = $kind !== 'computers';
        abort_unless($request->routeIs($moved ? 'assistant.assets.*' : 'asset-manager.*'), 404);
        abort_unless($c['portal']->code === ($moved ? 'zhuli' : 'asset-manager'), 404);
        if ($moved) {
            $this->scope->ensureMember($c['user']);
        }
        app()->setLocale('zh-CN');
        abort_unless($c['user']->can(Catalog::permission($kind)), 403);
        $menu = $c['portal']->menus->firstWhere('code', 'asset_manager_'.$kind);
        abort_unless($menu && $menu->is_enabled, 403);
        if ($write) {
            abort_unless($kind === 'computers' ? $this->computerScope->canManage($c['user']) : $c['user']->can(Catalog::permission($kind).'.manage'), 403);
        }

        return $c;
    }

    private function records(string $kind, array $c): Builder
    {
        $class = Catalog::model($kind);
        $query = $class::query();

        return $kind === 'computers' ? $this->computerScope->apply($query, $c['user']) : $this->scope->apply($query, $c['user']);
    }

    private function accounts(array $c): Builder
    {
        return $this->scope->apply(AssetIpfoxyAccount::query(), $c['user']);
    }

    private function listing(Request $request, string $kind, array $c): Builder
    {
        $query = $kind === 'ipfoxy' ? $this->ipfoxy->query($request) : $this->service->query($kind, $request);
        if ($kind === 'computers') {
            $this->computerScope->apply($query, $c['user']);
            if ($request->filled('filter.owner_department_id')) {
                $request->validate(['filter.owner_department_id' => ['integer', 'min:0']]);
                $owner = (int) $request->input('filter.owner_department_id');
                $owner === 0 ? $query->whereNull('owner_department_id') : $query->where('owner_department_id', $owner);
            }
        }
        if ($kind !== 'computers') {
            $column = $kind === 'ipfoxy' ? 'a.owner_department_id' : $query->getModel()->qualifyColumn('owner_department_id');
            $this->scope->apply($query, $c['user'], $column);
            if ($request->filled('filter.owner_department_id')) {
                $request->validate(['filter.owner_department_id' => ['integer', 'min:1']]);
                $query->where($column, $request->input('filter.owner_department_id'));
            }
        }

        return $query;
    }

    public function index(Request $request, string $kind)
    {
        $c = $this->context($request, $kind);
        $per = (int) $request->input('per_page', 20);
        if (! in_array($per, [20, 50, 100])) {
            $per = 20;
        }$query = $this->listing($request, $kind, $c);
        $cards = $kind === 'ipfoxy' ? [] : $this->service->cards($kind, clone $query);
        $rows = $kind === 'ipfoxy' ? $query->orderBy('expires_at')->orderBy('asset_ipfoxy_proxies.id')->paginate($per) : $query->orderByDesc('id')->paginate($per);
        $rows->withQueryString();
        $computerUsers = $kind === 'computers' ? $this->computerScope->users($c['user']) : [];

        return view('portals.asset-manager.'.$kind, [
            ...$c,
            'kind' => $kind,
            'labels' => Catalog::labels($kind),
            'fields' => ['owner_department_id' => 'department'] + Catalog::fields($kind),
            'ownerDepartments' => $kind === 'computers' ? $this->computerScope->departments($c['user']) : $this->scope->departments($c['user']),
            'routePrefix' => $kind === 'computers' ? 'asset-manager' : 'assistant.assets',
            'assetBase' => $kind === 'computers' ? url('/asset-manager') : url('/assistant/assets'),
            'rows' => $rows,
            'cards' => $cards,
            'service' => $this->service,
            'users' => $computerUsers,
            'filterUsers' => $kind === 'computers' && $request->filled('filter.owner_department_id') ? $this->computerScope->users($c['user'], (int) $request->input('filter.owner_department_id')) : $computerUsers,
            'writeUsers' => $kind === 'computers' ? $this->computerScope->users($c['user'], null, true) : [],
            'userDepartments' => $kind === 'computers' ? $this->computerScope->userDepartments($c['user'], array_keys($computerUsers)) : [],
            'departments' => in_array($kind, ['resources', 'codex-renewals']) ? $this->service->departments() : [],
            'accounts' => $kind === 'ipfoxy' ? $this->accounts($c)->orderBy('id')->get() : collect(),
            'canManage' => $kind === 'computers' ? $this->computerScope->canManage($c['user']) : $c['user']->can(Catalog::permission($kind).'.manage'),
            'writableDepartmentIds' => $kind === 'computers' ? $this->computerScope->writableDepartmentIds($c['user']) : [],
        ]);
    }

    public function show(Request $request, string $kind, int $record)
    {
        $c = $this->context($request, $kind);

        return response()->json($this->records($kind, $c)->findOrFail($record));
    }

    public function save(Request $request, string $kind, ?int $record = null)
    {
        $c = $this->context($request, $kind, true);
        $class = Catalog::model($kind);
        $existing = $record ? $this->records($kind, $c)->findOrFail($record) : null;
        if ($kind === 'computers' && $existing) {
            $this->computerScope->ensureWritable($c['user'], $existing->owner_department_id === null ? null : (int) $existing->owner_department_id);
        }
        $validationInput = $request->all();
        if ($kind === 'computers' && $existing && (int) ($validationInput['owner_department_id'] ?? $existing->owner_department_id) === (int) $existing->owner_department_id
            && array_key_exists('user_id', $validationInput) && (string) ($validationInput['user_id'] ?? '') === (string) ($existing->user_id ?? '')) {
            // Preserve the historical assignee snapshot when unrelated fields are edited.
            unset($validationInput['user_id']);
        }
        $data = $this->service->validate($kind, $validationInput);
        if ($kind === 'computers') {
            $input = $request->all();
            if ($existing && ! array_key_exists('owner_department_id', $input)) {
                $input['owner_department_id'] = $existing->owner_department_id;
            }
            $data['owner_department_id'] = $this->computerScope->owner($c['user'], $input);
            $selectedUser = array_key_exists('user_id', $data) ? $data['user_id'] : $existing?->user_id;
            $unchanged = $existing && (int) $existing->owner_department_id === $data['owner_department_id']
                && (! array_key_exists('user_id', $data) || (string) ($data['user_id'] ?? '') === (string) ($existing->user_id ?? ''));
            if (! $unchanged) {
                $this->computerScope->validateUser($c['user'], $selectedUser === null ? null : (int) $selectedUser, $data['owner_department_id']);
            }
        }
        if ($kind !== 'computers') {
            $data['owner_department_id'] = $this->scope->owner($c['user'], $request->all());
        }
        if ($record) {
            $existing->update($data);
        } else {
            $class::query()->create($data + ['created_by' => $c['user']->id]);
        }

        return response()->json(['message' => __('asset_manager.saved')]);
    }

    public function destroy(Request $request, string $kind, int $record)
    {
        $c = $this->context($request, $kind, true);
        $row = $this->records($kind, $c)->findOrFail($record);
        if ($kind === 'computers') {
            $this->computerScope->ensureWritable($c['user'], $row->owner_department_id === null ? null : (int) $row->owner_department_id);
        }
        $row->delete();

        return response()->json(['message' => __('asset_manager.deleted')]);
    }

    public function export(Request $request, string $kind)
    {
        $c = $this->context($request, $kind);
        if ($request->boolean('template')) {
            abort_if($kind === 'ipfoxy', 404);

            return $this->sheets->download($kind, [], true);
        }$request->validate(['selected' => ['nullable', 'array', 'max:100'], 'selected.*' => ['integer', 'min:1']]);
        $q = $this->listing($request, $kind, $c);
        if ($request->filled('selected')) {
            $q->whereIn($kind === 'ipfoxy' ? 'asset_ipfoxy_proxies.id' : 'id', $request->input('selected'));
        }

        return $this->sheets->download($kind, $q->orderBy($kind === 'ipfoxy' ? 'asset_ipfoxy_proxies.id' : 'id')->cursor());
    }

    public function preview(Request $request, string $kind)
    {
        $c = $this->context($request, $kind, true);
        abort_if($kind === 'ipfoxy', 404);
        $request->validate(['file' => ['required', 'file', 'max:20480', 'mimes:xlsx,csv,txt']]);
        $owner = $kind === 'computers' ? $this->computerScope->owner($c['user'], $request->all()) : $this->scope->owner($c['user'], $request->all());
        $result = $this->sheets->preview($kind, $request->file('file'), $this->records($kind, $c), $kind === 'computers' ? $this->computerScope->users($c['user'], $owner, true) : null);
        if ($owner !== null) {
            $result['rows'] = array_map(fn ($row) => $row + ['owner_department_id' => $owner], $result['rows']);
        }
        $token = (string) Str::uuid();
        Cache::put('asset-import:'.$token, ['actor' => $c['user']->id, 'kind' => $kind, 'owner_department_id' => $owner, ...$result], 900);

        return response()->json(['token' => $token, 'valid' => count($result['rows']), 'skipped' => $result['skipped'], 'errors' => $result['errors'], 'rows' => array_slice($result['rows'], 0, 20)]);
    }

    public function import(Request $request, string $kind)
    {
        $c = $this->context($request, $kind, true);
        abort_if($kind === 'ipfoxy', 404);
        $request->validate(['token' => ['required', 'uuid']]);
        $key = 'asset-import:'.$request->input('token');
        $lock = Cache::lock($key.':lock', 120);
        abort_unless($lock->get(), 409);
        try {
            $result = Cache::get($key);
            abort_unless($result && $result['actor'] === $c['user']->id && $result['kind'] === $kind, 422, __('asset_manager.failed'));
            abort_if($result['errors'] !== [] || $result['rows'] === [], 422, __('asset_manager.invalid'));
            $owner = $kind === 'computers' ? $this->computerScope->owner($c['user'], ['owner_department_id' => $result['owner_department_id'] ?? null]) : $this->scope->owner($c['user'], ['owner_department_id' => $result['owner_department_id'] ?? null]);
            $class = Catalog::model($kind);
            $model = new $class;
            $model->getConnection()->transaction(function () use ($result, $class, $c, $owner) {
                $importUsers = $result['kind'] === 'computers' ? $this->computerScope->users($c['user'], $owner, true) : null;
                foreach ($result['rows'] as $row) {
                    $created = $row['created_at'] ?? null;
                    unset($row['created_at']);
                    $data = $this->service->validate($result['kind'], $row) + ['created_by' => $c['user']->id];
                    if ($result['kind'] === 'computers') {
                        $this->computerScope->validateUser($c['user'], isset($data['user_id']) ? (int) $data['user_id'] : null, $owner, $importUsers);
                    }
                    if ($owner !== null) {
                        $data['owner_department_id'] = $owner;
                    }
                    if ($created) {
                        $data['created_at'] = $created;
                    }$class::query()->create($data);
                }
            });
            Cache::forget($key);

            return response()->json(['message' => __('asset_manager.import_result', ['created' => count($result['rows']), 'skipped' => $result['skipped']])]);
        } finally {
            $lock->release();
        }
    }

    public function accountSave(Request $request, ?int $account = null)
    {
        $c = $this->context($request, 'ipfoxy', true);
        $existing = $account ? $this->accounts($c)->findOrFail($account) : null;
        $owner = $this->scope->owner($c['user'], $request->all());
        if ($account) {
            abort_if(Cache::has('asset-ipfoxy-busy:'.$account), 409, __('asset_manager.busy'));
        }$data = $request->validate(['label' => ['required', 'string', 'max:191', Rule::unique(AssetIpfoxyAccount::class, 'label')->ignore($account)], 'api_id' => ['required', 'string', 'max:191', Rule::unique(AssetIpfoxyAccount::class, 'api_id')->ignore($account)], 'api_token' => [$account ? 'nullable' : 'required', 'string', 'max:2000']], [], __('asset_manager.fields'));
        $data['owner_department_id'] = $owner;
        if (empty($data['api_token'])) {
            unset($data['api_token']);
        }if ($account) {
            $existing->update($data);
        } else {
            AssetIpfoxyAccount::query()->create($data);
        }

        return response()->json(['message' => __('asset_manager.saved')]);
    }

    public function accountDelete(Request $request, int $account)
    {
        $c = $this->context($request, 'ipfoxy', true);
        $a = $this->accounts($c)->findOrFail($account);
        abort_if(Cache::has('asset-ipfoxy-busy:'.$account), 409, __('asset_manager.busy'));
        $a->getConnection()->transaction(function () use ($a) {
            AssetIpfoxyProxy::query()->where('account_id', $a->id)->delete();
            $a->delete();
        });

        return response()->json(['message' => __('asset_manager.deleted')]);
    }

    public function sync(Request $request, int $account)
    {
        $c = $this->context($request, 'ipfoxy', true);

        return response()->json($this->ipfoxy->begin($this->accounts($c)->findOrFail($account), (int) $c['user']->id));
    }

    public function syncStep(Request $request, string $sync)
    {
        $c = $this->context($request, 'ipfoxy', true);

        $state = Cache::get('asset-ipfoxy-sync:'.$sync);
        abort_unless($state && $state['actor'] === (int) $c['user']->id, 404);
        $this->accounts($c)->findOrFail($state['account_id']);

        return response()->json($this->ipfoxy->step($sync, (int) $c['user']->id));
    }
}
