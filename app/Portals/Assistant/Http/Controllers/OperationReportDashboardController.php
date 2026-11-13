<?php

namespace App\Portals\Assistant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\AdminUser;
use App\Models\OperationReport;
use App\Models\OperationDashboardDepartmentScope;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\Assistant\AssistantPortalAuthorizationService;
use App\Portals\Assistant\AssistantStoreOwnerService;
use App\Portals\Operation\OperationReportMetricsService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

class OperationReportDashboardController extends Controller
{
    private const DASHBOARD_PORTAL_CODE = 'yunying-kanban';

    public function __construct(
        private readonly PortalEntryController $portalEntries,
        private readonly AssistantPortalAuthorizationService $authorization,
        private readonly AssistantStoreOwnerService $assistantStoreOwners,
        private readonly OperationReportMetricsService $metrics,
    ) {
    }

    public function index(Request $request): Response|View
    {
        $context = $this->context($request);
        if ($context instanceof Response) return $context;

        $validated = $request->validate([
            'date_range' => ['nullable', Rule::in(['today', 'yesterday', 'last_7_days', 'last_15_days', 'last_30_days', 'this_month', 'last_month', 'custom'])],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'timezone' => ['nullable', Rule::in(array_keys($this->timezoneOptions()))],
            'operation_department_ids' => ['nullable', 'array'],
            'operation_department_ids.*' => ['integer', 'distinct'],
            'operator_user_id' => ['nullable', 'integer'],
            'exclude_resigned' => ['nullable', 'boolean'],
            'scope_user_id' => ['nullable', 'integer'],
            'sort_by' => ['nullable', Rule::in(['first_subscription_orders', 'first_total_orders'])],
            'sort_direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'display_mode' => ['nullable', Rule::in(['performance', 'team'])],
        ]);
        $timezone = (string) ($validated['timezone'] ?? OperationReportMetricsService::REPORT_TIMEZONE);
        $reportNow = Carbon::now($timezone);
        $dateRange = (string) ($validated['date_range'] ?? (
            $request->filled('date_from') || $request->filled('date_to') ? 'custom' : 'yesterday'
        ));
        [$dateFrom, $dateTo] = match ($dateRange) {
            'today' => [$reportNow->format('Y-m-d'), $reportNow->format('Y-m-d')],
            'yesterday' => [$reportNow->copy()->subDay()->format('Y-m-d'), $reportNow->copy()->subDay()->format('Y-m-d')],
            'last_7_days' => [$reportNow->copy()->subDays(6)->format('Y-m-d'), $reportNow->format('Y-m-d')],
            'last_15_days' => [$reportNow->copy()->subDays(14)->format('Y-m-d'), $reportNow->format('Y-m-d')],
            'last_30_days' => [$reportNow->copy()->subDays(29)->format('Y-m-d'), $reportNow->format('Y-m-d')],
            'last_month' => [
                $reportNow->copy()->subMonthNoOverflow()->startOfMonth()->format('Y-m-d'),
                $reportNow->copy()->subMonthNoOverflow()->endOfMonth()->format('Y-m-d'),
            ],
            'custom' => [
                (string) ($validated['date_from'] ?? $reportNow->copy()->startOfMonth()->format('Y-m-d')),
                (string) ($validated['date_to'] ?? $reportNow->copy()->endOfMonth()->format('Y-m-d')),
            ],
            default => [
                $reportNow->copy()->startOfMonth()->format('Y-m-d'),
                $reportNow->copy()->endOfMonth()->format('Y-m-d'),
            ],
        };
        abort_if($dateFrom > $dateTo, 422, '开始日期不能晚于结束日期。');

        $visibleDepartmentIds = $this->visibleDepartmentIds($context);
        $departments = Department::business()->whereIn('id', $visibleDepartmentIds)
            ->orderBy('sort')->orderBy('name')->get();
        $selectedDepartmentIds = collect($validated['operation_department_ids'] ?? [])
            ->map(fn ($id) => (int) $id)->unique()->values();
        $excludeResigned = (bool) ($validated['exclude_resigned'] ?? false);
        abort_unless($selectedDepartmentIds->diff($visibleDepartmentIds)->isEmpty(), 403);

        $allReports = OperationReport::query()
            ->whereIn('operation_department_id', $visibleDepartmentIds)
            ->when($selectedDepartmentIds->isNotEmpty(), fn ($query) => $query->whereIn('operation_department_id', $selectedDepartmentIds->all()))
            ->whereBetween('report_date', [$dateFrom, $dateTo])
            ->orderBy('id')
            ->get();
        $operatorDepartmentIds = $selectedDepartmentIds->isNotEmpty() ? $selectedDepartmentIds->all() : $visibleDepartmentIds;
        $operators = AdminUser::query()
            ->with('departments')
            ->where(function ($accounts) {
                $accounts->where('status', AdminUser::STATUS_ACTIVE)
                    ->orWhere('employment_status', 'resigned');
            })
            ->when($excludeResigned, fn ($query) => $query->where(function ($employment) {
                $employment->whereNull('employment_status')->orWhere('employment_status', '!=', 'resigned');
            }))
            ->whereHas('departments', fn ($query) => $query->whereIn('admin_roles.id', $operatorDepartmentIds))
            ->orderBy('name')->orderBy('username')
            ->get()
            ->unique('id')->values();
        $selectedOperatorId = isset($validated['operator_user_id'])
            ? (int) $validated['operator_user_id']
            : null;
        if ($selectedOperatorId !== null) {
            abort_unless($operators->contains(fn (AdminUser $operator) => (int) $operator->getKey() === $selectedOperatorId), 403);
        }
        $operatorOptions = $operators;
        if ($selectedOperatorId !== null) {
            $operators = $operators->where('id', $selectedOperatorId)->values();
        }
        $operatorIds = $operators->pluck('id')->map(fn ($id) => (int) $id)->values();
        $operatorDepartments = $operators->mapWithKeys(fn (AdminUser $operator) => [
            (int) $operator->getKey() => $this->assistantStoreOwners->operationDepartmentForUser($operator),
        ]);
        $operatorFallbackNameCounts = $operators->groupBy(function (AdminUser $operator) use ($operatorDepartments): string {
            $department = $operatorDepartments->get((int) $operator->getKey());
            return implode('|', [
                (int) ($department?->getKey() ?? 0),
                $this->normalizeOperatorName((string) ($operator->name ?: $operator->username)),
            ]);
        })->map->count();
        // 广告费始终跟随当前运营筛选范围；待审核或驳回记录只用于显示状态，不参与任何金额和 ROI 计算。
        $allReports = $allReports->whereIn('operator_user_id', $operatorIds->all())->values();
        // 同一运营、同一日期可能保留过历史重提记录。展示时优先使用已审核记录，
        // 避免旧的待审核/驳回记录覆盖已经审核通过的广告费。
        $preferredReport = static function ($reports): ?OperationReport {
            return $reports->sortByDesc(fn (OperationReport $report) => [
                $report->status === OperationReport::STATUS_APPROVED ? 1 : 0,
                (int) $report->getKey(),
            ])->first();
        };
        $reportsByOperatorDate = $allReports
            ->groupBy(fn (OperationReport $report) => (int) $report->operator_user_id.'|'.$report->report_date->format('Y-m-d'))
            ->map($preferredReport);
        // 兼容历史导入数据中运营用户 ID 发生变化的情况：仅在同部门、同日期、同姓名
        // 只有一个明确匹配结果时兜底，避免同名运营之间串数据。
        $reportsByDepartmentNameDate = $allReports
            ->groupBy(fn (OperationReport $report) => implode('|', [
                (int) $report->operation_department_id,
                $report->report_date->format('Y-m-d'),
                $this->normalizeOperatorName((string) $report->operator_name),
            ]));
        $cacheKey = 'assistant-operation-report:v4-timezone:'.$timezone.':'.$dateFrom.':'.$dateTo.':'.sha1($operatorIds->implode(','));
        $calculatedByOperator = Cache::remember($cacheKey, now()->addMinutes(5), function () use ($operatorIds, $dateFrom, $dateTo, $timezone): array {
            $calculated = [];
            // 分批读取，避免整月订单及商品明细一次载入导致 PHP 内存溢出。
            foreach ($operatorIds->chunk(2) as $operatorIdBatch) {
                $calculated += $this->metrics->buildRangeForOperators($dateFrom, $dateTo, $operatorIdBatch->values()->all(), $timezone);
                gc_collect_cycles();
            }
            return $calculated;
        });

        $rows = [];
        foreach ($operators as $operator) {
            $calculatedRows = collect($calculatedByOperator[(int) $operator->getKey()]['rows'] ?? []);
            $operationDepartment = $operatorDepartments->get((int) $operator->getKey());
            $calculatedRowsByDate = $calculatedRows->groupBy('date');

            // A report with approved advertising spend is itself a valid daily report row,
            // even when the operator did not place any orders on that date.
            $approvedReportDates = $allReports
                ->where('status', OperationReport::STATUS_APPROVED)
                ->where('operator_user_id', (int) $operator->getKey())
                ->map(fn (OperationReport $report) => $report->report_date->format('Y-m-d'));
            if ($operationDepartment) {
                $operatorNameKey = (int) $operationDepartment->getKey().'|'.$this->normalizeOperatorName((string) ($operator->name ?: $operator->username));
                if ((int) $operatorFallbackNameCounts->get($operatorNameKey, 0) === 1) {
                    $approvedReportDates = $approvedReportDates->merge(
                        $allReports
                            ->where('status', OperationReport::STATUS_APPROVED)
                            ->where('operation_department_id', (int) $operationDepartment->getKey())
                            ->filter(fn (OperationReport $report) =>
                                $this->normalizeOperatorName((string) $report->operator_name)
                                    === $this->normalizeOperatorName((string) ($operator->name ?: $operator->username))
                            )
                            ->map(fn (OperationReport $report) => $report->report_date->format('Y-m-d'))
                    );
                }
            }
            foreach ($approvedReportDates->unique() as $approvedReportDate) {
                if (! $calculatedRowsByDate->has($approvedReportDate)) {
                    $calculatedRowsByDate->put($approvedReportDate, collect([$this->emptyMetricRow(
                        $approvedReportDate,
                        (string) ($operator->name ?: $operator->username),
                    )]));
                }
            }
            if ($selectedOperatorId !== null) {
                $cursor = Carbon::createFromFormat('Y-m-d', $dateFrom);
                $lastDate = Carbon::createFromFormat('Y-m-d', $dateTo);
                while ($cursor->lte($lastDate)) {
                    $emptyDate = $cursor->format('Y-m-d');
                    if (! $calculatedRowsByDate->has($emptyDate)) {
                        $calculatedRowsByDate->put($emptyDate, collect([$this->emptyMetricRow(
                            $emptyDate,
                            (string) ($operator->name ?: $operator->username),
                        )]));
                    }
                    $cursor->addDay();
                }
            }
            $calculatedRowsByDate = $calculatedRowsByDate->sortKeys();
            foreach ($calculatedRowsByDate as $rowDate => $dateRows) {
                $report = $reportsByOperatorDate->get((int) $operator->getKey().'|'.$rowDate);
                if (! $report && $operationDepartment) {
                    $fallbackKey = implode('|', [
                        (int) $operationDepartment->getKey(),
                        (string) $rowDate,
                        $this->normalizeOperatorName((string) ($operator->name ?: $operator->username)),
                    ]);
                    $fallbackReports = $reportsByDepartmentNameDate->get($fallbackKey, collect());
                    $fallbackOperatorIds = $fallbackReports->pluck('operator_user_id')->map(fn ($id) => (int) $id)->unique();
                    $operatorNameKey = (int) $operationDepartment->getKey().'|'.$this->normalizeOperatorName((string) ($operator->name ?: $operator->username));
                    if ($fallbackOperatorIds->count() === 1 && (int) $operatorFallbackNameCounts->get($operatorNameKey, 0) === 1) {
                        $report = $preferredReport($fallbackReports);
                    }
                }
                $approvedReport = $report?->status === OperationReport::STATUS_APPROVED ? $report : null;
                $isEmptyOrderDay = (int) $dateRows->sum('total_orders') === 0
                    && $dateRows->every(fn (array $row) => ($row['shop_domain'] ?? '—') === '—');
                $advertisingMedia = collect($approvedReport?->advertising_rows ?: [])
                        ->map(fn (array $row) => trim((string) ($row['media_source'] ?? '')) ?: '其他')
                        ->unique(fn (string $media) => mb_strtolower($media))
                        ->values();
                if ($isEmptyOrderDay && $approvedReport) {
                    if ($advertisingMedia->isNotEmpty()) {
                        $dateRows = $advertisingMedia->map(fn (string $media) => $this->emptyMetricRow(
                            (string) $rowDate,
                            (string) ($operator->name ?: $operator->username),
                            $media,
                        ));
                    }
                } elseif ($advertisingMedia->isNotEmpty()) {
                    $existingMediaKeys = $dateRows->pluck('media_source')
                        ->map(fn ($media) => mb_strtolower(trim((string) $media) ?: '其他'))
                        ->all();
                    foreach ($advertisingMedia as $media) {
                        if (! in_array(mb_strtolower($media), $existingMediaKeys, true)) {
                            $dateRows->push($this->emptyMetricRow(
                                (string) $rowDate,
                                (string) ($operator->name ?: $operator->username),
                                $media,
                            ));
                        }
                    }
                }
                $mediaMetrics = collect($this->metrics->mediaRoiSummary(
                    $dateRows->values()->all(),
                    $approvedReport?->advertising_rows ?: [],
                ))->keyBy(fn (array $row) => mb_strtolower(trim((string) $row['media_source'])));
                $revenueByMedia = $dateRows->groupBy(fn (array $row) => mb_strtolower(trim((string) ($row['media_source'] ?? '其他'))))
                    ->map(fn ($items) => (float) $items->sum('first_total_revenue'));
                $reportFirstRevenue = (float) $dateRows->sum('first_total_revenue');
                $reportFirstCost = (float) $dateRows->sum(fn (array $row) =>
                    (float) ($row['first_purchase_cost'] ?? 0)
                    + (float) ($row['first_shipping_cost'] ?? 0)
                    + (float) ($row['first_return_fee'] ?? 0)
                );
                $reportActualAdSpend = (float) ($approvedReport?->actual_ad_spend ?? 0);
                $reportFirstProfit = $reportFirstRevenue - $reportActualAdSpend - $reportFirstCost;
                $dailyMediaNames = $dateRows->pluck('media_source')
                    ->map(fn ($media) => trim((string) $media) ?: '其他')->unique()->values();
                $dailySummary = [
                    'daily_first_total_orders' => (int) $dateRows->sum('first_total_orders'),
                    'daily_first_total_revenue' => $reportFirstRevenue,
                    'daily_first_average_order_value' => (int) $dateRows->sum('first_total_orders') > 0
                        ? $reportFirstRevenue / (int) $dateRows->sum('first_total_orders')
                        : null,
                    'daily_first_total_cost' => $reportFirstCost,
                    'daily_first_loss_rate' => $reportFirstCost > 0 ? $reportFirstProfit / $reportFirstCost : null,
                    'daily_media_names' => $dailyMediaNames->all(),
                    'daily_media_order_counts' => $dailyMediaNames->map(function (string $media) use ($dateRows): int {
                        return (int) $dateRows
                            ->filter(fn (array $row) => mb_strtolower(trim((string) ($row['media_source'] ?? '其他'))) === mb_strtolower($media))
                            ->sum('first_total_orders');
                    })->all(),
                    'daily_media_ad_spends' => $dailyMediaNames->map(function (string $media) use ($mediaMetrics): float {
                        return (float) ($mediaMetrics->get(mb_strtolower($media))['actual_ad_spend'] ?? 0);
                    })->all(),
                    'daily_media_rois' => $dailyMediaNames->map(function (string $media) use ($mediaMetrics): ?float {
                        $metric = $mediaMetrics->get(mb_strtolower($media));
                        return isset($metric['first_roi']) ? (float) $metric['first_roi'] : null;
                    })->all(),
                    'daily_first_roi' => $reportActualAdSpend > 0 ? $reportFirstRevenue / $reportActualAdSpend : null,
                    'daily_renewal_orders' => (int) $dateRows->sum('renewal_orders'),
                    'daily_renewal_revenue' => (float) $dateRows->sum('renewal_revenue'),
                ];

                foreach ($dateRows as $row) {
                $mediaKey = mb_strtolower(trim((string) ($row['media_source'] ?? '其他')));
                $mediaSpend = (float) ($mediaMetrics->get($mediaKey)['actual_ad_spend'] ?? 0);
                $mediaRevenue = (float) ($revenueByMedia->get($mediaKey) ?? 0);
                $isMediaPlaceholder = trim((string) ($row['shop_domain'] ?? '')) === '—';
                $allocatedSpend = $mediaRevenue > 0
                    ? $mediaSpend * (float) ($row['first_total_revenue'] ?? 0) / $mediaRevenue
                    : ($isMediaPlaceholder ? $mediaSpend : 0.0);
                $totalCost = (float) ($row['first_purchase_cost'] ?? 0)
                    + (float) ($row['first_shipping_cost'] ?? 0)
                    + (float) ($row['first_return_fee'] ?? 0);
                $profit = (float) ($row['first_total_revenue'] ?? 0) - $allocatedSpend - $totalCost;
                $rows[] = array_merge($row, $dailySummary, [
                    '_report_id' => $operator->getKey().'|'.$rowDate,
                    'operator_user_id' => (int) $operator->getKey(),
                    'date' => (string) $rowDate,
                    'operator_name' => (string) ($operator->name ?: $operator->username),
                    'is_resigned' => (string) $operator->employment_status === 'resigned',
                    'hired_at' => $operator->hired_at?->format('Y-m-d'),
                    'operation_department_name' => (string) ($operationDepartment?->name ?: $report?->operation_department_name ?: '未填写部门'),
                    'operation_department_id' => (int) ($operationDepartment?->getKey() ?? $report?->operation_department_id ?? 0),
                    'team_position' => (string) ($operator->departments->firstWhere('id', $operationDepartment?->getKey())?->pivot?->position ?: Department::POSITION_MEMBER),
                    'media_roi' => $mediaMetrics->get($mediaKey)['first_roi'] ?? null,
                    'allocated_actual_ad_spend' => $allocatedSpend,
                    'first_total_cost' => $totalCost,
                    'first_profit' => $profit,
                    'first_loss_rate' => $totalCost > 0 ? $profit / $totalCost : null,
                    'submission_status' => $report?->status,
                    'submission_status_label' => $report?->statusLabel() ?? '未提报',
                    'report_actual_ad_spend' => $approvedReport ? (float) $approvedReport->actual_ad_spend : null,
                    'report_first_roi' => $approvedReport && (float) $approvedReport->actual_ad_spend > 0
                        ? $reportFirstRevenue / (float) $approvedReport->actual_ad_spend
                        : null,
                ]);
                }
            }
        }
        $rows = $this->aggregateRangeRows($rows, $dateFrom, $dateTo);
        $rows = array_map(function (array $row): array {
            $row['daily_first_roi_class'] = $this->roiClass($row['daily_first_roi'] ?? null);
            $row['daily_media_roi_classes'] = array_map(fn ($roi): string => $this->roiClass($roi), $row['daily_media_rois'] ?? []);
            return $row;
        }, $rows);
        $sortBy = $validated['sort_by'] ?? null;
        $sortDirection = (string) ($validated['sort_direction'] ?? 'desc');
        $displayMode = (string) ($validated['display_mode'] ?? 'performance');
        usort($rows, function (array $a, array $b) use ($sortBy, $sortDirection, $displayMode): int {
            if ($displayMode === 'team') {
                $rank = static fn (array $row): int => match ($row['team_position'] ?? Department::POSITION_MEMBER) {
                    Department::POSITION_MANAGER => 0,
                    Department::POSITION_DEPUTY => 1,
                    default => 2,
                };
                return [$a['operation_department_name'], $rank($a), $a['operator_name'], $a['shop_domain']]
                    <=> [$b['operation_department_name'], $rank($b), $b['operator_name'], $b['shop_domain']];
            }
            if ($sortBy !== null) {
                $valueKey = $sortBy === 'first_subscription_orders'
                    ? 'daily_first_subscription_orders'
                    : 'daily_first_total_orders';
                $comparison = ((int) ($a[$valueKey] ?? 0)) <=> ((int) ($b[$valueKey] ?? 0));
                if ($comparison !== 0) {
                    return $sortDirection === 'asc' ? $comparison : -$comparison;
                }
            }

            return [$a['operation_department_name'], $a['operator_name'], $a['shop_domain']]
                <=> [$b['operation_department_name'], $b['operator_name'], $b['shop_domain']];
        });

        $approvedAdSpend = (float) $allReports->where('status', OperationReport::STATUS_APPROVED)->sum('actual_ad_spend');
        $totals = $this->totals($rows, $approvedAdSpend);
        $totals['first_roi_class'] = $this->roiClass($totals['first_roi'] ?? null);

        $canManageDashboardScope = $this->isDashboard($context) && $this->isBoss($context['user']);
        $dashboardScopeUsers = collect();
        $scopeSelectedUserId = null;
        $scopeSelectedDepartmentIds = [];
        if ($canManageDashboardScope) {
            $dashboardScopeUsers = AdminUser::query()
                ->where('status', AdminUser::STATUS_ACTIVE)
                ->where('id', '!=', $context['user']->getKey())
                ->orderBy('name')->orderBy('username')->get()
                ->reject(fn (AdminUser $user) => $this->isBoss($user))->values();
            $scopeSelectedUserId = isset($validated['scope_user_id'])
                ? (int) $validated['scope_user_id']
                : (int) ($dashboardScopeUsers->first()?->getKey() ?? 0);
            abort_if($scopeSelectedUserId > 0 && ! $dashboardScopeUsers->contains('id', $scopeSelectedUserId), 403);
            $scopeSelectedDepartmentIds = $scopeSelectedUserId > 0
                ? OperationDashboardDepartmentScope::query()->where('user_id', $scopeSelectedUserId)
                    ->pluck('department_id')->map(fn ($id) => (int) $id)->all()
                : [];
        }

        return view('portals.assistant.operation-reports.index', [
            'portal' => $context['portal']->loadMissing('menus'),
            'user' => $context['user'],
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'dateRange' => $dateRange,
            'timezone' => $timezone,
            'timezoneOptions' => $this->timezoneOptions(),
            'departments' => $departments,
            'selectedDepartmentIds' => $selectedDepartmentIds->all(),
            'operatorOptions' => $operatorOptions,
            'selectedOperatorId' => $selectedOperatorId,
            'excludeResigned' => $excludeResigned,
            'sortBy' => $sortBy,
            'sortDirection' => $sortDirection,
            'displayMode' => $displayMode,
            'rows' => $rows,
            'totals' => $totals,
            'identityRowspans' => $this->identityRowspans($rows),
            'reportRowspans' => $this->reportRowspans($rows),
            'reportBands' => $this->reportBands($rows, $displayMode),
            'reportIndexUrl' => $this->isDashboard($context)
                ? route('operation-dashboard.operation-reports.index')
                : route('assistant.operation-reports.index'),
            'orderDetailsUrl' => $this->isDashboard($context)
                ? route('operation-dashboard.operation-reports.order-details')
                : route('assistant.operation-reports.order-details'),
            'canManageDashboardScope' => $canManageDashboardScope,
            'dashboardScopeUsers' => $dashboardScopeUsers,
            'scopeSelectedUserId' => $scopeSelectedUserId,
            'scopeSelectedDepartmentIds' => $scopeSelectedDepartmentIds,
            'dashboardScopeUpdateUrl' => $canManageDashboardScope
                ? route('operation-dashboard.operation-reports.department-scope.update')
                : null,
        ]);
    }

    public function updateDepartmentScope(Request $request): RedirectResponse|Response
    {
        $context = $this->context($request);
        if ($context instanceof Response) return $context;
        abort_unless($this->isDashboard($context) && $this->isBoss($context['user']), 403);

        $validated = $request->validate([
            'user_id' => ['required', 'integer'],
            'department_ids' => ['nullable', 'array'],
            'department_ids.*' => ['integer', 'distinct'],
        ]);
        $targetUser = AdminUser::query()->where('status', AdminUser::STATUS_ACTIVE)
            ->whereKey((int) $validated['user_id'])->firstOrFail();
        abort_if($this->isBoss($targetUser), 422, 'Boss 账号始终拥有全部部门权限。');

        $allDepartmentIds = $this->assistantStoreOwners->operationDepartments()
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
        $departmentIds = collect($validated['department_ids'] ?? [])->map(fn ($id) => (int) $id)->unique()->values();
        abort_unless($departmentIds->diff($allDepartmentIds)->isEmpty(), 422, '选择的部门不在可配置范围内。');

        DB::connection(config('admin.database.connection') ?: config('database.default'))
            ->transaction(function () use ($targetUser, $departmentIds, $context): void {
                OperationDashboardDepartmentScope::query()->where('user_id', $targetUser->getKey())->delete();
                $now = now();
                foreach ($departmentIds as $departmentId) {
                    OperationDashboardDepartmentScope::query()->create([
                        'user_id' => $targetUser->getKey(),
                        'department_id' => $departmentId,
                        'created_by' => $context['user']->getKey(),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            });

        return redirect()->route('operation-dashboard.operation-reports.index', ['scope_user_id' => $targetUser->getKey()])
            ->with('success', $departmentIds->isEmpty() ? '已设置为可查看全部运营部门。' : '账号可查看部门已保存。');
    }

    public function orderDetails(Request $request): Response|JsonResponse
    {
        $context = $this->context($request);
        if ($context instanceof Response) return $context;
        $validated = $request->validate([
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'timezone' => ['nullable', Rule::in(array_keys($this->timezoneOptions()))],
            'operator_user_id' => ['required', 'integer'],
            'type' => ['required', Rule::in(['first_subscription', 'first_normal', 'first_total', 'renewal', 'total'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', Rule::in([10, 20, 50])],
        ]);
        $visibleDepartmentIds = $this->visibleDepartmentIds($context);
        $canViewOperator = AdminUser::query()
            ->whereKey((int) $validated['operator_user_id'])
            ->whereHas('departments', fn ($query) => $query->whereIn('admin_roles.id', $visibleDepartmentIds))
            ->exists();
        abort_unless($canViewOperator, 403);

        return response()->json($this->metrics->orderDetailsRange(
            $validated['date_from'],
            $validated['date_to'],
            (int) $validated['operator_user_id'],
            $validated['type'],
            (int) ($validated['page'] ?? 1),
            (int) ($validated['per_page'] ?? 10),
            null,
            null,
            (string) ($validated['timezone'] ?? OperationReportMetricsService::REPORT_TIMEZONE),
        ));
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

    private function context(Request $request): array|Response
    {
        $context = $this->portalEntries->portalContext($request);
        if ($context instanceof Response) return $context;
        if ($this->isDashboard($context)) return $context;
        $this->authorization->assertAssistantPortal($context['portal']);
        abort_unless($this->authorization->isAssistantDepartmentMember($context['user']), 403);
        return $context;
    }

    private function isDashboard(array $context): bool
    {
        return (string) $context['portal']->code === self::DASHBOARD_PORTAL_CODE;
    }

    /** @return list<int> */
    private function visibleDepartmentIds(array $context): array
    {
        if (! $this->isDashboard($context)) {
            return $this->assistantStoreOwners->visibleOperationDepartmentIds($context['user']);
        }

        $allDepartmentIds = $this->assistantStoreOwners->operationDepartments()
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($this->isBoss($context['user'])) return $allDepartmentIds;

        $configuredIds = OperationDashboardDepartmentScope::query()
            ->where('user_id', $context['user']->getKey())
            ->pluck('department_id')->map(fn ($id) => (int) $id)->all();

        return $configuredIds === [] ? $allDepartmentIds : array_values(array_intersect($allDepartmentIds, $configuredIds));
    }

    private function isBoss(AdminUser $user): bool
    {
        return $user->isAdministrator() || strcasecmp((string) $user->username, 'kky_boss') === 0;
    }

    private function totals(array $rows, float $actualAdSpend): array
    {
        $fields = ['first_subscription_orders','first_subscription_revenue','first_normal_orders','first_normal_revenue','first_total_orders','first_total_revenue','first_purchase_cost','first_shipping_cost','first_return_fee','renewal_orders','renewal_revenue','renewal_cost','total_orders','total_revenue'];
        $rowCollection = collect($rows);
        $totals = collect($fields)->mapWithKeys(fn ($field) => [$field => (float) $rowCollection->sum($field)])->all();
        $totals['first_total_cost'] = $totals['first_purchase_cost'] + $totals['first_shipping_cost'] + $totals['first_return_fee'];
        $totals['actual_ad_spend'] = $actualAdSpend;
        $totals['first_profit'] = $totals['first_total_revenue'] - $actualAdSpend - $totals['first_total_cost'];
        $totals['first_loss_rate'] = $totals['first_total_cost'] > 0 ? $totals['first_profit'] / $totals['first_total_cost'] : null;
        // ROI must use revenue and ad spend from the same submitted operators. Mixing revenue
        // from operators without an approved ad report would inflate the combined ROI.
        $reportedFirstRevenue = (float) $rowCollection
            ->filter(fn (array $row): bool => (float) ($row['report_actual_ad_spend'] ?? 0) > 0)
            ->sum('first_total_revenue');
        $totals['first_roi'] = $actualAdSpend > 0 ? $reportedFirstRevenue / $actualAdSpend : null;
        $totals['first_average_order_value'] = $totals['first_total_orders'] > 0
            ? $totals['first_total_revenue'] / $totals['first_total_orders']
            : null;
        return $totals;
    }

    private function normalizeOperatorName(string $value): string
    {
        return mb_strtolower((string) preg_replace('/[\s　]+/u', '', trim($value)));
    }

    private function emptyMetricRow(string $date, string $operatorName, string $mediaSource = '—'): array
    {
        return [
            'date' => $date,
            'operator_name' => $operatorName,
            'shop_domain' => '—',
            'media_source' => $mediaSource,
            'first_subscription_orders' => 0,
            'first_subscription_revenue' => 0.0,
            'first_normal_orders' => 0,
            'first_normal_revenue' => 0.0,
            'first_total_orders' => 0,
            'first_total_revenue' => 0.0,
            'first_purchase_cost' => 0.0,
            'first_shipping_cost' => 0.0,
            'first_return_fee' => 0.0,
            'renewal_orders' => 0,
            'renewal_revenue' => 0.0,
            'renewal_cost' => 0.0,
            'total_orders' => 0,
            'total_revenue' => 0.0,
        ];
    }

    /** @param list<array<string,mixed>> $rows
     *  @return list<array<string,mixed>>
     */
    private function aggregateRangeRows(array $rows, string $dateFrom, string $dateTo): array
    {
        $sumFields = [
            'first_subscription_orders', 'first_subscription_revenue',
            'first_normal_orders', 'first_normal_revenue',
            'first_total_orders', 'first_total_revenue',
            'first_purchase_cost', 'first_shipping_cost', 'first_return_fee',
            'renewal_orders', 'renewal_revenue', 'renewal_cost',
            'total_orders', 'total_revenue',
        ];

        return collect($rows)->groupBy('operator_user_id')->flatMap(function ($operatorRows) use ($sumFields, $dateFrom, $dateTo) {
            $first = $operatorRows->first();
            $row = collect($sumFields)->mapWithKeys(
                fn (string $field) => [$field => (float) $operatorRows->sum($field)]
            )->all();
            $reportRows = $operatorRows->unique('_report_id');
            $hasApprovedReport = $reportRows->contains(
                fn (array $item) => ($item['report_actual_ad_spend'] ?? null) !== null
            );
            $actualAdSpend = (float) $reportRows->sum(fn (array $item) => (float) ($item['report_actual_ad_spend'] ?? 0));
            $firstCost = (float) $row['first_purchase_cost']
                + (float) $row['first_shipping_cost']
                + (float) $row['first_return_fee'];
            $firstProfit = (float) $row['first_total_revenue'] - $actualAdSpend - $firstCost;
            $mediaRows = $operatorRows->groupBy(fn (array $item) => mb_strtolower(trim((string) ($item['media_source'] ?? '其他'))));
            $mediaNames = [];
            $mediaOrderCounts = [];
            $mediaAdSpends = [];
            $mediaRois = [];
            foreach ($mediaRows as $items) {
                $mediaName = trim((string) ($items->first()['media_source'] ?? '')) ?: '其他';
                if ($mediaName === '—') continue;
                $mediaRevenue = (float) $items->sum('first_total_revenue');
                $mediaSpend = (float) $items->sum('allocated_actual_ad_spend');
                $mediaNames[] = $mediaName;
                $mediaOrderCounts[] = (int) $items->sum('first_total_orders');
                $mediaAdSpends[] = $mediaSpend;
                $mediaRois[] = $mediaSpend > 0 ? $mediaRevenue / $mediaSpend : null;
            }
            $shopDomains = $operatorRows->pluck('shop_domain')
                ->map(fn ($domain) => trim((string) $domain))
                ->filter(fn (string $domain) => $domain !== '' && $domain !== '—')
                ->unique(fn (string $domain) => mb_strtolower($domain))
                ->sort()->values()->all();

            $calculationDays = null;
            if (! empty($first['hired_at'])) {
                $calculationStart = Carbon::parse($first['hired_at'])->startOfDay();
                $calculationEnd = Carbon::parse($dateTo)->startOfDay()->min(today());
                $calculationDays = $calculationStart->lte($calculationEnd)
                    ? $calculationStart->diffInDays($calculationEnd) + 1
                    : 0;
            }

            $shared = [
                '_report_id' => (int) $first['operator_user_id'].'|'.$dateFrom.'|'.$dateTo,
                'operator_user_id' => (int) $first['operator_user_id'],
                'date' => $dateFrom === $dateTo ? $dateFrom : $dateFrom.' 至 '.$dateTo,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'operator_name' => (string) $first['operator_name'],
                'is_resigned' => (bool) ($first['is_resigned'] ?? false),
                'hired_at' => $first['hired_at'] ?? null,
                'calculation_days' => $calculationDays,
                'operation_department_name' => (string) $first['operation_department_name'],
                'operation_department_id' => (int) ($first['operation_department_id'] ?? 0),
                'team_position' => (string) ($first['team_position'] ?? Department::POSITION_MEMBER),
                'shop_domains' => $shopDomains,
                'daily_first_total_orders' => (int) $row['first_total_orders'],
                'daily_first_subscription_orders' => (int) $row['first_subscription_orders'],
                'daily_first_total_revenue' => (float) $row['first_total_revenue'],
                'daily_first_average_order_value' => (int) $row['first_total_orders'] > 0
                    ? (float) $row['first_total_revenue'] / (int) $row['first_total_orders']
                    : null,
                'daily_first_total_cost' => $firstCost,
                'daily_first_loss_rate' => $firstCost > 0 ? $firstProfit / $firstCost : null,
                'daily_media_names' => $mediaNames,
                'daily_media_order_counts' => $mediaOrderCounts,
                'daily_media_ad_spends' => $mediaAdSpends,
                'daily_media_rois' => $mediaRois,
                'daily_first_roi' => $actualAdSpend > 0 ? (float) $row['first_total_revenue'] / $actualAdSpend : null,
                'daily_renewal_orders' => (int) $row['renewal_orders'],
                'daily_renewal_revenue' => (float) $row['renewal_revenue'],
                'submission_status' => $hasApprovedReport ? OperationReport::STATUS_APPROVED : null,
                'submission_status_label' => $hasApprovedReport ? '已提报' : '未提报',
                'report_actual_ad_spend' => $actualAdSpend,
                'report_first_roi' => $actualAdSpend > 0 ? (float) $row['first_total_revenue'] / $actualAdSpend : null,
            ];

            $storeRows = $operatorRows->filter(function (array $item): bool {
                $domain = trim((string) ($item['shop_domain'] ?? ''));

                return $domain !== '' && ! in_array($domain, ['—', '未知店铺'], true);
            });

            return $storeRows
                ->groupBy(fn (array $item) => mb_strtolower(trim((string) ($item['shop_domain'] ?? '—'))))
                ->map(function ($domainRows) use ($sumFields, $shared): array {
                    $domainFirst = $domainRows->first();
                    $domainMetrics = collect($sumFields)->mapWithKeys(
                        fn (string $field) => [$field => (float) $domainRows->sum($field)]
                    )->all();
                    $domainSpend = (float) $domainRows->sum('allocated_actual_ad_spend');
                    $domainCost = (float) $domainMetrics['first_purchase_cost']
                        + (float) $domainMetrics['first_shipping_cost']
                        + (float) $domainMetrics['first_return_fee'];
                    $domainMediaRows = $domainRows->groupBy(
                        fn (array $item) => mb_strtolower(trim((string) ($item['media_source'] ?? '其他')))
                    );
                    $domainMediaNames = [];
                    $domainMediaOrderCounts = [];
                    $domainMediaRois = [];
                    foreach ($domainMediaRows as $mediaItems) {
                        $mediaName = trim((string) ($mediaItems->first()['media_source'] ?? '')) ?: '其他';
                        if ($mediaName === '—') continue;
                        $mediaRevenue = (float) $mediaItems->sum('first_total_revenue');
                        $mediaSpend = (float) $mediaItems->sum('allocated_actual_ad_spend');
                        $domainMediaNames[] = $mediaName;
                        $domainMediaOrderCounts[] = (int) $mediaItems->sum('first_total_orders');
                        $domainMediaRois[] = $mediaSpend > 0 ? $mediaRevenue / $mediaSpend : null;
                    }

                    return array_merge($domainMetrics, $shared, [
                        'shop_domain' => trim((string) ($domainFirst['shop_domain'] ?? '')) ?: '—',
                        'media_source' => '汇总',
                        'media_roi' => null,
                        'allocated_actual_ad_spend' => $domainSpend,
                        'first_total_cost' => $domainCost,
                        'first_profit' => null,
                        'first_loss_rate' => null,
                        'store_media_names' => $domainMediaNames,
                        'store_media_order_counts' => $domainMediaOrderCounts,
                        'store_media_rois' => $domainMediaRois,
                        'store_first_roi' => $domainSpend > 0
                            ? (float) $domainMetrics['first_total_revenue'] / $domainSpend
                            : null,
                    ]);
                })
                ->sortBy('shop_domain', SORT_NATURAL | SORT_FLAG_CASE)
                ->values();
        })->values()->all();
    }

    private function roiClass(?float $roi): string
    {
        if ($roi === null) return '';
        if ($roi > 1.6) return 'roi-excellent';
        if ($roi >= 1.3) return 'roi-good';
        if ($roi >= 1.1) return 'roi-warning';
        return 'roi-danger';
    }

    private function identityRowspans(array $rows): array
    {
        return $this->rowspans($rows, fn (array $row) => implode("\x1F", [$row['_report_id'], $row['shop_domain']]));
    }

    private function reportRowspans(array $rows): array
    {
        return $this->rowspans($rows, fn (array $row) => (string) $row['_report_id']);
    }

    private function reportBands(array $rows, string $displayMode = 'performance'): array
    {
        $bands = [];
        $lastGroup = null;
        $band = 1;
        foreach ($rows as $index => $row) {
            $group = $displayMode === 'team'
                ? 'department:'.((int) ($row['operation_department_id'] ?? 0)).'|'.(string) ($row['operation_department_name'] ?? '')
                : (string) $row['_report_id'];
            if ($group !== $lastGroup) {
                $band = $band === 0 ? 1 : 0;
                $lastGroup = $group;
            }
            $bands[$index] = $band;
        }

        return $bands;
    }

    private function rowspans(array $rows, callable $keyFor): array
    {
        $spans = [];
        for ($index = 0, $count = count($rows); $index < $count; $index++) {
            $key = $keyFor($rows[$index]);
            if ($index > 0 && $key === $keyFor($rows[$index - 1])) { $spans[$index] = 0; continue; }
            $span = 1;
            while ($index + $span < $count && $key === $keyFor($rows[$index + $span])) $span++;
            $spans[$index] = $span;
        }
        return $spans;
    }
}
