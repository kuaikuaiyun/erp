<?php

namespace App\Portals\Operation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\AssistantAdvertisingMedia;
use App\Models\AssistantAdvertisingAgency;
use App\Models\AssistantDomainStore;
use App\Models\AppLovinAccountStoreBinding;
use App\Models\AppLovinSpendScreenshot;
use App\Models\Department;
use App\Models\OperationReport;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\Logistics\StreamedXlsxWriter;
use App\Portals\Assistant\AssistantStoreOwnerService;
use App\Portals\Operation\OperationReportMetricsService;
use App\Portals\Operation\AdvertisingReportSpreadsheetService;
use App\Portals\ProductRequests\ProductRequestAccessService;
use App\Services\DepartmentAuthorizationService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class OperationReportController extends Controller
{
    public function __construct(
        private readonly PortalEntryController $portalEntries,
        private readonly ProductRequestAccessService $access,
        private readonly OperationReportMetricsService $metrics,
        private readonly StreamedXlsxWriter $xlsxWriter,
        private readonly AssistantStoreOwnerService $assistantStoreOwners,
        private readonly AdvertisingReportSpreadsheetService $advertisingSpreadsheets,
        private readonly DepartmentAuthorizationService $departmentAuthorization,
    ) {
    }

    public function index(Request $request): Response|View
    {
        $context = $this->context($request);
        if ($context instanceof Response) return $context;
        $user = $context['user'];
        $isBoss = $this->isBoss($user);
        $validated = $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'timezone' => ['nullable', Rule::in(array_keys($this->timezoneOptions()))],
            'operation_department_id' => ['nullable', 'integer'],
            'operator_user_id' => ['nullable', 'string', 'max:20'],
        ]);
        $timezone = (string) ($validated['timezone'] ?? OperationReportMetricsService::REPORT_TIMEZONE);
        $reportNow = Carbon::now($timezone);
        $dateFrom = (string) ($validated['date_from'] ?? $reportNow->copy()->startOfMonth()->format('Y-m-d'));
        $dateTo = (string) ($validated['date_to'] ?? $reportNow->copy()->endOfMonth()->format('Y-m-d'));
        abort_if($dateFrom > $dateTo, 422, '开始日期不能晚于结束日期。');
        $operationDepartmentIds = $this->assistantStoreOwners->operationDepartments()->pluck('id');
        $managedOperationDepartmentIds = $this->departmentAuthorization->managedDepartmentIds($user)
            ->intersect($operationDepartmentIds)->values();
        $isReportDepartmentLeader = ! $isBoss && $managedOperationDepartmentIds->isNotEmpty();
        $visibleOperationDepartmentIds = $isBoss ? $operationDepartmentIds : $managedOperationDepartmentIds;
        $operationDepartments = ($isBoss || $isReportDepartmentLeader)
            ? Department::business()->whereIn('id', $visibleOperationDepartmentIds->all())
                ->orderBy('sort')->orderBy('name')->get(['id', 'name', 'sort'])
            : collect();
        $viewerOperationDepartment = $isBoss
            ? null
            : $this->assistantStoreOwners->operationDepartmentForUser($user);
        $selectedDepartmentId = ($isBoss || $isReportDepartmentLeader) && isset($validated['operation_department_id'])
            ? (int) $validated['operation_department_id']
            : null;
        if ($selectedDepartmentId !== null) {
            abort_unless($operationDepartments->contains(
                fn (Department $department): bool => (int) $department->getKey() === $selectedDepartmentId
            ), 403);
        }
        $operatorOptions = $isBoss
            ? $this->bossReportOperatorOptions($operationDepartments, $selectedDepartmentId)
            : $this->reportOperatorOptions($user, $selectedDepartmentId);
        $canSelectOperator = $isBoss || $operatorOptions->count() > 1;
        $selectAllOperators = ($validated['operator_user_id'] ?? null) === 'all';
        $defaultOperatorId = $operatorOptions->contains('id', $user->getKey())
            ? (int) $user->getKey()
            : (int) ($operatorOptions->first()?->getKey() ?? $user->getKey());
        $operatorId = $canSelectOperator && ! $selectAllOperators
            ? (int) ($validated['operator_user_id'] ?? $defaultOperatorId)
            : $defaultOperatorId;
        if (! $selectAllOperators) {
            $this->assertReportOperatorAccess($user, $operatorId, $operatorOptions);
        }
        $operator = $selectAllOperators ? null : AdminUser::query()->findOrFail($operatorId);
        $operatorIds = $selectAllOperators
            ? $operatorOptions->pluck('id')->map(fn ($id) => (int) $id)->values()
            : collect([$operatorId]);
        $calculatedByOperator = $this->metrics->buildRangeForOperators($dateFrom, $dateTo, $operatorIds->all(), $timezone);
        $calculated = [
            'rows' => $operatorIds->flatMap(function (int $id) use ($calculatedByOperator) {
                return collect($calculatedByOperator[$id]['rows'] ?? [])->map(function (array $row) use ($id): array {
                    $row['operator_user_id'] = $id;
                    return $row;
                });
            })->values()->all(),
            'warnings' => $operatorIds->flatMap(fn (int $id) => $calculatedByOperator[$id]['warnings'] ?? [])->values()->all(),
        ];
        $submittedReports = OperationReport::query()
            ->whereIn('operator_user_id', $operatorIds->all())
            ->whereBetween('report_date', [$dateFrom, $dateTo])
            ->where('status', OperationReport::STATUS_APPROVED)
            ->orderByDesc('report_date')->orderByDesc('id')->get();
        $submittedReportsByDate = $submittedReports->keyBy(
            fn (OperationReport $report) => (int) $report->operator_user_id.'|'.$report->report_date->format('Y-m-d')
        );
        $submittedAdSpendTotal = (float) $submittedReports->sum('actual_ad_spend');
        $submittedFirstRevenueTotal = (float) $submittedReports->sum(
            fn (OperationReport $report) => collect($report->metric_rows ?? [])->sum('first_total_revenue')
        );
        $submittedMediaRoiByDate = $submittedReports->mapWithKeys(function (OperationReport $report): array {
            $rows = collect($this->metrics->mediaRoiSummary(
                $report->metric_rows ?? [],
                $report->advertising_rows ?? []
            ))->keyBy(fn (array $row) => mb_strtolower(trim((string) $row['media_source'])))->all();

            return [(int) $report->operator_user_id.'|'.$report->report_date->format('Y-m-d') => $rows];
        })->all();
        $metricRows = $this->withFirstOrderProfitability($calculated['rows'], $submittedMediaRoiByDate);
        usort($metricRows, fn (array $left, array $right): int => [
            $left['date'],
            (int) $left['operator_user_id'],
            mb_strtolower(trim((string) $left['shop_domain'])),
            mb_strtolower(trim((string) $left['media_source'])),
        ] <=> [
            $right['date'],
            (int) $right['operator_user_id'],
            mb_strtolower(trim((string) $right['shop_domain'])),
            mb_strtolower(trim((string) $right['media_source'])),
        ]);
        $dailySummaries = collect($metricRows)->groupBy(
            fn (array $row) => (int) $row['operator_user_id'].'|'.$row['date']
        )->map(function ($dateRows, string $identityKey) use ($submittedReportsByDate, $submittedMediaRoiByDate): array {
            $date = (string) $dateRows->first()['date'];
            $firstRevenue = (float) $dateRows->sum('first_total_revenue');
            $firstCost = (float) $dateRows->sum('first_total_cost');
            $actualAdSpend = (float) ($submittedReportsByDate->get($identityKey)?->actual_ad_spend ?? 0);
            $firstProfit = $firstRevenue - $actualAdSpend - $firstCost;
            $mediaNames = $dateRows->pluck('media_source')->map(fn ($media) => trim((string) $media) ?: '其他')->unique()->values();
            $accountSpendsByMedia = collect($this->advertisingAccountSpendSummary(
                $submittedReportsByDate->get($identityKey)?->advertising_rows ?? []
            ))->groupBy(fn (array $account) => mb_strtolower($account['media_source']));

            return [
                'first_total_orders' => (int) $dateRows->sum('first_total_orders'),
                'first_subscription_rate' => (int) $dateRows->sum('first_total_orders') > 0
                    ? (float) $dateRows->sum('first_subscription_orders') / (int) $dateRows->sum('first_total_orders')
                    : null,
                'first_total_revenue' => $firstRevenue,
                'first_total_cost' => $firstCost,
                'first_loss_rate' => $firstCost > 0 ? $firstProfit / $firstCost : null,
                'media_names' => $mediaNames->all(),
                'media_order_counts' => $mediaNames->map(function (string $media) use ($dateRows): int {
                    return (int) $dateRows
                        ->filter(fn (array $row) => mb_strtolower(trim((string) ($row['media_source'] ?? '其他'))) === mb_strtolower($media))
                        ->sum('first_total_orders');
                })->all(),
                'media_spends' => $mediaNames->map(function (string $media) use ($identityKey, $submittedMediaRoiByDate): float {
                    $metric = $submittedMediaRoiByDate[$identityKey][mb_strtolower($media)] ?? null;
                    return (float) ($metric['actual_ad_spend'] ?? 0);
                })->all(),
                'media_accounts' => $mediaNames->map(
                    fn (string $media): array => $accountSpendsByMedia->get(mb_strtolower($media), collect())->values()->all()
                )->all(),
                'first_roi' => $actualAdSpend > 0 ? $firstRevenue / $actualAdSpend : null,
                'actual_ad_spend' => $actualAdSpend,
                'renewal_orders' => (int) $dateRows->sum('renewal_orders'),
                'renewal_revenue' => (float) $dateRows->sum('renewal_revenue'),
            ];
        })->all();
        $metricTotals = $this->sumRows($metricRows);
        $metricTotals['first_total_cost'] = (float) $metricTotals['first_purchase_cost']
            + (float) $metricTotals['first_shipping_cost']
            + (float) $metricTotals['first_return_fee'];
        $metricTotals['first_profit'] = (float) $metricTotals['first_total_revenue']
            - $submittedAdSpendTotal
            - $metricTotals['first_total_cost'];
        $metricTotals['first_loss_rate'] = $metricTotals['first_total_cost'] > 0
            ? $metricTotals['first_profit'] / $metricTotals['first_total_cost']
            : null;

        return view('portals.operation.reports.monthly', [
            'portal' => $context['portal']->loadMissing('menus'),
            'user' => $user,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'timezone' => $timezone,
            'timezoneOptions' => $this->timezoneOptions(),
            'selectedOperator' => $operator,
            'selectAllOperators' => $selectAllOperators,
            'operatorOptions' => $operatorOptions,
            'operationDepartments' => $operationDepartments,
            'selectedDepartmentId' => $selectedDepartmentId,
            'viewerOperationDepartment' => $viewerOperationDepartment,
            'isReportDepartmentLeader' => $isReportDepartmentLeader,
            'isBoss' => $isBoss,
            'canSelectOperator' => $canSelectOperator,
            'metricRows' => $metricRows,
            'metricTotals' => $metricTotals,
            'metricRowspans' => $this->identityRowspans($metricRows),
            'metricPersonRowspans' => $this->personRowspans($metricRows),
            'metricMediaRowspans' => $this->mediaRowspans($metricRows),
            'metricDateRowspans' => $this->dateRowspans($metricRows),
            'metricDateBands' => $this->dateBands($metricRows),
            'dailySummaries' => $dailySummaries,
            'warnings' => $calculated['warnings'],
            'submittedReports' => $submittedReports,
            'submittedReportsByDate' => $submittedReportsByDate,
            'submittedMediaRoiByDate' => $submittedMediaRoiByDate,
            'submittedAdSpendTotal' => $submittedAdSpendTotal,
            'submittedFirstRoi' => $submittedAdSpendTotal > 0
                ? $submittedFirstRevenueTotal / $submittedAdSpendTotal
                : null,
        ]);
    }

    public function weekly(Request $request): Response|View
    {
        $context = $this->context($request);
        if ($context instanceof Response) return $context;

        $user = $context['user'];
        $isBoss = $this->isBoss($user);
        $validated = $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'timezone' => ['nullable', Rule::in(array_keys($this->timezoneOptions()))],
            'operation_department_id' => ['nullable', 'integer'],
            'operator_user_id' => ['nullable', 'string', 'max:20'],
        ]);

        $timezone = (string) ($validated['timezone'] ?? OperationReportMetricsService::REPORT_TIMEZONE);
        $now = Carbon::now($timezone);
        $requestedDateFrom = (string) ($validated['date_from'] ?? $now->copy()->startOfMonth()->format('Y-m-d'));
        $requestedDateTo = (string) ($validated['date_to'] ?? $now->copy()->endOfMonth()->format('Y-m-d'));
        $rangeStart = Carbon::createFromFormat('Y-m-d', $requestedDateFrom, $timezone)
            ->startOfWeek(Carbon::MONDAY)->startOfDay();
        $rangeEnd = Carbon::createFromFormat('Y-m-d', $requestedDateTo, $timezone)
            ->endOfWeek(Carbon::SUNDAY)->startOfDay();
        $dateFrom = $rangeStart->format('Y-m-d');
        $dateTo = $rangeEnd->format('Y-m-d');

        $operationDepartmentIds = $this->assistantStoreOwners->operationDepartments()->pluck('id');
        $managedOperationDepartmentIds = $this->departmentAuthorization->managedDepartmentIds($user)
            ->intersect($operationDepartmentIds)->values();
        $isReportDepartmentLeader = ! $isBoss && $managedOperationDepartmentIds->isNotEmpty();
        $visibleOperationDepartmentIds = $isBoss ? $operationDepartmentIds : $managedOperationDepartmentIds;
        $operationDepartments = ($isBoss || $isReportDepartmentLeader)
            ? Department::business()->whereIn('id', $visibleOperationDepartmentIds->all())
                ->orderBy('sort')->orderBy('name')->get(['id', 'name', 'sort'])
            : collect();
        $viewerOperationDepartment = $isBoss
            ? null
            : $this->assistantStoreOwners->operationDepartmentForUser($user);
        $selectedDepartmentId = ($isBoss || $isReportDepartmentLeader) && isset($validated['operation_department_id'])
            ? (int) $validated['operation_department_id']
            : null;

        if ($selectedDepartmentId !== null) {
            abort_unless($operationDepartments->contains(
                fn (Department $department): bool => (int) $department->getKey() === $selectedDepartmentId
            ), 403);
        }

        $operatorOptions = $isBoss
            ? $this->bossReportOperatorOptions($operationDepartments, $selectedDepartmentId)
            : $this->reportOperatorOptions($user, $selectedDepartmentId);
        $canSelectOperator = $isBoss || $operatorOptions->count() > 1;
        $selectAllOperators = ($validated['operator_user_id'] ?? 'all') === 'all';
        $defaultOperatorId = $operatorOptions->contains('id', $user->getKey())
            ? (int) $user->getKey()
            : (int) ($operatorOptions->first()?->getKey() ?? $user->getKey());
        $operatorId = $canSelectOperator && ! $selectAllOperators
            ? (int) ($validated['operator_user_id'] ?? $defaultOperatorId)
            : $defaultOperatorId;

        if (! $selectAllOperators) {
            $this->assertReportOperatorAccess($user, $operatorId, $operatorOptions);
        }

        $operatorIds = $selectAllOperators
            ? $operatorOptions->pluck('id')->map(fn ($id) => (int) $id)->values()
            : collect([$operatorId]);
        $selectedOperator = $selectAllOperators ? null : $operatorOptions->firstWhere('id', $operatorId);
        $periods = collect();
        $cursor = $rangeStart->copy();
        while ($cursor->lte($rangeEnd)) {
            $periodStart = $cursor->copy();
            $periodEnd = $cursor->copy()->endOfWeek(Carbon::SUNDAY);
            if ($periodEnd->gt($rangeEnd)) $periodEnd = $rangeEnd->copy();
            $periods->push([$periodStart->format('Y-m-d'), $periodEnd->format('Y-m-d')]);
            $cursor = $periodEnd->copy()->addDay();
        }

        $weeklyRows = $periods->flatMap(function (array $period) use ($operatorIds, $operatorOptions, $timezone) {
            [$periodFrom, $periodTo] = $period;
            $calculatedByOperator = $this->metrics->buildRangeForOperators($periodFrom, $periodTo, $operatorIds->all(), $timezone);
            $reportsByOperator = OperationReport::query()
                ->whereIn('operator_user_id', $operatorIds->all())
                ->whereBetween('report_date', [$periodFrom, $periodTo])
                ->where('status', OperationReport::STATUS_APPROVED)
                ->get()->groupBy(fn (OperationReport $report) => (int) $report->operator_user_id);

            return $operatorIds->map(function (int $id) use ($operatorOptions, $calculatedByOperator, $reportsByOperator, $periodFrom, $periodTo): array {
                $operator = $operatorOptions->firstWhere('id', $id) ?: AdminUser::query()->find($id);
                $rows = $calculatedByOperator[$id]['rows'] ?? [];
                $totals = $this->sumRows($rows);
                $reports = $reportsByOperator->get($id, collect());
                $adSpend = (float) $reports->sum('actual_ad_spend');
                $firstCost = (float) $totals['first_purchase_cost'] + (float) $totals['first_shipping_cost'] + (float) $totals['first_return_fee'];
                $firstRevenue = (float) $totals['first_total_revenue'];
                $firstOrders = (int) $totals['first_total_orders'];
                $firstProfit = $firstRevenue - $adSpend - $firstCost;
                $department = $operator ? $this->assistantStoreOwners->operationDepartmentForUser($operator) : null;
                $advertisingRows = $reports->flatMap(fn (OperationReport $report) => $report->advertising_rows ?? [])->values()->all();
                $mediaOrderCounts = collect($rows)->groupBy(fn (array $row) => mb_strtolower(trim((string) ($row['media_source'] ?? '其他'))))
                    ->map(fn ($mediaRows) => (int) $mediaRows->sum('first_total_orders'));
                $accountSpendsByMedia = collect($this->advertisingAccountSpendSummary($advertisingRows))
                    ->groupBy(fn (array $account) => mb_strtolower($account['media_source']));
                $mediaSummary = collect($this->metrics->mediaRoiSummary($rows, $advertisingRows))
                    ->map(function (array $media) use ($mediaOrderCounts, $accountSpendsByMedia): array {
                        $media['first_orders'] = (int) ($mediaOrderCounts[mb_strtolower(trim($media['media_source']))] ?? 0);
                        $media['accounts'] = $accountSpendsByMedia
                            ->get(mb_strtolower(trim($media['media_source'])), collect())->values()->all();
                        return $media;
                    })->values()->all();

                return [
                    'period_from' => $periodFrom, 'period_to' => $periodTo,
                    'operator_user_id' => $id, 'operator_name' => $operator?->name ?: $operator?->username ?: '—',
                    'department_name' => $department?->name ?: '—',
                    'shop_domains' => collect($rows)->pluck('shop_domain')->filter()->unique()->sort()->values()->all(),
                    'first_orders' => $firstOrders, 'subscription_orders' => (int) $totals['first_subscription_orders'],
                    'subscription_revenue' => (float) $totals['first_subscription_revenue'],
                    'normal_orders' => (int) $totals['first_normal_orders'], 'normal_revenue' => (float) $totals['first_normal_revenue'],
                    'subscription_rate' => $firstOrders > 0 ? (int) $totals['first_subscription_orders'] / $firstOrders : null,
                    'first_revenue' => $firstRevenue, 'average_order_value' => $firstOrders > 0 ? $firstRevenue / $firstOrders : null,
                    'purchase_cost' => (float) $totals['first_purchase_cost'], 'shipping_cost' => (float) $totals['first_shipping_cost'],
                    'return_fee' => (float) $totals['first_return_fee'], 'first_cost' => $firstCost, 'ad_spend' => $adSpend,
                    'first_profit' => $firstProfit, 'first_loss_rate' => $firstCost > 0 ? $firstProfit / $firstCost : null,
                    'first_roi' => $adSpend > 0 ? $firstRevenue / $adSpend : null, 'media_summary' => $mediaSummary,
                    'renewal_orders' => (int) $totals['renewal_orders'], 'renewal_revenue' => (float) $totals['renewal_revenue'],
                    'total_revenue' => $firstRevenue + (float) $totals['renewal_revenue'],
                ];
            });
        })->values();

        $summary = [
            'first_orders' => (int) $weeklyRows->sum('first_orders'),
            'subscription_orders' => (int) $weeklyRows->sum('subscription_orders'),
            'subscription_revenue' => (float) $weeklyRows->sum('subscription_revenue'),
            'normal_orders' => (int) $weeklyRows->sum('normal_orders'),
            'normal_revenue' => (float) $weeklyRows->sum('normal_revenue'),
            'first_revenue' => (float) $weeklyRows->sum('first_revenue'),
            'purchase_cost' => (float) $weeklyRows->sum('purchase_cost'),
            'shipping_cost' => (float) $weeklyRows->sum('shipping_cost'),
            'return_fee' => (float) $weeklyRows->sum('return_fee'),
            'first_cost' => (float) $weeklyRows->sum('first_cost'),
            'ad_spend' => (float) $weeklyRows->sum('ad_spend'),
            'first_profit' => (float) $weeklyRows->sum('first_profit'),
            'renewal_orders' => (int) $weeklyRows->sum('renewal_orders'),
            'renewal_revenue' => (float) $weeklyRows->sum('renewal_revenue'),
        ];
        $summary['subscription_rate'] = $summary['first_orders'] > 0
            ? $summary['subscription_orders'] / $summary['first_orders']
            : null;
        $summary['first_loss_rate'] = $summary['first_cost'] > 0
            ? $summary['first_profit'] / $summary['first_cost']
            : null;
        $summary['first_roi'] = $summary['ad_spend'] > 0
            ? $summary['first_revenue'] / $summary['ad_spend']
            : null;

        return view('portals.operation.reports.weekly', [
            'portal' => $context['portal']->loadMissing('menus'),
            'user' => $user,
            'isBoss' => $isBoss,
            'operationDepartments' => $operationDepartments,
            'selectedDepartmentId' => $selectedDepartmentId,
            'viewerOperationDepartment' => $viewerOperationDepartment,
            'isReportDepartmentLeader' => $isReportDepartmentLeader,
            'operatorOptions' => $operatorOptions,
            'canSelectOperator' => $canSelectOperator,
            'selectAllOperators' => $selectAllOperators,
            'selectedOperator' => $selectedOperator,
            'inputDateFrom' => $requestedDateFrom,
            'inputDateTo' => $requestedDateTo,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'timezone' => $timezone,
            'timezoneOptions' => $this->timezoneOptions(),
            'weeklyRows' => $weeklyRows,
            'summary' => $summary,
        ]);
    }

    public function orderDetails(Request $request): Response|JsonResponse
    {
        $context = $this->context($request);
        if ($context instanceof Response) return $context;
        $user = $context['user'];
        $isBoss = $this->isBoss($user);
        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'timezone' => ['nullable', Rule::in(array_keys($this->timezoneOptions()))],
            'operator_user_id' => ['nullable', 'integer'],
            'shop_domain' => ['nullable', 'string', 'max:255'],
            'media_source' => ['required', 'string', 'max:100'],
            'type' => ['required', Rule::in(['first_subscription', 'first_normal', 'first_total', 'renewal', 'total'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', Rule::in([10, 20, 50])],
        ]);
        $operatorOptions = $isBoss
            ? $this->bossReportOperatorOptions($this->assistantStoreOwners->operationDepartments(), null)
            : $this->reportOperatorOptions($user);
        $canSelectOperator = $isBoss || $operatorOptions->count() > 1;
        $operatorId = $canSelectOperator
            ? (int) ($validated['operator_user_id'] ?? 0)
            : (int) $user->getKey();
        $this->assertReportOperatorAccess($user, $operatorId, $operatorOptions);

        $shopDomain = trim((string) ($validated['shop_domain'] ?? ''));

        return response()->json($this->metrics->orderDetailsRange(
            $validated['date'],
            $validated['date'],
            $operatorId,
            $validated['type'],
            (int) ($validated['page'] ?? 1),
            (int) ($validated['per_page'] ?? 10),
            $shopDomain !== '' ? $shopDomain : null,
            trim($validated['media_source']),
            (string) ($validated['timezone'] ?? OperationReportMetricsService::REPORT_TIMEZONE),
        ));
    }

    public function advertising(Request $request): Response|View
    {
        $context = $this->context($request);
        if ($context instanceof Response) return $context;
        $user = $context['user'];
        $isBoss = $this->isBoss($user);
        $canReview = $this->canReview($user);
        $validated = $request->validate([
            'report_date' => ['nullable', 'date_format:Y-m-d'],
            'operator_user_id' => ['nullable', 'integer'],
            'edit_report' => ['nullable', 'integer'],
        ]);
        $editingReport = ! empty($validated['edit_report']) ? OperationReport::query()->findOrFail((int) $validated['edit_report']) : null;
        if ($editingReport) {
            if (! in_array($editingReport->status, [OperationReport::STATUS_PENDING, OperationReport::STATUS_REJECTED], true)) {
                return redirect()->route('operation.advertising-reports.index', [
                    'report_date' => $editingReport->report_date->format('Y-m-d'),
                ])->with('warning', '该广告费提报已审核通过，无法继续编辑。请查看已审核记录。');
            }
            abort_unless($isBoss || (int) $editingReport->operator_user_id === (int) $user->getKey(), 403);
        }
        $date = $editingReport?->report_date?->format('Y-m-d')
            ?: ($validated['report_date'] ?? null);
        $operatorOptions = $isBoss ? $this->operatorOptions() : collect([$user]);
        $operatorId = $editingReport ? (int) $editingReport->operator_user_id : ($isBoss
            ? (int) ($validated['operator_user_id'] ?? $operatorOptions->first()?->getKey() ?? $user->getKey())
            : (int) $user->getKey());
        $operator = AdminUser::query()->findOrFail($operatorId);
        if ($isBoss) $this->assertAssignableOperator($operatorId);
        $operationDepartment = $this->assistantStoreOwners->operationDepartmentForUser($operator);
        $advertisingAssistantDepartmentIds = $operationDepartment
            ? $this->assistantStoreOwners->assistantDepartmentIdsForOperationDepartment((int) $operationDepartment->getKey())
            : [];

        // 广告费提报页只负责填写广告数据；业绩在提交时重新计算并保存快照。
        $calculated = ['rows' => [], 'totals' => $this->sumRows([]), 'warnings' => []];
        $report = $date !== null
            ? OperationReport::query()->whereDate('report_date', $date)->where('operator_user_id', $operatorId)->first()
            : null;
        $metricRows = $calculated['rows'];
        $advertisingRows = $request->session()->pull('advertising_import_rows', $editingReport?->advertising_rows ?: []);
        $submittedMetricRows = $report?->metric_rows ?: [];
        $submittedAdvertisingRows = $report?->advertising_rows ?: [];
        $history = OperationReport::query()
            ->when(! $isBoss, fn ($query) => $query->where('operator_user_id', $user->getKey()))
            ->orderByDesc('report_date')
            ->orderByDesc('id')
            ->paginate(20, ['*'], 'history_page')
            ->withQueryString();

        return view('portals.operation.reports.index', [
            'portal' => $context['portal']->loadMissing('menus'), 'user' => $user,
            'reportDate' => $date, 'selectedOperator' => $operator, 'report' => $report,
            'editingReport' => $editingReport,
            'metricRows' => $metricRows, 'metricTotals' => $this->sumRows($metricRows),
            'metricRowspans' => $this->identityRowspans($metricRows),
            'advertisingRows' => $advertisingRows, 'warnings' => $calculated['warnings'],
            'mediaRoiRows' => $this->metrics->mediaRoiSummary($submittedMetricRows, $submittedAdvertisingRows),
            'operatorOptions' => $operatorOptions,
            'mediaSources' => $this->advertisingMedia($advertisingAssistantDepartmentIds),
            'advertisingAgencies' => AssistantAdvertisingAgency::enabledOptions($advertisingAssistantDepartmentIds),
            'advertisingAgencyMedia' => AssistantAdvertisingAgency::enabledMediaNames($advertisingAssistantDepartmentIds),
            'advertisingAgencyNames' => AssistantAdvertisingAgency::enabledNames($advertisingAssistantDepartmentIds),
            'advertisingAgencyGroups' => $this->advertisingAgencyGroups($advertisingAssistantDepartmentIds),
            'operatorStores' => AssistantDomainStore::query()->where('owner_user_id', $operatorId)->whereNotNull('domain')->where('domain', '!=', '')->orderBy('domain')->get(['id','domain','shop_name']),
            'isBoss' => $isBoss, 'canReview' => $canReview,
            'canEditReport' => true, 'history' => $history,
            'canViewMissingGuideSkus' => false,
            'missingGuideSkus' => [],
            'missingOriginalSkuItems' => [],
        ]);
    }

    public function advertisingEditStatus(Request $request, OperationReport $report): Response|JsonResponse
    {
        $context = $this->context($request);
        if ($context instanceof Response) return $context;

        $user = $context['user'];
        abort_unless($this->isBoss($user) || (int) $report->operator_user_id === (int) $user->getKey(), 403);

        return response()->json([
            'editable' => in_array($report->status, [OperationReport::STATUS_PENDING, OperationReport::STATUS_REJECTED], true),
            'status' => $report->status,
            'status_label' => $report->statusLabel(),
        ]);
    }

    public function advertisingImportTemplate(Request $request): Response
    {
        $context = $this->context($request);
        if ($context instanceof Response) return $context;
        abort_unless($this->isBoss($context['user']), 403);
        $operatorAgencyGroups = [];
        foreach ($this->operatorOptions() as $operator) {
            $operationDepartment = $this->assistantStoreOwners->operationDepartmentForUser($operator);
            $assistantDepartmentIds = $operationDepartment
                ? $this->assistantStoreOwners->assistantDepartmentIdsForOperationDepartment((int) $operationDepartment->getKey())
                : [];
            $operatorAgencyGroups[$this->operatorImportLabel($operator)] = $this->advertisingAgencyGroups($assistantDepartmentIds);
        }

        return $this->advertisingSpreadsheets->templateResponse($operatorAgencyGroups);
    }

    public function advertisingImport(Request $request): RedirectResponse|JsonResponse
    {
        $context = $this->context($request);
        if ($context instanceof Response) abort($context->getStatusCode());
        abort_unless($this->isBoss($context['user']), 403);
        $validated = $request->validate([
            'import_file' => ['required', 'file', 'max:102400'],
        ], [
            'import_file.required' => '请选择需要导入的 Excel 文件。',
            'import_file.uploaded' => '文件上传失败，文件可能超过服务器允许的上传大小。当前 PHP 上传限制为 '.ini_get('upload_max_filesize').'，请压缩文件中的图片后重试，或调整服务器上传限制。',
            'import_file.file' => '上传的内容不是有效文件，请重新选择 Excel 文件。',
            'import_file.max' => '导入文件不能超过 100MB，请压缩文件中的图片后重试。',
        ]);
        $operators = $this->operatorOptions();
        $operatorConfigurations = [];
        $storedImagePaths = [];
        try {
            $rows = $this->advertisingSpreadsheets->read($request->file('import_file'));
            $reportGroups = [];
            foreach ($rows as $row) {
                $operatorMatches = $operators->filter(function (AdminUser $operator) use ($row): bool {
                    $needle = $this->normalizeAdvertisingText($row['operator_name']);

                    return in_array($needle, [
                        $this->normalizeAdvertisingText($this->operatorImportLabel($operator)),
                        $this->normalizeAdvertisingText((string) $operator->name),
                        $this->normalizeAdvertisingText((string) $operator->username),
                    ], true);
                })->values();
                if ($operatorMatches->count() !== 1) {
                    $reason = $operatorMatches->isEmpty() ? '不存在或没有已分配店铺' : '存在同名人员，请使用模板参考页中的完整名称';
                    throw new \InvalidArgumentException("第 {$row['row']} 行的运营人员「{$row['operator_name']}」{$reason}。");
                }
                /** @var AdminUser $operator */
                $operator = $operatorMatches->first();
                $operatorId = (int) $operator->getKey();
                if (! isset($operatorConfigurations[$operatorId])) {
                    $operationDepartment = $this->assistantStoreOwners->operationDepartmentForUser($operator);
                    if (! $operationDepartment) throw new \InvalidArgumentException("运营人员「{$row['operator_name']}」尚未归属运营部门。");
                    $assistantDepartmentIds = $this->assistantStoreOwners->assistantDepartmentIdsForOperationDepartment((int) $operationDepartment->getKey());
                    $operatorConfigurations[$operatorId] = [
                        'operator' => $operator,
                        'department' => $operationDepartment,
                        'agency_groups' => $this->advertisingAgencyGroups($assistantDepartmentIds),
                    ];
                }
                $agencyGroups = $operatorConfigurations[$operatorId]['agency_groups'];
                $matched = collect($agencyGroups)->flatMap(function (array $items, string $agencyName) use ($row): array {
                    if ($this->normalizeAdvertisingText($agencyName) !== $this->normalizeAdvertisingText($row['agency_name'])) return [];

                    return $items;
                })->first(
                    fn (array $item): bool => $this->normalizeAdvertisingText($item['media']) === $this->normalizeAdvertisingText($row['media_source'])
                );
                if (! $matched) {
                    throw new \InvalidArgumentException("第 {$row['row']} 行的广告代理与广告社媒组合不存在，或不属于该运营绑定的助理部门。");
                }
                $bookSpend = (float) $row['book_spend'];
                $coefficient = (float) $matched['coefficient'];
                $groupKey = $operatorId.'|'.$row['report_date'];
                $reportGroups[$groupKey] ??= ['operator_id' => $operatorId, 'report_date' => $row['report_date'], 'ads' => []];
                $reportGroups[$groupKey]['ads'][] = [
                    'agency' => $row['agency_name'],
                    'media_source' => (string) $matched['media'],
                    'account_name' => $row['account_name'],
                    'account_number' => $row['account_number'],
                    'book_spend' => $bookSpend,
                    'settlement_coefficient' => $coefficient,
                    'handling_fee' => round($bookSpend * $coefficient, 4),
                    'actual_spend' => round($bookSpend * (1 + $coefficient), 4),
                    '_image_bytes' => $row['image_bytes'],
                    '_image_extension' => $row['image_extension'],
                ];
            }
            foreach ($reportGroups as $group) {
                $existingReport = OperationReport::query()
                    ->whereDate('report_date', $group['report_date'])
                    ->where('operator_user_id', $group['operator_id'])
                    ->first();
                if ($duplicate = $this->duplicateAdvertisingMessage($group['ads'], $existingReport?->advertising_rows ?: [])) {
                    $operator = $operatorConfigurations[$group['operator_id']]['operator'];
                    throw new \InvalidArgumentException($this->operatorImportLabel($operator).' 在 '.$group['report_date'].' 的'.$duplicate);
                }
            }
            foreach ($reportGroups as &$group) foreach ($group['ads'] as &$ad) {
                $path = 'operation-reports/ads/'.Str::uuid().'.'.$ad['_image_extension'];
                if (! Storage::disk('public')->put($path, $ad['_image_bytes'])) throw new \InvalidArgumentException('广告消耗截图保存失败，请稍后重新导入。');
                $storedImagePaths[] = $path;
                $ad['screenshot'] = $path;
                unset($ad['_image_bytes'], $ad['_image_extension']);
            }
            unset($group, $ad);

            (new OperationReport)->getConnection()->transaction(function () use ($reportGroups, $operatorConfigurations, $context): void {
                foreach ($reportGroups as $group) {
                    $operator = $operatorConfigurations[$group['operator_id']]['operator'];
                    $department = $operatorConfigurations[$group['operator_id']]['department'];
                    $report = OperationReport::query()
                        ->whereDate('report_date', $group['report_date'])
                        ->where('operator_user_id', $group['operator_id'])
                        ->lockForUpdate()
                        ->first();
                    if ($duplicate = $this->duplicateAdvertisingMessage($group['ads'], $report?->advertising_rows ?: [])) {
                        throw new \InvalidArgumentException($this->operatorImportLabel($operator).' 在 '.$group['report_date'].' 的'.$duplicate);
                    }
                    $ads = array_values(array_merge($report?->advertising_rows ?: [], $group['ads']));
                    $calculated = $this->metrics->build($group['report_date'], $group['operator_id']);
                    $totals = $calculated['totals'];
                    $adSpend = collect($ads)->sum('actual_spend');
                    $firstProfit = $totals['first_total_revenue'] - $totals['first_purchase_cost'] - $totals['first_shipping_cost'] - $totals['first_return_fee'] - $adSpend;
                    $renewalNet = $totals['renewal_revenue'] - $totals['renewal_cost'];
                    $values = [
                        'report_date' => $group['report_date'], 'operator_user_id' => $group['operator_id'],
                        'operator_name' => (string) ($operator->name ?: $operator->username),
                        'operation_department_id' => (int) $department->getKey(), 'operation_department_name' => (string) $department->name,
                        'metric_rows' => $calculated['rows'], 'advertising_rows' => $ads,
                        'first_purchase_cost' => $totals['first_purchase_cost'], 'first_shipping_cost' => $totals['first_shipping_cost'],
                        'first_return_fee' => $totals['first_return_fee'], 'actual_ad_spend' => $adSpend,
                        'first_profit' => $firstProfit, 'renewal_net_revenue' => $renewalNet, 'overall_profit' => $firstProfit + $renewalNet,
                        'status' => OperationReport::STATUS_PENDING, 'submitted_by' => $context['user']->getKey(),
                        'submitted_at' => now(), 'reviewed_by' => null, 'reviewed_at' => null, 'review_reason' => null,
                    ];
                    if ($report) $report->update($values);
                    else OperationReport::query()->create($values);
                }
            });
        } catch (\Throwable $exception) {
            foreach ($storedImagePaths as $storedImagePath) Storage::disk('public')->delete($storedImagePath);
            if ($exception instanceof \InvalidArgumentException) {
                throw ValidationException::withMessages(['import_file' => $exception->getMessage()]);
            }
            throw $exception;
        }

        $message = '成功导入 '.count($rows).' 条广告数据，已按日期和运营人员新增或补充 '.count($reportGroups).' 份待审核记录并提交到助理系统。';
        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'imported_count' => count($rows),
                'report_count' => count($reportGroups),
                'message' => $message,
                'redirect_url' => route('operation.advertising-reports.index'),
            ]);
        }

        return redirect()->route('operation.advertising-reports.index')->with('success', $message);
    }

    public function show(Request $request, OperationReport $report): Response|View
    {
        $context = $this->context($request);
        if ($context instanceof Response) return $context;
        $user = $context['user'];
        abort_unless(
            $this->isBoss($user) || $this->canReview($user) || (int) $report->operator_user_id === (int) $user->getKey(),
            403,
        );
        $rows = $report->metric_rows ?: [];

        return view('portals.operation.reports.show', [
            'portal' => $context['portal']->loadMissing('menus'), 'user' => $user, 'report' => $report,
            'metricRows' => $rows, 'metricTotals' => $this->sumRows($rows),
            'metricRowspans' => $this->identityRowspans($rows),
            'advertisingRows' => $report->advertising_rows ?: [],
            'mediaRoiRows' => $this->metrics->mediaRoiSummary($rows, $report->advertising_rows ?: []),
            'canReview' => false,
            'canEdit' => in_array($report->status, [OperationReport::STATUS_PENDING, OperationReport::STATUS_APPROVED, OperationReport::STATUS_REJECTED], true)
                && ($this->isBoss($user) || (int) $report->operator_user_id === (int) $user->getKey()),
        ]);
    }

    public function advertisingShow(Request $request, OperationReport $report): Response|View
    {
        $context = $this->context($request);
        if ($context instanceof Response) return $context;
        $user = $context['user'];
        abort_unless($this->isBoss($user) || (int) $report->operator_user_id === (int) $user->getKey(), 403);

        return view('portals.operation.reports.advertising-show', [
            'portal' => $context['portal']->loadMissing('menus'),
            'user' => $user,
            'report' => $report,
            'advertisingRows' => $report->advertising_rows ?: [],
            'canEdit' => in_array($report->status, [OperationReport::STATUS_PENDING, OperationReport::STATUS_APPROVED, OperationReport::STATUS_REJECTED], true),
            'canDelete' => in_array($report->status, [OperationReport::STATUS_PENDING, OperationReport::STATUS_REJECTED], true),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $context = $this->context($request);
        if ($context instanceof Response) abort($context->getStatusCode());
        $user = $context['user'];
        $isBoss = $this->isBoss($user);
        $configOperatorId = $isBoss && $request->filled('operator_user_id') ? $request->integer('operator_user_id') : (int) $user->getKey();
        $configOperator = AdminUser::query()->find($configOperatorId);
        $configOperationDepartment = $configOperator ? $this->assistantStoreOwners->operationDepartmentForUser($configOperator) : null;
        $advertisingAssistantDepartmentIds = $configOperationDepartment
            ? $this->assistantStoreOwners->assistantDepartmentIdsForOperationDepartment((int) $configOperationDepartment->getKey())
            : [];
        $mediaSources = $this->advertisingMedia($advertisingAssistantDepartmentIds);
        $agencyOptions = AssistantAdvertisingAgency::enabledOptions($advertisingAssistantDepartmentIds);
        $agencyMedia = AssistantAdvertisingAgency::enabledMediaNames($advertisingAssistantDepartmentIds);
        $agencyNames = AssistantAdvertisingAgency::enabledNames($advertisingAssistantDepartmentIds);
        $request->validate([
            'ads.*.screenshot' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);
        $inputAds = $request->input('ads', []);
        foreach ($inputAds as $index => &$ad) {
            if ($request->hasFile('ads.'.$index.'.screenshot')) {
                $ad['existing_screenshot'] = $request->file('ads.'.$index.'.screenshot')
                    ->store('operation-reports/ads', 'public');
            }
        }
        unset($ad);
        $request->merge(['ads' => $inputAds]);

        $validated = $request->validate([
            'report_date' => ['required', 'date_format:Y-m-d'],
            'report_id' => ['nullable', 'integer'],
            'operator_user_id' => ['nullable', 'integer'],
            'ads' => ['required', 'array', 'min:1', 'max:100'],
            'ads.*.media_source' => ['required', Rule::in($mediaSources)],
            'ads.*.agency' => ['required', 'string', Rule::in(array_keys($agencyOptions))],
            'ads.*.account_name' => ['required', 'string', 'max:191'],
            'ads.*.account_number' => ['required', 'string', 'max:191'],
            'ads.*.assistant_domain_store_id' => ['nullable', 'integer'],
            'ads.*.book_spend' => ['required', 'numeric', 'min:0'],
            'ads.*.settlement_coefficient' => ['required', 'numeric', 'min:0'],
            'ads.*.existing_screenshot' => ['nullable', 'string', 'max:500', 'regex:/^operation-reports\/ads\/[A-Za-z0-9._-]+$/'],
        ]);
        $operatorId = $isBoss && ! empty($validated['operator_user_id'])
            ? (int) $validated['operator_user_id'] : (int) $user->getKey();
        $editingReport = ! empty($validated['report_id']) ? OperationReport::query()->findOrFail((int) $validated['report_id']) : null;
        if ($editingReport && ! in_array($editingReport->status, [OperationReport::STATUS_PENDING, OperationReport::STATUS_REJECTED], true)) {
            return redirect()->route('operation.advertising-reports.index', [
                'report_date' => $editingReport->report_date->format('Y-m-d'),
            ])->with('warning', '该广告费提报已审核通过，无法继续编辑。本次修改未保存。');
        }
        if ($editingReport) {
            abort_unless(in_array($editingReport->status, [OperationReport::STATUS_PENDING, OperationReport::STATUS_REJECTED], true), 422, '只有待审核或审核不通过的提报可以编辑。');
            abort_unless($isBoss || (int) $editingReport->operator_user_id === (int) $user->getKey(), 403);
            abort_unless((int) $editingReport->operator_user_id === $operatorId && $editingReport->report_date->format('Y-m-d') === $validated['report_date'], 422);
        }
        if ($isBoss) $this->assertAssignableOperator($operatorId);
        $operator = AdminUser::query()->findOrFail($operatorId);
        $operationDepartment = $this->assistantStoreOwners->operationDepartmentForUser($operator);
        if (! $operationDepartment) {
            throw ValidationException::withMessages([
                'operator_user_id' => '该运营人员尚未归属运营部门，无法提交报表。',
            ]);
        }
        $existing = OperationReport::query()->whereDate('report_date', $validated['report_date'])->where('operator_user_id', $operatorId)->first();

        $ads = [];
        foreach (($validated['ads'] ?? []) as $index => $ad) {
            if (empty($ad['existing_screenshot'])) {
                throw ValidationException::withMessages(['ads.'.$index.'.screenshot' => '请上传第 '.($index + 1).' 条广告账户的广告消耗截图。']);
            }
            $hasContent = collect(['media_source','agency','account_name','account_number','book_spend','settlement_coefficient'])
                ->contains(fn ($field) => ($ad[$field] ?? '') !== '' && ($ad[$field] ?? null) !== null);
            if (! $hasContent && empty($ad['existing_screenshot'])) continue;
            $path = $ad['existing_screenshot'] ?? null;
            $bookSpend = (float) ($ad['book_spend'] ?? 0);
            $coefficient = (float) $agencyOptions[$ad['agency']];
            $handlingFee = $bookSpend * $coefficient;
            $mediaSource = trim((string) ($agencyMedia[$ad['agency']] ?? ''));
            if ($mediaSource === '') {
                throw ValidationException::withMessages(['ads.'.$index.'.agency' => '该广告代理尚未关联广告社媒，请先到助理系统中设置。']);
            }
            $store = null;
            if (mb_strtolower($mediaSource) === 'applovin') {
                $store = AssistantDomainStore::query()->whereKey((int) ($ad['assistant_domain_store_id'] ?? 0))->where('owner_user_id', $operatorId)->first();
                if (! $store) throw ValidationException::withMessages(['ads.'.$index.'.assistant_domain_store_id' => '选择 AppLovin 时必须选择该运营已绑定的店铺域名。']);
            }
            $ads[] = [
                'media_source' => $mediaSource,
                'agency' => trim((string) ($agencyNames[$ad['agency']] ?? '')),
                'account_name' => trim((string) ($ad['account_name'] ?? '')),
                'account_number' => trim((string) ($ad['account_number'] ?? '')),
                'assistant_domain_store_id' => $store?->getKey(),
                'store_domain' => $store?->domain,
                'book_spend' => round($bookSpend, 4),
                'settlement_coefficient' => round($coefficient, 4),
                'handling_fee' => round($handlingFee, 4),
                'actual_spend' => round($bookSpend + $handlingFee, 4),
                'screenshot' => $path,
            ];
        }

        $existingAds = ! $editingReport && $existing ? ($existing->advertising_rows ?: []) : [];
        if ($duplicate = $this->duplicateAdvertisingMessage($ads, $existingAds)) {
            throw ValidationException::withMessages(['ads' => $duplicate]);
        }
        $supplementingExisting = ! $editingReport && $existing !== null;
        if ($supplementingExisting) {
            $ads = array_values(array_merge($existingAds, $ads));
            $editingReport = $existing;
        }

        $calculated = $this->metrics->build($validated['report_date'], $operatorId);
        $totals = $calculated['totals'];
        $adSpend = collect($ads)->sum('actual_spend');
        $firstProfit = $totals['first_total_revenue'] - $totals['first_purchase_cost'] - $totals['first_shipping_cost'] - $totals['first_return_fee'] - $adSpend;
        $renewalNet = $totals['renewal_revenue'] - $totals['renewal_cost'];
        $values = [
                'report_date' => $validated['report_date'], 'operator_user_id' => $operatorId,
                'operator_name' => (string) ($operator->name ?: $operator->username),
                'operation_department_id' => (int) $operationDepartment->getKey(),
                'operation_department_name' => (string) $operationDepartment->name,
                'metric_rows' => $calculated['rows'], 'advertising_rows' => $ads,
                'first_purchase_cost' => $totals['first_purchase_cost'],
                'first_shipping_cost' => $totals['first_shipping_cost'],
                'first_return_fee' => $totals['first_return_fee'], 'actual_ad_spend' => $adSpend,
                'first_profit' => $firstProfit, 'renewal_net_revenue' => $renewalNet,
                'overall_profit' => $firstProfit + $renewalNet,
                'status' => OperationReport::STATUS_PENDING, 'submitted_by' => $user->getKey(),
                'submitted_at' => now(), 'reviewed_by' => null, 'reviewed_at' => null, 'review_reason' => null,
            ];
        if ($editingReport) $editingReport->update($values);
        else OperationReport::query()->create($values);
        foreach (collect($ads)->where(fn ($row) => mb_strtolower(trim((string) ($row['media_source'] ?? ''))) === 'applovin') as $row) {
            AppLovinAccountStoreBinding::query()->updateOrCreate([
                'account_key' => AppLovinSpendScreenshot::accountKey($operatorId, (string) $row['agency'], (string) $row['media_source'], (string) $row['account_name'], (string) $row['account_number']),
            ], ['assistant_domain_store_id' => (int) $row['assistant_domain_store_id'], 'bound_by' => $user->getKey()]);
        }

        $successMessage = $supplementingExisting
            ? '广告费补录已追加到当天报表，整份报表已转为待审核。'
            : ($editingReport ? '运营报表已重新提交，状态仍为待审核。' : '运营报表已提交，当前状态为待审核。');

        return redirect()->route('operation.advertising-reports.index', ['report_date' => $validated['report_date'], 'operator_user_id' => $isBoss ? $operatorId : null])->with('success', $successMessage);
    }

    public function destroy(Request $request, OperationReport $report): RedirectResponse
    {
        $context = $this->context($request);
        if ($context instanceof Response) abort($context->getStatusCode());
        $user = $context['user'];
        abort_unless($this->isBoss($user) || (int) $report->operator_user_id === (int) $user->getKey(), 403);
        abort_unless(in_array($report->status, [OperationReport::STATUS_PENDING, OperationReport::STATUS_REJECTED], true), 422, '只有待审核或审核不通过的提报可以删除。');
        $screenshots = collect($report->advertising_rows ?: [])->pluck('screenshot')->filter()->all();
        $report->delete();
        if ($screenshots !== []) Storage::disk('public')->delete($screenshots);

        return redirect()->route('operation.advertising-reports.index')->with('success', '广告费提报已删除。');
    }

    public function screenshot(Request $request): Response
    {
        $context = $this->context($request);
        if ($context instanceof Response) return $context;

        $validated = $request->validate([
            'path' => ['required', 'string', 'max:500', 'regex:/^operation-reports\/ads\/[A-Za-z0-9._-]+$/'],
        ]);
        abort_unless(Storage::disk('public')->exists($validated['path']), 404);

        return Storage::disk('public')->response($validated['path']);
    }

    public function approve(Request $request, OperationReport $report): RedirectResponse
    {
        $context = $this->context($request);
        if ($context instanceof Response) abort($context->getStatusCode());
        return redirect()->route('operation.reports.show', $report)
            ->with('error', '审核流程已迁移到助理系统，请在“Shopify订阅广告费审核”菜单中操作。');
    }

    public function export(Request $request): Response
    {
        $view = $this->advertising($request);
        if ($view instanceof Response) return $view;
        $data = $view->getData();
        $metrics = collect($data['metricRows'])->values();
        $ads = collect($data['advertisingRows'])->values();
        $count = max($metrics->count(), $ads->count(), 1);
        $columns = collect(['日期','人名','店铺域名','媒体','首单订阅单量','首单订阅营业额($)','首单普通单量','首单普通营业额($)','首单合计单量','首单合计营业额($)','首单总采购费','首单总运费','广告代理','广告社媒','广告账户名称','广告账户编号','账面广告费($)','结算系数','手续费($)','实际广告费($)','广告消耗截图','首单交易退货费','运营首单利润($)','续费净收益($)','运营整体净利润($)','运营社媒首单ROI','填写状态','续费单量','续费营业额($)','续费费用','总单量','总营业额($)'])
            ->map(fn ($label) => ['label' => $label, 'width' => mb_strlen($label) > 8 ? 20 : 15])->all();
        return $this->xlsxWriter->response('运营报表_'.$data['reportDate'].'_'.$data['selectedOperator']->name.'.xlsx', '运营报表', $columns, static function () use ($metrics, $ads, $count, $data): iterable {
            for ($i = 0; $i < $count; $i++) {
                $m = $metrics->get($i, []); $a = $ads->get($i, []);
                $report = $data['report'];
                $adSpend = (float) ($report?->actual_ad_spend ?? $ads->sum('actual_spend'));
                $firstRevenue = (float) $data['metricTotals']['first_total_revenue'];
                $values = [$data['reportDate'],$data['selectedOperator']->name,$m['shop_domain']??'',$m['media_source']??'',
                    $m['first_subscription_orders']??'', $m['first_subscription_revenue']??'', $m['first_normal_orders']??'', $m['first_normal_revenue']??'',
                    $m['first_total_orders']??'', $m['first_total_revenue']??'', $m['first_purchase_cost']??'', $m['first_shipping_cost']??'',
                    $a['agency']??'', $a['media_source']??'', $a['account_name']??'', $a['account_number']??'', $a['book_spend']??'', $a['settlement_coefficient']??'', $a['handling_fee']??'', $a['actual_spend']??'', $a['screenshot']??'',
                    $m['first_return_fee']??'', $i === 0 ? ($report?->first_profit ?? '') : '', $i === 0 ? ($report?->renewal_net_revenue ?? '') : '', $i === 0 ? ($report?->overall_profit ?? '') : '',
                    $i === 0 && $adSpend > 0 ? $firstRevenue / $adSpend : '', $i === 0 ? ($report ? ($report->status === OperationReport::STATUS_APPROVED ? '已审核' : '待审核') : '未提交') : '',
                    $m['renewal_orders']??'', $m['renewal_revenue']??'', $m['renewal_cost']??'', $m['total_orders']??'', $m['total_revenue']??''];
                $textIndexes = [0,1,2,3,12,13,14,15,20,26];
                yield ['kind' => $i % 2 ? 'variant' : 'normal', 'height' => 25, 'cells' => array_map(fn ($value, $index) => ['value' => $value, 'type' => in_array($index, $textIndexes, true) ? 'text' : 'number', 'align' => in_array($index, $textIndexes, true) ? 'left' : 'right'], $values, array_keys($values))];
            }
        }, false);
    }

    public function exportMissingGuideSkus(Request $request): Response
    {
        $context = $this->context($request);
        if ($context instanceof Response) return $context;
        abort_unless($this->canViewMissingGuideSkus($context['user']), 403, '只有 Boss 账号可以导出缺少指导价的 SKU。');

        $rows = $this->metrics->missingGuideSkuSummary();
        $content = collect($rows)
            ->pluck('sku')
            ->filter(fn ($sku) => $sku !== '未填写 SKU')
            ->unique()
            ->implode("\r\n");

        return response("\xEF\xBB\xBF".$content."\r\n", 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="missing-guide-skus-'.now('Asia/Shanghai')->format('Ymd-His').'.txt"',
        ]);
    }

    private function context(Request $request): array|Response
    {
        $context = $this->portalEntries->portalContext($request);
        if ($context instanceof Response) return $context;
        $this->access->assertPortal($context['portal'], ProductRequestAccessService::OPERATION_PORTAL_CODE);
        return $context;
    }

    private function isBoss(AdminUser $user): bool
    {
        return $user->isAdministrator() || strcasecmp((string) $user->username, 'kky_boss') === 0;
    }

    private function canViewMissingGuideSkus(AdminUser $user): bool
    {
        return strcasecmp((string) $user->username, 'kky_boss') === 0;
    }

    private function canReview(AdminUser $user): bool
    {
        return $this->isBoss($user) || $user->departments()->wherePivot('position', Department::POSITION_MANAGER)->exists();
    }

    private function operatorOptions()
    {
        $ids = AssistantDomainStore::query()->whereNotNull('owner_user_id')->distinct()->pluck('owner_user_id');
        return AdminUser::query()->whereIn('id', $ids)->orderBy('name')->get(['id','name','username']);
    }

    /**
     * Boss can select every assigned operator. Department managers and deputy
     * managers can select active users within their existing management scope.
     * Ordinary operators remain limited to their own data.
     */
    private function reportOperatorOptions(AdminUser $user, ?int $selectedDepartmentId = null)
    {
        if ($this->isBoss($user)) {
            return $this->operatorOptions();
        }

        $departmentIds = $this->departmentAuthorization->managedDepartmentIds($user);
        if ($departmentIds->isEmpty()) {
            return collect([$user]);
        }

        if ($selectedDepartmentId !== null) {
            abort_unless($departmentIds->contains($selectedDepartmentId), 403);
            $departmentIds = collect([$selectedDepartmentId]);
        }

        $rolesTable = config('admin.database.roles_table');

        return AdminUser::query()
            ->where('status', AdminUser::STATUS_ACTIVE)
            ->whereHas('departments', fn ($query) => $query->whereIn($rolesTable.'.id', $departmentIds->all()))
            ->orderBy('name')
            ->orderBy('username')
            ->get(['id', 'name', 'username'])
            ->unique('id')
            ->values();
    }

    private function bossReportOperatorOptions($operationDepartments, ?int $selectedDepartmentId)
    {
        $departmentIds = $selectedDepartmentId !== null
            ? AdminUser::query()
                ->where('status', AdminUser::STATUS_ACTIVE)
                ->whereHas('departments', fn ($query) => $query->where(
                    config('admin.database.roles_table').'.id',
                    $selectedDepartmentId
                ))
                ->pluck('id')
            : AdminUser::query()
                ->where('status', AdminUser::STATUS_ACTIVE)
                ->whereHas('departments', fn ($query) => $query->whereIn(
                    config('admin.database.roles_table').'.id',
                    $operationDepartments->pluck('id')->all()
                ))
                ->pluck('id');

        $operators = AdminUser::query()
            ->whereIn('id', $departmentIds->all())
            ->where('status', AdminUser::STATUS_ACTIVE)
            ->orderBy('name')
            ->orderBy('username')
            ->get(['id', 'name', 'username']);

        return $operators->isNotEmpty() ? $operators : $this->operatorOptions();
    }

    private function assertReportOperatorAccess(AdminUser $viewer, int $operatorId, $operatorOptions): void
    {
        abort_unless($operatorId > 0 && $operatorOptions->contains(
            fn (AdminUser $operator): bool => (int) $operator->getKey() === $operatorId
        ), 403, '无权查看该运营人员的报表数据。');

    }

    private function assertAssignableOperator(int $operatorId): void
    {
        abort_unless(AssistantDomainStore::query()->where('owner_user_id', $operatorId)->exists(), 422, '该账号没有已分配店铺。');
    }

    /** @return list<string> */
    private function advertisingMedia(array $assistantDepartmentIds): array
    {
        return AssistantAdvertisingMedia::enabledNames($assistantDepartmentIds);
    }

    private function normalizeAdvertisingText(string $value): string
    {
        return mb_strtolower((string) preg_replace('/\s+/u', '', trim($value)));
    }

    /**
     * @param  list<array<string, mixed>>  $incomingRows
     * @param  list<array<string, mixed>>  $existingRows
     */
    private function duplicateAdvertisingMessage(array $incomingRows, array $existingRows = []): ?string
    {
        $seen = [];
        foreach ($existingRows as $row) {
            $seen[$this->advertisingIdentity($row)] = true;
        }

        foreach ($incomingRows as $index => $row) {
            $identity = $this->advertisingIdentity($row);
            if (isset($seen[$identity])) {
                $media = trim((string) ($row['media_source'] ?? ''));
                $accountNumber = trim((string) ($row['account_number'] ?? ''));

                return '第 '.($index + 1).' 条广告账户与当天已有数据重复（'.$media.' / '.$accountNumber.'），请勿重复提交。';
            }
            $seen[$identity] = true;
        }

        return null;
    }

    /** @param array<string, mixed> $row */
    private function advertisingIdentity(array $row): string
    {
        return implode('|', [
            $this->normalizeAdvertisingText((string) ($row['agency'] ?? '')),
            $this->normalizeAdvertisingText((string) ($row['media_source'] ?? '')),
            $this->normalizeAdvertisingText((string) ($row['account_number'] ?? '')),
        ]);
    }

    private function operatorImportLabel(AdminUser $operator): string
    {
        $name = trim((string) $operator->name);
        $username = trim((string) $operator->username);

        return $name !== '' && $username !== '' && $name !== $username ? $name.'（'.$username.'）' : ($name ?: $username);
    }

    /** @return array<string, list<array{key:string,media:string,coefficient:float}>> */
    private function advertisingAgencyGroups(array $assistantDepartmentIds): array
    {
        return AssistantAdvertisingAgency::enabledGroups($assistantDepartmentIds);
    }

    private function sumRows(array $rows): array
    {
        $fields = ['first_subscription_orders','first_subscription_revenue','first_normal_orders','first_normal_revenue','first_total_orders','first_total_revenue','first_purchase_cost','first_shipping_cost','first_return_fee','renewal_orders','renewal_revenue','renewal_cost','total_orders','total_revenue'];
        return collect($fields)->mapWithKeys(fn ($field) => [$field => collect($rows)->sum($field)])->all();
    }

    /**
     * @param  list<array<string, mixed>>  $advertisingRows
     * @return list<array{media_source:string,account_name:string,account_number:string,actual_ad_spend:float}>
     */
    private function advertisingAccountSpendSummary(array $advertisingRows): array
    {
        return collect($advertisingRows)
            ->groupBy(fn (array $row): string => implode("\x1F", [
                mb_strtolower(trim((string) ($row['media_source'] ?? ''))),
                mb_strtolower(trim((string) ($row['account_name'] ?? ''))),
                mb_strtolower(trim((string) ($row['account_number'] ?? ''))),
            ]))
            ->map(function ($rows): array {
                $first = $rows->first();

                return [
                    'media_source' => trim((string) ($first['media_source'] ?? '')) ?: '其他',
                    'account_name' => trim((string) ($first['account_name'] ?? '')),
                    'account_number' => trim((string) ($first['account_number'] ?? '')),
                    'actual_ad_spend' => (float) $rows->sum(function (array $row): float {
                        $bookSpend = (float) ($row['book_spend'] ?? 0);
                        $handlingFee = (float) ($row['handling_fee']
                            ?? ($bookSpend * (float) ($row['settlement_coefficient'] ?? 0)));

                        return (float) ($row['actual_spend'] ?? ($bookSpend + $handlingFee));
                    }),
                ];
            })
            ->sortBy(fn (array $account): string => mb_strtolower(
                $account['media_source'].'|'.$account['account_name'].'|'.$account['account_number']
            ))
            ->values()
            ->all();
    }

    private function withFirstOrderProfitability(array $rows, array $mediaRoiByDate): array
    {
        $revenueByMedia = [];
        foreach ($rows as $row) {
            $key = (int) ($row['operator_user_id'] ?? 0)."\x1F".$row['date']."\x1F".mb_strtolower(trim((string) $row['media_source']));
            $revenueByMedia[$key] = ($revenueByMedia[$key] ?? 0.0) + (float) $row['first_total_revenue'];
        }

        return array_map(function (array $row) use ($mediaRoiByDate, $revenueByMedia): array {
            $mediaKey = mb_strtolower(trim((string) $row['media_source']));
            $identityKey = (int) ($row['operator_user_id'] ?? 0).'|'.$row['date'];
            $groupKey = (int) ($row['operator_user_id'] ?? 0)."\x1F".$row['date']."\x1F".$mediaKey;
            $mediaSpend = (float) ($mediaRoiByDate[$identityKey][$mediaKey]['actual_ad_spend'] ?? 0);
            $groupRevenue = (float) ($revenueByMedia[$groupKey] ?? 0);
            $row['allocated_actual_ad_spend'] = $groupRevenue > 0
                ? $mediaSpend * (float) $row['first_total_revenue'] / $groupRevenue
                : 0.0;
            $row['first_total_cost'] = (float) $row['first_purchase_cost']
                + (float) $row['first_shipping_cost']
                + (float) $row['first_return_fee'];
            $row['first_profit'] = (float) $row['first_total_revenue']
                - $row['allocated_actual_ad_spend']
                - $row['first_total_cost'];
            $row['first_loss_rate'] = $row['first_total_cost'] > 0
                ? $row['first_profit'] / $row['first_total_cost']
                : null;

            return $row;
        }, $rows);
    }

    private function identityRowspans(array $rows): array
    {
        $spans = [];
        for ($index = 0, $count = count($rows); $index < $count; $index++) {
            $key = [$rows[$index]['date'], $rows[$index]['operator_name'], $rows[$index]['shop_domain']];
            if ($index > 0 && $key === [$rows[$index - 1]['date'], $rows[$index - 1]['operator_name'], $rows[$index - 1]['shop_domain']]) {
                $spans[$index] = 0;
                continue;
            }
            $span = 1;
            while ($index + $span < $count && $key === [$rows[$index + $span]['date'], $rows[$index + $span]['operator_name'], $rows[$index + $span]['shop_domain']]) $span++;
            $spans[$index] = $span;
        }
        return $spans;
    }

    private function personRowspans(array $rows): array
    {
        $spans = [];
        for ($index = 0, $count = count($rows); $index < $count; $index++) {
            $key = [$rows[$index]['date'], $rows[$index]['operator_name']];
            if ($index > 0 && $key === [$rows[$index - 1]['date'], $rows[$index - 1]['operator_name']]) {
                $spans[$index] = 0;
                continue;
            }

            $span = 1;
            while ($index + $span < $count && $key === [$rows[$index + $span]['date'], $rows[$index + $span]['operator_name']]) $span++;
            $spans[$index] = $span;
        }

        return $spans;
    }

    private function mediaRowspans(array $rows): array
    {
        $spans = [];
        for ($index = 0, $count = count($rows); $index < $count; $index++) {
            $key = [
                $rows[$index]['date'],
                $rows[$index]['operator_name'],
                mb_strtolower(trim((string) $rows[$index]['media_source'])),
            ];
            $previousKey = $index > 0 ? [
                $rows[$index - 1]['date'],
                $rows[$index - 1]['operator_name'],
                mb_strtolower(trim((string) $rows[$index - 1]['media_source'])),
            ] : null;
            if ($key === $previousKey) {
                $spans[$index] = 0;
                continue;
            }

            $span = 1;
            while ($index + $span < $count) {
                $next = $rows[$index + $span];
                $nextKey = [$next['date'], $next['operator_name'], mb_strtolower(trim((string) $next['media_source']))];
                if ($key !== $nextKey) break;
                $span++;
            }
            $spans[$index] = $span;
        }

        return $spans;
    }

    private function dateRowspans(array $rows): array
    {
        $spans = [];
        for ($index = 0, $count = count($rows); $index < $count; $index++) {
            $key = [$rows[$index]['date'], (int) ($rows[$index]['operator_user_id'] ?? 0)];
            if ($index > 0 && $key === [$rows[$index - 1]['date'], (int) ($rows[$index - 1]['operator_user_id'] ?? 0)]) {
                $spans[$index] = 0;
                continue;
            }

            $span = 1;
            while ($index + $span < $count && $key === [$rows[$index + $span]['date'], (int) ($rows[$index + $span]['operator_user_id'] ?? 0)]) $span++;
            $spans[$index] = $span;
        }

        return $spans;
    }

    private function dateBands(array $rows): array
    {
        $bands = [];
        $lastDate = null;
        $band = 1;
        foreach ($rows as $index => $row) {
            if ($row['date'] !== $lastDate) {
                $band = $band === 0 ? 1 : 0;
                $lastDate = $row['date'];
            }
            $bands[$index] = $band;
        }

        return $bands;
    }

    /** @return array<string,string> */
    private function timezoneOptions(): array
    {
        $options = [];
        for ($offset = -12; $offset <= 14; $offset++) {
            $value = sprintf('%s%02d:00', $offset < 0 ? '-' : '+', abs($offset));
            $label = 'UTC'.($offset >= 0 ? '+' : '').$offset;
            if ($value === '+08:00') $label .= '（北京时间）';
            if ($value === OperationReportMetricsService::REPORT_TIMEZONE) $label .= '（默认）';
            $options[$value] = $label;
        }
        return $options;
    }
}
