<?php

namespace App\Portals\Assistant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AppLovinAccountStoreBinding;
use App\Models\AppLovinSpendScreenshot;
use App\Models\AssistantDomainStore;
use App\Models\Department;
use App\Models\OperationReport;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\Assistant\AppLovinHistoricalSpendService;
use App\Portals\Assistant\AssistantPortalAuthorizationService;
use App\Portals\Assistant\AssistantStoreOwnerAttributionService;
use App\Portals\Assistant\AssistantStoreOwnerService;
use App\Portals\Assistant\OperationDashboardDepartmentScopeService;
use App\Portals\Operation\OperationReportMetricsService;
use App\Services\DepartmentAuthorizationService;
use App\Support\AppLovinGiftConsumption;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class AppLovinPerformanceReportController extends Controller
{
    public function __construct(
        private readonly PortalEntryController $portalEntries,
        private readonly AssistantPortalAuthorizationService $authorization,
        private readonly AssistantStoreOwnerService $storeOwners,
        private readonly AssistantStoreOwnerAttributionService $ownerAttribution,
        private readonly OperationReportMetricsService $metrics,
        private readonly DepartmentAuthorizationService $departmentAuthorization,
        private readonly OperationDashboardDepartmentScopeService $dashboardScopes,
        private readonly AppLovinHistoricalSpendService $historicalSpend,
    ) {}

    public function index(Request $request): Response|View
    {
        $context = $this->portalEntries->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }
        $isOperationPortal = $request->routeIs('operation.applovin-performance-reports.*');
        $isDashboard = $request->routeIs('operation-dashboard.applovin-performance-reports.*');
        if ($isDashboard) {
            abort_unless($context['portal']->code === 'yunying-kanban', 404);
        } elseif ($isOperationPortal) {
            abort_unless($context['portal']->code === 'yunying', 404);
        } else {
            $this->authorization->assertAssistantPortal($context['portal']);
            abort_unless($this->authorization->isAssistantDepartmentMember($context['user']), 403);
        }

        $canSeeAll = $context['user']->isAdministrator() || strcasecmp((string) $context['user']->username, 'kky_boss') === 0;
        $managedIds = $this->departmentAuthorization->managedDepartmentIds($context['user'])
            ->intersect($this->storeOwners->operationDepartments()->pluck('id'))->map(fn ($id) => (int) $id)->values()->all();
        $operatorOnly = $isOperationPortal && ! $canSeeAll && $managedIds === [];
        $visibleIds = $isDashboard
            ? $this->dashboardScopes->visibleDepartmentIds($context['user'])
            : ($isOperationPortal
            ? ($canSeeAll ? $this->storeOwners->operationDepartments()->pluck('id')->map(fn ($id) => (int) $id)->all() : $managedIds)
            : $this->storeOwners->visibleOperationDepartmentIds($context['user']));
        $timezoneOptions = $this->timezoneOptions();
        $filters = $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'date_range' => ['nullable', Rule::in(['custom', 'yesterday', 'last_3', 'last_7', 'last_15', 'last_30', 'last_60', 'last_90', 'last_6_months', 'last_year'])],
            'timezone' => ['nullable', Rule::in(array_keys($timezoneOptions))],
            'department_ids' => ['nullable', 'array'],
            'department_ids.*' => ['integer', 'distinct', Rule::in($visibleIds)],
            'operator' => ['nullable', 'string', 'max:100'],
            'display_mode' => ['nullable', Rule::in(['performance', 'team'])],
            'store_domain' => ['nullable', 'string', 'max:191'],
            'account_number' => ['nullable', 'string', 'max:191'],
            'gift_status' => ['nullable', Rule::in(['yes', 'no'])],
            'consumption_range' => ['nullable', Rule::in(['lt_5000', '5000_10000', 'gt_10000', 'custom'])],
            'consumption_custom' => ['nullable', 'numeric', 'min:0', 'required_if:consumption_range,custom'],
            'consumption_custom_max' => ['nullable', 'numeric', 'min:0', 'required_if:consumption_range,custom', 'gte:consumption_custom'],
            'ad_spend_sort' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);
        $timezone = (string) ($filters['timezone'] ?? OperationReportMetricsService::REPORT_TIMEZONE);
        $today = now($timezone)->startOfDay();
        $dateRange = (string) ($filters['date_range'] ?? ($request->hasAny(['date_from', 'date_to']) ? 'custom' : 'last_7'));
        [$presetFrom, $presetTo] = match ($dateRange) {
            'yesterday' => [$today->copy()->subDay(), $today->copy()->subDay()],
            'last_3' => [$today->copy()->subDays(2), $today->copy()],
            'last_15' => [$today->copy()->subDays(14), $today->copy()],
            'last_30' => [$today->copy()->subDays(29), $today->copy()],
            'last_60' => [$today->copy()->subDays(59), $today->copy()],
            'last_90' => [$today->copy()->subDays(89), $today->copy()],
            'last_6_months' => [$today->copy()->subMonths(6)->addDay(), $today->copy()],
            'last_year' => [$today->copy()->subYear()->addDay(), $today->copy()],
            default => [$today->copy()->subDays(6), $today->copy()],
        };
        $dateFrom = $dateRange === 'custom'
            ? (string) ($filters['date_from'] ?? $presetFrom->toDateString())
            : $presetFrom->toDateString();
        $dateTo = $dateRange === 'custom'
            ? (string) ($filters['date_to'] ?? $presetTo->toDateString())
            : $presetTo->toDateString();
        if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }

        $query = OperationReport::query()->where('status', OperationReport::STATUS_APPROVED);
        if (! $canSeeAll) {
            $operatorOnly
                ? $query->where('operator_user_id', $context['user']->getKey())
                : ($visibleIds === [] ? $query->whereRaw('1=0') : $query->whereIn('operation_department_id', $visibleIds));
        }
        $selectedDepartmentIds = collect($filters['department_ids'] ?? [])->map(fn ($id) => (int) $id)->unique()->values()->all();
        $query->when($selectedDepartmentIds !== [], fn ($q) => $q->whereIn('operation_department_id', $selectedDepartmentIds))
            ->when($filters['operator'] ?? null, fn ($q, $name) => $q->where('operator_name', 'like', '%'.$name.'%'));
        // 历史累计只依赖账号、广告和指标字段，避免把整份历史日报的无关大字段全部载入内存。
        $accountSpendReports = (clone $query)->get(['id', 'report_date', 'operator_user_id', 'advertising_rows', 'metric_rows']);
        $query->when($dateFrom !== '', fn ($q) => $q->whereDate('report_date', '>=', $dateFrom))
            ->when($dateTo !== '', fn ($q) => $q->whereDate('report_date', '<=', $dateTo));

        $reports = $query->with('operator.departments')->orderBy('report_date')->orderBy('operator_name')->get();
        $operatorIds = $reports->pluck('operator_user_id')
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->sort()
            ->values();
        $metricsCacheKey = 'assistant-applovin-performance:v4:'.$this->ownerAttribution->cacheVersion().':'.$timezone.':'.$dateFrom.':'.$dateTo.':'.sha1($operatorIds->implode(','));
        $calculatedByOperator = Cache::remember($metricsCacheKey, now()->addMinutes(15), function () use ($operatorIds, $dateFrom, $dateTo, $timezone): array {
            $calculated = [];
            // 长时间范围会包含大量订单及商品明细，按运营分批读取，避免 90 天等范围一次性加载导致内存溢出。
            foreach ($operatorIds->chunk(2) as $operatorIdBatch) {
                $calculated += $this->metrics->buildRangeForOperators(
                    $dateFrom,
                    $dateTo,
                    $operatorIdBatch->values()->all(),
                    $timezone,
                    requireBothSubscriptionTags: true,
                    attributeByStoreOwnerHistory: true,
                );
                gc_collect_cycles();
            }

            return $calculated;
        });
        $bindings = AppLovinAccountStoreBinding::query()->pluck('assistant_domain_store_id', 'account_key');
        $storeIds = $bindings->values()->merge(
            $reports->flatMap(fn (OperationReport $report) => collect($report->advertising_rows ?: [])->pluck('assistant_domain_store_id'))
        )->filter()->map(fn ($id) => (int) $id)->unique()->values();
        $stores = AssistantDomainStore::query()->whereIn('id', $storeIds)->get(['id', 'domain'])->keyBy('id');
        $accountSpendRecords = $accountSpendReports->flatMap(fn (OperationReport $report) => collect($report->advertising_rows ?: [])
            ->filter(fn (array $ad) => mb_strtolower(trim((string) ($ad['media_source'] ?? ''))) === 'applovin')
            ->map(function (array $ad) use ($report): array {
                $accountKey = AppLovinSpendScreenshot::accountKey(
                    (int) $report->operator_user_id,
                    (string) ($ad['agency'] ?? ''),
                    (string) ($ad['media_source'] ?? ''),
                    (string) ($ad['account_name'] ?? ''),
                    (string) ($ad['account_number'] ?? '')
                );

                return [
                    'account_identity' => AppLovinGiftConsumption::accountIdentity(
                        (int) $report->operator_user_id,
                        (string) ($ad['account_number'] ?? ''),
                        $accountKey,
                    ),
                    'spend' => (float) ($ad['actual_spend'] ?? 0),
                ];
            }));
        $accountSpendTotals = $accountSpendRecords->groupBy('account_identity')->map(fn ($items) => (float) $items->sum('spend'));
        $globalSpendTotals = $this->historicalSpend->totalsByAccountNumber();
        $globalSpendLedger = $this->historicalSpend->ledgerByAccountNumber();
        $giftAccountNumbers = AppLovinSpendScreenshot::query()->whereNotNull('account_number')->pluck('account_number')
            ->map(fn ($number): string => AppLovinHistoricalSpendService::normalizeAccountNumber((string) $number))
            ->filter()->unique()->values();
        unset($accountSpendRecords);
        gc_collect_cycles();
        $historicalRevenueByAccount = $accountSpendReports->reduce(function (array $totals, OperationReport $report) use ($bindings, $stores, $calculatedByOperator, $dateFrom, $dateTo): array {
            $storeAds = collect($report->advertising_rows ?: [])
                ->filter(fn (array $ad): bool => mb_strtolower(trim((string) ($ad['media_source'] ?? ''))) === 'applovin')
                ->map(function (array $ad) use ($report, $bindings, $stores): ?array {
                    $accountKey = AppLovinSpendScreenshot::accountKey(
                        (int) $report->operator_user_id,
                        (string) ($ad['agency'] ?? ''),
                        (string) ($ad['media_source'] ?? ''),
                        (string) ($ad['account_name'] ?? ''),
                        (string) ($ad['account_number'] ?? '')
                    );
                    $storeId = (int) ($ad['assistant_domain_store_id'] ?? 0);
                    if ($storeId < 1) {
                        $storeId = (int) ($bindings->get($accountKey) ?? 0);
                    }
                    $domain = trim((string) ($ad['store_domain'] ?? $stores->get($storeId)?->domain ?? ''));

                    return $storeId > 0 && $domain !== '' ? [
                        'store_id' => $storeId,
                        'domain' => $domain,
                        'account_identity' => AppLovinGiftConsumption::accountIdentity(
                            (int) $report->operator_user_id,
                            (string) ($ad['account_number'] ?? ''),
                            $accountKey,
                        ),
                        'spend' => (float) ($ad['actual_spend'] ?? 0),
                    ] : null;
                })->filter()->groupBy('store_id');

            foreach ($storeAds as $adsForStore) {
                $domain = mb_strtolower(trim((string) $adsForStore->first()['domain']));
                $reportDate = $report->report_date->format('Y-m-d');
                // For the selected range, always use freshly attributed order metrics. This
                // makes an edited ownership boundary move the matching performance at once;
                // older ranges keep their saved report snapshot to avoid loading all history.
                $metricRows = $reportDate >= $dateFrom && $reportDate <= $dateTo
                    ? collect($calculatedByOperator[(int) $report->operator_user_id]['rows'] ?? [])
                        ->filter(fn (array $metric): bool => ($metric['date'] ?? '') === $reportDate)
                    : collect($report->metric_rows ?: []);
                $revenue = $metricRows
                    ->filter(fn (array $metric): bool => mb_strtolower(trim((string) ($metric['shop_domain'] ?? ''))) === $domain
                        && mb_strtolower(trim((string) ($metric['media_source'] ?? ''))) === 'applovin')
                    ->sum(fn (array $metric): float => (float) ($metric['first_total_revenue'] ?? 0));
                $accounts = $adsForStore->groupBy('account_identity')->map(fn ($rows): float => (float) $rows->sum('spend'));
                $totalSpend = (float) $accounts->sum();
                foreach ($accounts as $accountIdentity => $spend) {
                    $share = $totalSpend > 0
                        ? (float) $spend / $totalSpend
                        : 1 / max(1, $accounts->count());
                    $totals[$accountIdentity] = ($totals[$accountIdentity] ?? 0.0) + ($revenue * $share);
                }
            }

            return $totals;
        }, []);
        unset($accountSpendReports);
        gc_collect_cycles();

        $dailyRows = $reports->flatMap(function (OperationReport $report) use ($bindings, $stores, $accountSpendTotals, $calculatedByOperator): array {
            $ads = collect($report->advertising_rows ?: [])
                ->filter(fn (array $ad) => mb_strtolower(trim((string) ($ad['media_source'] ?? ''))) === 'applovin')
                ->map(function (array $ad) use ($report, $bindings, $stores): ?array {
                    $accountKey = AppLovinSpendScreenshot::accountKey(
                        (int) $report->operator_user_id,
                        (string) ($ad['agency'] ?? ''),
                        (string) ($ad['media_source'] ?? ''),
                        (string) ($ad['account_name'] ?? ''),
                        (string) ($ad['account_number'] ?? '')
                    );
                    $storeId = (int) ($ad['assistant_domain_store_id'] ?? 0);
                    if ($storeId < 1) {
                        $storeId = (int) ($bindings->get($accountKey) ?? 0);
                    }

                    $store = $stores->get($storeId);
                    $domain = trim((string) ($ad['store_domain'] ?? $store?->domain ?? ''));
                    if ($storeId < 1 || $domain === '') {
                        return null;
                    }

                    return [
                        ...$ad,
                        'account_key' => $accountKey,
                        'account_identity' => AppLovinGiftConsumption::accountIdentity(
                            (int) $report->operator_user_id,
                            (string) ($ad['account_number'] ?? ''),
                            $accountKey,
                        ),
                        'resolved_store_id' => $storeId,
                        'resolved_store_domain' => $domain,
                    ];
                })
                ->filter()
                ->groupBy('resolved_store_id');

            return $ads->map(function ($storeAds) use ($report, $accountSpendTotals, $calculatedByOperator): array {
                $domain = trim((string) $storeAds->first()['resolved_store_domain']);
                $domainKey = mb_strtolower($domain);
                $metrics = collect($calculatedByOperator[(int) $report->operator_user_id]['rows'] ?? [])->filter(fn (array $metric) => ($metric['date'] ?? '') === $report->report_date->format('Y-m-d')
                    && mb_strtolower(trim((string) ($metric['shop_domain'] ?? ''))) === $domainKey
                    && mb_strtolower(trim((string) ($metric['media_source'] ?? ''))) === 'applovin'
                );
                $orders = (int) $metrics->sum('first_total_orders');
                $revenue = round((float) $metrics->sum('first_total_revenue'), 2);
                $subscriptionOrders = (int) $metrics->sum('first_subscription_orders');
                $subscriptionRevenue = round((float) $metrics->sum('first_subscription_revenue'), 2);
                $spend = round((float) $storeAds->sum('actual_spend'), 2);
                $accountKeys = $storeAds->pluck('account_identity')->filter()->unique()->values();

                return [
                    'date' => $report->report_date->format('Y-m-d'),
                    'department_id' => (int) $report->operation_department_id,
                    'department' => (string) $report->operation_department_name,
                    'operator_user_id' => (int) $report->operator_user_id,
                    'operator' => (string) $report->operator_name,
                    'team_position' => (string) ($report->operator?->departments?->firstWhere('id', $report->operation_department_id)?->pivot?->position ?: Department::POSITION_MEMBER),
                    'store_id' => (int) $storeAds->first()['resolved_store_id'],
                    'store_domain' => $domain,
                    'account_names' => $storeAds->pluck('account_name')->map(fn ($name) => trim((string) $name))->filter()->unique()->values()->all(),
                    'account_numbers' => $storeAds->pluck('account_number')->map(fn ($number) => trim((string) $number))->filter()->unique()->values()->all(),
                    'account_keys' => $accountKeys->all(),
                    'agency_names' => $storeAds->pluck('agency')->map(fn ($agency) => trim((string) $agency))->filter()->unique()->values()->all(),
                    'first_orders' => $orders,
                    'first_revenue' => $revenue,
                    'first_subscription_orders' => $subscriptionOrders,
                    'first_subscription_revenue' => $subscriptionRevenue,
                    'subscription_rate' => $orders > 0 ? $subscriptionOrders / $orders : null,
                    'average_order_value' => $orders > 0 ? $revenue / $orders : null,
                    'ad_spend' => $spend,
                    'roi' => $spend > 0 ? $revenue / $spend : null,
                    'total_consumption' => round((float) $accountKeys->sum(fn ($key) => (float) ($accountSpendTotals->get($key) ?? 0)), 2),
                ];
            })->values()->all();
        })->values();

        $rows = $dailyRows
            ->groupBy(fn (array $row) => implode('|', [$row['department_id'], $row['operator_user_id'], $row['store_id']]))
            ->map(function ($group) use ($globalSpendTotals, $globalSpendLedger, $giftAccountNumbers, $historicalRevenueByAccount): array {
                $first = $group->first();
                $orders = (int) $group->sum('first_orders');
                $revenue = round((float) $group->sum('first_revenue'), 2);
                $subscriptionOrders = (int) $group->sum('first_subscription_orders');
                $subscriptionRevenue = round((float) $group->sum('first_subscription_revenue'), 2);
                $spend = round((float) $group->sum('ad_spend'), 2);
                $accountKeys = $group->pluck('account_keys')->flatten()->filter()->unique()->values();
                $accountNumberValues = $group->pluck('account_numbers')->flatten()->filter()->unique()->values();
                $giftEligibleNumbers = $accountNumberValues->filter(fn ($number): bool => (float) $globalSpendTotals->get(AppLovinHistoricalSpendService::normalizeAccountNumber((string) $number), 0) >= 5000
                );
                $giftStatus = $giftEligibleNumbers->isEmpty()
                    ? 'not_eligible'
                    : ($giftEligibleNumbers->contains(fn ($number): bool => $giftAccountNumbers->contains(AppLovinHistoricalSpendService::normalizeAccountNumber((string) $number)))
                        ? 'available' : 'pending_upload');
                $segmentMetrics = $this->historicalSpend->segmentMetricsForAccounts(
                    $globalSpendLedger,
                    $accountNumberValues,
                    (int) $first['operator_user_id'],
                    AppLovinHistoricalSpendService::SUBSCRIPTION_PROJECT,
                    (string) $group->max('date'),
                    $giftAccountNumbers,
                );
                $deductedConsumption = (float) $segmentMetrics['deducted_consumption'];
                $historicalRevenue = round(max(
                    (float) $accountKeys->sum(fn ($key) => (float) ($historicalRevenueByAccount[$key] ?? 0)),
                    $revenue,
                ), 2);

                return [
                    'department_id' => (int) $first['department_id'],
                    'department' => $first['department'],
                    'operator_user_id' => (int) $first['operator_user_id'],
                    'operator' => $first['operator'],
                    'team_position' => (string) $first['team_position'],
                    'store_id' => (int) $first['store_id'],
                    'store_domain' => $first['store_domain'],
                    'date_label' => $group->min('date') === $group->max('date') ? $group->min('date') : $group->min('date').' 至 '.$group->max('date'),
                    'account_names' => $group->pluck('account_names')->flatten()->filter()->unique()->implode('、'),
                    'account_numbers' => $group->pluck('account_numbers')->flatten()->filter()->unique()->implode('、'),
                    'account_number_values' => $accountNumberValues->all(),
                    'account_keys' => $accountKeys->all(),
                    'agency_names' => $group->pluck('agency_names')->flatten()->filter()->unique()->implode('、'),
                    'first_orders' => $orders,
                    'first_revenue' => $revenue,
                    'first_subscription_orders' => $subscriptionOrders,
                    'first_subscription_revenue' => $subscriptionRevenue,
                    'subscription_rate' => $orders > 0 ? $subscriptionOrders / $orders : null,
                    'average_order_value' => $orders > 0 ? $revenue / $orders : null,
                    'ad_spend' => $spend,
                    'roi' => $spend > 0 ? $revenue / $spend : null,
                    'total_consumption' => (float) $segmentMetrics['segment_consumption'],
                    'pre_takeover_consumption' => (float) $segmentMetrics['before_consumption'],
                    'global_total_consumption' => $this->historicalSpend->sumForAccountNumbers($globalSpendTotals, $accountNumberValues),
                    'allocated_gift' => (float) $segmentMetrics['allocated_gift'],
                    'deducted_consumption' => $deductedConsumption,
                    'all_gift_deducted' => (bool) $segmentMetrics['all_gift_deducted'],
                    'segment_keys' => $segmentMetrics['segment_keys'],
                    'historical_revenue' => $historicalRevenue,
                    'estimated_total_roi' => $deductedConsumption > 0 ? $historicalRevenue / $deductedConsumption : null,
                    'gift_status' => $giftStatus,
                    'details' => $group->sortByDesc('date')->map(function (array $detail): array {
                        $detail['account_names'] = implode('、', $detail['account_names']);
                        $detail['account_numbers'] = implode('、', $detail['account_numbers']);
                        $detail['agency_names'] = implode('、', $detail['agency_names']);

                        return $detail;
                    })->values()->all(),
                ];
            })->values();

        if (($filters['gift_status'] ?? '') === 'yes') {
            $rows = $rows->where('gift_status', 'available')->values();
        } elseif (($filters['gift_status'] ?? '') === 'no') {
            $rows = $rows->where('gift_status', '!=', 'available')->values();
        }
        $domainOptions = $rows->pluck('store_domain')->filter()->unique(fn ($domain) => mb_strtolower((string) $domain))->sort()->values();
        if (($filters['store_domain'] ?? '') !== '') {
            $selectedDomain = mb_strtolower(trim((string) $filters['store_domain']));
            $rows = $rows->filter(fn (array $row): bool => mb_strtolower(trim((string) $row['store_domain'])) === $selectedDomain)->values();
        }
        if (($filters['account_number'] ?? '') !== '') {
            $selectedAccountNumber = $this->normalizeAccountNumberSearch((string) $filters['account_number']);
            $rows = $rows->filter(fn (array $row): bool => str_contains(
                $this->normalizeAccountNumberSearch((string) $row['account_numbers']),
                $selectedAccountNumber,
            ))->values();
        }
        if (($filters['consumption_range'] ?? '') === 'lt_5000') {
            $rows = $rows->filter(fn (array $row): bool => (float) $row['total_consumption'] < 5000)->values();
        } elseif (($filters['consumption_range'] ?? '') === '5000_10000') {
            $rows = $rows->filter(fn (array $row): bool => (float) $row['total_consumption'] >= 5000 && (float) $row['total_consumption'] <= 10000)->values();
        } elseif (($filters['consumption_range'] ?? '') === 'gt_10000') {
            $rows = $rows->filter(fn (array $row): bool => (float) $row['total_consumption'] > 10000)->values();
        } elseif (($filters['consumption_range'] ?? '') === 'custom') {
            $minimumConsumption = (float) ($filters['consumption_custom'] ?? 0);
            $maximumConsumption = (float) ($filters['consumption_custom_max'] ?? 0);
            $rows = $rows->filter(fn (array $row): bool => (float) $row['total_consumption'] >= $minimumConsumption
                && (float) $row['total_consumption'] <= $maximumConsumption)->values();
        }

        $displayMode = (string) ($filters['display_mode'] ?? 'performance');
        $adSpendSort = (string) ($filters['ad_spend_sort'] ?? 'desc');
        $rows = $rows->sort(function (array $left, array $right) use ($adSpendSort): int {
            $comparison = (float) $left['ad_spend'] <=> (float) $right['ad_spend'];
            if ($adSpendSort === 'desc') {
                $comparison *= -1;
            }

            return $comparison !== 0
                ? $comparison
                : [$left['department'], $left['operator'], $left['store_domain']]
                    <=> [$right['department'], $right['operator'], $right['store_domain']];
        })->values();
        $totals = [
            'stores' => $rows->pluck('store_domain')->unique()->count(),
            'subscription_orders' => (int) $rows->sum('first_subscription_orders'),
            'subscription_revenue' => round((float) $rows->sum('first_subscription_revenue'), 2),
            'orders' => (int) $rows->sum('first_orders'),
            'revenue' => round((float) $rows->sum('first_revenue'), 2),
            'ad_spend' => round((float) $rows->sum('ad_spend'), 2),
        ];
        $totals['average_order_value'] = $totals['orders'] > 0 ? $totals['revenue'] / $totals['orders'] : null;
        $totals['subscription_rate'] = $totals['orders'] > 0 ? $totals['subscription_orders'] / $totals['orders'] : null;
        $totals['roi'] = $totals['ad_spend'] > 0 ? $totals['revenue'] / $totals['ad_spend'] : null;
        $totals['total_consumption'] = round((float) $rows->sum('total_consumption'), 2);
        $totals['global_total_consumption'] = $this->historicalSpend->sumForAccountNumbers(
            $globalSpendTotals,
            $rows->pluck('account_number_values')->flatten()->filter()->unique(),
        );
        $totalAccountKeys = $rows->pluck('account_keys')->flatten()->filter()->unique()->values();
        $totals['pre_takeover_consumption'] = round((float) $rows->sum('pre_takeover_consumption'), 2);
        $totals['deducted_consumption'] = round((float) $rows->sum('deducted_consumption'), 2);
        $totals['historical_revenue'] = round(max(
            (float) $totalAccountKeys->sum(fn ($key) => (float) ($historicalRevenueByAccount[$key] ?? 0)),
            (float) $totals['revenue'],
        ), 2);
        $totals['estimated_total_roi'] = $totals['deducted_consumption'] > 0
            ? $totals['historical_revenue'] / $totals['deducted_consumption']
            : null;
        $exportRows = $rows->map(fn (array $row): array => [
            'date_label' => $row['date_label'],
            'department' => $row['department'],
            'operator' => $row['operator'],
            'store_domain' => $row['store_domain'],
            'first_subscription_orders' => $row['first_subscription_orders'],
            'subscription_rate' => $row['subscription_rate'],
            'first_subscription_revenue' => $row['first_subscription_revenue'],
            'first_orders' => $row['first_orders'],
            'first_revenue' => $row['first_revenue'],
            'ad_spend' => $row['ad_spend'],
            'gift_status' => $row['gift_status'],
            'roi' => $row['roi'],
            'account_numbers' => $row['account_numbers'],
            'pre_takeover_consumption' => $row['pre_takeover_consumption'],
            'total_consumption' => $row['total_consumption'],
            'global_total_consumption' => $row['global_total_consumption'],
            'deducted_consumption' => $row['deducted_consumption'],
            'all_gift_deducted' => $row['all_gift_deducted'],
            'historical_revenue' => $row['historical_revenue'],
            'estimated_total_roi' => $row['estimated_total_roi'],
        ])->values();
        $page = max(1, (int) $request->query('page', 1));
        $paginator = new LengthAwarePaginator($rows->forPage($page, 30)->values(), $rows->count(), 30, $page, ['path' => $request->url(), 'query' => $request->query()]);

        return view('portals.assistant.applovin-performance-reports.index', [
            'portal' => $context['portal']->loadMissing('menus'), 'user' => $context['user'], 'rows' => $paginator, 'exportRows' => $exportRows, 'totals' => $totals,
            'operator' => $filters['operator'] ?? '',
            'displayMode' => $displayMode,
            'adSpendSort' => $adSpendSort,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'timezone' => $timezone,
            'timezoneOptions' => $timezoneOptions,
            'dateRange' => $dateRange,
            'selectedDepartmentIds' => $selectedDepartmentIds,
            'selectedStoreDomain' => $filters['store_domain'] ?? '',
            'selectedAccountNumber' => $filters['account_number'] ?? '',
            'domainOptions' => $domainOptions,
            'selectedGiftStatus' => $filters['gift_status'] ?? '',
            'selectedConsumptionRange' => $filters['consumption_range'] ?? '',
            'consumptionCustom' => $filters['consumption_custom'] ?? '',
            'consumptionCustomMax' => $filters['consumption_custom_max'] ?? '',
            'departmentOptions' => $this->storeOwners->operationDepartments()->whereIn('id', $visibleIds)->values(),
            'performanceResetRoute' => $isDashboard
                ? 'operation-dashboard.applovin-performance-reports.index'
                : ($isOperationPortal
                    ? 'operation.applovin-performance-reports.index'
                    : 'assistant.applovin-performance-reports.index'),
        ]);
    }

    private function normalizeAccountNumberSearch(string $value): string
    {
        return mb_strtolower((string) preg_replace('/[\s\p{Cf}]+/u', '', trim($value)));
    }

    /** @return array<string,string> */
    private function timezoneOptions(): array
    {
        $options = [];
        for ($offset = -12; $offset <= 14; $offset++) {
            $value = sprintf('%s%02d:00', $offset < 0 ? '-' : '+', abs($offset));
            $label = 'UTC'.($offset >= 0 ? '+' : '').$offset;
            if ($value === '+08:00') {
                $label .= '（北京时间）';
            }
            if ($value === OperationReportMetricsService::REPORT_TIMEZONE) {
                $label .= '（默认）';
            }
            $options[$value] = $label;
        }

        return $options;
    }
}
