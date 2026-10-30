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
            'period_type' => ['nullable', Rule::in(['day', 'month'])],
            'report_date' => ['nullable', 'date_format:Y-m-d'],
            'report_month' => ['nullable', 'date_format:Y-m'],
            'operation_department_id' => ['nullable', 'integer'],
        ]);
        $periodType = (string) ($validated['period_type'] ?? 'month');
        $beijingNow = Carbon::now('Asia/Shanghai');
        if ($periodType === 'day') {
            $reportDate = (string) ($validated['report_date'] ?? $beijingNow->format('Y-m-d'));
            $dateFrom = $dateTo = $reportDate;
            $reportMonth = $beijingNow->format('Y-m');
        } else {
            $reportMonth = (string) ($validated['report_month'] ?? $beijingNow->format('Y-m'));
            $month = Carbon::createFromFormat('Y-m-d', $reportMonth.'-01', 'Asia/Shanghai');
            $dateFrom = $month->copy()->startOfMonth()->format('Y-m-d');
            $dateTo = $month->copy()->endOfMonth()->format('Y-m-d');
            $reportDate = $beijingNow->format('Y-m-d');
        }

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
        $reportsByOperatorDate = $allReports->keyBy(
            fn (OperationReport $report) => $report->operator_user_id.'|'.$report->report_date->format('Y-m-d')
        );
        $operatorDepartmentIds = $selectedDepartmentId !== null ? [$selectedDepartmentId] : $visibleDepartmentIds;
        $operators = AdminUser::query()
            ->where('status', AdminUser::STATUS_ACTIVE)
            ->whereHas('departments', fn ($query) => $query->whereIn('admin_roles.id', $operatorDepartmentIds))
            ->orderBy('name')->orderBy('username')
            ->get()
            ->unique('id')->values();
        $operatorIds = $operators->pluck('id')->map(fn ($id) => (int) $id)->values();
        $cacheKey = 'assistant-operation-report:'.$dateFrom.':'.$dateTo.':'.sha1($operatorIds->implode(','));
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
            $operationDepartment = $this->assistantStoreOwners->operationDepartmentForUser($operator);
            foreach ($calculatedRows->groupBy('date') as $rowDate => $dateRows) {
                $report = $reportsByOperatorDate->get($operator->getKey().'|'.$rowDate);
                $approvedReport = $report?->status === OperationReport::STATUS_APPROVED ? $report : null;
                $mediaMetrics = collect($this->metrics->mediaRoiSummary(
                    $dateRows->values()->all(),
                    $approvedReport?->advertising_rows ?: [],
                ))->keyBy(fn (array $row) => mb_strtolower(trim((string) $row['media_source'])));
                $revenueByMedia = $dateRows->groupBy(fn (array $row) => mb_strtolower(trim((string) ($row['media_source'] ?? '其他'))))
                    ->map(fn ($items) => (float) $items->sum('first_total_revenue'));
                $reportFirstRevenue = (float) $dateRows->sum('first_total_revenue');

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
                $rows[] = array_merge($row, [
                    '_report_id' => $operator->getKey().'|'.$rowDate,
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
            'periodType' => $periodType,
            'reportDate' => $reportDate,
            'reportMonth' => $reportMonth,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'departments' => $departments,
            'selectedDepartmentId' => $selectedDepartmentId,
            'rows' => $rows,
            'totals' => $totals,
            'identityRowspans' => $this->identityRowspans($rows),
            'reportRowspans' => $this->reportRowspans($rows),
        ]);
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
