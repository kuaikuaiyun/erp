<?php

namespace App\Portals\Operation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\AssistantAdvertisingMedia;
use App\Models\AssistantAdvertisingAgency;
use App\Models\AssistantDomainStore;
use App\Models\Department;
use App\Models\OperationReport;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\Logistics\StreamedXlsxWriter;
use App\Portals\Assistant\AssistantStoreOwnerService;
use App\Portals\Operation\OperationReportMetricsService;
use App\Portals\Operation\AdvertisingReportSpreadsheetService;
use App\Portals\ProductRequests\ProductRequestAccessService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
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
            'operator_user_id' => ['nullable', 'integer'],
        ]);
        $beijingNow = Carbon::now('Asia/Shanghai');
        $dateFrom = (string) ($validated['date_from'] ?? $beijingNow->copy()->startOfMonth()->format('Y-m-d'));
        $dateTo = (string) ($validated['date_to'] ?? $beijingNow->copy()->endOfMonth()->format('Y-m-d'));
        abort_if($dateFrom > $dateTo, 422, '开始日期不能晚于结束日期。');
        $operatorOptions = $isBoss ? $this->operatorOptions() : collect([$user]);
        $operatorId = $isBoss
            ? (int) ($validated['operator_user_id'] ?? $operatorOptions->first()?->getKey() ?? $user->getKey())
            : (int) $user->getKey();
        if ($isBoss) $this->assertAssignableOperator($operatorId);
        $operator = AdminUser::query()->findOrFail($operatorId);
        $calculated = $this->metrics->buildRange($dateFrom, $dateTo, $operatorId);
        $submittedReports = OperationReport::query()
            ->where('operator_user_id', $operatorId)
            ->whereBetween('report_date', [$dateFrom, $dateTo])
            ->orderByDesc('report_date')->orderByDesc('id')->get();
        $submittedReportsByDate = $submittedReports->keyBy(
            fn (OperationReport $report) => $report->report_date->format('Y-m-d')
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

            return [$report->report_date->format('Y-m-d') => $rows];
        })->all();

        return view('portals.operation.reports.monthly', [
            'portal' => $context['portal']->loadMissing('menus'),
            'user' => $user,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'selectedOperator' => $operator,
            'operatorOptions' => $operatorOptions,
            'isBoss' => $isBoss,
            'metricRows' => $calculated['rows'],
            'metricTotals' => $this->sumRows($calculated['rows']),
            'metricRowspans' => $this->identityRowspans($calculated['rows']),
            'metricDateRowspans' => $this->dateRowspans($calculated['rows']),
            'metricDateBands' => $this->dateBands($calculated['rows']),
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
            abort_unless(in_array($editingReport->status, [OperationReport::STATUS_PENDING, OperationReport::STATUS_REJECTED], true), 422, '只有待审核或审核不通过的提报可以编辑。');
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
            ->when(! $isBoss && ! $canReview, fn ($query) => $query->where('operator_user_id', $user->getKey()))
            ->orderByDesc('report_date')->orderByDesc('id')->limit(60)->get();

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
            'isBoss' => $isBoss, 'canReview' => $canReview,
            'canEditReport' => true, 'history' => $history,
            'canViewMissingGuideSkus' => false,
            'missingGuideSkus' => [],
            'missingOriginalSkuItems' => [],
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

    public function advertisingImport(Request $request): RedirectResponse
    {
        $context = $this->context($request);
        if ($context instanceof Response) abort($context->getStatusCode());
        abort_unless($this->isBoss($context['user']), 403);
        $validated = $request->validate([
            'import_file' => ['required', 'file', 'max:10240'],
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
                if (OperationReport::query()->whereDate('report_date', $group['report_date'])->where('operator_user_id', $group['operator_id'])->exists()) {
                    $operator = $operatorConfigurations[$group['operator_id']]['operator'];
                    throw new \InvalidArgumentException($this->operatorImportLabel($operator).' 在 '.$group['report_date'].' 已有提报记录，请先删除或编辑原记录。');
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
                    $calculated = $this->metrics->build($group['report_date'], $group['operator_id']);
                    $totals = $calculated['totals'];
                    $adSpend = collect($group['ads'])->sum('actual_spend');
                    $firstProfit = $totals['first_total_revenue'] - $totals['first_purchase_cost'] - $totals['first_shipping_cost'] - $totals['first_return_fee'] - $adSpend;
                    $renewalNet = $totals['renewal_revenue'] - $totals['renewal_cost'];
                    OperationReport::query()->create([
                        'report_date' => $group['report_date'], 'operator_user_id' => $group['operator_id'],
                        'operator_name' => (string) ($operator->name ?: $operator->username),
                        'operation_department_id' => (int) $department->getKey(), 'operation_department_name' => (string) $department->name,
                        'metric_rows' => $calculated['rows'], 'advertising_rows' => $group['ads'],
                        'first_purchase_cost' => $totals['first_purchase_cost'], 'first_shipping_cost' => $totals['first_shipping_cost'],
                        'first_return_fee' => $totals['first_return_fee'], 'actual_ad_spend' => $adSpend,
                        'first_profit' => $firstProfit, 'renewal_net_revenue' => $renewalNet, 'overall_profit' => $firstProfit + $renewalNet,
                        'status' => OperationReport::STATUS_PENDING, 'submitted_by' => $context['user']->getKey(),
                        'submitted_at' => now(), 'reviewed_by' => null, 'reviewed_at' => null, 'review_reason' => null,
                    ]);
                }
            });
        } catch (\Throwable $exception) {
            foreach ($storedImagePaths as $storedImagePath) Storage::disk('public')->delete($storedImagePath);
            if ($exception instanceof \InvalidArgumentException) {
                throw ValidationException::withMessages(['import_file' => $exception->getMessage()]);
            }
            throw $exception;
        }

        return redirect()->route('operation.advertising-reports.index')
            ->with('success', '成功导入 '.count($rows).' 条广告数据，已按日期和运营人员生成 '.count($reportGroups).' 份待审核记录并提交到助理系统。');
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
            'canEdit' => in_array($report->status, [OperationReport::STATUS_PENDING, OperationReport::STATUS_REJECTED], true)
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
            'canEdit' => in_array($report->status, [OperationReport::STATUS_PENDING, OperationReport::STATUS_REJECTED], true),
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
            'ads.*.book_spend' => ['required', 'numeric', 'min:0'],
            'ads.*.settlement_coefficient' => ['required', 'numeric', 'min:0'],
            'ads.*.existing_screenshot' => ['nullable', 'string', 'max:500', 'regex:/^operation-reports\/ads\/[A-Za-z0-9._-]+$/'],
        ]);
        $operatorId = $isBoss && ! empty($validated['operator_user_id'])
            ? (int) $validated['operator_user_id'] : (int) $user->getKey();
        $editingReport = ! empty($validated['report_id']) ? OperationReport::query()->findOrFail((int) $validated['report_id']) : null;
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
        if ($existing && (! $editingReport || (int) $existing->getKey() !== (int) $editingReport->getKey())) {
            throw ValidationException::withMessages(['report_date' => '该运营当天的报表已经提交，请到提交记录中查看。']);
        }

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
            $ads[] = [
                'media_source' => $mediaSource,
                'agency' => trim((string) ($agencyNames[$ad['agency']] ?? '')),
                'account_name' => trim((string) ($ad['account_name'] ?? '')),
                'account_number' => trim((string) ($ad['account_number'] ?? '')),
                'book_spend' => round($bookSpend, 4),
                'settlement_coefficient' => round($coefficient, 4),
                'handling_fee' => round($handlingFee, 4),
                'actual_spend' => round($bookSpend + $handlingFee, 4),
                'screenshot' => $path,
            ];
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

        return redirect()->route('operation.advertising-reports.index', ['report_date' => $validated['report_date'], 'operator_user_id' => $isBoss ? $operatorId : null])->with('success', $editingReport ? '运营报表已重新提交，状态仍为待审核。' : '运营报表已提交，当前状态为待审核。');
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
            ->with('error', '审核流程已迁移到助理系统，请在“运营报表审核”菜单中操作。');
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

    private function operatorImportLabel(AdminUser $operator): string
    {
        $name = trim((string) $operator->name);
        $username = trim((string) $operator->username);

        return $name !== '' && $username !== '' && $name !== $username ? $name.'（'.$username.'）' : ($name ?: $username);
    }

    /** @return array<string, list<array{key:string,media:string,coefficient:float}>> */
    private function advertisingAgencyGroups(array $assistantDepartmentIds): array
    {
        $options = AssistantAdvertisingAgency::enabledOptions($assistantDepartmentIds);
        $mediaNames = AssistantAdvertisingAgency::enabledMediaNames($assistantDepartmentIds);
        $agencyNames = AssistantAdvertisingAgency::enabledNames($assistantDepartmentIds);
        $groups = [];

        foreach ($options as $key => $coefficient) {
            $agencyName = trim((string) ($agencyNames[$key] ?? ''));
            $mediaName = trim((string) ($mediaNames[$key] ?? ''));
            if ($agencyName === '' || $mediaName === '') continue;

            $groups[$agencyName][] = [
                'key' => (string) $key,
                'media' => $mediaName,
                'coefficient' => (float) $coefficient,
            ];
        }

        return $groups;
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

    private function dateRowspans(array $rows): array
    {
        $spans = [];
        for ($index = 0, $count = count($rows); $index < $count; $index++) {
            $date = $rows[$index]['date'];
            if ($index > 0 && $date === $rows[$index - 1]['date']) {
                $spans[$index] = 0;
                continue;
            }

            $span = 1;
            while ($index + $span < $count && $date === $rows[$index + $span]['date']) $span++;
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
}
