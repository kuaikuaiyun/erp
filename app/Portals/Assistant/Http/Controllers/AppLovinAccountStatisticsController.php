<?php

namespace App\Portals\Assistant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\AppLovinAccountStoreBinding;
use App\Models\AppLovinSpendScreenshot;
use App\Models\AssistantDomainStore;
use App\Models\Department;
use App\Models\FashionAdvertisingReport;
use App\Models\OperationReport;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\Assistant\AssistantPortalAuthorizationService;
use App\Portals\Assistant\AssistantStoreOwnerService;
use App\Portals\Assistant\OperationDashboardDepartmentScopeService;
use App\Services\DepartmentAuthorizationService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class AppLovinAccountStatisticsController extends Controller
{
    public function __construct(
        private readonly PortalEntryController $portalEntries,
        private readonly AssistantPortalAuthorizationService $authorization,
        private readonly AssistantStoreOwnerService $storeOwners,
        private readonly DepartmentAuthorizationService $departmentAuthorization,
        private readonly OperationDashboardDepartmentScopeService $dashboardScopes,
    ) {}

    public function index(Request $request): Response|View
    {
        $context = $this->context($request);
        if ($context instanceof Response) return $context;

        $project = $this->project($request);
        $routes = $this->routeNames($project);
        $allAccounts = $this->accounts($context['user'], $project);
        $availableDepartmentIds = $allAccounts->pluck('department_id')
            ->map(fn ($id): int => (int) $id)->filter()->unique()->values()->all();
        $filters = $request->validate([
            'department_ids' => ['nullable', 'array'],
            'department_ids.*' => ['integer', 'distinct', Rule::in($availableDepartmentIds)],
            'operator' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', Rule::in([20, 50, 100])],
            'sort_by' => ['nullable', Rule::in(['department', 'operator_name', 'hire_days', 'account_count', 'under_5000_count', 'between_5000_10000_count', 'over_10000_count', 'total_spend'])],
            'sort_direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);
        $operator = trim((string) ($filters['operator'] ?? ''));
        $departmentIds = collect($filters['department_ids'] ?? [])
            ->map(fn ($id): int => (int) $id)->unique()->values()->all();
        $accounts = $allAccounts
            ->when($departmentIds !== [], fn (Collection $rows) => $rows->whereIn('department_id', $departmentIds))
            ->when($operator !== '', fn (Collection $rows) => $rows->filter(fn (array $row) => str_contains(mb_strtolower($row['operator_name']), mb_strtolower($operator))));

        $spendBands = [
            'under_5000' => $accounts->where('total_spend', '<', 5000)->count(),
            'between_5000_10000' => $accounts->filter(fn (array $row): bool => $row['total_spend'] >= 5000 && $row['total_spend'] <= 10000)->count(),
            'over_10000' => $accounts->where('total_spend', '>', 10000)->count(),
        ];

        $sortBy = (string) ($filters['sort_by'] ?? 'total_spend');
        $sortDirection = (string) ($filters['sort_direction'] ?? 'desc');
        $profiles = AdminUser::query()->whereIn('id', $accounts->pluck('operator_user_id')->unique())
            ->get(['id', 'hired_at'])->keyBy('id');
        $today = now('Asia/Shanghai')->startOfDay();
        $operators = $accounts->groupBy('operator_user_id')->map(function (Collection $rows) use ($profiles, $today): array {
            $first = $rows->first();
            $hiredAt = $profiles->get((int) $first['operator_user_id'])?->hired_at;
            $hireDate = $hiredAt ? \Carbon\CarbonImmutable::parse($hiredAt->format('Y-m-d'), 'Asia/Shanghai') : null;
            $hireDays = $hireDate ? ($hireDate->gt($today) ? 0 : (int) $hireDate->diffInDays($today) + 1) : null;
            return [
                'operator_user_id' => (int) $first['operator_user_id'],
                'operator_name' => $first['operator_name'],
                'hire_days' => $hireDays,
                'department_id' => (int) $first['department_id'],
                'department' => $first['department'],
                'account_count' => $rows->count(),
                'under_5000_count' => $rows->where('total_spend', '<', 5000)->count(),
                'between_5000_10000_count' => $rows->filter(fn (array $row): bool => $row['total_spend'] >= 5000 && $row['total_spend'] <= 10000)->count(),
                'over_10000_count' => $rows->where('total_spend', '>', 10000)->count(),
                'total_spend' => (float) $rows->sum('total_spend'),
            ];
        })->sort(function (array $left, array $right) use ($sortBy, $sortDirection): int {
            $leftValue = $left[$sortBy];
            $rightValue = $right[$sortBy];
            if ($leftValue === null || $rightValue === null) {
                $comparison = ($leftValue === null) <=> ($rightValue === null);
            } else {
                $comparison = in_array($sortBy, ['department', 'operator_name'], true)
                    ? strnatcasecmp((string) $leftValue, (string) $rightValue)
                    : $leftValue <=> $rightValue;
                if ($sortDirection === 'desc') $comparison = -$comparison;
            }
            return $comparison ?: strnatcasecmp($left['operator_name'], $right['operator_name'])
                ?: $left['operator_user_id'] <=> $right['operator_user_id'];
        })->values();

        $perPage = (int) ($filters['per_page'] ?? 20);
        $page = max(1, (int) $request->query('page', 1));
        $operators = new LengthAwarePaginator($operators->forPage($page, $perPage)->values(), $operators->count(), $perPage, $page, [
            'path' => $request->url(), 'query' => $request->query(),
        ]);

        return view('portals.assistant.applovin-account-statistics.index', [
            'portal' => $context['portal']->loadMissing('menus'), 'user' => $context['user'],
            'operators' => $operators,
            'departmentOptions' => Department::business()->whereIn('id', $availableDepartmentIds)
                ->orderBy('sort')->orderBy('name')->get(['id', 'parent_id', 'name']),
            'filters' => ['department_ids' => $departmentIds, 'operator' => $operator, 'per_page' => $perPage,
                'sort_by' => $sortBy, 'sort_direction' => $sortDirection],
            'spendBands' => $spendBands,
            'statisticsRoutes' => $routes,
        ]);
    }

    public function show(Request $request, int $operator): Response|View
    {
        $context = $this->context($request);
        if ($context instanceof Response) return $context;

        $filters = $request->validate([
            'spend_band' => ['nullable', Rule::in(['under_5000', 'between_5000_10000', 'over_10000'])],
        ]);
        $project = $this->project($request);
        $routes = $this->routeNames($project);
        $accounts = $this->accounts($context['user'], $project)->where('operator_user_id', $operator)->values();
        abort_if($accounts->isEmpty(), 404);
        $first = $accounts->first();
        $spendBand = (string) ($filters['spend_band'] ?? '');
        $accounts = match ($spendBand) {
            'under_5000' => $accounts->where('total_spend', '<', 5000)->values(),
            'between_5000_10000' => $accounts->filter(fn (array $row): bool => $row['total_spend'] >= 5000 && $row['total_spend'] <= 10000)->values(),
            'over_10000' => $accounts->where('total_spend', '>', 10000)->values(),
            default => $accounts,
        };
        $accounts = $accounts->sortByDesc('total_spend')->values();
        $page = max(1, (int) $request->query('page', 1));
        $paginator = new LengthAwarePaginator($accounts->forPage($page, 20)->values(), $accounts->count(), 20, $page, [
            'path' => $request->url(), 'query' => $request->query(),
        ]);

        return view('portals.assistant.applovin-account-statistics.show', [
            'portal' => $context['portal']->loadMissing('menus'), 'user' => $context['user'],
            'operatorName' => $first['operator_name'], 'department' => $first['department'],
            'accounts' => $paginator, 'totalSpend' => (float) $accounts->sum('total_spend'),
            'spendBand' => $spendBand,
            'isGlobal' => false,
            'backUrl' => route($routes['index']),
            'allAccountsUrl' => route($routes['show'], $operator),
            'spendBandLabel' => match ($spendBand) {
                'under_5000' => '历史总消耗小于 $5,000',
                'between_5000_10000' => '历史总消耗 $5,000–$10,000',
                'over_10000' => '历史总消耗大于 $10,000',
                default => '全部账号',
            },
        ]);
    }

    public function spendBand(Request $request): Response|View
    {
        $context = $this->context($request);
        if ($context instanceof Response) return $context;

        $project = $this->project($request);
        $routes = $this->routeNames($project);
        $allAccounts = $this->accounts($context['user'], $project);
        $availableDepartmentIds = $allAccounts->pluck('department_id')
            ->map(fn ($id): int => (int) $id)->filter()->unique()->values()->all();
        $filters = $request->validate([
            'spend_band' => ['required', Rule::in(['under_5000', 'between_5000_10000', 'over_10000'])],
            'department_ids' => ['nullable', 'array'],
            'department_ids.*' => ['integer', 'distinct', Rule::in($availableDepartmentIds)],
            'operator' => ['nullable', 'string', 'max:100'],
        ]);
        $departmentIds = collect($filters['department_ids'] ?? [])
            ->map(fn ($id): int => (int) $id)->unique()->values()->all();
        $operator = trim((string) ($filters['operator'] ?? ''));
        $spendBand = (string) $filters['spend_band'];
        $accounts = $allAccounts
            ->when($departmentIds !== [], fn (Collection $rows) => $rows->whereIn('department_id', $departmentIds))
            ->when($operator !== '', fn (Collection $rows) => $rows->filter(fn (array $row) => str_contains(mb_strtolower($row['operator_name']), mb_strtolower($operator))));
        $accounts = match ($spendBand) {
            'under_5000' => $accounts->where('total_spend', '<', 5000)->values(),
            'between_5000_10000' => $accounts->filter(fn (array $row): bool => $row['total_spend'] >= 5000 && $row['total_spend'] <= 10000)->values(),
            'over_10000' => $accounts->where('total_spend', '>', 10000)->values(),
        };
        $accounts = $accounts->sortByDesc('total_spend')->values();
        $page = max(1, (int) $request->query('page', 1));
        $paginator = new LengthAwarePaginator($accounts->forPage($page, 20)->values(), $accounts->count(), 20, $page, [
            'path' => $request->url(), 'query' => $request->query(),
        ]);
        $backQuery = array_filter([
            'department_ids' => $departmentIds ?: null,
            'operator' => $operator !== '' ? $operator : null,
        ]);

        return view('portals.assistant.applovin-account-statistics.show', [
            'portal' => $context['portal']->loadMissing('menus'), 'user' => $context['user'],
            'operatorName' => $operator !== '' ? $operator : '全部运营',
            'department' => $departmentIds !== []
                ? Department::business()->whereIn('id', $departmentIds)->pluck('name')->implode('、')
                : '全部团队',
            'accounts' => $paginator, 'totalSpend' => (float) $accounts->sum('total_spend'),
            'spendBand' => $spendBand, 'isGlobal' => true,
            'backUrl' => route($routes['index'], $backQuery),
            'allAccountsUrl' => route($routes['index'], $backQuery),
            'spendBandLabel' => match ($spendBand) {
                'under_5000' => '历史总消耗小于 $5,000',
                'between_5000_10000' => '历史总消耗 $5,000–$10,000',
                'over_10000' => '历史总消耗大于 $10,000',
            },
        ]);
    }

    /**
     * Show per-account AppLovin lifecycle and staged ROI for the operation dashboard.
     */
    public function personalSituation(Request $request): Response|View
    {
        $context = $this->context($request);
        if ($context instanceof Response) return $context;
        abort_unless($this->project($request) === 'dashboard', 404);

        $subscriptionDepartmentIds = $this->storeOwners->operationDepartments()
            ->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $visibleDepartmentIds = array_values(array_intersect(
            $this->visibleDepartmentIds($context['user'], 'dashboard'),
            $subscriptionDepartmentIds,
        ));
        $filters = $request->validate([
            'department_ids' => ['nullable', 'array'],
            'department_ids.*' => ['integer', 'distinct', Rule::in($visibleDepartmentIds)],
            'keyword' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', Rule::in([20, 50, 100])],
        ]);
        $selectedDepartmentIds = collect($filters['department_ids'] ?? [])
            ->map(fn ($id): int => (int) $id)->unique()->values()->all();
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        $reports = $this->appLovinPersonalReports($context['user'], $visibleDepartmentIds, $selectedDepartmentIds);
        $rows = $this->appLovinPersonalRows($reports)
            ->when($keyword !== '', fn (Collection $items) => $items->filter(function (array $row) use ($keyword): bool {
                $needle = mb_strtolower($keyword);
                return str_contains(mb_strtolower($row['operator_name']), $needle)
                    || str_contains(mb_strtolower($row['account_number']), $needle)
                    || str_contains(mb_strtolower($row['account_name']), $needle);
            }))
            ->sort(function (array $left, array $right): int {
                return [$left['department'], $left['operator_name'], -(float) $left['total_spend'], $left['account_number'], $left['account_name']]
                    <=> [$right['department'], $right['operator_name'], -(float) $right['total_spend'], $right['account_number'], $right['account_name']];
            })->values();
        $perPage = (int) ($filters['per_page'] ?? 20);
        $page = max(1, (int) $request->query('page', 1));
        $paginator = new LengthAwarePaginator($rows->forPage($page, $perPage)->values(), $rows->count(), $perPage, $page, [
            'path' => $request->url(), 'query' => $request->query(),
        ]);

        return view('portals.assistant.applovin-personal-situations.index', [
            'portal' => $context['portal']->loadMissing('menus'), 'user' => $context['user'], 'rows' => $paginator,
            'departmentOptions' => Department::business()->whereIn('id', $visibleDepartmentIds)->orderBy('sort')->orderBy('name')->get(['id', 'parent_id', 'name']),
            'filters' => ['department_ids' => $selectedDepartmentIds, 'keyword' => $keyword, 'per_page' => $perPage],
            'totals' => ['accounts' => $rows->count(), 'reached_2500' => $rows->filter(fn (array $row): bool => $row['reach_2500_date'] !== null)->count(), 'spend' => (float) $rows->sum('total_spend')],
        ]);
    }

    /** @return Collection<int, OperationReport> */
    private function appLovinPersonalReports(AdminUser $viewer, array $visibleDepartmentIds, array $selectedDepartmentIds): Collection
    {
        $query = OperationReport::query();
        if (! $this->canSeeAll($viewer)) {
            $visibleDepartmentIds === [] ? $query->whereRaw('1 = 0') : $query->whereIn('operation_department_id', $visibleDepartmentIds);
        }
        if ($selectedDepartmentIds !== []) $query->whereIn('operation_department_id', $selectedDepartmentIds);

        return $query->with('operationDepartment:id,name')->orderBy('report_date')->orderBy('id')->get();
    }

    /** @return Collection<int, array<string, mixed>> */
    private function appLovinPersonalRows(Collection $reports): Collection
    {
        $bindings = AppLovinAccountStoreBinding::query()->pluck('assistant_domain_store_id', 'account_key');
        $storeIds = $reports->flatMap(fn (OperationReport $report) => collect($report->advertising_rows ?: [])->pluck('assistant_domain_store_id'))
            ->merge($bindings->values())->filter()->map(fn ($id): int => (int) $id)->unique()->values();
        $stores = AssistantDomainStore::query()->whereIn('id', $storeIds)->get(['id', 'domain'])->keyBy('id');
        $activation = [];
        $daily = [];

        foreach ($reports as $report) {
            $ads = collect($report->advertising_rows ?: [])->filter(fn (array $ad): bool => mb_strtolower(trim((string) ($ad['media_source'] ?? ''))) === 'applovin')
                ->map(function (array $ad) use ($report, $bindings, $stores): array {
                    $number = trim((string) ($ad['account_number'] ?? ''));
                    $name = trim((string) ($ad['account_name'] ?? ''));
                    $accountKey = AppLovinSpendScreenshot::accountKey((int) $report->operator_user_id, (string) ($ad['agency'] ?? ''), 'AppLovin', $name, $number);
                    $identity = (int) $report->operator_user_id.'|'.mb_strtolower($number !== '' ? $number : $name);
                    $storeId = (int) ($ad['assistant_domain_store_id'] ?? $bindings->get($accountKey) ?? 0);
                    $domain = trim((string) ($ad['store_domain'] ?? $stores->get($storeId)?->domain ?? ''));
                    return ['identity' => $identity, 'store_id' => $storeId, 'domain' => $domain, 'spend' => (float) ($ad['actual_spend'] ?? 0), 'account_name' => $name, 'account_number' => $number];
                })->values();
            $submittedAt = $report->submitted_at ?: $report->created_at;
            foreach ($ads as $ad) {
                $activation[$ad['identity']] ??= ['date' => $report->report_date->format('Y-m-d'), 'time' => $submittedAt?->format('Y-m-d H:i:s'),
                    // Department names stored on reports are historical snapshots.
                    // Prefer the live department record so renamed departments do
                    // not split one operator into multiple groups in this report.
                    'department' => (string) ($report->operationDepartment?->name ?: $report->operation_department_name),
                    'operator_user_id' => (int) $report->operator_user_id, 'operator_name' => (string) $report->operator_name,
                    'account_name' => $ad['account_name'], 'account_number' => $ad['account_number'], 'store_domains' => []];
                if ($ad['domain'] !== '') {
                    $activation[$ad['identity']]['store_domains'][mb_strtolower($ad['domain'])] = $ad['domain'];
                }
            }
            if ($report->status !== OperationReport::STATUS_APPROVED) continue;

            $ads->groupBy(fn (array $ad): string => $ad['store_id'] > 0 ? 'store:'.$ad['store_id'] : 'account:'.$ad['identity'])
                ->each(function (Collection $storeAds) use (&$daily, $report): void {
                    $domain = mb_strtolower(trim((string) $storeAds->first()['domain']));
                    $revenue = $domain === '' ? 0.0 : (float) collect($report->metric_rows ?: [])
                        ->filter(fn (array $metric): bool => mb_strtolower(trim((string) ($metric['shop_domain'] ?? ''))) === $domain && mb_strtolower(trim((string) ($metric['media_source'] ?? ''))) === 'applovin')
                        ->sum(fn (array $metric): float => (float) ($metric['first_total_revenue'] ?? 0));
                    $totalSpend = (float) $storeAds->sum('spend');
                    foreach ($storeAds as $ad) {
                        $share = $totalSpend > 0 ? $ad['spend'] / $totalSpend : 1 / max(1, $storeAds->count());
                        $daily[$ad['identity']][] = ['date' => $report->report_date->format('Y-m-d'), 'spend' => (float) $ad['spend'], 'revenue' => $revenue * $share];
                    }
                });
        }

        return collect($activation)->map(function (array $account, string $identity) use ($daily): array {
            $entries = collect($daily[$identity] ?? [])->groupBy('date')->map(fn (Collection $items, string $date): array => ['date' => $date, 'spend' => (float) $items->sum('spend'), 'revenue' => (float) $items->sum('revenue')])->sortBy('date')->values();
            $bands = ['to_2500' => ['spend' => 0.0, 'revenue' => 0.0], '2500_to_5000' => ['spend' => 0.0, 'revenue' => 0.0], 'over_5000' => ['spend' => 0.0, 'revenue' => 0.0]];
            $cumulative = 0.0; $reachDate = null; $reach5000Date = null; $over5000Date = null;
            foreach ($entries as $entry) {
                $spend = (float) $entry['spend']; $revenue = (float) $entry['revenue']; $remaining = $spend;
                foreach ([['to_2500', 2500.0], ['2500_to_5000', 5000.0], ['over_5000', INF]] as [$band, $limit]) {
                    if ($remaining <= 0) break;
                    $capacity = $limit === INF ? $remaining : max(0, $limit - $cumulative);
                    $portion = min($remaining, $capacity);
                    if ($portion <= 0) continue;
                    $bands[$band]['spend'] += $portion;
                    $bands[$band]['revenue'] += $spend > 0 ? $revenue * ($portion / $spend) : 0;
                    $before = $cumulative; $cumulative += $portion; $remaining -= $portion;
                    if ($reachDate === null && $before < 2500 && $cumulative >= 2500) $reachDate = $entry['date'];
                    if ($reach5000Date === null && $before < 5000 && $cumulative >= 5000) $reach5000Date = $entry['date'];
                    if ($over5000Date === null && $before <= 5000 && $cumulative > 5000) $over5000Date = $entry['date'];
                }
            }
            $start = \Carbon\CarbonImmutable::parse($account['date'], 'Asia/Shanghai');
            $reachDays = $reachDate ? $start->diffInDays(\Carbon\CarbonImmutable::parse($reachDate, 'Asia/Shanghai')) + 1 : null;
            $reach5000Days = $reach5000Date ? $start->diffInDays(\Carbon\CarbonImmutable::parse($reach5000Date, 'Asia/Shanghai')) + 1 : null;
            $over5000Days = $over5000Date ? $start->diffInDays(\Carbon\CarbonImmutable::parse($over5000Date, 'Asia/Shanghai')) + 1 : null;
            $ratio = static fn (array $band): ?float => $band['spend'] > 0 ? $band['revenue'] / $band['spend'] : null;
            $storeDomains = collect($account['store_domains'] ?? [])->values()->filter()->unique(fn ($domain) => mb_strtolower((string) $domain))->implode('、');
            $storePerformance = (float) $entries->sum('revenue');
            // A phase is only meaningful after its spend threshold has been reached.
            // Do not calculate or flag a partially completed phase.
            $reach2500Roi = $reachDate ? $ratio($bands['to_2500']) : null;
            $midRoi = $reach5000Date ? $ratio($bands['2500_to_5000']) : null;
            $overRoi = $over5000Date ? $ratio($bands['over_5000']) : null;
            $reach2500Performance = $reachDate ? (float) $bands['to_2500']['revenue'] : null;
            $reach5000Performance = $reach5000Date
                ? (float) ($bands['to_2500']['revenue'] + $bands['2500_to_5000']['revenue'])
                : null;
            $over5000Performance = $over5000Date ? (float) $bands['over_5000']['revenue'] : null;
            $reach2500Spend = $reachDate ? (float) $bands['to_2500']['spend'] : null;
            $reach5000Spend = $reach5000Date
                ? (float) ($bands['to_2500']['spend'] + $bands['2500_to_5000']['spend'])
                : null;
            $over5000Spend = $over5000Date ? (float) $bands['over_5000']['spend'] : null;
            $isBelowTarget = ($reach2500Roi !== null && $reach2500Roi > 0 && $reach2500Roi < 1.2)
                || ($midRoi !== null && $midRoi > 0 && $midRoi < 1.3)
                || ($overRoi !== null && $overRoi > 0 && $overRoi < 1.4);
            // array_merge is intentional: the activation payload holds domains as an
            // array while the table needs a displayable string.
            return array_merge($account, ['store_domains' => $storeDomains, 'store_performance' => $storePerformance, 'total_spend' => $cumulative, 'reach_2500_date' => $reachDate, 'reach_2500_days' => $reachDays, 'reach_5000_date' => $reach5000Date, 'reach_5000_days' => $reach5000Days, 'over_5000_date' => $over5000Date, 'over_5000_days' => $over5000Days, 'reach_2500_performance' => $reach2500Performance, 'reach_5000_performance' => $reach5000Performance, 'over_5000_performance' => $over5000Performance, 'reach_2500_spend' => $reach2500Spend, 'reach_5000_spend' => $reach5000Spend, 'over_5000_spend' => $over5000Spend, 'reach_2500_roi' => $reach2500Roi, 'roi_2500_to_5000' => $midRoi, 'roi_over_5000' => $overRoi, 'is_below_target' => $isBelowTarget]);
        })->values();
    }

    private function accounts(AdminUser $viewer, string $project): Collection
    {
        $visibleIds = $this->visibleDepartmentIds($viewer, $project);
        $unrestricted = $this->canSeeAll($viewer);
        $subscriptionQuery = OperationReport::query()->where('status', OperationReport::STATUS_APPROVED);
        if (! $unrestricted) {
            if ($project === 'dashboard' || $project === 'assistant') {
                $visibleIds === []
                    ? $subscriptionQuery->whereRaw('1=0')
                    : $subscriptionQuery->whereIn('operation_department_id', $visibleIds);
            } else {
                $subscriptionQuery->where(function ($scope) use ($viewer, $visibleIds): void {
                    $scope->where('operator_user_id', $viewer->getKey());
                    if ($visibleIds !== []) {
                        $scope->orWhereIn('operation_department_id', $visibleIds);
                    }
                });
            }
        }
        $subscription = $project === 'fashion' ? collect() : $subscriptionQuery->get()->flatMap(fn (OperationReport $report) => collect($report->advertising_rows ?: [])
            ->filter(fn (array $row) => mb_strtolower(trim((string) ($row['media_source'] ?? ''))) === 'applovin')
            ->map(fn (array $row): array => [
                'operator_user_id' => (int) $report->operator_user_id, 'operator_name' => (string) $report->operator_name,
                'department_id' => (int) $report->operation_department_id, 'department' => (string) ($report->operation_department_name ?: '未设置团队'),
                'agency' => trim((string) ($row['agency'] ?? '')), 'account_name' => trim((string) ($row['account_name'] ?? '')),
                'account_number' => trim((string) ($row['account_number'] ?? '')), 'spend' => (float) ($row['actual_spend'] ?? 0),
                'date' => $report->report_date->format('Y-m-d'), 'source' => '订阅运营',
            ]));

        $fashion = $project === 'operation' ? collect() : FashionAdvertisingReport::query()->where('status', FashionAdvertisingReport::STATUS_APPROVED)
            ->with(['adSpendEntries', 'submitter.departments'])->get()
            ->flatMap(function (FashionAdvertisingReport $report) use ($viewer, $visibleIds, $unrestricted, $project): Collection {
                $departments = $report->submitter?->departments ?? collect();
                $isOwnReport = (int) $report->submitter_id === (int) $viewer->getKey();
                $managedDepartment = $departments->first(fn ($item) => in_array((int) $item->id, $visibleIds, true));
                $strictDepartmentScope = $project === 'dashboard' || $project === 'assistant';
                $department = $unrestricted || (! $strictDepartmentScope && $isOwnReport)
                    ? ($departments->first(fn ($item) => (bool) ($item->pivot->is_primary ?? false)) ?: $departments->first())
                    : $managedDepartment;
                if (! $unrestricted && ($strictDepartmentScope ? ! $managedDepartment : (! $isOwnReport && ! $managedDepartment))) return collect();
                return $report->adSpendEntries->filter(fn ($entry) => mb_strtolower(trim((string) $entry->media)) === 'applovin')
                    ->map(fn ($entry): array => [
                        'operator_user_id' => (int) $report->submitter_id, 'operator_name' => (string) $report->submitter_name,
                        'department_id' => (int) ($department?->id ?: 0), 'department' => (string) ($department?->name ?: '服装运营部门'),
                        'agency' => trim((string) $entry->agency), 'account_name' => trim((string) $entry->account_name),
                        'account_number' => trim((string) $entry->account_number), 'spend' => (float) $entry->actual_spend_usd,
                        'date' => $report->report_date->format('Y-m-d'), 'source' => '服装运营',
                    ]);
            });

        $accounts = $subscription->concat($fashion)->groupBy(function (array $row): string {
            $identity = $row['account_number'] !== '' ? $row['account_number'] : mb_strtolower($row['account_name']);
            return $row['operator_user_id'].'|'.mb_strtolower($row['agency']).'|'.$identity;
        })->map(function (Collection $rows): array {
            $latest = $rows->sortByDesc('date')->first();
            $accountKey = AppLovinSpendScreenshot::accountKey((int) $latest['operator_user_id'], $latest['agency'], 'AppLovin', $latest['account_name'], $latest['account_number']);
            return $latest + [
                'account_key' => $accountKey, 'total_spend' => (float) $rows->sum('spend'),
                'first_date' => (string) $rows->min('date'), 'last_date' => (string) $rows->max('date'),
                'sources' => $rows->pluck('source')->unique()->implode('、'),
            ];
        })->values();

        $bindings = AppLovinAccountStoreBinding::query()->whereIn('account_key', $accounts->pluck('account_key'))->get()->keyBy('account_key');
        $stores = AssistantDomainStore::query()->whereIn('id', $bindings->pluck('assistant_domain_store_id')->filter()->unique())->get()->keyBy('id');
        return $accounts->map(function (array $account) use ($bindings, $stores): array {
            $binding = $bindings->get($account['account_key']);
            $store = $binding ? $stores->get($binding->assistant_domain_store_id) : null;
            return $account + ['store_domain' => (string) ($store?->domain ?: '')];
        });
    }

    private function visibleDepartmentIds(AdminUser $user, string $project): array
    {
        if ($project === 'dashboard') {
            return $this->dashboardScopes->visibleDepartmentIds($user);
        }
        if ($project !== 'assistant' && ! $this->canSeeAll($user)) {
            return $this->departmentAuthorization->managedDepartmentIds($user)->map(fn ($id) => (int) $id)->all();
        }

        return $this->canSeeAll($user)
            ? $this->storeOwners->storeOwnershipOperationDepartments()->pluck('id')->map(fn ($id) => (int) $id)->all()
            : $this->storeOwners->visibleOperationDepartmentIds($user);
    }

    private function context(Request $request): array|Response
    {
        $context = $this->portalEntries->portalContext($request);
        if ($context instanceof Response) return $context;
        $project = $this->project($request);
        if ($project === 'assistant') {
            $this->authorization->assertAssistantPortal($context['portal']);
            abort_unless($this->authorization->isAssistantDepartmentMember($context['user']), 403);
        } elseif ($project === 'dashboard') {
            abort_unless($context['portal']->code === 'yunying-kanban', 404);
        } else {
            abort_unless($context['portal']->code === ($project === 'fashion' ? 'fuzhuang' : 'yunying'), 404);
        }
        return $context;
    }

    private function project(Request $request): string
    {
        if ($request->routeIs('operation-dashboard.applovin-account-statistics.*') || $request->routeIs('operation-dashboard.applovin-personal-situations.*')) return 'dashboard';
        if ($request->routeIs('operation.applovin-account-statistics.*')) return 'operation';
        if ($request->routeIs('fashion.applovin-account-statistics.*')) return 'fashion';
        return 'assistant';
    }

    /** @return array{index:string,show:string,spend_band:string} */
    private function routeNames(string $project): array
    {
        $prefix = $project === 'dashboard' ? 'operation-dashboard' : ($project === 'assistant' ? 'assistant' : $project);
        return [
            'index' => $prefix.'.applovin-account-statistics.index',
            'show' => $prefix.'.applovin-account-statistics.show',
            'spend_band' => $prefix.'.applovin-account-statistics.spend-band',
        ];
    }

    private function canSeeAll(AdminUser $user): bool
    {
        return $user->isAdministrator() || strcasecmp((string) $user->username, 'kky_boss') === 0;
    }
}
