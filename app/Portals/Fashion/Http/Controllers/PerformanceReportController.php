<?php

namespace App\Portals\Fashion\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\AssistantShopifyStoreOrder;
use App\Models\FashionAdvertisingReport;
use App\Models\FashionLogisticsOrder;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\Assistant\ShopifyAnalyticsReferenceRateService;
use App\Portals\Fashion\OperationProjectCatalog;
use App\Services\DepartmentAuthorizationService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class PerformanceReportController extends Controller
{
    private array $project;

    public function __construct(
        private readonly PortalEntryController $portalEntries,
        private readonly DepartmentAuthorizationService $departmentAuthorization,
        private readonly ShopifyAnalyticsReferenceRateService $referenceRates,
    ) {}

    public function index(Request $request): Response|View
    {
        $context = $this->portalEntries->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }
        $this->setProject($request, $context);

        $timezoneOptions = $this->timezoneOptions();
        $siteBuilderOptions = $this->siteBuilderOptions();
        $validated = $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'timezone' => ['nullable', Rule::in(array_keys($timezoneOptions))],
            'site_builder' => ['nullable', Rule::in(array_keys($siteBuilderOptions))],
            'operator_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'in:20,50,100'],
        ]);

        $timezone = (string) ($validated['timezone'] ?? '-08:00');
        $month = now($timezone);
        $dateFrom = $validated['date_from'] ?? $month->copy()->startOfMonth()->toDateString();
        $dateTo = $validated['date_to'] ?? $month->copy()->endOfMonth()->toDateString();
        if ($dateFrom > $dateTo) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }
        $storageFrom = CarbonImmutable::parse($dateFrom.' 00:00:00', $timezone)->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s');
        $storageTo = CarbonImmutable::parse($dateTo.' 23:59:59', $timezone)->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s');

        $user = $context['user'];
        $connection = config('admin.database.connection') ?: config('database.default');
        $storeTable = 'assistant_domain_stores';
        $userTable = config('admin.database.users_table');
        $orderTable = (new FashionLogisticsOrder)->getTable();
        $nameIdentities = DB::connection($connection)->table($userTable)
            ->whereNotNull('name')->whereRaw("TRIM(name) <> ''")
            ->selectRaw('id as owner_id, LOWER(TRIM(name)) as identity');
        $usernameIdentities = DB::connection($connection)->table($userTable)
            ->whereNotNull('username')->whereRaw("TRIM(username) <> ''")
            ->selectRaw('id as owner_id, LOWER(TRIM(username)) as identity');
        $identityMap = DB::connection($connection)->query()
            ->fromSub($nameIdentities->unionAll($usernameIdentities), 'operator_identities')
            ->selectRaw('MIN(owner_id) as owner_id, identity')->groupBy('identity');
        $resolvedOwnerSql = 'COALESCE(stores.owner_user_id, owner_identity.owner_id)';
        // Both platforms store their China-normalized payment time in this legacy
        // column. The selected report timezone is applied below for date grouping.
        $orderTimestampSql = 'orders.paid_at_utc';
        $managedDepartmentIds = $this->departmentAuthorization->managedDepartmentIds($user)
            ->intersect($this->fashionDepartmentIds())->values();
        $canSelectOperator = $user->isAdministrator() || $managedDepartmentIds->isNotEmpty();
        $operators = $this->reportOperators($user, $managedDepartmentIds);
        $selectedOperatorId = ! empty($validated['operator_id']) ? (int) $validated['operator_id'] : null;
        if ($selectedOperatorId !== null) {
            abort_unless($canSelectOperator && $operators->contains(
                fn (AdminUser $operator): bool => (int) $operator->getKey() === $selectedOperatorId
            ), 403, '无权查看该运营人员的业绩数据。');
        }

        if ($this->project['code'] === 'kunpeng-shopify') {
            return $this->shopifyIndex(
                $context,
                $validated,
                $timezoneOptions,
                $timezone,
                $dateFrom,
                $dateTo,
                $user,
                $connection,
                $identityMap,
                $resolvedOwnerSql,
                $operators,
                $canSelectOperator,
                $selectedOperatorId,
                $siteBuilderOptions,
            );
        }

        $base = DB::connection($connection)->table($orderTable.' as orders')
            ->join($storeTable.' as stores', 'stores.id', '=', 'orders.assistant_domain_store_id')
            ->leftJoinSub($identityMap, 'owner_identity', fn ($join) => $join->on('owner_identity.identity', '=', DB::raw('LOWER(TRIM(stores.user_name))')))
            ->leftJoin($userTable.' as owners', 'owners.id', '=', DB::raw($resolvedOwnerSql))
            ->whereNotNull('orders.paid_at_utc')
            ->whereBetween(DB::raw($orderTimestampSql), [$storageFrom, $storageTo]);

        $base->whereIn(DB::raw('TRIM(stores.project)'), OperationProjectCatalog::storeProjectNames($this->project));
        $base->whereRaw("LOWER(TRIM(orders.source_platform)) = CASE WHEN LOWER(TRIM(stores.site_builder)) = 'xshopy' THEN 'xshoppy' ELSE LOWER(TRIM(stores.site_builder)) END");
        $base->where(function ($paid): void {
            $paid->whereNotIn(DB::raw('LOWER(TRIM(orders.source_platform))'), ['nbc', 'shopyy'])
                ->orWhere('orders.payment_status', '230');
        });

        $selectedSiteBuilder = (string) ($validated['site_builder'] ?? '');
        if ($selectedSiteBuilder !== '') {
            $this->applyPerformancePlatformFilter($base, $selectedSiteBuilder);
        }

        if (! $user->isAdministrator() || $selectedOperatorId !== null) {
            $scopeOperators = $selectedOperatorId !== null
                ? $operators->filter(fn (AdminUser $operator): bool => (int) $operator->getKey() === $selectedOperatorId)
                : $operators;
            $ownerIds = $scopeOperators->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();
            $ownerNames = $scopeOperators->flatMap(fn (AdminUser $operator) => [$operator->name, $operator->username])
                ->filter()->map(fn ($name): string => trim((string) $name))->unique()->values()->all();
            $base->where(function ($binding) use ($ownerIds, $ownerNames): void {
                if ($ownerIds !== []) {
                    $binding->whereIn('stores.owner_user_id', $ownerIds);
                } else {
                    $binding->whereRaw('1 = 0');
                }
                if ($ownerNames !== []) {
                    $binding->orWhereIn('stores.user_name', $ownerNames);
                }
            });
        }

        $summary = (clone $base)->selectRaw(
            'COUNT(*) as order_count, COALESCE(SUM(orders.total_amount), 0) as revenue, COUNT(DISTINCT stores.id) as store_count'
        )->first();

        // The offset is restricted by timezoneOptions(), so it is safe to embed. Using two
        // parameter placeholders makes MySQL ONLY_FULL_GROUP_BY treat SELECT/GROUP BY as
        // different expressions even when both bound values are identical.
        $reportDateSql = "DATE(CONVERT_TZ({$orderTimestampSql}, '+08:00', '{$timezone}'))";
        $rows = (clone $base)
            ->selectRaw($reportDateSql.' as report_date')
            ->addSelect([
                'stores.id as store_id',
                'stores.shop_name',
                'stores.domain',
                'stores.site_builder',
                'owners.name as owner_name',
                'owners.username as owner_username',
            ])
            ->selectRaw($resolvedOwnerSql.' as owner_user_id')
            ->selectRaw('COUNT(*) as order_count, COALESCE(SUM(orders.total_amount), 0) as revenue')
            ->groupByRaw($reportDateSql.', stores.id, stores.shop_name, stores.domain, stores.site_builder, '.$resolvedOwnerSql.', owners.name, owners.username')
            ->orderByDesc('report_date')
            ->orderBy('owner_name')
            ->orderBy('stores.shop_name')
            ->paginate((int) ($validated['per_page'] ?? 20))
            ->withQueryString();

        $operatorDailyRevenue = (clone $base)
            ->selectRaw($reportDateSql.' as report_date, '.$resolvedOwnerSql.' as owner_user_id, COALESCE(SUM(orders.total_amount),0) revenue')
            ->whereRaw($resolvedOwnerSql.' IS NOT NULL')
            ->groupByRaw($reportDateSql.', '.$resolvedOwnerSql)
            ->get()->keyBy(fn ($item) => (int) $item->owner_user_id.'|'.$item->report_date);
        $scopeOperatorIds = $operatorDailyRevenue->pluck('owner_user_id')->map(fn ($id) => (int) $id)->unique()->values();
        $operatorProfiles = AdminUser::query()->with('departments')->whereIn('id', $scopeOperatorIds)->get()->keyBy('id');
        $approvedReports = FashionAdvertisingReport::query()
            ->where('project_code', $this->project['code'])
            ->where('status', FashionAdvertisingReport::STATUS_APPROVED)
            ->whereBetween('report_date', [$dateFrom, $dateTo])
            ->whereIn('submitter_id', $scopeOperatorIds)
            ->with('adSpendEntries')->get();
        $performanceFormula = OperationProjectCatalog::performanceFormula($this->project['code']);
        $reportsByOperatorDate = $approvedReports->groupBy(fn (FashionAdvertisingReport $report) => (int) $report->submitter_id.'|'.$report->report_date->format('Y-m-d'));
        $rows->setCollection($rows->getCollection()->map(function ($row) use ($operatorProfiles, $operatorDailyRevenue, $reportsByOperatorDate, $performanceFormula): object {
            $operatorId = (int) $row->owner_user_id;
            $profile = $operatorProfiles->get($operatorId);
            $department = $profile?->departments?->first(fn ($item) => (bool) ($item->pivot->is_primary ?? false)) ?: $profile?->departments?->first();
            $dailyRevenue = (float) ($operatorDailyRevenue->get($operatorId.'|'.$row->report_date)->revenue ?? 0);
            $dailyReports = $reportsByOperatorDate->get($operatorId.'|'.$row->report_date, collect());
            $media = $dailyReports->flatMap->adSpendEntries->groupBy(fn ($entry) => trim((string) $entry->media) ?: '未设置媒体')
                ->map(function ($entries, string $name) use ($performanceFormula): array {
                    $spend = round((float) $entries->sum($performanceFormula ? 'book_spend_usd' : 'actual_spend_usd'), 2);

                    return ['name' => $name, 'spend' => $spend];
                })->sortKeys()->values();
            $financials = $this->performanceFinancials($dailyRevenue, $dailyReports->flatMap->adSpendEntries, $performanceFormula);
            $row->department_name = (string) ($department?->name ?: '未分配部门');
            $row->hired_at = $profile?->hired_at?->format('Y-m-d');
            $row->hire_days = $profile?->hired_at ? max(1, (int) $profile->hired_at->diffInDays(CarbonImmutable::parse($row->report_date)) + 1) : null;
            $row->media_metrics = $media;
            foreach ($financials as $key => $value) $row->{$key} = $value;

            return $row;
        }));

        $orderCount = (int) ($summary->order_count ?? 0);
        $revenue = (float) ($summary->revenue ?? 0);
        $summaryFinancials = $this->performanceFinancials($revenue, $approvedReports->flatMap->adSpendEntries, $performanceFormula);

        return view('portals.fashion.performance-report.index', [
            ...$context,
            'rows' => $rows,
            'operators' => $operators,
            'canSelectOperator' => $canSelectOperator,
            'timezoneOptions' => $timezoneOptions,
            'filters' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'timezone' => $timezone,
                'site_builder' => $validated['site_builder'] ?? '',
                'operator_id' => $selectedOperatorId ?? '',
                'per_page' => (int) ($validated['per_page'] ?? 20),
            ],
            'summary' => [
                'order_count' => $orderCount,
                'revenue' => $revenue,
                'average_order_value' => $orderCount > 0 ? $revenue / $orderCount : 0,
                'store_count' => (int) ($summary->store_count ?? 0),
                'ad_spend' => $summaryFinancials['book_ad_spend'],
                'roi' => $summaryFinancials['total_roi'],
                ...$summaryFinancials,
            ],
            'operationProject' => $this->project,
            'siteBuilderOptions' => $siteBuilderOptions,
            'isShopifyPerformance' => false,
            'performanceFormula' => $performanceFormula,
        ]);
    }

    private function shopifyIndex(
        array $context,
        array $validated,
        array $timezoneOptions,
        string $timezone,
        string $dateFrom,
        string $dateTo,
        AdminUser $user,
        string $connection,
        $identityMap,
        string $resolvedOwnerSql,
        $operators,
        bool $canSelectOperator,
        ?int $selectedOperatorId,
        array $siteBuilderOptions,
    ): View {
        $storeTable = 'assistant_domain_stores';
        $userTable = config('admin.database.users_table');
        $orderTable = (new AssistantShopifyStoreOrder)->getTable();
        $utcFrom = CarbonImmutable::parse($dateFrom.' 00:00:00', $timezone)->utc()->format('Y-m-d H:i:s');
        $utcToExclusive = CarbonImmutable::parse($dateTo.' 00:00:00', $timezone)->addDay()->utc()->format('Y-m-d H:i:s');
        $revenueSql = $this->shopifyRevenueSql('orders.original_currency_code', 'orders.original_total_price');

        $base = DB::connection($connection)->table($orderTable.' as orders')
            ->join($storeTable.' as stores', 'stores.id', '=', 'orders.assistant_domain_store_id')
            ->leftJoinSub($identityMap, 'owner_identity', fn ($join) => $join->on('owner_identity.identity', '=', DB::raw('LOWER(TRIM(stores.user_name))')))
            ->leftJoin($userTable.' as owners', 'owners.id', '=', DB::raw($resolvedOwnerSql))
            ->whereIn(DB::raw('TRIM(stores.project)'), OperationProjectCatalog::storeProjectNames($this->project))
            ->whereRaw("LOWER(TRIM(stores.site_builder)) = 'shopify'")
            ->where('orders.is_test', false)
            ->whereIn(DB::raw('UPPER(orders.financial_status)'), AssistantShopifyStoreOrder::successfulFinancialStatuses())
            ->whereNotNull('orders.shopify_created_at')
            ->where('orders.shopify_created_at', '>=', $utcFrom)
            ->where('orders.shopify_created_at', '<', $utcToExclusive);

        if (($validated['site_builder'] ?? '') !== '' && ($validated['site_builder'] ?? '') !== 'shopify') {
            $base->whereRaw('1 = 0');
        }

        if (! $user->isAdministrator() || $selectedOperatorId !== null) {
            $scopeOperators = $selectedOperatorId !== null
                ? $operators->filter(fn (AdminUser $operator): bool => (int) $operator->getKey() === $selectedOperatorId)
                : $operators;
            $ownerIds = $scopeOperators->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();
            $ownerNames = $scopeOperators->flatMap(fn (AdminUser $operator) => [$operator->name, $operator->username])
                ->filter()->map(fn ($name): string => trim((string) $name))->unique()->values()->all();
            $base->where(function ($binding) use ($ownerIds, $ownerNames): void {
                if ($ownerIds !== []) {
                    $binding->whereIn('stores.owner_user_id', $ownerIds);
                } else {
                    $binding->whereRaw('1 = 0');
                }
                if ($ownerNames !== []) {
                    $binding->orWhereIn('stores.user_name', $ownerNames);
                }
            });
        }

        $summary = (clone $base)->selectRaw(
            "COUNT(*) as order_count, COALESCE(SUM({$revenueSql}), 0) as revenue, COUNT(DISTINCT stores.id) as store_count"
        )->first();
        $reportDateSql = "DATE(CONVERT_TZ(orders.shopify_created_at, '+00:00', '{$timezone}'))";
        $rows = (clone $base)
            ->selectRaw($reportDateSql.' as report_date')
            ->addSelect([
                'stores.id as store_id',
                'stores.shop_name',
                'stores.domain',
                'stores.site_builder',
                'owners.name as owner_name',
                'owners.username as owner_username',
            ])
            ->selectRaw($resolvedOwnerSql.' as owner_user_id')
            ->selectRaw("COUNT(*) as order_count, COALESCE(SUM({$revenueSql}), 0) as revenue")
            ->groupByRaw($reportDateSql.', stores.id, stores.shop_name, stores.domain, stores.site_builder, '.$resolvedOwnerSql.', owners.name, owners.username')
            ->orderByDesc('report_date')
            ->orderBy('owner_name')
            ->orderBy('stores.shop_name')
            ->paginate((int) ($validated['per_page'] ?? 20))
            ->withQueryString();

        $operatorDailyRevenue = (clone $base)
            ->selectRaw($reportDateSql.' as report_date, '.$resolvedOwnerSql." as owner_user_id, COALESCE(SUM({$revenueSql}), 0) revenue")
            ->whereRaw($resolvedOwnerSql.' IS NOT NULL')
            ->groupByRaw($reportDateSql.', '.$resolvedOwnerSql)
            ->get()->keyBy(fn ($item) => (int) $item->owner_user_id.'|'.$item->report_date);
        $scopeOperatorIds = $operatorDailyRevenue->pluck('owner_user_id')->map(fn ($id) => (int) $id)->unique()->values();
        $operatorProfiles = AdminUser::query()->with('departments')->whereIn('id', $scopeOperatorIds)->get()->keyBy('id');
        $approvedReports = FashionAdvertisingReport::query()
            ->where('project_code', $this->project['code'])
            ->where('status', FashionAdvertisingReport::STATUS_APPROVED)
            ->whereBetween('report_date', [$dateFrom, $dateTo])
            ->whereIn('submitter_id', $scopeOperatorIds)
            ->with('adSpendEntries')->get();
        $performanceFormula = OperationProjectCatalog::performanceFormula($this->project['code']);
        $reportsByOperatorDate = $approvedReports->groupBy(fn (FashionAdvertisingReport $report) => (int) $report->submitter_id.'|'.$report->report_date->format('Y-m-d'));
        $rows->setCollection($rows->getCollection()->map(function ($row) use ($operatorProfiles, $operatorDailyRevenue, $reportsByOperatorDate, $performanceFormula): object {
            $operatorId = (int) $row->owner_user_id;
            $profile = $operatorProfiles->get($operatorId);
            $department = $profile?->departments?->first(fn ($item) => (bool) ($item->pivot->is_primary ?? false)) ?: $profile?->departments?->first();
            $dailyRevenue = (float) ($operatorDailyRevenue->get($operatorId.'|'.$row->report_date)->revenue ?? 0);
            $dailyReports = $reportsByOperatorDate->get($operatorId.'|'.$row->report_date, collect());
            $media = $dailyReports->flatMap->adSpendEntries
                ->groupBy(fn ($entry) => trim((string) $entry->media) ?: '未设置媒体')
                ->map(function ($entries, string $name) use ($performanceFormula): array {
                    return ['name' => $name, 'spend' => round((float) $entries->sum($performanceFormula ? 'book_spend_usd' : 'actual_spend_usd'), 2)];
                })->sortKeys()->values();
            $financials = $this->performanceFinancials($dailyRevenue, $dailyReports->flatMap->adSpendEntries, $performanceFormula);
            $row->department_name = (string) ($department?->name ?: '未分配部门');
            $row->hired_at = $profile?->hired_at?->format('Y-m-d');
            $row->hire_days = $profile?->hired_at ? max(1, (int) $profile->hired_at->diffInDays(CarbonImmutable::parse($row->report_date)) + 1) : null;
            $row->media_metrics = $media;
            foreach ($financials as $key => $value) $row->{$key} = $value;

            return $row;
        }));

        $orderCount = (int) ($summary->order_count ?? 0);
        $revenue = (float) ($summary->revenue ?? 0);
        $summaryFinancials = $this->performanceFinancials($revenue, $approvedReports->flatMap->adSpendEntries, $performanceFormula);

        return view('portals.fashion.performance-report.index', [
            ...$context,
            'rows' => $rows,
            'operators' => $operators,
            'canSelectOperator' => $canSelectOperator,
            'timezoneOptions' => $timezoneOptions,
            'filters' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'timezone' => $timezone,
                'site_builder' => $validated['site_builder'] ?? '',
                'operator_id' => $selectedOperatorId ?? '',
                'per_page' => (int) ($validated['per_page'] ?? 20),
            ],
            'summary' => [
                'order_count' => $orderCount,
                'revenue' => $revenue,
                'average_order_value' => $orderCount > 0 ? $revenue / $orderCount : 0,
                'store_count' => (int) ($summary->store_count ?? 0),
                'ad_spend' => $summaryFinancials['book_ad_spend'],
                'roi' => $summaryFinancials['total_roi'],
                ...$summaryFinancials,
            ],
            'operationProject' => $this->project,
            'siteBuilderOptions' => $siteBuilderOptions,
            'isShopifyPerformance' => true,
            'performanceFormula' => $performanceFormula,
        ]);
    }

    /** @return array{book_ad_spend:float,service_fee:float,actual_ad_spend:float,store_fee:float,misc_fee_rmb:float,total_roi:?float,profit_rmb:?float} */
    private function performanceFinancials(float $revenue, $entries, ?array $formula): array
    {
        $actualAdSpend = round((float) $entries->sum('actual_spend_usd'), 2);
        if ($formula === null) {
            return [
                'book_ad_spend' => $actualAdSpend,
                'service_fee' => 0.0,
                'actual_ad_spend' => $actualAdSpend,
                'store_fee' => 0.0,
                'misc_fee_rmb' => 0.0,
                'total_roi' => $actualAdSpend > 0 ? $revenue / $actualAdSpend : null,
                'profit_rmb' => null,
            ];
        }

        $bookAdSpend = round((float) $entries->sum('book_spend_usd'), 2);
        $serviceFee = round($actualAdSpend - $bookAdSpend, 2);
        $storeFee = $revenue * $formula['store_fee_rate'];
        $miscFeeRmb = round((float) $entries->sum('misc_fee_rmb'), 2);
        $profitRmb = (($revenue * $formula['gross_margin_rate']) - $bookAdSpend - $serviceFee - $storeFee)
            * $formula['usd_to_rmb_rate'] - $miscFeeRmb;

        return [
            'book_ad_spend' => $bookAdSpend,
            'service_fee' => $serviceFee,
            'actual_ad_spend' => $actualAdSpend,
            'store_fee' => round($storeFee, 2),
            'misc_fee_rmb' => $miscFeeRmb,
            'total_roi' => $actualAdSpend > 0 ? $revenue / $actualAdSpend : null,
            'profit_rmb' => round($profitRmb, 2),
        ];
    }

    private function shopifyRevenueSql(string $currencyExpression, string $amountExpression): string
    {
        $clauses = [];
        $rates = $this->referenceRates->rates();
        $rates['USD'] ??= 1.0;
        foreach ($rates as $currency => $rate) {
            $clauses[] = "WHEN '{$currency}' THEN ({$amountExpression}) * ".number_format($rate, 8, '.', '');
        }

        return 'CASE UPPER(COALESCE('.$currencyExpression.", '')) ".implode(' ', $clauses).' ELSE 0 END';
    }

    public function orders(Request $request): Response|JsonResponse
    {
        $context = $this->portalEntries->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }
        $this->setProject($request, $context);
        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'store_id' => ['required', 'integer'],
            'page' => ['nullable', 'integer', 'min:1'],
            'timezone' => ['nullable', Rule::in(array_keys($this->timezoneOptions()))],
        ]);
        $timezone = (string) ($validated['timezone'] ?? '-08:00');
        if ($this->project['code'] === 'kunpeng-shopify') {
            return $this->shopifyOrders($context, $validated, $timezone);
        }
        $storageFrom = CarbonImmutable::parse($validated['date'].' 00:00:00', $timezone)->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s');
        $storageTo = CarbonImmutable::parse($validated['date'].' 23:59:59', $timezone)->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s');
        $user = $context['user'];
        $visibleOperators = $this->reportOperators(
            $user,
            $this->departmentAuthorization->managedDepartmentIds($user)
                ->intersect($this->fashionDepartmentIds())->values()
        );
        $visibleOperatorIds = $visibleOperators->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();
        $visibleOperatorNames = $visibleOperators->flatMap(fn (AdminUser $operator) => [$operator->name, $operator->username])
            ->filter()->map(fn ($name): string => trim((string) $name))->unique()->values()->all();
        $store = DB::connection(config('admin.database.connection') ?: config('database.default'))
            ->table('assistant_domain_stores')
            ->where('id', (int) $validated['store_id'])
            ->whereIn(DB::raw('TRIM(project)'), OperationProjectCatalog::storeProjectNames($this->project))
            ->when(! $user->isAdministrator(), function ($query) use ($visibleOperatorIds, $visibleOperatorNames): void {
                $query->where(function ($binding) use ($visibleOperatorIds, $visibleOperatorNames): void {
                    if ($visibleOperatorIds !== []) {
                        $binding->whereIn('owner_user_id', $visibleOperatorIds);
                    } else {
                        $binding->whereRaw('1 = 0');
                    }
                    if ($visibleOperatorNames !== []) {
                        $binding->orWhereIn('user_name', $visibleOperatorNames);
                    }
                });
            })->first();
        abort_unless($store, 403, '无权查看该店铺订单。');
        $sourcePlatform = mb_strtolower(trim((string) $store->site_builder));
        $sourcePlatform = $sourcePlatform === 'xshopy' ? 'xshoppy' : $sourcePlatform;

        // Both platforms use China-normalized payment time in this legacy column.
        $orderTimestampSql = 'paid_at_utc';
        $ordersQuery = FashionLogisticsOrder::query()
            ->where('assistant_domain_store_id', (int) $validated['store_id'])
            ->where('source_platform', $sourcePlatform)
            ->whereNotNull('paid_at_utc')
            ->whereBetween(DB::raw($orderTimestampSql), [$storageFrom, $storageTo])
            ->when(in_array($sourcePlatform, ['nbc', 'shopyy'], true), fn ($query) => $query->where('payment_status', '230'));
        $amount = (float) (clone $ordersQuery)->sum('total_amount');
        $orders = $ordersQuery
            ->with(['items:id,fashion_logistics_order_id,product_name,sku,quantity,total'])
            ->orderByRaw($orderTimestampSql.' desc')->orderByDesc('id')->paginate(20);

        return response()->json([
            'date' => $validated['date'],
            'store' => (string) ($store->shop_name ?: $store->domain),
            'count' => $orders->total(),
            'amount' => round($amount, 2),
            'pagination' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
                'from' => $orders->firstItem(),
                'to' => $orders->lastItem(),
            ],
            'orders' => $orders->getCollection()->map(fn (FashionLogisticsOrder $order): array => [
                'id' => (int) $order->getKey(),
                'order_number' => (string) ($order->order_number ?: $order->external_order_id),
                'status' => (string) ($order->status ?: '—'),
                'currency' => (string) ($order->currency ?: 'USD'),
                'total_amount' => (float) $order->total_amount,
                'customer_name' => (string) ($order->customer_name ?: '—'),
                'customer_email' => (string) ($order->customer_email ?: '—'),
                'payment_method' => (string) ($order->payment_method_title ?: $order->payment_method ?: '—'),
                'order_created_at' => $this->formatChinaOrderTime($order, $timezone),
                'paid_at' => $order->getRawOriginal('paid_at'),
                'items' => $order->items->map(fn ($item): array => [
                    'product_name' => (string) ($item->product_name ?: '—'),
                    'sku' => (string) ($item->sku ?: '—'),
                    'quantity' => (float) $item->quantity,
                    'total' => (float) $item->total,
                ])->values()->all(),
            ])->values()->all(),
        ]);
    }

    private function shopifyOrders(array $context, array $validated, string $timezone): JsonResponse
    {
        $utcFrom = CarbonImmutable::parse($validated['date'].' 00:00:00', $timezone)->utc()->format('Y-m-d H:i:s');
        $utcToExclusive = CarbonImmutable::parse($validated['date'].' 00:00:00', $timezone)->addDay()->utc()->format('Y-m-d H:i:s');
        $user = $context['user'];
        $visibleOperators = $this->reportOperators(
            $user,
            $this->departmentAuthorization->managedDepartmentIds($user)
                ->intersect($this->fashionDepartmentIds())->values()
        );
        $visibleOperatorIds = $visibleOperators->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();
        $visibleOperatorNames = $visibleOperators->flatMap(fn (AdminUser $operator) => [$operator->name, $operator->username])
            ->filter()->map(fn ($name): string => trim((string) $name))->unique()->values()->all();
        $store = DB::connection(config('admin.database.connection') ?: config('database.default'))
            ->table('assistant_domain_stores')
            ->where('id', (int) $validated['store_id'])
            ->whereRaw("LOWER(TRIM(site_builder)) = 'shopify'")
            ->whereIn(DB::raw('TRIM(project)'), OperationProjectCatalog::storeProjectNames($this->project))
            ->when(! $user->isAdministrator(), function ($query) use ($visibleOperatorIds, $visibleOperatorNames): void {
                $query->where(function ($binding) use ($visibleOperatorIds, $visibleOperatorNames): void {
                    if ($visibleOperatorIds !== []) {
                        $binding->whereIn('owner_user_id', $visibleOperatorIds);
                    } else {
                        $binding->whereRaw('1 = 0');
                    }
                    if ($visibleOperatorNames !== []) {
                        $binding->orWhereIn('user_name', $visibleOperatorNames);
                    }
                });
            })->first();
        abort_unless($store, 403, '无权查看该店铺订单。');

        $revenueSql = $this->shopifyRevenueSql('original_currency_code', 'original_total_price');
        $ordersQuery = AssistantShopifyStoreOrder::query()
            ->where('assistant_domain_store_id', (int) $validated['store_id'])
            ->where('is_test', false)
            ->whereIn(DB::raw('UPPER(financial_status)'), AssistantShopifyStoreOrder::successfulFinancialStatuses())
            ->whereNotNull('shopify_created_at')
            ->where('shopify_created_at', '>=', $utcFrom)
            ->where('shopify_created_at', '<', $utcToExclusive);
        $amount = (float) ((clone $ordersQuery)->selectRaw("COALESCE(SUM({$revenueSql}), 0) as amount")->value('amount') ?? 0);
        $orders = $ordersQuery
            ->select('*')->selectRaw($revenueSql.' as report_total')
            ->orderByDesc('shopify_created_at')->orderByDesc('id')->paginate(20);

        return response()->json([
            'date' => $validated['date'],
            'store' => (string) ($store->shop_name ?: $store->domain),
            'count' => $orders->total(),
            'amount' => round($amount, 2),
            'pagination' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
                'from' => $orders->firstItem(),
                'to' => $orders->lastItem(),
            ],
            'orders' => $orders->getCollection()->map(function (AssistantShopifyStoreOrder $order) use ($timezone): array {
                $createdAt = $this->formatUtcOrderTime($order->getRawOriginal('shopify_created_at'), $timezone);
                $processedAt = $this->formatUtcOrderTime(
                    $order->getRawOriginal('shopify_processed_at') ?: $order->getRawOriginal('shopify_created_at'),
                    $timezone,
                );

                return [
                    'id' => (int) $order->getKey(),
                    'order_number' => (string) ($order->shopify_order_number ?: $order->shopify_order_id),
                    'status' => (string) ($order->financial_status ?: '—'),
                    'currency' => 'USD',
                    'total_amount' => (float) $order->report_total,
                    'customer_name' => '—',
                    'customer_email' => '—',
                    'payment_method' => 'Shopify',
                    'order_created_at' => $createdAt,
                    'paid_at' => $processedAt,
                    'items' => [],
                ];
            })->values()->all(),
        ]);
    }

    private function formatUtcOrderTime(mixed $value, string $timezone): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, 'UTC')->setTimezone($timezone)->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    private function formatChinaOrderTime(FashionLogisticsOrder $order, string $timezone): ?string
    {
        $value = $order->getRawOriginal('order_created_at');
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, 'Asia/Shanghai')->setTimezone($timezone)->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    private function reportOperators(AdminUser $user, $managedDepartmentIds)
    {
        if ($user->isAdministrator()) {
            return $this->projectStoreOperators();
        }

        if ($managedDepartmentIds->isEmpty()) {
            return collect([$user]);
        }

        return AdminUser::query()
            ->where('status', AdminUser::STATUS_ACTIVE)
            ->whereHas('departments', fn ($query) => $query->whereIn(
                config('admin.database.roles_table').'.id',
                $managedDepartmentIds->all()
            ))
            ->orderBy('name')->orderBy('username')
            ->get(['id', 'name', 'username'])
            ->unique('id')->values();
    }

    private function fashionOperators()
    {
        $departmentIds = $this->fashionDepartmentIds();
        if ($departmentIds->isEmpty()) {
            return collect();
        }

        return AdminUser::query()
            ->where('status', AdminUser::STATUS_ACTIVE)
            ->whereHas('departments', fn ($query) => $query->whereIn('id', $departmentIds->all()))
            ->get(['id', 'name', 'username']);
    }

    private function projectStoreOperators()
    {
        $stores = DB::connection(config('admin.database.connection') ?: config('database.default'))
            ->table('assistant_domain_stores')
            ->whereIn(DB::raw('TRIM(project)'), OperationProjectCatalog::storeProjectNames($this->project))
            ->get(['owner_user_id', 'user_name']);
        $ids = $stores->pluck('owner_user_id')->filter()->map(fn ($id): int => (int) $id)->unique()->values();
        $names = $stores->pluck('user_name')->filter()->map(fn ($name): string => trim((string) $name))->unique()->values();

        return AdminUser::query()->where(function ($query) use ($ids, $names): void {
            if ($ids->isNotEmpty()) {
                $query->whereIn('id', $ids);
            }
            if ($names->isNotEmpty()) {
                $query->{$ids->isNotEmpty() ? 'orWhereIn' : 'whereIn'}('name', $names)
                    ->orWhereIn('username', $names);
            }
            if ($ids->isEmpty() && $names->isEmpty()) {
                $query->whereRaw('1 = 0');
            }
        })->orderBy('name')->orderBy('username')->get(['id', 'name', 'username']);
    }

    /** @return array<string,string> */
    private function siteBuilderOptions(): array
    {
        return DB::connection(config('admin.database.connection') ?: config('database.default'))
            ->table('assistant_domain_stores')
            ->whereIn(DB::raw('TRIM(project)'), OperationProjectCatalog::storeProjectNames($this->project))
            ->whereNotNull('site_builder')
            ->pluck('site_builder')
            ->map(function ($platform): string {
                $platform = mb_strtolower(trim((string) $platform));

                return $platform === 'xshopy' ? 'xshoppy' : $platform;
            })
            ->filter()
            ->unique()
            ->sort()
            ->mapWithKeys(fn (string $platform): array => [$platform => $this->siteBuilderLabel($platform)])
            ->all();
    }

    private function siteBuilderLabel(string $platform): string
    {
        return match ($platform) {
            'xshoppy' => 'XShoppy',
            'shopline' => 'Shopline',
            'shopify' => 'Shopify',
            'shopyy' => 'ShopYY',
            'nbc' => 'NBC',
            default => strtoupper($platform),
        };
    }

    private function applyPerformancePlatformFilter($query, string $platform): void
    {
        $platform = mb_strtolower(trim($platform));
        $platform = $platform === 'xshopy' ? 'xshoppy' : $platform;
        if ($platform === 'xshoppy') {
            $query->whereRaw("LOWER(TRIM(stores.site_builder)) IN ('xshoppy','xshopy')");
        } else {
            $query->whereRaw('LOWER(TRIM(stores.site_builder)) = ?', [$platform]);
        }
        $query->whereRaw('LOWER(TRIM(orders.source_platform)) = ?', [$platform]);
        if (in_array($platform, ['nbc', 'shopyy'], true)) {
            $query->where('orders.payment_status', '230');
        }
    }

    private function fashionDepartmentIds()
    {
        return OperationProjectCatalog::departmentIds($this->project['code']);
    }

    private function setProject(Request $request, array &$context): void
    {
        $code = (string) ($request->route('operation_project') ?: 'fuzhuang');
        $this->project = OperationProjectCatalog::get($code);
        abort_unless($context['portal']->code === $code, 404);
        $context['operationProject'] = $this->project;
    }

    /** @return array<string,string> */
    private function timezoneOptions(): array
    {
        $options = [];
        for ($offset = -12; $offset <= 14; $offset++) {
            $value = sprintf('%s%02d:00', $offset < 0 ? '-' : '+', abs($offset));
            $label = 'UTC'.($offset >= 0 ? '+' : '').$offset;
            if ($value === '-08:00') {
                $label .= '（默认）';
            }
            if ($value === '+08:00') {
                $label .= '（北京时间）';
            }
            $options[$value] = $label;
        }

        return $options;
    }
}
