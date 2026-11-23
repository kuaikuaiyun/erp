<?php

namespace App\Portals\AssetManager\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Portals\AssetManager\AccountTransferService;
use App\Portals\AssetManager\AssetComputerDepartmentBindingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class AccountTransferController extends Controller
{
    public function __construct(private AccountController $accounts, private AccountTransferService $service) {}

    public function options(Request $request)
    {
        $c = $this->accounts->context($request, true);
        return response()->json(['companies' => $this->service->companies($c['user'])])->header('Cache-Control', 'no-store');
    }

    public function template(Request $request)
    {
        $c = $this->accounts->context($request, true);
        return $this->service->download($c['user']);
    }

    public function export(Request $request)
    {
        $c = $this->accounts->context($request);
        $data = $request->validate(['account_ids' => 'nullable|array|max:5000', 'account_ids.*' => 'required|integer|distinct|min:1',
            'search' => 'nullable|string|max:100', 'department_ids' => 'nullable|array|max:500', 'department_ids.*' => 'integer|distinct',
            'status' => 'nullable|in:0,1', 'employment_status' => 'nullable|in:employed,resigned'], __('asset_accounts.validation'), __('asset_accounts.attributes'));
        $query = $this->service->exportQuery($c['user']);
        if (!empty($data['account_ids'])) {
            $query->whereIn('id', $data['account_ids']);
            if ((clone $query)->count() !== count($data['account_ids'])) throw ValidationException::withMessages(['account_ids' => __('asset_accounts.transfer.selection_error')]);
        } else {
            if (filled($data['search'] ?? null)) {
                $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $data['search']).'%';
                $query->where(fn ($q) => $q->whereRaw("name LIKE ? ESCAPE '!'", [$term])->orWhereRaw("username LIKE ? ESCAPE '!'", [$term]));
            }
            if (!empty($data['department_ids'])) {
                $ids = array_intersect(app(AssetComputerDepartmentBindingService::class)->departmentIds($c['user']), array_map('intval', $data['department_ids']));
                $query->whereHas('departments', fn ($q) => $q->whereIn('id', $ids));
            }
            foreach (['status', 'employment_status'] as $field) if (filled($data[$field] ?? null)) $query->where($field, $data[$field]);
        }
        if ((clone $query)->count() > 5000) throw ValidationException::withMessages(['export' => __('account_transfer.export_limit')]);
        return $this->service->download($c['user'], $query);
    }

    public function assign(Request $request)
    {
        $c = $this->accounts->context($request, true);
        $data = $request->validate(['account_ids' => 'required|array|min:1|max:5000', 'account_ids.*' => 'required|integer|distinct|min:1', 'company_id' => 'required|integer|min:1'], __('asset_accounts.validation'), __('asset_accounts.attributes'));
        return response()->json($this->service->assign($c['user'], $data['account_ids'], (int) $data['company_id']));
    }

    public function preview(Request $request)
    {
        $c = $this->accounts->context($request, true);
        $request->validate(['file' => 'required|file|mimes:xlsx|max:5120'], __('asset_accounts.validation'), __('asset_accounts.attributes'));
        $rows = $this->service->sheets->read($request->file('file'));
        $checked = $this->service->validateRows($rows, $c['user']); $token = null;
        if (!$checked['errors']) {
            $token = bin2hex(random_bytes(32));
            Cache::put('hr.accounts.import.'.$token, ['actor' => $c['user']->id, 'browser' => hash('sha256', $request->session()->getId()), 'rows' => $rows], now()->addMinutes(10));
        }
        return response()->json(['token' => $token, 'count' => count($rows), 'errors' => $checked['errors'], 'rows' => array_map(fn ($r) => array_intersect_key($r, array_flip(['_line','username','name','company_name','company_id','department_codes','department_positions'])), $rows)])->header('Cache-Control','no-store');
    }

    public function commit(Request $request)
    {
        $c = $this->accounts->context($request, true);
        $data = $request->validate(['token' => ['required','string','regex:/^[a-f0-9]{64}$/D'], 'password' => 'required|string|min:8|max:255'], __('asset_accounts.validation'), __('asset_accounts.attributes'));
        $lock = Cache::lock('hr.accounts.import.lock.'.$data['token'], 600);
        if (!$lock->get()) throw ValidationException::withMessages(['file' => __('account_transfer.busy')]);
        try {
            $key = 'hr.accounts.import.'.$data['token']; $batch = Cache::get($key);
            if (!$batch || (int) $batch['actor'] !== (int) $c['user']->id || !hash_equals($batch['browser'], hash('sha256', $request->session()->getId()))) throw ValidationException::withMessages(['file' => __('account_transfer.expired')]);
            if (isset($batch['result'])) return response()->json($batch['result']);
            $result = $this->service->create($batch['rows'], $c['user'], $data['password']);
            $batch['result'] = $result; unset($batch['rows']); Cache::put($key, $batch, now()->addMinutes(10));
            return response()->json($result)->header('Cache-Control', 'no-store');
        } finally { $lock->release(); }
    }
}
