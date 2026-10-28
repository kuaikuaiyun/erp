<?php

namespace App\Portals\Assistant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\OperationReport;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\Assistant\AssistantPortalAuthorizationService;
use App\Portals\Operation\OperationReportMetricsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
    ) {
    }

    public function index(Request $request): Response|View
    {
        $context = $this->context($request);
        if ($context instanceof Response) return $context;

        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['', OperationReport::STATUS_PENDING, OperationReport::STATUS_APPROVED])],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'operator' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', Rule::in([20, 50, 100])],
        ]);
        $status = trim((string) ($filters['status'] ?? ''));
        $operator = trim((string) ($filters['operator'] ?? ''));
        $perPage = (int) ($filters['per_page'] ?? 20);

        $reports = OperationReport::query()
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
            'portal' => $context['portal']->load('menus'),
            'user' => $context['user'],
            'reports' => $reports,
            'pendingCount' => OperationReport::query()->where('status', OperationReport::STATUS_PENDING)->count(),
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
        $rows = $report->metric_rows ?: [];

        return view('portals.operation.reports.show', [
            'portal' => $context['portal']->load('menus'), 'user' => $context['user'], 'report' => $report,
            'metricRows' => $rows, 'metricTotals' => $this->sumRows($rows),
            'metricRowspans' => $this->identityRowspans($rows),
            'advertisingRows' => $report->advertising_rows ?: [],
            'mediaRoiRows' => $this->metrics->mediaRoiSummary($rows, $report->advertising_rows ?: []),
            'canReview' => true, 'canEdit' => false,
            'backUrl' => route('assistant.operation-report-reviews.index'),
            'approveUrl' => route('assistant.operation-report-reviews.approve', $report),
            'screenshotRouteName' => 'assistant.operation-report-reviews.screenshot',
        ]);
    }

    public function approve(Request $request, OperationReport $report): RedirectResponse
    {
        $context = $this->context($request);
        if ($context instanceof Response) abort($context->getStatusCode());
        abort_unless($report->status === OperationReport::STATUS_PENDING, 422, '该报表已经审核，请勿重复操作。');
        $report->update([
            'status' => OperationReport::STATUS_APPROVED,
            'reviewed_by' => $context['user']->getKey(),
            'reviewed_at' => now(),
        ]);

        return redirect()->route('assistant.operation-report-reviews.index')->with('success', '运营报表已审核通过。');
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

    private function context(Request $request): array|Response
    {
        $context = $this->portalEntries->portalContext($request);
        if ($context instanceof Response) return $context;
        $this->authorization->assertAssistantPortal($context['portal']);
        abort_unless($this->authorization->isAssistantDepartmentMember($context['user']), 403);
        return $context;
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
