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
use App\Portals\Operation\OperationReportMetricsService;
use App\Portals\ProductRequests\ProductRequestAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
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
    ) {
    }

    public function index(Request $request): Response|View
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
            abort_unless($editingReport->status === OperationReport::STATUS_PENDING, 422, '只有待审核报表可以重新编辑。');
            abort_unless($isBoss || (int) $editingReport->operator_user_id === (int) $user->getKey(), 403);
        }
        $date = $editingReport?->report_date?->format('Y-m-d')
            ?: (string) ($validated['report_date'] ?? now('Asia/Shanghai')->format('Y-m-d'));
        $operatorOptions = $isBoss ? $this->operatorOptions() : collect([$user]);
        $operatorId = $editingReport ? (int) $editingReport->operator_user_id : ($isBoss
            ? (int) ($validated['operator_user_id'] ?? $operatorOptions->first()?->getKey() ?? $user->getKey())
            : (int) $user->getKey());
        $operator = AdminUser::query()->findOrFail($operatorId);
        if ($isBoss) $this->assertAssignableOperator($operatorId);

        $calculated = $this->metrics->build($date, $operatorId);
        $report = OperationReport::query()->whereDate('report_date', $date)->where('operator_user_id', $operatorId)->first();
        $metricRows = $calculated['rows'];
        $advertisingRows = $editingReport?->advertising_rows ?: [];
        $submittedMetricRows = $report?->metric_rows ?: [];
        $submittedAdvertisingRows = $report?->advertising_rows ?: [];
        $history = OperationReport::query()
            ->when(! $isBoss && ! $canReview, fn ($query) => $query->where('operator_user_id', $user->getKey()))
            ->orderByDesc('report_date')->orderByDesc('id')->limit(60)->get();

        return view('portals.operation.reports.index', [
            'portal' => $context['portal']->load('menus'), 'user' => $user,
            'reportDate' => $date, 'selectedOperator' => $operator, 'report' => $report,
            'editingReport' => $editingReport,
            'metricRows' => $metricRows, 'metricTotals' => $this->sumRows($metricRows),
            'metricRowspans' => $this->identityRowspans($metricRows),
            'advertisingRows' => $advertisingRows, 'warnings' => $calculated['warnings'],
            'mediaRoiRows' => $this->metrics->mediaRoiSummary($submittedMetricRows, $submittedAdvertisingRows),
            'operatorOptions' => $operatorOptions,
            'mediaSources' => $this->advertisingMedia(),
            'advertisingAgencies' => AssistantAdvertisingAgency::enabledOptions(),
            'advertisingAgencyMedia' => AssistantAdvertisingAgency::enabledMediaNames(),
            'advertisingAgencyNames' => AssistantAdvertisingAgency::enabledNames(),
            'isBoss' => $isBoss, 'canReview' => $canReview,
            'canEditReport' => true, 'history' => $history,
            'canViewMissingGuideSkus' => $this->canViewMissingGuideSkus($user),
            'missingGuideSkus' => $this->canViewMissingGuideSkus($user) ? $this->metrics->missingGuideSkuSummary() : [],
            'missingOriginalSkuItems' => $isBoss ? $this->metrics->missingOriginalSkuSummary($date) : [],
        ]);
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
            'portal' => $context['portal']->load('menus'), 'user' => $user, 'report' => $report,
            'metricRows' => $rows, 'metricTotals' => $this->sumRows($rows),
            'metricRowspans' => $this->identityRowspans($rows),
            'advertisingRows' => $report->advertising_rows ?: [],
            'mediaRoiRows' => $this->metrics->mediaRoiSummary($rows, $report->advertising_rows ?: []),
            'canReview' => false,
            'canEdit' => $report->status === OperationReport::STATUS_PENDING
                && ($this->isBoss($user) || (int) $report->operator_user_id === (int) $user->getKey()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $context = $this->context($request);
        if ($context instanceof Response) abort($context->getStatusCode());
        $user = $context['user'];
        $isBoss = $this->isBoss($user);
        $mediaSources = $this->advertisingMedia();
        $agencyOptions = AssistantAdvertisingAgency::enabledOptions();
        $agencyMedia = AssistantAdvertisingAgency::enabledMediaNames();
        $agencyNames = AssistantAdvertisingAgency::enabledNames();
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
            abort_unless($editingReport->status === OperationReport::STATUS_PENDING, 422, '只有待审核报表可以重新编辑。');
            abort_unless($isBoss || (int) $editingReport->operator_user_id === (int) $user->getKey(), 403);
            abort_unless((int) $editingReport->operator_user_id === $operatorId && $editingReport->report_date->format('Y-m-d') === $validated['report_date'], 422);
        }
        if ($isBoss) $this->assertAssignableOperator($operatorId);
        $operator = AdminUser::query()->findOrFail($operatorId);
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
                'actual_spend' => round($bookSpend * $coefficient, 4),
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
                'metric_rows' => $calculated['rows'], 'advertising_rows' => $ads,
                'first_purchase_cost' => $totals['first_purchase_cost'],
                'first_shipping_cost' => $totals['first_shipping_cost'],
                'first_return_fee' => $totals['first_return_fee'], 'actual_ad_spend' => $adSpend,
                'first_profit' => $firstProfit, 'renewal_net_revenue' => $renewalNet,
                'overall_profit' => $firstProfit + $renewalNet,
                'status' => OperationReport::STATUS_PENDING, 'submitted_by' => $user->getKey(),
                'submitted_at' => now(), 'reviewed_by' => null, 'reviewed_at' => null,
            ];
        if ($editingReport) $editingReport->update($values);
        else OperationReport::query()->create($values);

        return redirect()->route('operation.reports.index', ['report_date' => $validated['report_date'], 'operator_user_id' => $isBoss ? $operatorId : null])->with('success', $editingReport ? '运营报表已重新提交，状态仍为待审核。' : '运营报表已提交，当前状态为待审核。');
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
        $view = $this->index($request);
        if ($view instanceof Response) return $view;
        $data = $view->getData();
        $metrics = collect($data['metricRows'])->values();
        $ads = collect($data['advertisingRows'])->values();
        $count = max($metrics->count(), $ads->count(), 1);
        $columns = collect(['日期','人名','店铺域名','媒体','首单订阅单量','首单订阅营业额($)','首单普通单量','首单普通营业额($)','首单合计单量','首单合计营业额($)','首单总采购费','首单总运费','广告代理','广告社媒','广告账户名称','广告账户编号','账面广告费($)','结算系数','实际广告费($)','广告消耗截图','首单交易退货费','运营首单利润($)','续费净收益($)','运营整体净利润($)','运营社媒首单ROI','填写状态','续费单量','续费营业额($)','续费费用','总单量','总营业额($)'])
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
                    $a['agency']??'', $a['media_source']??'', $a['account_name']??'', $a['account_number']??'', $a['book_spend']??'', $a['settlement_coefficient']??'', $a['actual_spend']??'', $a['screenshot']??'',
                    $m['first_return_fee']??'', $i === 0 ? ($report?->first_profit ?? '') : '', $i === 0 ? ($report?->renewal_net_revenue ?? '') : '', $i === 0 ? ($report?->overall_profit ?? '') : '',
                    $i === 0 && $adSpend > 0 ? $firstRevenue / $adSpend : '', $i === 0 ? ($report ? ($report->status === OperationReport::STATUS_APPROVED ? '已审核' : '待审核') : '未提交') : '',
                    $m['renewal_orders']??'', $m['renewal_revenue']??'', $m['renewal_cost']??'', $m['total_orders']??'', $m['total_revenue']??''];
                $textIndexes = [0,1,2,3,12,13,14,15,19,25];
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
    private function advertisingMedia(): array
    {
        return AssistantAdvertisingMedia::enabledNames();
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
}
