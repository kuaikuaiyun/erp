<?php

namespace App\Portals\Assistant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AssistantDomainStore;
use App\Models\Department;
use App\Models\FashionAdvertisingReport;
use App\Models\FashionLogisticsOrder;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\Assistant\AssistantPortalAuthorizationService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class FashionAppLovinPerformanceReportController extends Controller
{
    public function __construct(
        private readonly PortalEntryController $portalEntries,
        private readonly AssistantPortalAuthorizationService $authorization,
    ) {}

    public function index(Request $request): Response|View
    {
        $context = $this->portalEntries->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }
        $this->authorization->assertAssistantPortal($context['portal']);
        abort_unless($this->authorization->isAssistantDepartmentMember($context['user']), 403);

        $submitterIds = FashionAdvertisingReport::query()->whereHas('adSpendEntries', fn ($query) => $query->whereRaw("LOWER(TRIM(media)) = 'applovin'"))
            ->whereIn('status', [FashionAdvertisingReport::STATUS_APPROVED, FashionAdvertisingReport::STATUS_REJECTED])
            ->pluck('submitter_id')->filter()->unique()->values();
        $departments = Department::business()->whereHas('departmentMembers', fn ($query) => $query->whereIn('admin_users.id', $submitterIds))
            ->orderBy('sort')->orderBy('name')->get(['id', 'name']);
        $departmentIds = $departments->pluck('id')->map(fn ($id) => (int) $id)->all();
        $filters = $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'department_id' => ['nullable', 'integer', Rule::in($departmentIds)],
            'operator' => ['nullable', 'string', 'max:100'],
        ]);
        $dateFrom = (string) ($filters['date_from'] ?? now()->subDays(6)->toDateString());
        $dateTo = (string) ($filters['date_to'] ?? now()->toDateString());
        if ($dateFrom > $dateTo) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }

        $reports = FashionAdvertisingReport::query()
            ->whereIn('status', [FashionAdvertisingReport::STATUS_APPROVED, FashionAdvertisingReport::STATUS_REJECTED])
            ->whereBetween('report_date', [$dateFrom, $dateTo])
            ->whereHas('adSpendEntries', fn ($query) => $query->whereRaw("LOWER(TRIM(media)) = 'applovin'"))
            ->with(['adSpendEntries' => fn ($query) => $query->whereRaw("LOWER(TRIM(media)) = 'applovin'"), 'submitter.departments'])
            ->when($filters['department_id'] ?? null, fn ($query, $id) => $query->whereHas('submitter.departments', fn ($department) => $department->whereKey($id)))
            ->when($filters['operator'] ?? null, fn ($query, $name) => $query->where('submitter_name', 'like', '%'.$name.'%'))
            ->orderBy('report_date')->get();

        $storeIds = $reports->flatMap->adSpendEntries->pluck('assistant_domain_store_id')->filter()->map(fn ($id) => (int) $id)->unique()->values();
        $stores = AssistantDomainStore::query()->whereIn('id', $storeIds)->get(['id', 'domain', 'site_builder'])->keyBy('id');
        $dailyRows = $reports->flatMap(function (FashionAdvertisingReport $report) use ($stores): array {
            return $report->adSpendEntries->filter(fn ($entry) => (int) $entry->assistant_domain_store_id > 0)
                ->groupBy('assistant_domain_store_id')->map(function ($entries, $storeId) use ($report, $stores): ?array {
                    $store = $stores->get((int) $storeId);
                    if (! $store) {
                        return null;
                    }
                    $from = CarbonImmutable::parse($report->report_date->format('Y-m-d').' 00:00:00', '-08:00')->utc();
                    $to = CarbonImmutable::parse($report->report_date->format('Y-m-d').' 23:59:59', '-08:00')->utc();
                    $platform = mb_strtolower(trim((string) $store->site_builder)) === 'shopline' ? 'shopline' : 'xshoppy';
                    $metrics = FashionLogisticsOrder::query()->where('assistant_domain_store_id', $storeId)->where('source_platform', $platform)
                        ->whereBetween('order_created_at', [$from, $to])->selectRaw('COUNT(*) order_count, COALESCE(SUM(total_amount),0) revenue')->first();
                    $orders = (int) ($metrics?->order_count ?? 0);
                    $revenue = round((float) ($metrics?->revenue ?? 0), 2);
                    $spend = round((float) $entries->sum('actual_spend_usd'), 2);

                    return [
                        'date' => $report->report_date->format('Y-m-d'),
                        'department' => $report->submitter?->departments?->pluck('name')->filter()->unique()->implode('、') ?: '—',
                        'operator_id' => (int) $report->submitter_id,
                        'operator' => (string) $report->submitter_name,
                        'store_id' => (int) $storeId,
                        'store_domain' => (string) ($entries->pluck('store_domain')->filter()->first() ?: $store->domain),
                        'site_builder' => $platform === 'shopline' ? 'Shopline' : 'XShoppy',
                        'orders' => $orders,
                        'revenue' => $revenue,
                        'ad_spend' => $spend,
                        'roi' => $spend > 0 ? $revenue / $spend : null,
                    ];
                })->filter()->values()->all();
        })->values();

        $rows = $dailyRows->groupBy(fn (array $row) => $row['operator_id'].'|'.$row['store_id'])
            ->map(function ($group): array {
                $first = $group->first();
                $orders = (int) $group->sum('orders');
                $revenue = round((float) $group->sum('revenue'), 2);
                $spend = round((float) $group->sum('ad_spend'), 2);

                return [...$first, 'date_label' => $group->min('date') === $group->max('date') ? $group->min('date') : $group->min('date').' 至 '.$group->max('date'),
                    'orders' => $orders, 'revenue' => $revenue, 'ad_spend' => $spend, 'roi' => $spend > 0 ? $revenue / $spend : null,
                    'details' => $group->sortByDesc('date')->values()->all()];
            })->sortByDesc('ad_spend')->values();
        $totals = ['stores' => $rows->pluck('store_id')->unique()->count(), 'orders' => (int) $rows->sum('orders'),
            'revenue' => round((float) $rows->sum('revenue'), 2), 'ad_spend' => round((float) $rows->sum('ad_spend'), 2)];
        $totals['roi'] = $totals['ad_spend'] > 0 ? $totals['revenue'] / $totals['ad_spend'] : null;
        $page = max(1, (int) $request->query('page', 1));
        $rows = new LengthAwarePaginator($rows->forPage($page, 30)->values(), $rows->count(), 30, $page, ['path' => $request->url(), 'query' => $request->query()]);

        return view('portals.assistant.fashion-applovin-performance-reports.index', [
            'portal' => $context['portal']->loadMissing('menus'), 'user' => $context['user'], 'rows' => $rows, 'totals' => $totals,
            'dateFrom' => $dateFrom, 'dateTo' => $dateTo, 'operator' => $filters['operator'] ?? '',
            'selectedDepartmentId' => $filters['department_id'] ?? null, 'departmentOptions' => $departments,
        ]);
    }
}
