<?php

namespace App\Portals\Assistant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\AssistantDomainStore;
use App\Models\Department;
use App\Models\FashionAdvertisingReport;
use App\Models\FashionLogisticsOrder;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\Assistant\AssistantPortalAuthorizationService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class FashionAdvertisingReviewController extends Controller
{
    public function __construct(
        private readonly PortalEntryController $portalEntries,
        private readonly AssistantPortalAuthorizationService $authorization,
    ) {}

    public function index(Request $request): Response|View
    {
        $context = $this->context($request);
        if ($context instanceof Response) {
            return $context;
        }

        $filters = [
            'status' => in_array($request->input('status'), ['pending', 'approved', 'rejected', 'deleted'], true) ? (string) $request->input('status') : '',
            'from' => $this->date($request->input('from')),
            'to' => $this->date($request->input('to')),
            'department_id' => max(0, (int) $request->input('department_id')),
            'submitter_id' => max(0, (int) $request->input('submitter_id')),
            'per_page' => in_array((int) $request->input('per_page'), [20, 50, 100], true) ? (int) $request->input('per_page') : 20,
        ];

        $reportSubmitterIds = FashionAdvertisingReport::withTrashed()
            ->whereNotNull('submitter_id')
            ->distinct()
            ->pluck('submitter_id');
        $submitters = AdminUser::query()
            ->whereIn('id', $reportSubmitterIds)
            ->orderBy('name')
            ->get(['id', 'name', 'username']);
        $departments = Department::business()
            ->whereHas('departmentMembers', fn ($query) => $query->whereIn('admin_users.id', $reportSubmitterIds))
            ->orderBy('sort')
            ->orderBy('name')
            ->get(['id', 'name']);

        $reports = FashionAdvertisingReport::withTrashed()->with(['performanceEntries', 'adSpendEntries', 'submitter.departments'])
            ->when($filters['status'] === 'deleted', fn ($query) => $query->onlyTrashed())
            ->when(in_array($filters['status'], ['pending', 'approved', 'rejected'], true), fn ($query) => $query->whereNull('deleted_at')->where('status', $filters['status']))
            ->when($filters['from'], fn ($query) => $query->whereDate('report_date', '>=', $filters['from']))
            ->when($filters['to'], fn ($query) => $query->whereDate('report_date', '<=', $filters['to']))
            ->when($filters['department_id'] > 0, fn ($query) => $query->whereHas('submitter.departments', fn ($departmentQuery) => $departmentQuery->whereKey($filters['department_id'])))
            ->when($filters['submitter_id'] > 0, fn ($query) => $query->where('submitter_id', $filters['submitter_id']))
            ->orderByRaw("CASE status WHEN 'pending' THEN 0 WHEN 'rejected' THEN 1 ELSE 2 END")
            ->orderByDesc('report_date')->orderByDesc('id')->paginate($filters['per_page'])->withQueryString();

        $performanceCache = [];
        $reports->setCollection($reports->getCollection()->map(function (FashionAdvertisingReport $report) use (&$performanceCache): FashionAdvertisingReport {
            $cacheKey = (int) $report->submitter_id.'|'.$report->report_date->format('Y-m-d');
            if (! isset($performanceCache[$cacheKey])) {
                $submitterNames = collect([$report->submitter?->name, $report->submitter?->username])
                    ->filter()->map(fn ($name) => mb_strtolower(trim((string) $name)))->unique()->values();
                $storeIds = AssistantDomainStore::query()
                    ->where(function ($owner) use ($report, $submitterNames): void {
                        $owner->where('owner_user_id', $report->submitter_id);
                        if ($submitterNames->isNotEmpty()) {
                            $owner->orWhereIn(DB::raw('LOWER(TRIM(user_name))'), $submitterNames->all());
                        }
                    })
                    ->whereRaw("LOWER(TRIM(site_builder)) IN ('xshoppy','xshopy','shopline')")
                    ->pluck('id');
                $from = CarbonImmutable::parse($report->report_date->format('Y-m-d').' 00:00:00', '-08:00')->utc();
                $to = CarbonImmutable::parse($report->report_date->format('Y-m-d').' 23:59:59', '-08:00')->utc();
                $metrics = FashionLogisticsOrder::query()
                    ->whereIn('assistant_domain_store_id', $storeIds)
                    ->whereIn('source_platform', ['xshoppy', 'shopline'])
                    ->whereBetween('order_created_at', [$from, $to])
                    ->selectRaw('source_platform, COUNT(*) order_count, COALESCE(SUM(total_amount),0) revenue')
                    ->groupBy('source_platform')->get();
                $platformRows = $metrics->map(fn ($metric): array => [
                    'platform' => mb_strtolower((string) $metric->source_platform) === 'shopline' ? 'Shopline' : 'XShoppy',
                    'orders' => (int) $metric->order_count,
                    'revenue' => round((float) $metric->revenue, 2),
                ])->values();
                $orders = (int) $platformRows->sum('orders');
                $revenue = round((float) $platformRows->sum('revenue'), 2);
                $spend = (float) $report->total_ad_spend_usd;
                $performanceCache[$cacheKey] = ['orders' => $orders, 'revenue' => $revenue, 'roi' => $spend > 0 ? $revenue / $spend : null, 'platform_rows' => $platformRows];
            }
            $report->setAttribute('fashion_order_count', $performanceCache[$cacheKey]['orders']);
            $report->setAttribute('fashion_revenue', $performanceCache[$cacheKey]['revenue']);
            $report->setAttribute('fashion_roi', $performanceCache[$cacheKey]['roi']);
            $report->setAttribute('fashion_platform_rows', $performanceCache[$cacheKey]['platform_rows']);

            return $report;
        }));

        $context['portal']->loadMissing('menus');

        return view('portals.assistant.fashion-advertising-reviews.index', [
            ...$context,
            'reports' => $reports,
            'filters' => $filters,
            'departments' => $departments,
            'submitters' => $submitters,
            'pendingCount' => FashionAdvertisingReport::query()->where('status', FashionAdvertisingReport::STATUS_PENDING)->count(),
        ]);
    }

    public function review(Request $request, FashionAdvertisingReport $report): RedirectResponse
    {
        $context = $this->context($request);
        if ($context instanceof Response) {
            abort(403);
        }
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'rejected'])],
            'review_reason' => ['nullable', 'string', 'max:1000', Rule::requiredIf($request->input('decision') === 'rejected')],
        ], ['review_reason.required' => '审核不通过时请填写原因。']);

        $report->forceFill([
            'status' => $data['decision'],
            'reviewed_by' => $context['user']->getKey(),
            'reviewer_name' => $context['user']->name,
            'reviewed_at' => now(),
            'review_reason' => $data['decision'] === 'rejected' ? trim((string) $data['review_reason']) : null,
        ])->save();

        return back()->with('success', $data['decision'] === 'approved' ? '报表已审核通过。' : '报表已审核不通过。');
    }

    public function show(Request $request, FashionAdvertisingReport $report): Response|View
    {
        $context = $this->context($request);
        if ($context instanceof Response) {
            return $context;
        }
        $report->load(['adSpendEntries', 'submitter.departments']);
        $this->loadPerformanceMetrics($report);
        $context['portal']->loadMissing('menus');

        return view('portals.assistant.fashion-advertising-reviews.show', [...$context, 'report' => $report]);
    }

    public function bulkReview(Request $request): RedirectResponse
    {
        $context = $this->context($request);
        if ($context instanceof Response) {
            abort(403);
        }
        $data = $request->validate([
            'report_ids' => ['required', 'array', 'min:1'],
            'report_ids.*' => ['integer'],
            'decision' => ['required', Rule::in(['approved', 'rejected'])],
            'review_reason' => ['nullable', 'string', 'max:1000', Rule::requiredIf($request->input('decision') === 'rejected')],
        ], ['report_ids.required' => '请至少选择一条待审核报表。', 'review_reason.required' => '批量审核不通过时请填写原因。']);

        $reports = FashionAdvertisingReport::query()->whereIn('id', $data['report_ids'])
            ->where('status', FashionAdvertisingReport::STATUS_PENDING)->get();
        abort_if($reports->isEmpty(), 422, '所选记录中没有可审核的待审核报表。');
        foreach ($reports as $report) {
            $report->forceFill([
                'status' => $data['decision'],
                'reviewed_by' => $context['user']->getKey(),
                'reviewer_name' => $context['user']->name ?: $context['user']->username,
                'reviewed_at' => now(),
                'review_reason' => $data['decision'] === 'rejected' ? trim((string) $data['review_reason']) : null,
            ])->save();
        }

        return back()->with('success', '已批量处理 '.$reports->count().' 份服装广告费报表。');
    }

    public function destroy(Request $request, FashionAdvertisingReport $report): RedirectResponse
    {
        $context = $this->context($request);
        if ($context instanceof Response) {
            abort(403);
        }

        $report->forceFill([
            'deleted_by' => $context['user']->getKey(),
            'deleter_name' => $context['user']->name ?: $context['user']->username,
        ])->save();
        $report->delete();

        return back()->with('success', '广告费提报已删除，服装运营人员可以重新填写；原记录仍可通过“已删除”状态查看。');
    }

    public function screenshot(Request $request, string $type, int $entry): BinaryFileResponse
    {
        $context = $this->context($request);
        if ($context instanceof Response) {
            abort(403);
        }
        $relation = $type === 'performance' ? 'performanceEntries' : ($type === 'advertising' ? 'adSpendEntries' : null);
        abort_unless($relation, 404);
        $report = FashionAdvertisingReport::withTrashed()->whereHas($relation, fn ($query) => $query->whereKey($entry))->firstOrFail();
        $item = $report->{$relation}()->whereKey($entry)->firstOrFail();
        abort_unless($item->screenshot_path && Storage::disk('local')->exists($item->screenshot_path), 404);

        return response()->file(Storage::disk('local')->path($item->screenshot_path));
    }

    private function context(Request $request): array|Response
    {
        $context = $this->portalEntries->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }
        $this->authorization->assertAssistantPortal($context['portal']);

        return $context;
    }

    private function date(mixed $value): string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
    }

    private function loadPerformanceMetrics(FashionAdvertisingReport $report): void
    {
        $submitterNames = collect([$report->submitter?->name, $report->submitter?->username])
            ->filter()->map(fn ($name) => mb_strtolower(trim((string) $name)))->unique()->values();
        $storeIds = AssistantDomainStore::query()
            ->where(function ($owner) use ($report, $submitterNames): void {
                $owner->where('owner_user_id', $report->submitter_id);
                if ($submitterNames->isNotEmpty()) {
                    $owner->orWhereIn(DB::raw('LOWER(TRIM(user_name))'), $submitterNames->all());
                }
            })->whereRaw("LOWER(TRIM(site_builder)) IN ('xshoppy','xshopy','shopline')")->pluck('id');
        $from = CarbonImmutable::parse($report->report_date->format('Y-m-d').' 00:00:00', '-08:00')->utc();
        $to = CarbonImmutable::parse($report->report_date->format('Y-m-d').' 23:59:59', '-08:00')->utc();
        $platformRows = FashionLogisticsOrder::query()->whereIn('assistant_domain_store_id', $storeIds)
            ->whereIn('source_platform', ['xshoppy', 'shopline'])->whereBetween('order_created_at', [$from, $to])
            ->selectRaw('source_platform, COUNT(*) order_count, COALESCE(SUM(total_amount),0) revenue')->groupBy('source_platform')->get()
            ->map(fn ($metric): array => ['platform' => mb_strtolower((string) $metric->source_platform) === 'shopline' ? 'Shopline' : 'XShoppy', 'orders' => (int) $metric->order_count, 'revenue' => round((float) $metric->revenue, 2)])->values();
        $orders = (int) $platformRows->sum('orders');
        $revenue = round((float) $platformRows->sum('revenue'), 2);
        $spend = (float) $report->total_ad_spend_usd;
        $report->setAttribute('fashion_order_count', $orders);
        $report->setAttribute('fashion_revenue', $revenue);
        $report->setAttribute('fashion_roi', $spend > 0 ? $revenue / $spend : null);
        $report->setAttribute('fashion_platform_rows', $platformRows);
    }
}
