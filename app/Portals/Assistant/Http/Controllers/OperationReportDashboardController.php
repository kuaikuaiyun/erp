<?php

namespace App\Portals\Assistant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\AdminUser;
use App\Models\OperationReport;
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
use Symfony\Component\HttpFoundation\Response;

class OperationReportDashboardController extends Controller
{
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
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'operation_department_id' => ['nullable', 'integer'],
            'operator_user_id' => ['nullable', 'integer'],
        ]);
        $reportNow = Carbon::now(OperationReportMetricsService::REPORT_TIMEZONE);
        $dateFrom = (string) ($validated['date_from'] ?? $reportNow->copy()->startOfMonth()->format('Y-m-d'));
        $dateTo = (string) ($validated['date_to'] ?? $reportNow->copy()->endOfMonth()->format('Y-m-d'));
        abort_if($dateFrom > $dateTo, 422, '开始日期不能晚于结束日期。');

        $visibleDepartmentIds = $this->assistantStoreOwners->visibleOperationDepartmentIds($context['user']);
        $departments = Department::business()->whereIn('id', $visibleDepartmentIds)
            ->orderBy('sort')->orderBy('name')->get();
        $selectedDepartmentId = isset($validated['operation_department_id'])
            ? (int) $validated['operation_department_id']
            : null;
        if ($selectedDepartmentId !== null) {
            abort_unless(in_array($selectedDepartmentId, $visibleDepartmentIds, true), 403);
        }

        $allReports = OperationReport::query()
            ->whereIn('operation_department_id', $visibleDepartmentIds)
            ->when($selectedDepartmentId !== null, fn ($query) => $query->where('operation_department_id', $selectedDepartmentId))
            ->whereBetween('report_date', [$dateFrom, $dateTo])
            ->orderBy('id')
            ->get();
        $operatorDepartmentIds = $selectedDepartmentId !== null ? [$selectedDepartmentId] : $visibleDepartmentIds;
        $operators = AdminUser::query()
            ->where('status', AdminUser::STATUS_ACTIVE)
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
        $cacheKey = 'assistant-operation-report:v2:'.$dateFrom.':'.$dateTo.':'.sha1($operatorIds->implode(','));
        $calculatedByOperator = Cache::remember($cacheKey, now()->addMinutes(5), function () use ($operatorIds, $dateFrom, $dateTo): array {
            $calculated = [];
            // 分批读取，避免整月订单及商品明细一次载入导致 PHP 内存溢出。
            foreach ($operatorIds->chunk(2) as $operatorIdBatch) {
                $calculated += $this->metrics->buildRangeForOperators($dateFrom, $dateTo, $operatorIdBatch->values()->all());
                gc_collect_cycles();
            }
            return $calculated;
        });

        $rows = [];
        foreach ($operators as $operator) {
            $calculatedRows = collect($calculatedByOperator[(int) $operator->getKey()]['rows'] ?? []);
            $operationDepartment = $operatorDepartments->get((int) $operator->getKey());
            foreach ($calculatedRows->groupBy('date') as $rowDate => $dateRows) {
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
                    'daily_first_total_cost' => $reportFirstCost,
                    'daily_first_loss_rate' => $reportFirstCost > 0 ? $reportFirstProfit / $reportFirstCost : null,
                    'daily_media_names' => $dailyMediaNames->all(),
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
                $allocatedSpend = $mediaRevenue > 0
                    ? $mediaSpend * (float) ($row['first_total_revenue'] ?? 0) / $mediaRevenue
                    : 0.0;
                $totalCost = (float) ($row['first_purchase_cost'] ?? 0)
                    + (float) ($row['first_shipping_cost'] ?? 0)
                    + (float) ($row['first_return_fee'] ?? 0);
                $profit = (float) ($row['first_total_revenue'] ?? 0) - $allocatedSpend - $totalCost;
                $rows[] = array_merge($row, $dailySummary, [
                    '_report_id' => $operator->getKey().'|'.$rowDate,
                    'operator_user_id' => (int) $operator->getKey(),
                    'date' => (string) $rowDate,
                    'operator_name' => (string) ($operator->name ?: $operator->username),
                    'operation_department_name' => (string) ($operationDepartment?->name ?: $report?->operation_department_name ?: '未填写部门'),
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
        usort($rows, fn (array $a, array $b) => [$a['date'], $a['operation_department_name'], $a['operator_name'], $a['shop_domain'], $a['media_source']] <=> [$b['date'], $b['operation_department_name'], $b['operator_name'], $b['shop_domain'], $b['media_source']]);

        $approvedAdSpend = (float) $allReports->where('status', OperationReport::STATUS_APPROVED)->sum('actual_ad_spend');
        $totals = $this->totals($rows, $approvedAdSpend);

        return view('portals.assistant.operation-reports.index', [
            'portal' => $context['portal']->loadMissing('menus'),
            'user' => $context['user'],
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'departments' => $departments,
            'selectedDepartmentId' => $selectedDepartmentId,
            'operatorOptions' => $operatorOptions,
            'selectedOperatorId' => $selectedOperatorId,
            'rows' => $rows,
            'totals' => $totals,
            'identityRowspans' => $this->identityRowspans($rows),
            'reportRowspans' => $this->reportRowspans($rows),
        ]);
    }

    public function orderDetails(Request $request): Response|JsonResponse
    {
        $context = $this->context($request);
        if ($context instanceof Response) return $context;
        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'operator_user_id' => ['required', 'integer'],
            'shop_domain' => ['required', 'string', 'max:255'],
            'media_source' => ['required', 'string', 'max:100'],
            'type' => ['required', Rule::in(['first_subscription', 'first_normal', 'first_total', 'renewal', 'total'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', Rule::in([10, 20, 50])],
        ]);
        $visibleDepartmentIds = $this->assistantStoreOwners->visibleOperationDepartmentIds($context['user']);
        $canViewOperator = AdminUser::query()
            ->whereKey((int) $validated['operator_user_id'])
            ->whereHas('departments', fn ($query) => $query->whereIn('admin_roles.id', $visibleDepartmentIds))
            ->exists();
        abort_unless($canViewOperator, 403);

        return response()->json($this->metrics->orderDetails(
            $validated['date'],
            (int) $validated['operator_user_id'],
            trim($validated['shop_domain']),
            trim($validated['media_source']),
            $validated['type'],
            (int) ($validated['page'] ?? 1),
            (int) ($validated['per_page'] ?? 10),
        ));
    }

    private function context(Request $request): array|Response
    {
        $context = $this->portalEntries->portalContext($request);
        if ($context instanceof Response) return $context;
        $this->authorization->assertAssistantPortal($context['portal']);
        abort_unless($this->authorization->isAssistantDepartmentMember($context['user']), 403);
        return $context;
    }

    private function totals(array $rows, float $actualAdSpend): array
    {
        $fields = ['first_subscription_orders','first_subscription_revenue','first_normal_orders','first_normal_revenue','first_total_orders','first_total_revenue','first_purchase_cost','first_shipping_cost','first_return_fee','renewal_orders','renewal_revenue','renewal_cost','total_orders','total_revenue'];
        $totals = collect($fields)->mapWithKeys(fn ($field) => [$field => (float) collect($rows)->sum($field)])->all();
        $totals['first_total_cost'] = $totals['first_purchase_cost'] + $totals['first_shipping_cost'] + $totals['first_return_fee'];
        $totals['actual_ad_spend'] = $actualAdSpend;
        $totals['first_profit'] = $totals['first_total_revenue'] - $actualAdSpend - $totals['first_total_cost'];
        $totals['first_loss_rate'] = $totals['first_total_cost'] > 0 ? $totals['first_profit'] / $totals['first_total_cost'] : null;
        $totals['first_roi'] = $actualAdSpend > 0 ? $totals['first_total_revenue'] / $actualAdSpend : null;
        return $totals;
    }

    private function normalizeOperatorName(string $value): string
    {
        return mb_strtolower((string) preg_replace('/[\s　]+/u', '', trim($value)));
    }

    private function identityRowspans(array $rows): array
    {
        return $this->rowspans($rows, fn (array $row) => implode("\x1F", [$row['_report_id'], $row['shop_domain']]));
    }

    private function reportRowspans(array $rows): array
    {
        return $this->rowspans($rows, fn (array $row) => (string) $row['_report_id']);
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
