<?php

namespace App\Portals\Assistant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AppLovinSpendScreenshot;
use App\Models\AppLovinAccountStoreBinding;
use App\Models\AssistantDomainStore;
use App\Models\FashionAdvertisingReport;
use App\Models\OperationReport;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\Assistant\AssistantPortalAuthorizationService;
use App\Portals\Assistant\AssistantStoreOwnerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class AppLovinSpendReviewController extends Controller
{
    private const DASHBOARD_PORTAL_CODE = 'yunying-kanban';

    public function __construct(
        private readonly PortalEntryController $portalEntries,
        private readonly AssistantPortalAuthorizationService $authorization,
        private readonly AssistantStoreOwnerService $storeOwners,
    ) {}

    public function index(Request $request): Response|View
    {
        $context = $this->portalEntries->portalContext($request);
        if ($context instanceof Response) return $context;
        $isDashboard = (string) $context['portal']->code === self::DASHBOARD_PORTAL_CODE;
        if (! $isDashboard) {
            $this->authorization->assertAssistantPortal($context['portal']);
            abort_unless($this->authorization->isAssistantDepartmentMember($context['user']), 403);
        }
        $visibleIds = $isDashboard
            ? $this->storeOwners->operationDepartments()->pluck('id')->map(fn ($id) => (int) $id)->all()
            : $this->storeOwners->visibleOperationDepartmentIds($context['user']);
        $filters = $request->validate(['operator' => ['nullable', 'string', 'max:100'], 'department_id' => ['nullable', 'integer', Rule::in($visibleIds)], 'over_threshold' => ['nullable', 'in:1']]);
        $query = OperationReport::query()->where('status', OperationReport::STATUS_APPROVED);
        if (! $isDashboard && ! $context['user']->isAdministrator()) {
            $visibleIds === [] ? $query->whereRaw('1=0') : $query->whereIn('operation_department_id', $visibleIds);
        }
        $query->when($filters['department_id'] ?? null, fn ($q,$id) => $q->where('operation_department_id',$id));
        $query->when($filters['operator'] ?? null, fn ($q, $name) => $q->where('operator_name', 'like', '%'.$name.'%'));
        $rows = $query->orderBy('report_date')->get()->flatMap(fn (OperationReport $report) => collect($report->advertising_rows ?: [])
            ->filter(fn (array $row) => mb_strtolower(trim((string) ($row['media_source'] ?? ''))) === 'applovin')
            ->map(fn (array $row) => [...$row, 'report_id' => $report->getKey(), 'date' => $report->report_date->format('Y-m-d'), 'operator_user_id' => (int)$report->operator_user_id, 'operator_name' => $report->operator_name, 'department' => $report->operation_department_name]));
        $fashionQuery = FashionAdvertisingReport::query()
            ->where('status', FashionAdvertisingReport::STATUS_APPROVED)
            ->with(['adSpendEntries', 'submitter.departments']);
        $fashionQuery->when($filters['operator'] ?? null, fn ($q, $name) => $q->where('submitter_name', 'like', '%'.$name.'%'));
        $fashionRows = $fashionQuery->get()
            ->filter(function (FashionAdvertisingReport $report) use ($filters): bool {
                if (empty($filters['department_id'])) return true;
                return $report->submitter?->departments?->contains('id', (int) $filters['department_id']) ?? false;
            })
            ->flatMap(function (FashionAdvertisingReport $report) {
                $department = $report->submitter?->departments?->first(fn ($item) => (bool) ($item->pivot->is_primary ?? false))
                    ?: $report->submitter?->departments?->first();
                return $report->adSpendEntries
                    ->filter(fn ($entry) => mb_strtolower(trim((string) $entry->media)) === 'applovin')
                    ->map(fn ($entry): array => [
                        'agency' => (string) $entry->agency,
                        'media_source' => (string) $entry->media,
                        'account_name' => (string) $entry->account_name,
                        'account_number' => (string) $entry->account_number,
                        'actual_spend' => (float) $entry->actual_spend_usd,
                        'report_id' => $report->getKey(),
                        'date' => $report->report_date->format('Y-m-d'),
                        'operator_user_id' => (int) $report->submitter_id,
                        'operator_name' => (string) $report->submitter_name,
                        'department' => (string) ($department?->name ?: '服装运营部门'),
                        'source' => 'fashion',
                    ]);
            });
        $rows = $rows->concat($fashionRows);
        $accounts = $rows->groupBy(fn (array $row) => implode('|', [$row['operator_name'], $row['agency'] ?? '', $row['account_name'] ?? '', $row['account_number'] ?? '']))
            ->map(function ($group): array { $first=$group->first(); $agency=(string)($first['agency']??'');$media=(string)($first['media_source']??'');$name=(string)($first['account_name']??'');$number=(string)($first['account_number']??''); return ['account_key'=>AppLovinSpendScreenshot::accountKey((int)$first['operator_user_id'],$agency,$media,$name,$number),'operator_user_id'=>(int)$first['operator_user_id'],'operator_name'=>$first['operator_name'],'department'=>$first['department'],'agency'=>$agency,'media'=>$media,'account_name'=>$name,'account_number'=>$number,'total'=>(float)$group->sum('actual_spend'),'details'=>$group->values()]; })->sortByDesc('total')->values();
        $screenshots = AppLovinSpendScreenshot::query()->whereIn('account_key', $accounts->pluck('account_key'))->get()->keyBy('account_key');
        $bindings = AppLovinAccountStoreBinding::query()->whereIn('account_key', $accounts->pluck('account_key'))->get()->keyBy('account_key');
        $stores = AssistantDomainStore::query()->whereNotNull('owner_user_id')->whereNotNull('domain')->where('domain', '!=', '')
            ->orderBy('domain')->get(['id', 'owner_user_id', 'shop_name', 'domain']);
        $accounts = $accounts->map(fn (array $account) => [...$account,
            'uploaded_screenshot' => $screenshots->get($account['account_key']),
            'store_binding' => $bindings->get($account['account_key']),
        ]);
        if (($filters['over_threshold'] ?? '') === '1') $accounts = $accounts->where('total', '>=', 5000)->values();
        $page=max(1,(int)$request->query('page',1));
        $accounts = new LengthAwarePaginator($accounts->forPage($page,20)->values(),$accounts->count(),20,$page,['path'=>$request->url(),'query'=>$request->query()]);
        $departmentOptions = $this->storeOwners->operationDepartments()->whereIn('id',$visibleIds)->values();

        return view('portals.assistant.daily-submissions.applovin-spend', [
            'portal'=>$context['portal']->loadMissing('menus'),'user'=>$context['user'],
            'operator'=>$filters['operator']??'','selectedDepartmentId'=>$filters['department_id']??null,'overThreshold'=>$filters['over_threshold']??'','departmentOptions'=>$departmentOptions,'accounts'=>$accounts,'threshold'=>5000,
            'appLovinIndexUrl'=>$isDashboard ? route('operation-dashboard.applovin-spends.index') : route('assistant.applovin-spends.index'),
            'appLovinScreenshotRoute'=>$isDashboard ? 'operation-dashboard.applovin-spends.screenshot' : 'assistant.applovin-spends.screenshot',
            'stores'=>$stores,
        ]);
    }

    public function bindStore(Request $request): RedirectResponse
    {
        $context = $this->portalEntries->portalContext($request);
        if ($context instanceof Response) abort($context->getStatusCode());
        $this->authorization->assertAssistantPortal($context['portal']);
        abort_unless($this->authorization->isAssistantDepartmentMember($context['user']), 403);
        $validated = $request->validate([
            'account_key' => ['required', 'string', 'size:64'],
            'store_id' => ['nullable', 'integer', Rule::exists((new AssistantDomainStore())->getTable(), 'id')],
        ]);

        $visibleIds = $this->storeOwners->visibleOperationDepartmentIds($context['user']);
        $account = OperationReport::query()->where('status', OperationReport::STATUS_APPROVED)
            ->when(! $context['user']->isAdministrator(), fn ($query) => $visibleIds === [] ? $query->whereRaw('1=0') : $query->whereIn('operation_department_id', $visibleIds))
            ->get()->flatMap(fn (OperationReport $report) => collect($report->advertising_rows ?: [])->map(function (array $row) use ($report): array {
                return [
                    'account_key' => AppLovinSpendScreenshot::accountKey((int) $report->operator_user_id, (string) ($row['agency'] ?? ''), (string) ($row['media_source'] ?? ''), (string) ($row['account_name'] ?? ''), (string) ($row['account_number'] ?? '')),
                    'operator_user_id' => (int) $report->operator_user_id,
                    'media' => mb_strtolower(trim((string) ($row['media_source'] ?? ''))),
                ];
            }))->first(fn (array $row) => $row['media'] === 'applovin' && hash_equals($row['account_key'], $validated['account_key']));
        if (! $account) {
            $account = FashionAdvertisingReport::query()->where('status', FashionAdvertisingReport::STATUS_APPROVED)
                ->with('adSpendEntries')->get()->flatMap(fn (FashionAdvertisingReport $report) => $report->adSpendEntries->map(fn ($entry): array => [
                    'account_key' => AppLovinSpendScreenshot::accountKey((int) $report->submitter_id, (string) $entry->agency, (string) $entry->media, (string) $entry->account_name, (string) $entry->account_number),
                    'operator_user_id' => (int) $report->submitter_id,
                    'media' => mb_strtolower(trim((string) $entry->media)),
                ]))->first(fn (array $row) => $row['media'] === 'applovin' && hash_equals($row['account_key'], $validated['account_key']));
        }
        abort_unless($account, 403);

        if (empty($validated['store_id'])) {
            AppLovinAccountStoreBinding::query()->where('account_key', $validated['account_key'])->delete();
            return back()->with('success', '店铺域名绑定已取消。');
        }

        abort_unless(AssistantDomainStore::query()->whereKey((int) $validated['store_id'])->where('owner_user_id', $account['operator_user_id'])->exists(), 422, '只能绑定该运营人员名下的店铺。');

        AppLovinAccountStoreBinding::query()->updateOrCreate(
            ['account_key' => $validated['account_key']],
            ['assistant_domain_store_id' => (int) $validated['store_id'], 'bound_by' => $context['user']->getKey()]
        );
        return back()->with('success', '店铺域名绑定成功。');
    }

    public function screenshot(Request $request, AppLovinSpendScreenshot $screenshot): BinaryFileResponse
    {
        $context = $this->portalEntries->portalContext($request);
        if ($context instanceof Response) abort($context->getStatusCode());
        $isDashboard = (string) $context['portal']->code === self::DASHBOARD_PORTAL_CODE;
        if (! $isDashboard) {
            $this->authorization->assertAssistantPortal($context['portal']);
            abort_unless($this->authorization->isAssistantDepartmentMember($context['user']), 403);
            if (! $context['user']->isAdministrator()) abort_unless(in_array((int)$screenshot->operation_department_id, $this->storeOwners->visibleOperationDepartmentIds($context['user']), true), 403);
        }
        abort_unless(Storage::disk('local')->exists($screenshot->screenshot_path), 404);
        return response()->file(Storage::disk('local')->path($screenshot->screenshot_path));
    }
}
