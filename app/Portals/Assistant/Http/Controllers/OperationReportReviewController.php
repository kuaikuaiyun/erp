<?php

namespace App\Portals\Assistant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\OperationReport;
use App\Models\AdminUser;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\Assistant\AssistantPortalAuthorizationService;
use App\Portals\Assistant\AssistantStoreOwnerService;
use App\Portals\Operation\OperationReportMetricsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class OperationReportReviewController extends Controller
{
    public function __construct(
        private readonly PortalEntryController $portalEntries,
        private readonly AssistantPortalAuthorizationService $authorization,
        private readonly OperationReportMetricsService $metrics,
        private readonly AssistantStoreOwnerService $assistantStoreOwners,
    ) {
    }

    public function index(Request $request): Response|View
    {
        $context = $this->context($request);
        if ($context instanceof Response) return $context;

        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['', OperationReport::STATUS_PENDING, OperationReport::STATUS_APPROVED, OperationReport::STATUS_REJECTED])],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'operator' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', Rule::in([20, 50, 100])],
        ]);
        $status = trim((string) ($filters['status'] ?? ''));
        $operator = trim((string) ($filters['operator'] ?? ''));
        $perPage = (int) ($filters['per_page'] ?? 20);

        $reportsQuery = $this->scopeReports(OperationReport::query(), $context['user']);
        $reports = $reportsQuery
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->when(!empty($filters['date_from']), fn ($query) => $query->whereDate('report_date', '>=', $filters['date_from']))
            ->when(!empty($filters['date_to']), fn ($query) => $query->whereDate('report_date', '<=', $filters['date_to']))
            ->when($operator !== '', fn ($query) => $query->where('operator_name', 'like', '%'.$operator.'%'))
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->orderByDesc('report_date')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        return view('portals.assistant.operation-report-reviews.index', [
            'portal' => $context['portal']->loadMissing('menus'),
            'user' => $context['user'],
            'reports' => $reports,
            'pendingCount' => $this->scopeReports(OperationReport::query(), $context['user'])
                ->where('status', OperationReport::STATUS_PENDING)->count(),
            'filters' => [
                'status' => $status,
                'date_from' => (string) ($filters['date_from'] ?? ''),
                'date_to' => (string) ($filters['date_to'] ?? ''),
                'operator' => $operator,
                'per_page' => $perPage,
            ],
        ]);
    }

    public function show(Request $request, OperationReport $report): Response|View
    {
        $context = $this->context($request);
        if ($context instanceof Response) return $context;
        abort_unless($this->canAccessReport($report, $context['user']), 403);
        $rows = $report->metric_rows ?: [];

        return view('portals.operation.reports.show', [
            'portal' => $context['portal']->loadMissing('menus'), 'user' => $context['user'], 'report' => $report,
            'metricRows' => $rows, 'metricTotals' => $this->sumRows($rows),
            'metricRowspans' => $this->identityRowspans($rows),
            'advertisingRows' => $report->advertising_rows ?: [],
            'mediaRoiRows' => $this->metrics->mediaRoiSummary($rows, $report->advertising_rows ?: []),
            'canReview' => true, 'canEdit' => false,
            'backUrl' => route('assistant.operation-report-reviews.index'),
            'approveUrl' => route('assistant.operation-report-reviews.approve', $report),
            'rejectUrl' => route('assistant.operation-report-reviews.reject', $report),
            'screenshotRouteName' => 'assistant.operation-report-reviews.screenshot',
        ]);
    }

    public function approve(Request $request, OperationReport $report): RedirectResponse
    {
        $context = $this->context($request);
        if ($context instanceof Response) abort($context->getStatusCode());
        abort_unless($this->canAccessReport($report, $context['user']), 403);
        abort_unless($report->status === OperationReport::STATUS_PENDING, 422, '该报表已经审核，请勿重复操作。');
        $report->update([
            'status' => OperationReport::STATUS_APPROVED,
            'reviewed_by' => $context['user']->getKey(),
            'reviewed_at' => now(),
            'review_reason' => null,
        ]);

        return redirect()->route('assistant.operation-report-reviews.index')->with('success', '运营报表已审核通过。');
    }

    public function reject(Request $request, OperationReport $report): RedirectResponse
    {
        $context = $this->context($request);
        if ($context instanceof Response) abort($context->getStatusCode());
        abort_unless($this->canAccessReport($report, $context['user']), 403);
        abort_unless($report->status === OperationReport::STATUS_PENDING, 422, '只有待审核报表可以审核不通过。');
        $validated = $request->validate([
            'review_reason' => ['required', 'string', 'max:1000'],
        ]);
        $report->update([
            'status' => OperationReport::STATUS_REJECTED,
            'reviewed_by' => $context['user']->getKey(),
            'reviewed_at' => now(),
            'review_reason' => trim($validated['review_reason']),
        ]);

        return redirect()->route('assistant.operation-report-reviews.index')->with('success', '运营报表已审核不通过。');
    }

    public function bulkReview(Request $request): RedirectResponse
    {
        $context = $this->context($request);
        if ($context instanceof Response) abort($context->getStatusCode());
        $validated = $request->validate([
            'report_ids' => ['required', 'array', 'min:1', 'max:100'],
            'report_ids.*' => ['required', 'integer', 'distinct'],
            'review_action' => ['required', Rule::in([OperationReport::STATUS_APPROVED, OperationReport::STATUS_REJECTED])],
            'review_reason' => [Rule::requiredIf($request->input('review_action') === OperationReport::STATUS_REJECTED), 'nullable', 'string', 'max:1000'],
        ], [
            'report_ids.required' => '请至少选择一条待审核报表。',
            'report_ids.min' => '请至少选择一条待审核报表。',
            'review_reason.required' => '批量审核不通过时必须填写原因。',
        ]);
        $reportIds = collect($validated['report_ids'])->map(fn ($id) => (int) $id)->unique()->values();
        $accessibleQuery = $this->scopeReports(OperationReport::query(), $context['user'])->whereIn('id', $reportIds);
        abort_unless((clone $accessibleQuery)->count() === $reportIds->count(), 403, '选择的报表中包含当前助理部门无权审核的数据。');
        abort_unless((clone $accessibleQuery)->where('status', OperationReport::STATUS_PENDING)->count() === $reportIds->count(), 422, '选择的报表中包含已审核记录，请刷新页面后重新选择。');

        $action = $validated['review_action'];
        $updated = $accessibleQuery->where('status', OperationReport::STATUS_PENDING)->update([
            'status' => $action,
            'reviewed_by' => $context['user']->getKey(),
            'reviewed_at' => now(),
            'review_reason' => $action === OperationReport::STATUS_REJECTED
                ? trim((string) $validated['review_reason'])
                : null,
            'updated_at' => now(),
        ]);

        $label = $action === OperationReport::STATUS_APPROVED ? '审核通过' : '审核不通过';
        return redirect()->route('assistant.operation-report-reviews.index')
            ->with('success', '已批量将 '.$updated.' 份运营报表标记为'.$label.'。');
    }

    public function screenshot(Request $request): Response
    {
        $context = $this->context($request);
        if ($context instanceof Response) return $context;
        $validated = $request->validate([
            'report_id' => ['required', 'integer'],
            'path' => ['required', 'string', 'max:500', 'regex:/^operation-reports\/ads\/[A-Za-z0-9._-]+$/'],
        ]);
        $report = OperationReport::query()->findOrFail((int) $validated['report_id']);
        abort_unless($this->canAccessReport($report, $context['user']), 403);
        $belongsToReport = collect($report->advertising_rows ?: [])->contains(
            static fn (array $row): bool => (string) ($row['screenshot'] ?? '') === $validated['path'],
        );
        abort_unless($belongsToReport, 404);
        abort_unless(Storage::disk('public')->exists($validated['path']), 404);

        return Storage::disk('public')->response($validated['path']);
    }

    private function context(Request $request): array|Response
    {
        $context = $this->portalEntries->portalContext($request);
        if ($context instanceof Response) return $context;
        $this->authorization->assertAssistantPortal($context['portal']);
        abort_unless($this->authorization->isAssistantDepartmentMember($context['user']), 403);
        return $context;
    }

    /** @param Builder<OperationReport> $query
     *  @return Builder<OperationReport>
     */
    private function scopeReports(Builder $query, AdminUser $user): Builder
    {
        if ($user->isAdministrator()) return $query;

        $departmentIds = $this->assistantStoreOwners->visibleOperationDepartmentIds($user);

        return $departmentIds === []
            ? $query->whereRaw('1 = 0')
            : $query->whereIn('operation_department_id', $departmentIds);
    }

    private function canAccessReport(OperationReport $report, AdminUser $user): bool
    {
        return $user->isAdministrator()
            || in_array((int) $report->operation_department_id, $this->assistantStoreOwners->visibleOperationDepartmentIds($user), true);
    }

    private function sumRows(array $rows): array
    {
        $fields = ['first_subscription_orders','first_subscription_revenue','first_normal_orders','first_normal_revenue','first_total_orders','first_total_revenue','first_purchase_cost','first_shipping_cost','first_return_fee','renewal_orders','renewal_revenue','renewal_cost','total_orders','total_revenue'];
        return collect($fields)->mapWithKeys(fn ($field) => [$field => collect($rows)->sum($field)])->all();
    }

    private function identityRowspans(array $rows): array
    {
        $spans = [];
        for ($index = 0, $count = count($rows); $index < $count; $index++) {
            $key = [$rows[$index]['date'], $rows[$index]['operator_name'], $rows[$index]['shop_domain']];
            if ($index > 0 && $key === [$rows[$index - 1]['date'], $rows[$index - 1]['operator_name'], $rows[$index - 1]['shop_domain']]) { $spans[$index] = 0; continue; }
            $span = 1;
            while ($index + $span < $count && $key === [$rows[$index + $span]['date'], $rows[$index + $span]['operator_name'], $rows[$index + $span]['shop_domain']]) $span++;
            $spans[$index] = $span;
        }
        return $spans;
    }
}
