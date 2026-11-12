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
use App\Portals\Assistant\AssistantStoreOwnerService;
use App\Portals\Fashion\FashionAdvertisingReportBulkImportService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class FashionAdvertisingReviewController extends Controller
{
    public function __construct(
        private readonly PortalEntryController $portalEntries,
        private readonly AssistantPortalAuthorizationService $authorization,
        private readonly AssistantStoreOwnerService $storeOwners,
        private readonly FashionAdvertisingReportBulkImportService $bulkImporter,
    ) {}

    public function importTemplate(Request $request): Response
    {
        $context = $this->context($request);
        if ($context instanceof Response) return $context;

        return $this->bulkImporter->templateResponse($this->importableOperators($context['user']));
    }

    public function import(Request $request): RedirectResponse|JsonResponse
    {
        $context = $this->context($request);
        if ($context instanceof Response) abort($context->getStatusCode());
        $request->validate(['import_file' => ['required', 'file', 'max:102400']], [
            'import_file.required' => '请选择需要导入的 Excel 文件。',
            'import_file.uploaded' => '文件上传失败，文件可能超过服务器允许的大小。',
            'import_file.file' => '上传的内容不是有效文件。',
            'import_file.max' => '导入文件不能超过 100MB。',
        ]);
        try {
            $result = $this->bulkImporter->import($request->file('import_file'), $this->importableOperators($context['user']), $context['user']);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['import_file' => $exception->getMessage()]);
        }
        $message = '成功导入 '.$result['imported_count'].' 条广告数据，生成 '.$result['report_count'].' 份审核通过记录';
        if ($result['overwritten_count'] > 0) $message .= '，覆盖 '.$result['overwritten_count'].' 份原记录';
        $message .= '。';
        if ($request->expectsJson()) {
            return response()->json($result + ['ok' => true, 'message' => $message, 'redirect_url' => route('assistant.fashion-advertising-reviews.index')]);
        }

        return redirect()->route('assistant.fashion-advertising-reviews.index')->with('success', $message);
    }

    public function export(Request $request): Response
    {
        $context = $this->context($request);
        if ($context instanceof Response) return $context;
        $visibleDepartmentIds = $this->visibleDepartmentIds($context['user']);

        $status = in_array($request->input('status'), ['pending', 'approved', 'rejected', 'deleted'], true) ? (string) $request->input('status') : '';
        $from = $this->date($request->input('from'));
        $to = $this->date($request->input('to'));
        $departmentIds = collect($request->input('department_ids', []))
            ->push($request->input('department_id'))
            ->map(fn ($id): int => max(0, (int) $id))
            ->filter()->unique()->values();
        $submitterId = max(0, (int) $request->input('submitter_id'));
        $media = trim((string) $request->input('media'));
        $accountNumber = trim((string) $request->input('account_number'));

        $reports = FashionAdvertisingReport::withTrashed()->with(['adSpendEntries', 'submitter.departments'])
            ->when($visibleDepartmentIds !== null, fn ($query) => $visibleDepartmentIds === []
                ? $query->whereRaw('1 = 0')
                : $query->whereHas('submitter.departments', fn ($departments) => $departments->whereIn('admin_roles.id', $visibleDepartmentIds)))
            ->when($status === 'deleted', fn ($query) => $query->onlyTrashed())
            ->when(in_array($status, ['pending', 'approved', 'rejected'], true), fn ($query) => $query->whereNull('deleted_at')->where('status', $status))
            ->when($from, fn ($query) => $query->whereDate('report_date', '>=', $from))
            ->when($to, fn ($query) => $query->whereDate('report_date', '<=', $to))
            ->when($departmentIds->isNotEmpty(), fn ($query) => $query->whereHas('submitter.departments', fn ($departmentQuery) => $departmentQuery->whereKey($departmentIds->all())))
            ->when($submitterId > 0, fn ($query) => $query->where('submitter_id', $submitterId))
            ->when($media !== '', fn ($query) => $query->whereHas('adSpendEntries', fn ($entryQuery) => $entryQuery->where('media', 'like', '%'.$media.'%')))
            ->when($accountNumber !== '', fn ($query) => $query->whereHas('adSpendEntries', fn ($entryQuery) => $entryQuery->where('account_number', 'like', '%'.$accountNumber.'%')))
            ->orderByDesc('report_date')->orderByDesc('id')->get();

        return $this->bulkImporter->exportResponse($reports);
    }

    public function index(Request $request): Response|View
    {
        $context = $this->context($request);
        if ($context instanceof Response) {
            return $context;
        }
        $visibleDepartmentIds = $this->visibleDepartmentIds($context['user']);

        $departmentIds = collect($request->input('department_ids', []))
            ->push($request->input('department_id'))
            ->map(fn ($id): int => max(0, (int) $id))
            ->filter()->unique()->values()->all();
        $filters = [
            'status' => in_array($request->input('status'), ['pending', 'approved', 'rejected', 'deleted'], true) ? (string) $request->input('status') : '',
            'from' => $this->date($request->input('from')),
            'to' => $this->date($request->input('to')),
            'department_ids' => $departmentIds,
            'submitter_id' => max(0, (int) $request->input('submitter_id')),
            'media' => trim((string) $request->input('media')),
            'account_number' => trim((string) $request->input('account_number')),
            'per_page' => in_array((int) $request->input('per_page'), [20, 50, 100], true) ? (int) $request->input('per_page') : 20,
        ];

        $reportSubmitterIds = FashionAdvertisingReport::withTrashed()
            ->when($visibleDepartmentIds !== null, fn ($query) => $visibleDepartmentIds === []
                ? $query->whereRaw('1 = 0')
                : $query->whereHas('submitter.departments', fn ($departments) => $departments->whereIn('admin_roles.id', $visibleDepartmentIds)))
            ->whereNotNull('submitter_id')
            ->distinct()
            ->pluck('submitter_id');
        $submitters = AdminUser::query()
            ->whereIn('id', $reportSubmitterIds)
            ->orderBy('name')
            ->get(['id', 'name', 'username']);
        $departments = Department::business()
            ->whereHas('departmentMembers', fn ($query) => $query->whereIn('admin_users.id', $reportSubmitterIds))
            ->when($visibleDepartmentIds !== null, fn ($query) => $query->whereIn('id', $visibleDepartmentIds))
            ->orderBy('sort')
            ->orderBy('name')
            ->get(['id', 'name']);

        $reports = FashionAdvertisingReport::withTrashed()->with(['performanceEntries', 'adSpendEntries', 'submitter.departments'])
            ->when($visibleDepartmentIds !== null, fn ($query) => $visibleDepartmentIds === []
                ? $query->whereRaw('1 = 0')
                : $query->whereHas('submitter.departments', fn ($departments) => $departments->whereIn('admin_roles.id', $visibleDepartmentIds)))
            ->when($filters['status'] === 'deleted', fn ($query) => $query->onlyTrashed())
            ->when(in_array($filters['status'], ['pending', 'approved', 'rejected'], true), fn ($query) => $query->whereNull('deleted_at')->where('status', $filters['status']))
            ->when($filters['from'], fn ($query) => $query->whereDate('report_date', '>=', $filters['from']))
            ->when($filters['to'], fn ($query) => $query->whereDate('report_date', '<=', $filters['to']))
            ->when($filters['department_ids'] !== [], fn ($query) => $query->whereHas('submitter.departments', fn ($departmentQuery) => $departmentQuery->whereKey($filters['department_ids'])))
            ->when($filters['submitter_id'] > 0, fn ($query) => $query->where('submitter_id', $filters['submitter_id']))
            ->when($filters['media'] !== '', fn ($query) => $query->whereHas('adSpendEntries', fn ($entryQuery) => $entryQuery
                ->where('media', 'like', '%'.$filters['media'].'%')))
            ->when($filters['account_number'] !== '', fn ($query) => $query->whereHas('adSpendEntries', fn ($entryQuery) => $entryQuery
                ->where('account_number', 'like', '%'.$filters['account_number'].'%')))
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
            'pendingCount' => FashionAdvertisingReport::query()
                ->when($visibleDepartmentIds !== null, fn ($query) => $visibleDepartmentIds === []
                    ? $query->whereRaw('1 = 0')
                    : $query->whereHas('submitter.departments', fn ($departments) => $departments->whereIn('admin_roles.id', $visibleDepartmentIds)))
                ->where('status', FashionAdvertisingReport::STATUS_PENDING)->count(),
        ]);
    }

    public function review(Request $request, FashionAdvertisingReport $report): RedirectResponse
    {
        $context = $this->context($request);
        if ($context instanceof Response) {
            abort(403);
        }
        $this->assertReportVisible($report, $context['user']);
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

        return redirect()->route('assistant.fashion-advertising-reviews.index')
            ->with('success', $data['decision'] === 'approved' ? '报表已审核通过。' : '报表已审核不通过。');
    }

    public function show(Request $request, FashionAdvertisingReport $report): Response|View
    {
        $context = $this->context($request);
        if ($context instanceof Response) {
            return $context;
        }
        $this->assertReportVisible($report, $context['user']);
        $report->load(['adSpendEntries', 'submitter.departments']);
        $this->loadPerformanceMetrics($report);
        $context['portal']->loadMissing('menus');

        return view('portals.assistant.fashion-advertising-reviews.show', [...$context, 'report' => $report]);
    }

    public function edit(Request $request, FashionAdvertisingReport $report): Response|View
    {
        $context = $this->context($request);
        if ($context instanceof Response) return $context;
        $this->assertReportVisible($report, $context['user']);
        abort_unless($report->status === FashionAdvertisingReport::STATUS_APPROVED, 422, '只有审核通过的报表可以重新编辑广告信息。');
        $report->load(['adSpendEntries', 'submitter.departments']);

        return view('portals.assistant.fashion-advertising-reviews.edit', [...$context, 'report' => $report]);
    }

    public function update(Request $request, FashionAdvertisingReport $report): RedirectResponse
    {
        $context = $this->context($request);
        if ($context instanceof Response) abort(403);
        $this->assertReportVisible($report, $context['user']);
        abort_unless($report->status === FashionAdvertisingReport::STATUS_APPROVED, 422, '只有审核通过的报表可以重新编辑广告信息。');
        $report->load('adSpendEntries');
        $entryIds = $report->adSpendEntries->modelKeys();
        $data = $request->validate([
            'entries' => ['required', 'array', 'min:1', 'max:50'],
            'entries.*.id' => ['required', 'integer', Rule::in($entryIds)],
            'entries.*.agency' => ['required', 'string', 'max:100'],
            'entries.*.media' => ['required', 'string', 'max:100'],
            'entries.*.account_name' => ['required', 'string', 'max:191'],
            'entries.*.account_number' => ['required', 'string', 'max:191'],
            'entries.*.store_domain' => ['nullable', 'string', 'max:191'],
            'entries.*.book_spend_usd' => ['required', 'numeric', 'min:0'],
            'entries.*.settlement_coefficient' => ['required', 'numeric', 'min:0'],
            'entries.*.screenshot' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'deleted_entry_ids' => ['nullable', 'array'],
            'deleted_entry_ids.*' => ['integer', 'distinct', Rule::in($entryIds)],
        ]);

        $submittedEntryIds = collect($data['entries'])->pluck('id')->map(static fn ($id): int => (int) $id);
        $deletedEntryIds = collect($data['deleted_entry_ids'] ?? [])->map(static fn ($id): int => (int) $id);
        if ($submittedEntryIds->intersect($deletedEntryIds)->isNotEmpty()
            || $submittedEntryIds->merge($deletedEntryIds)->unique()->sort()->values()->all() !== collect($entryIds)->sort()->values()->all()) {
            throw ValidationException::withMessages(['entries' => '广告记录发生变化，请刷新页面后重新操作。']);
        }
        if ($submittedEntryIds->isEmpty()) {
            throw ValidationException::withMessages(['entries' => '至少需要保留一条广告记录。']);
        }

        $deletedScreenshotPaths = [];
        DB::connection($report->getConnectionName())->transaction(function () use ($request, $report, $data, $context, $deletedEntryIds, &$deletedScreenshotPaths): void {
            foreach ($data['entries'] as $index => $row) {
                $entry = $report->adSpendEntries->firstWhere('id', (int) $row['id']);
                abort_unless($entry, 422);
                $bookSpend = round((float) $row['book_spend_usd'], 2);
                $coefficient = round((float) $row['settlement_coefficient'], 4);
                $values = [
                    'agency' => trim((string) $row['agency']),
                    'media' => trim((string) $row['media']),
                    'account_name' => trim((string) $row['account_name']),
                    'account_number' => trim((string) $row['account_number']),
                    'store_domain' => trim((string) ($row['store_domain'] ?? '')) ?: null,
                    'book_spend_usd' => $bookSpend,
                    'settlement_coefficient' => $coefficient,
                    'actual_spend_usd' => round($bookSpend * (1 + $coefficient), 2),
                ];
                if ($file = $request->file('entries.'.$index.'.screenshot')) {
                    $values['screenshot_path'] = $file->storeAs('fashion-advertising/'.now()->format('Ym'), Str::uuid().'.'.$file->extension(), 'local');
                }
                $entry->update($values);
            }
            foreach ($report->adSpendEntries->whereIn('id', $deletedEntryIds) as $entry) {
                if ($entry->screenshot_path) {
                    $deletedScreenshotPaths[] = $entry->screenshot_path;
                }
                $entry->delete();
            }
            $spend = round((float) $report->adSpendEntries()->sum('actual_spend_usd'), 2);
            $report->update([
                'total_ad_spend_usd' => $spend,
                'roi' => $spend > 0 ? round((float) $report->total_revenue_usd / $spend, 4) : 0,
                'status' => FashionAdvertisingReport::STATUS_APPROVED,
                'reviewed_by' => $context['user']->getKey(),
                'reviewer_name' => $context['user']->name ?: $context['user']->username,
                'reviewed_at' => now(),
                'review_reason' => null,
            ]);
        });
        foreach ($deletedScreenshotPaths as $path) {
            Storage::disk('local')->delete($path);
        }

        return redirect()->route('assistant.fashion-advertising-reviews.show', $report)
            ->with('success', '广告信息已修改，报表保持审核通过状态。');
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

        $visibleDepartmentIds = $this->visibleDepartmentIds($context['user']);
        $reports = FashionAdvertisingReport::query()->whereIn('id', $data['report_ids'])
            ->when($visibleDepartmentIds !== null, fn ($query) => $visibleDepartmentIds === []
                ? $query->whereRaw('1 = 0')
                : $query->whereHas('submitter.departments', fn ($departments) => $departments->whereIn('admin_roles.id', $visibleDepartmentIds)))
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
        $this->assertReportVisible($report, $context['user']);

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
        $this->assertReportVisible($report, $context['user']);
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

    private function importableOperators(AdminUser $user)
    {
        $ownerIds = AssistantDomainStore::query()->whereNotNull('owner_user_id')
            ->whereRaw("LOWER(TRIM(site_builder)) IN ('xshoppy','xshopy','shopline')")
            ->distinct()->pluck('owner_user_id');
        $query = AdminUser::query()->whereIn('id', $ownerIds);
        if (! $user->isAdministrator()) {
            $departmentIds = $this->storeOwners->visibleOperationDepartmentIds($user);
            if ($departmentIds === []) return collect();
            $query->whereHas('departments', fn ($departmentQuery) => $departmentQuery->whereIn('admin_roles.id', $departmentIds));
        }

        return $query->orderBy('name')->get(['id', 'name', 'username']);
    }

    /** @return list<int>|null null means unrestricted administrator access. */
    private function visibleDepartmentIds(AdminUser $user): ?array
    {
        return $user->isAdministrator() ? null : $this->storeOwners->visibleOperationDepartmentIds($user);
    }

    private function assertReportVisible(FashionAdvertisingReport $report, AdminUser $user): void
    {
        $departmentIds = $this->visibleDepartmentIds($user);
        if ($departmentIds === null) return;

        abort_unless($departmentIds !== [] && $report->submitter()
            ->whereHas('departments', fn ($departments) => $departments->whereIn('admin_roles.id', $departmentIds))
            ->exists(), 403);
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
