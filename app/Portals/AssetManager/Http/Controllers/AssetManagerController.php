<?php

namespace App\Portals\AssetManager\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AssetIpfoxyAccount;
use App\Models\AssetIpfoxyProxy;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\AssetManager\AssetManagerCatalog as Catalog;
use App\Portals\AssetManager\AssetManagerService;
use App\Portals\AssetManager\AssetManagerSpreadsheetService;
use App\Portals\AssetManager\IpfoxyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class AssetManagerController extends Controller
{
    public function __construct(private PortalEntryController $entries, private AssetManagerService $service, private AssetManagerSpreadsheetService $sheets, private IpfoxyService $ipfoxy) {}

    private function context(Request $request, string $kind, bool $write = false): array
    {
        Catalog::fields($kind);
        $c = $this->entries->portalContext($request);
        if ($c instanceof Response) {
            abort($c->getStatusCode() === 200 ? 403 : $c->getStatusCode());
        }abort_unless($c['portal']->code === 'asset-manager', 404);
        app()->setLocale('zh-CN');
        abort_unless($c['user']->can(Catalog::permission($kind)), 403);
        $menu = $c['portal']->menus->firstWhere('code', 'asset_manager_'.$kind);
        abort_unless($menu && $menu->is_enabled, 403);
        if ($write) {
            abort_unless($c['user']->can(Catalog::permission($kind).'.manage'), 403);
        }

return $c;
    }

    public function index(Request $request, string $kind)
    {
        $c = $this->context($request, $kind);
        $per = (int) $request->input('per_page', 20);
        if (! in_array($per, [20, 50, 100])) {
            $per = 20;
        }$query = $kind === 'ipfoxy' ? $this->ipfoxy->query($request) : $this->service->query($kind, $request);
        $cards = $kind === 'ipfoxy' ? [] : $this->service->cards($kind, clone $query);
        $rows = $kind === 'ipfoxy' ? $query->orderBy('expires_at')->orderBy('asset_ipfoxy_proxies.id')->paginate($per) : $query->orderByDesc('id')->paginate($per);
        $rows->withQueryString();

        return view('portals.asset-manager.'.$kind, [...$c, 'kind' => $kind, 'labels' => Catalog::labels($kind), 'fields' => Catalog::fields($kind), 'rows' => $rows, 'cards' => $cards, 'service' => $this->service, 'users' => $kind === 'computers' ? $this->service->users() : [], 'departments' => in_array($kind, ['resources', 'codex-renewals']) ? $this->service->departments() : [], 'accounts' => $kind === 'ipfoxy' ? AssetIpfoxyAccount::query()->orderBy('id')->get() : collect(), 'canManage' => $c['user']->can(Catalog::permission($kind).'.manage')]);
    }

    public function show(Request $request, string $kind, int $record)
    {
        $this->context($request, $kind);
        $class = Catalog::model($kind);

        return response()->json($class::query()->findOrFail($record));
    }

    public function save(Request $request, string $kind, ?int $record = null)
    {
        $c = $this->context($request, $kind, true);
        $class = Catalog::model($kind);
        $data = $this->service->validate($kind, $request->all());
        if ($record) {
            $class::query()->findOrFail($record)->update($data);
        } else {
            $class::query()->create($data + ['created_by' => $c['user']->id]);
        }

return response()->json(['message' => __('asset_manager.saved')]);
    }

    public function destroy(Request $request, string $kind, int $record)
    {
        $this->context($request, $kind, true);
        $class = Catalog::model($kind);
        $class::query()->findOrFail($record)->delete();

        return response()->json(['message' => __('asset_manager.deleted')]);
    }

    public function export(Request $request, string $kind)
    {
        $this->context($request, $kind);
        if ($request->boolean('template')) {
            abort_if($kind === 'ipfoxy', 404);

            return $this->sheets->download($kind, [], true);
        }$request->validate(['selected' => ['nullable', 'array', 'max:100'], 'selected.*' => ['integer', 'min:1']]);
        $q = $kind === 'ipfoxy' ? $this->ipfoxy->query($request) : $this->service->query($kind, $request);
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
        $result = $this->sheets->preview($kind, $request->file('file'));
        $token = (string) Str::uuid();
        Cache::put('asset-import:'.$token, ['actor' => $c['user']->id, 'kind' => $kind, ...$result], 900);

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
            $class = Catalog::model($kind);
            $model = new $class;
            $model->getConnection()->transaction(function () use ($result, $class, $c) {
                foreach ($result['rows'] as $row) {
                    $created = $row['created_at'] ?? null;
                    unset($row['created_at']);
                    $data = $this->service->validate($result['kind'], $row) + ['created_by' => $c['user']->id];
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
        $this->context($request, 'ipfoxy', true);
        if ($account) {
            abort_if(Cache::has('asset-ipfoxy-busy:'.$account), 409, __('asset_manager.busy'));
        }$data = $request->validate(['label' => ['required', 'string', 'max:191', Rule::unique(AssetIpfoxyAccount::class, 'label')->ignore($account)], 'api_id' => ['required', 'string', 'max:191', Rule::unique(AssetIpfoxyAccount::class, 'api_id')->ignore($account)], 'api_token' => [$account ? 'nullable' : 'required', 'string', 'max:2000']], [], __('asset_manager.fields'));
        if (empty($data['api_token'])) {
            unset($data['api_token']);
        }if ($account) {
            AssetIpfoxyAccount::query()->findOrFail($account)->update($data);
        } else {
            AssetIpfoxyAccount::query()->create($data);
        }

return response()->json(['message' => __('asset_manager.saved')]);
    }

    public function accountDelete(Request $request, int $account)
    {
        $this->context($request, 'ipfoxy', true);
        abort_if(Cache::has('asset-ipfoxy-busy:'.$account), 409, __('asset_manager.busy'));
        $a = AssetIpfoxyAccount::query()->findOrFail($account);
        $a->getConnection()->transaction(function () use ($a) {
            AssetIpfoxyProxy::query()->where('account_id', $a->id)->delete();
            $a->delete();
        });

        return response()->json(['message' => __('asset_manager.deleted')]);
    }

    public function sync(Request $request, int $account)
    {
        $c = $this->context($request, 'ipfoxy', true);

        return response()->json($this->ipfoxy->begin(AssetIpfoxyAccount::query()->findOrFail($account), (int) $c['user']->id));
    }

    public function syncStep(Request $request,string $sync)
    {
        $c = $this->context($request,'ipfoxy',true);

        return response()->json($this->ipfoxy->step($sync,(int) $c['user']->id));
    }
}
