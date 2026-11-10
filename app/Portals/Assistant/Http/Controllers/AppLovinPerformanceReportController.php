<?php

namespace App\Portals\Assistant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AppLovinAccountStoreBinding;
use App\Models\AppLovinSpendScreenshot;
use App\Models\AssistantDomainStore;
use App\Models\OperationReport;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\Assistant\AssistantPortalAuthorizationService;
use App\Portals\Assistant\AssistantStoreOwnerService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class AppLovinPerformanceReportController extends Controller
{
    public function __construct(
        private readonly PortalEntryController $portalEntries,
        private readonly AssistantPortalAuthorizationService $authorization,
        private readonly AssistantStoreOwnerService $storeOwners,
    ) {}

    public function index(Request $request): Response|View
    {
        $context = $this->portalEntries->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }
        $this->authorization->assertAssistantPortal($context['portal']);
        abort_unless($this->authorization->isAssistantDepartmentMember($context['user']), 403);

        $visibleIds = $this->storeOwners->visibleOperationDepartmentIds($context['user']);
        $filters = $request->validate([
            'department_id' => ['nullable', 'integer', Rule::in($visibleIds)],
            'operator' => ['nullable', 'string', 'max:100'],
        ]);

        $query = OperationReport::query()->where('status', OperationReport::STATUS_APPROVED);
        if (! $context['user']->isAdministrator()) {
            $visibleIds === [] ? $query->whereRaw('1=0') : $query->whereIn('operation_department_id', $visibleIds);
        }
        $query->when($filters['department_id'] ?? null, fn ($q, $id) => $q->where('operation_department_id', $id))
            ->when($filters['operator'] ?? null, fn ($q, $name) => $q->where('operator_name', 'like', '%'.$name.'%'));

        $reports = $query->orderBy('report_date')->orderBy('operator_name')->get();
        $bindings = AppLovinAccountStoreBinding::query()->pluck('assistant_domain_store_id', 'account_key');
        $storeIds = $bindings->values()->merge(
            $reports->flatMap(fn (OperationReport $report) => collect($report->advertising_rows ?: [])->pluck('assistant_domain_store_id'))
        )->filter()->map(fn ($id) => (int) $id)->unique()->values();
        $stores = AssistantDomainStore::query()->whereIn('id', $storeIds)->get(['id', 'domain'])->keyBy('id');
        $accountSpendTotals = $reports->flatMap(fn (OperationReport $report) => collect($report->advertising_rows ?: [])
            ->filter(fn (array $ad) => mb_strtolower(trim((string) ($ad['media_source'] ?? ''))) === 'applovin')
            ->map(fn (array $ad): array => [
                'account_key' => AppLovinSpendScreenshot::accountKey(
                    (int) $report->operator_user_id,
                    (string) ($ad['agency'] ?? ''),
                    (string) ($ad['media_source'] ?? ''),
                    (string) ($ad['account_name'] ?? ''),
                    (string) ($ad['account_number'] ?? '')
                ),
                'spend' => (float) ($ad['actual_spend'] ?? 0),
            ]))->groupBy('account_key')->map(fn ($items) => (float) $items->sum('spend'));
        $giftScreenshots = AppLovinSpendScreenshot::query()->whereIn('account_key', $accountSpendTotals->keys())->get()->keyBy('account_key');

        $dailyRows = $reports->flatMap(function (OperationReport $report) use ($bindings, $stores): array {
            $ads = collect($report->advertising_rows ?: [])
                ->filter(fn (array $ad) => mb_strtolower(trim((string) ($ad['media_source'] ?? ''))) === 'applovin')
                ->map(function (array $ad) use ($report, $bindings, $stores): ?array {
                    $accountKey = AppLovinSpendScreenshot::accountKey(
                        (int) $report->operator_user_id,
                        (string) ($ad['agency'] ?? ''),
                        (string) ($ad['media_source'] ?? ''),
                        (string) ($ad['account_name'] ?? ''),
                        (string) ($ad['account_number'] ?? '')
                    );
                    $storeId = (int) ($ad['assistant_domain_store_id'] ?? 0);
                    if ($storeId < 1) {
                        $storeId = (int) ($bindings->get($accountKey) ?? 0);
                    }

                    $store = $stores->get($storeId);
                    $domain = trim((string) ($ad['store_domain'] ?? $store?->domain ?? ''));
                    if ($storeId < 1 || $domain === '') {
                        return null;
                    }

                    return [...$ad, 'account_key' => $accountKey, 'resolved_store_id' => $storeId, 'resolved_store_domain' => $domain];
                })
                ->filter()
                ->groupBy('resolved_store_id');

            return $ads->map(function ($storeAds) use ($report): array {
                $domain = trim((string) $storeAds->first()['resolved_store_domain']);
                $domainKey = mb_strtolower($domain);
                $metrics = collect($report->metric_rows ?: [])->filter(fn (array $metric) => mb_strtolower(trim((string) ($metric['shop_domain'] ?? ''))) === $domainKey
                    && mb_strtolower(trim((string) ($metric['media_source'] ?? ''))) === 'applovin'
                );
                $orders = (int) $metrics->sum('first_total_orders');
                $revenue = round((float) $metrics->sum('first_total_revenue'), 2);
                $spend = round((float) $storeAds->sum('actual_spend'), 2);

                return [
                    'date' => $report->report_date->format('Y-m-d'),
                    'department_id' => (int) $report->operation_department_id,
                    'department' => (string) $report->operation_department_name,
                    'operator_user_id' => (int) $report->operator_user_id,
                    'operator' => (string) $report->operator_name,
                    'store_id' => (int) $storeAds->first()['resolved_store_id'],
                    'store_domain' => $domain,
                    'account_names' => $storeAds->pluck('account_name')->map(fn ($name) => trim((string) $name))->filter()->unique()->values()->all(),
                    'account_keys' => $storeAds->pluck('account_key')->filter()->unique()->values()->all(),
                    'agency_names' => $storeAds->pluck('agency')->map(fn ($agency) => trim((string) $agency))->filter()->unique()->values()->all(),
                    'first_orders' => $orders,
                    'first_revenue' => $revenue,
                    'average_order_value' => $orders > 0 ? $revenue / $orders : null,
                    'ad_spend' => $spend,
                    'roi' => $spend > 0 ? $revenue / $spend : null,
                ];
            })->values()->all();
        })->values();

        $rows = $dailyRows
            ->groupBy(fn (array $row) => implode('|', [$row['department_id'], $row['operator_user_id'], $row['store_id']]))
            ->map(function ($group) use ($accountSpendTotals, $giftScreenshots): array {
                $first = $group->first();
                $orders = (int) $group->sum('first_orders');
                $revenue = round((float) $group->sum('first_revenue'), 2);
                $spend = round((float) $group->sum('ad_spend'), 2);
                $giftEligibleKeys = $group->pluck('account_keys')->flatten()->unique()
                    ->filter(fn ($key) => (float) ($accountSpendTotals->get($key) ?? 0) >= 5000);
                $giftStatus = $giftEligibleKeys->isEmpty()
                    ? 'not_eligible'
                    : ($giftEligibleKeys->contains(fn ($key) => $giftScreenshots->has($key)) ? 'available' : 'pending_upload');

                return [
                    'department' => $first['department'],
                    'operator' => $first['operator'],
                    'store_domain' => $first['store_domain'],
                    'account_names' => $group->pluck('account_names')->flatten()->filter()->unique()->implode('、'),
                    'agency_names' => $group->pluck('agency_names')->flatten()->filter()->unique()->implode('、'),
                    'first_orders' => $orders,
                    'first_revenue' => $revenue,
                    'average_order_value' => $orders > 0 ? $revenue / $orders : null,
                    'ad_spend' => $spend,
                    'roi' => $spend > 0 ? $revenue / $spend : null,
                    'gift_status' => $giftStatus,
                    'details' => $group->sortByDesc('date')->map(function (array $detail): array {
                        $detail['account_names'] = implode('、', $detail['account_names']);
                        $detail['agency_names'] = implode('、', $detail['agency_names']);

                        return $detail;
                    })->values()->all(),
                ];
            })
            ->sortByDesc('ad_spend')
            ->values();

        $totals = [
            'stores' => $rows->pluck('store_domain')->unique()->count(),
            'orders' => (int) $rows->sum('first_orders'),
            'revenue' => round((float) $rows->sum('first_revenue'), 2),
            'ad_spend' => round((float) $rows->sum('ad_spend'), 2),
        ];
        $totals['average_order_value'] = $totals['orders'] > 0 ? $totals['revenue'] / $totals['orders'] : null;
        $totals['roi'] = $totals['ad_spend'] > 0 ? $totals['revenue'] / $totals['ad_spend'] : null;
        $page = max(1, (int) $request->query('page', 1));
        $paginator = new LengthAwarePaginator($rows->forPage($page, 30)->values(), $rows->count(), 30, $page, ['path' => $request->url(), 'query' => $request->query()]);

        return view('portals.assistant.applovin-performance-reports.index', [
            'portal' => $context['portal']->loadMissing('menus'), 'user' => $context['user'], 'rows' => $paginator, 'totals' => $totals,
            'operator' => $filters['operator'] ?? '',
            'selectedDepartmentId' => $filters['department_id'] ?? null,
            'departmentOptions' => $this->storeOwners->operationDepartments()->whereIn('id', $visibleIds)->values(),
        ]);
    }
}
