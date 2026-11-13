<?php

namespace App\Portals\Fashion\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\Department;
use App\Models\FashionAdvertisingReport;
use App\Models\FashionLogisticsOrder;
use App\Portal\Http\Controllers\PortalEntryController;
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
    private const PORTAL_CODE = 'fuzhuang';

    public function __construct(
        private readonly PortalEntryController $portalEntries,
        private readonly DepartmentAuthorizationService $departmentAuthorization,
    ) {}

    public function index(Request $request): Response|View
    {
        $context = $this->portalEntries->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }
        abort_unless($context['portal']->code === self::PORTAL_CODE, 404);

        $timezoneOptions = $this->timezoneOptions();
        $validated = $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'timezone' => ['nullable', Rule::in(array_keys($timezoneOptions))],
            'site_builder' => ['nullable', Rule::in(['xshoppy', 'shopline'])],
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

        $base = DB::connection($connection)->table($orderTable.' as orders')
            ->join($storeTable.' as stores', 'stores.id', '=', 'orders.assistant_domain_store_id')
            ->leftJoin($userTable.' as owners', 'owners.id', '=', 'stores.owner_user_id')
            ->whereNotNull('orders.paid_at_utc')
            ->whereBetween(DB::raw($orderTimestampSql), [$storageFrom, $storageTo])
            ->where(function ($platform): void {
                $platform->where(function ($xshoppy): void {
                    $xshoppy->whereRaw("LOWER(TRIM(stores.site_builder)) IN ('xshoppy','xshopy')")
                        ->where('orders.source_platform', 'xshoppy');
                })->orWhere(function ($shopline): void {
                    $shopline->whereRaw("LOWER(TRIM(stores.site_builder)) = 'shopline'")
                        ->where('orders.source_platform', 'shopline');
                });
            });

        if (($validated['site_builder'] ?? '') === 'xshoppy') {
            $base->whereRaw("LOWER(TRIM(stores.site_builder)) IN ('xshoppy','xshopy')")
                ->where('orders.source_platform', 'xshoppy');
        } elseif (($validated['site_builder'] ?? '') === 'shopline') {
            $base->whereRaw("LOWER(TRIM(stores.site_builder)) = 'shopline'")
                ->where('orders.source_platform', 'shopline');
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
                'stores.owner_user_id',
                'owners.name as owner_name',
                'owners.username as owner_username',
            ])
            ->selectRaw('COUNT(*) as order_count, COALESCE(SUM(orders.total_amount), 0) as revenue')
            ->groupByRaw($reportDateSql.', stores.id, stores.shop_name, stores.domain, stores.site_builder, stores.owner_user_id, owners.name, owners.username')
            ->orderByDesc('report_date')
            ->orderBy('owner_name')
            ->orderBy('stores.shop_name')
            ->paginate((int) ($validated['per_page'] ?? 20))
            ->withQueryString();

        $operatorDailyRevenue = (clone $base)
            ->selectRaw($reportDateSql.' as report_date, stores.owner_user_id, COALESCE(SUM(orders.total_amount),0) revenue')
            ->whereNotNull('stores.owner_user_id')
            ->groupByRaw($reportDateSql.', stores.owner_user_id')
            ->get()->keyBy(fn ($item) => (int) $item->owner_user_id.'|'.$item->report_date);
        $scopeOperatorIds = $operatorDailyRevenue->pluck('owner_user_id')->map(fn ($id) => (int) $id)->unique()->values();
        $operatorProfiles = AdminUser::query()->with('departments')->whereIn('id', $scopeOperatorIds)->get()->keyBy('id');
        $approvedReports = FashionAdvertisingReport::query()
            ->where('status', FashionAdvertisingReport::STATUS_APPROVED)
            ->whereBetween('report_date', [$dateFrom, $dateTo])
            ->whereIn('submitter_id', $scopeOperatorIds)
            ->with('adSpendEntries')->get();
        $reportsByOperatorDate = $approvedReports->groupBy(fn (FashionAdvertisingReport $report) => (int) $report->submitter_id.'|'.$report->report_date->format('Y-m-d'));
        $rows->setCollection($rows->getCollection()->map(function ($row) use ($operatorProfiles, $operatorDailyRevenue, $reportsByOperatorDate): object {
            $operatorId = (int) $row->owner_user_id;
            $profile = $operatorProfiles->get($operatorId);
            $department = $profile?->departments?->first(fn ($item) => (bool) ($item->pivot->is_primary ?? false)) ?: $profile?->departments?->first();
            $dailyRevenue = (float) ($operatorDailyRevenue->get($operatorId.'|'.$row->report_date)->revenue ?? 0);
            $dailyReports = $reportsByOperatorDate->get($operatorId.'|'.$row->report_date, collect());
            $media = $dailyReports->flatMap->adSpendEntries->groupBy(fn ($entry) => trim((string) $entry->media) ?: '未设置媒体')
                ->map(function ($entries, string $name): array {
                    $spend = round((float) $entries->sum('actual_spend_usd'), 2);

                    return ['name' => $name, 'spend' => $spend];
                })->sortKeys()->values();
            $adSpend = round((float) $media->sum('spend'), 2);
            $row->department_name = (string) ($department?->name ?: '未分配部门');
            $row->hired_at = $profile?->hired_at?->format('Y-m-d');
            $row->hire_days = $profile?->hired_at ? max(1, (int) $profile->hired_at->diffInDays(CarbonImmutable::parse($row->report_date)) + 1) : null;
            $row->media_metrics = $media;
            $row->actual_ad_spend = $adSpend;
            $row->total_roi = $adSpend > 0 ? $dailyRevenue / $adSpend : null;

            return $row;
        }));

        $orderCount = (int) ($summary->order_count ?? 0);
        $revenue = (float) ($summary->revenue ?? 0);
        $summaryAdSpend = round((float) $approvedReports->flatMap->adSpendEntries->sum('actual_spend_usd'), 2);

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
                'ad_spend' => $summaryAdSpend,
                'roi' => $summaryAdSpend > 0 ? $revenue / $summaryAdSpend : null,
            ],
        ]);
    }

    public function orders(Request $request): Response|JsonResponse
    {
        $context = $this->portalEntries->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }
        abort_unless($context['portal']->code === self::PORTAL_CODE, 404);
        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'store_id' => ['required', 'integer'],
            'page' => ['nullable', 'integer', 'min:1'],
            'timezone' => ['nullable', Rule::in(array_keys($this->timezoneOptions()))],
        ]);
        $timezone = (string) ($validated['timezone'] ?? '-08:00');
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
            ->whereRaw("LOWER(TRIM(site_builder)) IN ('xshoppy','xshopy','shopline')")
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
        $sourcePlatform = mb_strtolower(trim((string) $store->site_builder)) === 'shopline' ? 'shopline' : 'xshoppy';

        // Both platforms use China-normalized payment time in this legacy column.
        $orderTimestampSql = 'paid_at_utc';
        $ordersQuery = FashionLogisticsOrder::query()
            ->where('assistant_domain_store_id', (int) $validated['store_id'])
            ->where('source_platform', $sourcePlatform)
            ->whereNotNull('paid_at_utc')
            ->whereBetween(DB::raw($orderTimestampSql), [$storageFrom, $storageTo]);
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
            $storeOperatorIds = DB::connection(config('admin.database.connection') ?: config('database.default'))
                ->table('assistant_domain_stores')
                ->whereNotNull('owner_user_id')
                ->whereRaw("LOWER(TRIM(site_builder)) IN ('xshoppy','xshopy','shopline')")
                ->distinct()->pluck('owner_user_id')->map(fn ($id): int => (int) $id);
            $operatorIds = $storeOperatorIds
                ->merge($this->fashionOperators()->pluck('id')->map(fn ($id): int => (int) $id))
                ->unique()->values();

            return AdminUser::query()->whereIn('id', $operatorIds)
                ->orderBy('name')->orderBy('username')->get(['id', 'name', 'username']);
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

    private function fashionDepartmentIds()
    {
        $root = Department::business()->where('name', '服装运营部门')->first(['id']);
        if (! $root) {
            return collect();
        }

        $departments = Department::business()->get(['id', 'parent_id']);
        $departmentIds = collect([(int) $root->id]);
        do {
            $children = $departments->whereIn('parent_id', $departmentIds)
                ->pluck('id')->map(fn ($id): int => (int) $id)
                ->diff($departmentIds)->values();
            $before = $departmentIds->count();
            $departmentIds = $departmentIds->merge($children)->unique()->values();
        } while ($departmentIds->count() > $before);

        return $departmentIds;
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
