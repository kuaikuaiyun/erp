<?php

namespace App\Portals\Assistant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AppLovinAccountStoreBinding;
use App\Models\AppLovinAccountRiskNote;
use App\Models\AppLovinSpendScreenshot;
use App\Models\AssistantDomainStore;
use App\Models\AssistantDomainStoreOwnerHistory;
use App\Models\Department;
use App\Models\FashionAdvertisingReport;
use App\Models\OperationReport;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\Assistant\AssistantPortalAuthorizationService;
use App\Portals\Assistant\AssistantStoreOwnerService;
use App\Portals\Assistant\OperationDashboardDepartmentScopeService;
use App\Portals\Fashion\OperationProjectCatalog;
use App\Services\AppLovinAccountStoreBindingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class AppLovinSpendReviewController extends Controller
{
    private const DASHBOARD_PORTAL_CODE = 'yunying-kanban';

    public function __construct(
        private readonly PortalEntryController $portalEntries,
        private readonly AssistantPortalAuthorizationService $authorization,
        private readonly AssistantStoreOwnerService $storeOwners,
        private readonly OperationDashboardDepartmentScopeService $dashboardScopes,
    ) {}

    public function index(Request $request): Response|View
    {
        $context = $this->portalEntries->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }
        $isDashboard = (string) $context['portal']->code === self::DASHBOARD_PORTAL_CODE;
        if (! $isDashboard) {
            $this->authorization->assertAssistantPortal($context['portal']);
            abort_unless($this->authorization->isAssistantDepartmentMember($context['user']), 403);
        }
        $visibleIds = $isDashboard
            ? $this->dashboardScopes->visibleDepartmentIds($context['user'])
            : $this->storeOwners->visibleOperationDepartmentIds($context['user']);
        $allowedDepartmentIds = $context['user']->isAdministrator()
            ? Department::business()->pluck('id')->map(fn ($id): int => (int) $id)->all()
            : $visibleIds;
        $filters = $request->validate([
            'operator' => ['nullable', 'string', 'max:100'],
            'department_ids' => ['nullable', 'array'],
            'department_ids.*' => ['integer', 'distinct', Rule::in($allowedDepartmentIds)],
            'over_threshold' => ['nullable', 'in:1'],
            'account_number' => ['nullable', 'string', 'max:191'],
            'per_page' => ['nullable', 'integer', Rule::in([20, 50, 100])],
        ]);
        $selectedDepartmentIds = collect($filters['department_ids'] ?? [])->map(fn ($id) => (int) $id)->unique()->values()->all();
        $accountNumber = trim((string) ($filters['account_number'] ?? ''));
        $accountNumberNeedle = mb_strtolower($accountNumber);
        $accountNumberLike = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $accountNumber).'%';
        $query = OperationReport::query()->where('status', OperationReport::STATUS_APPROVED);
        if ($isDashboard || ! $context['user']->isAdministrator()) {
            $visibleIds === [] ? $query->whereRaw('1=0') : $query->whereIn('operation_department_id', $visibleIds);
        }
        if ($accountNumber !== '') {
            // advertising_rows is historical JSON data. Filtering it before hydration avoids
            // decoding and aggregating every approved report for an account search.
            $query->where('advertising_rows', 'like', $accountNumberLike);
        }
        $rows = $query->select(['id', 'report_date', 'operator_user_id', 'operator_name', 'operation_department_id', 'operation_department_name', 'advertising_rows'])
            ->orderBy('report_date')->get()->flatMap(fn (OperationReport $report) => collect($report->advertising_rows ?: [])
            ->filter(fn (array $row) => mb_strtolower(trim((string) ($row['media_source'] ?? ''))) === 'applovin'
                && ($accountNumberNeedle === '' || str_contains(mb_strtolower((string) ($row['account_number'] ?? '')), $accountNumberNeedle)))
            ->map(fn (array $row) => [...$row, 'report_id' => $report->getKey(), 'date' => $report->report_date->format('Y-m-d'), 'operator_user_id' => (int) $report->operator_user_id, 'operator_name' => $report->operator_name, 'department_id' => (int) $report->operation_department_id, 'department' => $report->operation_department_name]));
        $operationProjects = OperationProjectCatalog::all();
        $projectDepartmentIds = collect(array_keys($operationProjects))->mapWithKeys(
            fn (string $code): array => [$code => OperationProjectCatalog::departmentIds($code)->all()]
        );
        $fashionEntryFilter = function ($query) use ($accountNumber, $accountNumberLike): void {
            $query->whereRaw('LOWER(TRIM(media)) = ?', ['applovin']);
            if ($accountNumber !== '') {
                $query->where('account_number', 'like', $accountNumberLike);
            }
        };
        $fashionQuery = FashionAdvertisingReport::query()
            ->whereIn('project_code', array_keys($operationProjects))
            ->where('status', FashionAdvertisingReport::STATUS_APPROVED)
            ->whereHas('adSpendEntries', $fashionEntryFilter)
            ->with(['adSpendEntries' => $fashionEntryFilter, 'submitter.departments'])
            ->select(['id', 'project_code', 'report_date', 'submitter_id', 'submitter_name']);
        if ($isDashboard || ! $context['user']->isAdministrator()) {
            $visibleIds === []
                ? $fashionQuery->whereRaw('1=0')
                : $fashionQuery->whereHas('submitter.departments', fn ($query) => $query->whereIn('admin_roles.id', $visibleIds));
        }
        $restrictToVisibleDepartments = $isDashboard || ! $context['user']->isAdministrator();
        $fashionRows = $fashionQuery->get()
            ->flatMap(function (FashionAdvertisingReport $report) use ($restrictToVisibleDepartments, $visibleIds, $projectDepartmentIds) {
                $departments = $report->submitter?->departments ?? collect();
                $reportProjectDepartmentIds = $projectDepartmentIds->get((string) $report->project_code, []);
                $department = $departments->first(fn ($item) => in_array((int) $item->id, $reportProjectDepartmentIds, true)
                    && (! $restrictToVisibleDepartments || in_array((int) $item->id, $visibleIds, true)));

                return $report->adSpendEntries->map(fn ($entry): array => [
                        'agency' => (string) $entry->agency,
                        'media_source' => (string) $entry->media,
                        'account_name' => (string) $entry->account_name,
                        'account_number' => (string) $entry->account_number,
                        'actual_spend' => (float) $entry->actual_spend_usd,
                        'report_id' => $report->getKey(),
                        'date' => $report->report_date->format('Y-m-d'),
                        'operator_user_id' => (int) $report->submitter_id,
                        'operator_name' => (string) $report->submitter_name,
                        'department_id' => (int) ($department?->id ?: 0),
                        'department' => (string) ($department?->name ?: '未设置项目团队'),
                        'source' => 'fashion',
                    ]);
            });
        $rows = $rows->concat($fashionRows);
        if ($isDashboard || ! $context['user']->isAdministrator()) {
            $rows = $rows->whereIn('department_id', $visibleIds)->values();
        }
        $availableDepartmentIds = $rows->pluck('department_id')->map(fn ($id) => (int) $id)->filter()->unique()->values();
        $accounts = $rows->groupBy(function (array $row): string {
            $normalizedNumber = preg_replace('/[^0-9a-z]+/i', '', mb_strtolower(trim((string) ($row['account_number'] ?? '')))) ?? '';
            if ($normalizedNumber !== '') {
                return 'number|'.$normalizedNumber;
            }

            return implode('|', [
                'legacy',
                (int) ($row['operator_user_id'] ?? 0),
                mb_strtolower(trim((string) ($row['agency'] ?? ''))),
                mb_strtolower(trim((string) ($row['account_name'] ?? ''))),
            ]);
        })
            ->map(function ($group): array {
                $ordered = $group->sortBy(fn (array $row): string => implode('|', [
                    (string) ($row['date'] ?? ''),
                    str_pad((string) ($row['report_id'] ?? 0), 20, '0', STR_PAD_LEFT),
                    str_pad((string) ($row['operator_user_id'] ?? 0), 20, '0', STR_PAD_LEFT),
                ]))->values();
                $current = $ordered->last();
                $agency = trim((string) ($current['agency'] ?? ''));
                $media = 'AppLovin';
                $number = trim((string) ($current['account_number'] ?? ''));
                $names = $ordered->pluck('account_name')->map(fn ($name) => trim((string) $name))->filter()->values();
                $name = $names->filter(fn ($candidate) => $candidate !== $number && ! ctype_digit($candidate))->last() ?: $names->last() ?: $number;
                $currentKey = AppLovinSpendScreenshot::accountKey(
                    (int) ($current['operator_user_id'] ?? 0),
                    (string) ($current['agency'] ?? ''),
                    (string) ($current['media_source'] ?? ''),
                    (string) ($current['account_name'] ?? ''),
                    (string) ($current['account_number'] ?? '')
                );
                $keys = collect([$currentKey])->merge($ordered->map(fn (array $row) => AppLovinSpendScreenshot::accountKey(
                    (int) ($row['operator_user_id'] ?? 0),
                    (string) ($row['agency'] ?? ''),
                    (string) ($row['media_source'] ?? ''),
                    (string) ($row['account_name'] ?? ''),
                    (string) ($row['account_number'] ?? '')
                )))->unique()->values();

                $usageHistory = [];
                foreach ($ordered as $row) {
                    $operatorId = (int) ($row['operator_user_id'] ?? 0);
                    $date = (string) ($row['date'] ?? '');
                    $lastIndex = count($usageHistory) - 1;
                    if ($lastIndex >= 0 && $usageHistory[$lastIndex]['operator_user_id'] === $operatorId) {
                        $usageHistory[$lastIndex]['end_date'] = $date;
                        $usageHistory[$lastIndex]['spend'] += (float) ($row['actual_spend'] ?? 0);
                        continue;
                    }
                    $usageHistory[] = [
                        'operator_user_id' => $operatorId,
                        'operator_name' => (string) ($row['operator_name'] ?? ''),
                        'department_id' => (int) ($row['department_id'] ?? 0),
                        'department' => (string) ($row['department'] ?? ''),
                        'start_date' => $date,
                        'end_date' => $date,
                        'spend' => (float) ($row['actual_spend'] ?? 0),
                    ];
                }

                return [
                    'account_key' => $keys->first(),
                    'account_keys' => $keys->values(),
                    'operator_user_id' => (int) $current['operator_user_id'],
                    'operator_user_ids' => $ordered->pluck('operator_user_id')->map(fn ($id) => (int) $id)->filter()->unique()->values()->all(),
                    'operator_name' => (string) $current['operator_name'],
                    'department' => (string) $current['department'],
                    'department_ids' => $ordered->pluck('department_id')->map(fn ($id) => (int) $id)->filter()->unique()->values()->all(),
                    'agency' => $agency,
                    'media' => $media,
                    'account_name' => $name,
                    'account_number' => $number,
                    'total' => (float) $ordered->sum('actual_spend'),
                    'usage_history' => $usageHistory,
                    'details' => $ordered,
                ];
            })->sortByDesc('total')->values();
        if ($selectedDepartmentIds !== []) {
            $accounts = $accounts->filter(fn (array $account): bool => collect($account['department_ids'])
                ->intersect($selectedDepartmentIds)->isNotEmpty())->values();
        }
        $operator = trim((string) ($filters['operator'] ?? ''));
        if ($operator !== '') {
            $accounts = $accounts->filter(fn (array $account): bool => collect($account['usage_history'])
                ->contains(fn (array $usage): bool => mb_stripos($usage['operator_name'], $operator) !== false))->values();
        }
        if (($filters['over_threshold'] ?? '') === '1') {
            $accounts = $accounts->where('total', '>=', 5000)->values();
        }
        if ($accountNumber !== '') {
            $accounts = $accounts->filter(fn (array $account): bool => str_contains(
                mb_strtolower((string) ($account['account_number'] ?? '')),
                $accountNumberNeedle
            ))->values();
        }

        $allAccountKeys = $accounts->pluck('account_keys')->flatten()->unique()->values();
        $screenshots = AppLovinSpendScreenshot::query()->whereIn('account_key', $allAccountKeys)->get()->keyBy('account_key');
        $bindings = AppLovinAccountStoreBinding::query()->whereIn('account_key', $allAccountKeys)->get()->keyBy('account_key');
        $accountNumbers = $accounts->pluck('account_number')
            ->map(fn ($number) => AppLovinAccountRiskNote::normalizeAccountNumber((string) $number))
            ->filter()->unique()->values();
        $riskNotes = AppLovinAccountRiskNote::query()
            ->whereIn('account_number', $accountNumbers)
            ->get()->keyBy('account_number');
        $eligibleOperatorIds = $accounts->pluck('operator_user_id')->map(fn ($id) => (int) $id)->filter()->unique()->values();
        $stores = $eligibleOperatorIds->isEmpty()
            ? collect()
            : AssistantDomainStore::query()
                ->whereNotNull('domain')->where('domain', '!=', '')
                ->where(function ($query) use ($eligibleOperatorIds): void {
                    $query->whereIn('owner_user_id', $eligibleOperatorIds)
                        ->orWhereHas('ownerHistories', fn ($history) => $history->whereIn('owner_user_id', $eligibleOperatorIds));
                })
                ->orderBy('domain')->get(['id', 'owner_user_id', 'shop_name', 'domain']);
        $historicalOwners = $stores->isEmpty()
            ? collect()
            : AssistantDomainStoreOwnerHistory::query()->whereIn('assistant_domain_store_id', $stores->pluck('id'))
                ->whereIn('owner_user_id', $eligibleOperatorIds)
                ->get(['assistant_domain_store_id', 'owner_user_id'])
                ->groupBy('assistant_domain_store_id');
        $stores->each(function (AssistantDomainStore $store) use ($historicalOwners): void {
            $ownerIds = collect([(int) $store->owner_user_id])
                ->merge($historicalOwners->get($store->getKey(), collect())->pluck('owner_user_id'))
                ->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
            $store->setAttribute('eligible_owner_ids', $ownerIds);
        });
        $accounts = $accounts->map(function (array $account) use ($screenshots, $bindings, $riskNotes): array {
            $screenshot = $account['account_keys']->map(fn ($key) => $screenshots->get($key))->filter()
                ->sortByDesc(fn (AppLovinSpendScreenshot $item) => $item->updated_at?->getTimestamp() ?? 0)->first();
            $binding = $account['account_keys']->map(fn ($key) => $bindings->get($key))->filter()->first();
            $number = AppLovinAccountRiskNote::normalizeAccountNumber((string) $account['account_number']);
            $note = $riskNotes->get($number);

            return [
                ...$account,
                'uploaded_screenshot' => $screenshot,
                'gift_uploaded_at' => $note?->gift_granted_at ?: $screenshot?->updated_at,
                'store_binding' => $binding,
                'risk_factors' => (string) ($note?->risk_factors ?: ''),
                'closed_at' => $note?->closed_at,
                'account_status_note' => (string) ($note?->account_status_note ?: ''),
            ];
        });
        $page = max(1, (int) $request->query('page', 1));
        $perPage = (int) ($filters['per_page'] ?? 20);
        $accounts = new LengthAwarePaginator($accounts->forPage($page, $perPage)->values(), $accounts->count(), $perPage, $page, ['path' => $request->url(), 'query' => $request->query()]);
        $departmentOptions = Department::business()->whereIn('id', $availableDepartmentIds)
            ->orderBy('sort')->orderBy('name')->get(['id', 'parent_id', 'name']);

        return view('portals.assistant.daily-submissions.applovin-spend', [
            'portal' => $context['portal']->loadMissing('menus'), 'user' => $context['user'],
            'operator' => $operator, 'selectedDepartmentIds' => $selectedDepartmentIds, 'overThreshold' => $filters['over_threshold'] ?? '', 'accountNumber' => $accountNumber, 'departmentOptions' => $departmentOptions, 'accounts' => $accounts, 'threshold' => 5000,
            'appLovinIndexUrl' => $isDashboard ? route('operation-dashboard.applovin-spends.index') : route('assistant.applovin-spends.index'),
            'appLovinScreenshotRoute' => $isDashboard ? 'operation-dashboard.applovin-spends.screenshot' : 'assistant.applovin-spends.screenshot',
            'appLovinBindingRoute' => $isDashboard ? 'operation-dashboard.applovin-spends.store-binding' : 'assistant.applovin-spends.store-binding',
            'canEditAccountDetails' => ! $isDashboard,
            'stores' => $stores,
        ]);
    }

    public function saveAccountDetails(Request $request): RedirectResponse
    {
        $context = $this->portalEntries->portalContext($request);
        if ($context instanceof Response) {
            abort($context->getStatusCode());
        }
        $this->authorization->assertAssistantPortal($context['portal']);
        abort_unless($this->authorization->isAssistantDepartmentMember($context['user']), 403);

        $validated = $request->validate([
            'account_number' => ['required', 'string', 'max:191'],
            'closed_at' => ['nullable', 'date'],
            'gift_granted_at' => ['nullable', 'date'],
            'account_status_note' => ['nullable', 'string', 'max:2000'],
        ]);
        abort_unless($request->exists('closed_at') || $request->exists('gift_granted_at') || $request->exists('account_status_note'), 422, '没有需要保存的账号信息。');
        $number = AppLovinAccountRiskNote::normalizeAccountNumber($validated['account_number']);
        abort_if($number === '', 422, '账号编号为空，无法保存账号信息。');

        $updates = ['updated_by' => $context['user']->getKey()];
        if ($request->exists('closed_at')) {
            $updates['closed_at'] = ($validated['closed_at'] ?? null) ?: null;
        }
        if ($request->exists('gift_granted_at')) {
            $updates['gift_granted_at'] = ($validated['gift_granted_at'] ?? null) ?: null;
        }
        if ($request->exists('account_status_note')) {
            $updates['account_status_note'] = trim((string) ($validated['account_status_note'] ?? '')) ?: null;
        }
        AppLovinAccountRiskNote::query()->updateOrCreate(['account_number' => $number], $updates);

        $message = $request->exists('account_status_note')
            ? '账单情况已保存。'
            : ($request->exists('gift_granted_at') ? '有赠金时间已保存。' : '下户时间已保存。');

        return back()->with('success', $message);
    }

    public function bindStore(Request $request): RedirectResponse
    {
        $context = $this->portalEntries->portalContext($request);
        if ($context instanceof Response) {
            abort($context->getStatusCode());
        }
        $isDashboard = (string) $context['portal']->code === self::DASHBOARD_PORTAL_CODE;
        if (! $isDashboard) {
            $this->authorization->assertAssistantPortal($context['portal']);
            abort_unless($this->authorization->isAssistantDepartmentMember($context['user']), 403);
        }
        $validated = $request->validate([
            'account_key' => ['required', 'string', 'size:64'],
            'store_id' => ['nullable', 'integer', Rule::exists((new AssistantDomainStore)->getTable(), 'id')],
        ]);

        $visibleIds = $isDashboard
            ? $this->dashboardScopes->visibleDepartmentIds($context['user'])
            : $this->storeOwners->visibleOperationDepartmentIds($context['user']);
        $account = OperationReport::query()->where('status', OperationReport::STATUS_APPROVED)
            ->when($isDashboard || ! $context['user']->isAdministrator(), fn ($query) => $visibleIds === [] ? $query->whereRaw('1=0') : $query->whereIn('operation_department_id', $visibleIds))
            ->get()->flatMap(fn (OperationReport $report) => collect($report->advertising_rows ?: [])->map(function (array $row) use ($report): array {
                return [
                    'account_key' => AppLovinSpendScreenshot::accountKey((int) $report->operator_user_id, (string) ($row['agency'] ?? ''), (string) ($row['media_source'] ?? ''), (string) ($row['account_name'] ?? ''), (string) ($row['account_number'] ?? '')),
                    'operator_user_id' => (int) $report->operator_user_id,
                    'account_number' => (string) ($row['account_number'] ?? ''),
                    'media' => mb_strtolower(trim((string) ($row['media_source'] ?? ''))),
                ];
            }))->first(fn (array $row) => $row['media'] === 'applovin' && hash_equals($row['account_key'], $validated['account_key']));
        if (! $account) {
            $account = FashionAdvertisingReport::query()->where('status', FashionAdvertisingReport::STATUS_APPROVED)
                ->with(['adSpendEntries', 'submitter.departments'])
                ->when($isDashboard || ! $context['user']->isAdministrator(), fn ($query) => $visibleIds === []
                    ? $query->whereRaw('1=0')
                    : $query->whereHas('submitter.departments', fn ($departmentQuery) => $departmentQuery->whereIn('admin_roles.id', $visibleIds)))
                ->get()->flatMap(fn (FashionAdvertisingReport $report) => $report->adSpendEntries->map(fn ($entry): array => [
                    'account_key' => AppLovinSpendScreenshot::accountKey((int) $report->submitter_id, (string) $entry->agency, (string) $entry->media, (string) $entry->account_name, (string) $entry->account_number),
                    'operator_user_id' => (int) $report->submitter_id,
                    'account_number' => (string) $entry->account_number,
                    'media' => mb_strtolower(trim((string) $entry->media)),
                ]))->first(fn (array $row) => $row['media'] === 'applovin' && hash_equals($row['account_key'], $validated['account_key']));
        }
        abort_unless($account, 403);

        if (empty($validated['store_id'])) {
            app(AppLovinAccountStoreBindingService::class)->unbind(
                (string) $account['account_number'], (string) $validated['account_key']
            );

            return back()->with('success', '店铺域名绑定已取消。');
        }

        $canBindStore = AssistantDomainStore::query()->whereKey((int) $validated['store_id'])
            ->where(function ($query) use ($account): void {
                $query->where('owner_user_id', $account['operator_user_id'])
                    ->orWhereHas('ownerHistories', fn ($history) => $history->where('owner_user_id', $account['operator_user_id']));
            })->exists();
        abort_unless($canBindStore, 422, '只能绑定当前或历史上归属该运营人员的店铺。');

        app(AppLovinAccountStoreBindingService::class)->bind(
            (string) $account['account_number'], (int) $validated['store_id'], (int) $context['user']->getKey()
        );

        return back()->with('success', '店铺域名绑定成功。');
    }

    public function screenshot(Request $request, AppLovinSpendScreenshot $screenshot): BinaryFileResponse
    {
        $context = $this->portalEntries->portalContext($request);
        if ($context instanceof Response) {
            abort($context->getStatusCode());
        }
        $isDashboard = (string) $context['portal']->code === self::DASHBOARD_PORTAL_CODE;
        if (! $isDashboard) {
            $this->authorization->assertAssistantPortal($context['portal']);
            abort_unless($this->authorization->isAssistantDepartmentMember($context['user']), 403);
            if (! $context['user']->isAdministrator()) {
                abort_unless(in_array((int) $screenshot->operation_department_id, $this->storeOwners->visibleOperationDepartmentIds($context['user']), true), 403);
            }
        } else {
            abort_unless(in_array((int) $screenshot->operation_department_id, $this->dashboardScopes->visibleDepartmentIds($context['user']), true), 403);
        }
        abort_unless(Storage::disk('local')->exists($screenshot->screenshot_path), 404);

        return response()->file(Storage::disk('local')->path($screenshot->screenshot_path));
    }
}
