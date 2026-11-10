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
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'department_id' => ['nullable', 'integer', Rule::in($visibleIds)],
            'operator' => ['nullable', 'string', 'max:100'],
        ]);
        $dateFrom = (string) ($filters['date_from'] ?? now()->subDays(6)->toDateString());
        $dateTo = (string) ($filters['date_to'] ?? now()->toDateString());
        if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }

        $query = OperationReport::query()->where('status', OperationReport::STATUS_APPROVED);
        if (! $context['user']->isAdministrator()) {
            $visibleIds === [] ? $query->whereRaw('1=0') : $query->whereIn('operation_department_id', $visibleIds);
        }
        $query->when($filters['department_id'] ?? null, fn ($q, $id) => $q->where('operation_department_id', $id))
            ->when($filters['operator'] ?? null, fn ($q, $name) => $q->where('operator_name', 'like', '%'.$name.'%'));
        $accountSpendReports = (clone $query)->get();
        $query->when($dateFrom !== '', fn ($q) => $q->whereDate('report_date', '>=', $dateFrom))
            ->when($dateTo !== '', fn ($q) => $q->whereDate('report_date', '<=', $dateTo));

        $reports = $query->orderBy('report_date')->orderBy('operator_name')->get();
        $bindings = AppLovinAccountStoreBinding::query()->pluck('assistant_domain_store_id', 'account_key');
        $storeIds = $bindings->values()->merge(
            $reports->flatMap(fn (OperationReport $report) => collect($report->advertising_rows ?: [])->pluck('assistant_domain_store_id'))
        )->filter()->map(fn ($id) => (int) $id)->unique()->values();
        $stores = AssistantDomainStore::query()->whereIn('id', $storeIds)->get(['id', 'domain'])->keyBy('id');
        $accountSpendTotals = $accountSpendReports->flatMap(fn (OperationReport $report) => collect($report->advertising_rows ?: [])
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

        $dailyRows = $reports->flatMap(function (OperationReport $report) use ($bindings, $stores, $accountSpendTotals): array {
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

            return $ads->map(function ($storeAds) use ($report, $accountSpendTotals): array {
                $domain = trim((string) $storeAds->first()['resolved_store_domain']);
                $domainKey = mb_strtolower($domain);
                $metrics = collect($report->metric_rows ?: [])->filter(fn (array $metric) => mb_strtolower(trim((string) ($metric['shop_domain'] ?? ''))) === $domainKey
                    && mb_strtolower(trim((string) ($metric['media_source'] ?? ''))) === 'applovin'
                );
                $orders = (int) $metrics->sum('first_total_orders');
                $revenue = round((float) $metrics->sum('first_total_revenue'), 2);
                $subscriptionOrders = (int) $metrics->sum('first_subscription_orders');
                $subscriptionRevenue = round((float) $metrics->sum('first_subscription_revenue'), 2);
                $spend = round((float) $storeAds->sum('actual_spend'), 2);
                $accountKeys = $storeAds->pluck('account_key')->filter()->unique()->values();

                return [
                    'date' => $report->report_date->format('Y-m-d'),
                    'department_id' => (int) $report->operation_department_id,
                    'department' => (string) $report->operation_department_name,
                    'operator_user_id' => (int) $report->operator_user_id,
                    'operator' => (string) $report->operator_name,
                    'store_id' => (int) $storeAds->first()['resolved_store_id'],
                    'store_domain' => $domain,
                    'account_names' => $storeAds->pluck('account_name')->map(fn ($name) => trim((string) $name))->filter()->unique()->values()->all(),
                    'account_numbers' => $storeAds->pluck('account_number')->map(fn ($number) => trim((string) $number))->filter()->unique()->values()->all(),
                    'account_keys' => $accountKeys->all(),
                    'agency_names' => $storeAds->pluck('agency')->map(fn ($agency) => trim((string) $agency))->filter()->unique()->values()->all(),
                    'first_orders' => $orders,
                    'first_revenue' => $revenue,
                    'first_subscription_orders' => $subscriptionOrders,
                    'first_subscription_revenue' => $subscriptionRevenue,
                    'subscription_rate' => $orders > 0 ? $subscriptionOrders / $orders : null,
                    'average_order_value' => $orders > 0 ? $revenue / $orders : null,
                    'ad_spend' => $spend,
                    'roi' => $spend > 0 ? $revenue / $spend : null,
                    'total_consumption' => round((float) $accountKeys->sum(fn ($key) => (float) ($accountSpendTotals->get($key) ?? 0)), 2),
                ];
            })->values()->all();
        })->values();

        $rows = $dailyRows
            ->groupBy(fn (array $row) => implode('|', [$row['department_id'], $row['operator_user_id'], $row['store_id']]))
            ->map(function ($group) use ($accountSpendTotals, $giftScreenshots): array {
                $first = $group->first();
                $orders = (int) $group->sum('first_orders');
                $revenue = round((float) $group->sum('first_revenue'), 2);
                $subscriptionOrders = (int) $group->sum('first_subscription_orders');
                $subscriptionRevenue = round((float) $group->sum('first_subscription_revenue'), 2);
                $spend = round((float) $group->sum('ad_spend'), 2);
                $giftEligibleKeys = $group->pluck('account_keys')->flatten()->unique()
                    ->filter(fn ($key) => (float) ($accountSpendTotals->get($key) ?? 0) >= 5000);
                $giftStatus = $giftEligibleKeys->isEmpty()
                    ? 'not_eligible'
                    : ($giftEligibleKeys->contains(fn ($key) => $giftScreenshots->has($key)) ? 'available' : 'pending_upload');
                $accountKeys = $group->pluck('account_keys')->flatten()->filter()->unique()->values();

                return [
                    'department' => $first['department'],
                    'operator' => $first['operator'],
                    'store_domain' => $first['store_domain'],
                    'date_label' => $group->min('date') === $group->max('date') ? $group->min('date') : $group->min('date').' 至 '.$group->max('date'),
                    'account_names' => $group->pluck('account_names')->flatten()->filter()->unique()->implode('、'),
                    'account_numbers' => $group->pluck('account_numbers')->flatten()->filter()->unique()->implode('、'),
                    'account_keys' => $accountKeys->all(),
                    'agency_names' => $group->pluck('agency_names')->flatten()->filter()->unique()->implode('、'),
                    'first_orders' => $orders,
                    'first_revenue' => $revenue,
                    'first_subscription_orders' => $subscriptionOrders,
                    'first_subscription_revenue' => $subscriptionRevenue,
                    'subscription_rate' => $orders > 0 ? $subscriptionOrders / $orders : null,
                    'average_order_value' => $orders > 0 ? $revenue / $orders : null,
                    'ad_spend' => $spend,
                    'roi' => $spend > 0 ? $revenue / $spend : null,
                    'total_consumption' => round((float) $accountKeys->sum(fn ($key) => (float) ($accountSpendTotals->get($key) ?? 0)), 2),
                    'gift_status' => $giftStatus,
                    'details' => $group->sortByDesc('date')->map(function (array $detail): array {
                        $detail['account_names'] = implode('、', $detail['account_names']);
                        $detail['account_numbers'] = implode('、', $detail['account_numbers']);
                        $detail['agency_names'] = implode('、', $detail['agency_names']);

                        return $detail;
                    })->values()->all(),
                ];
            })
            ->sortByDesc('ad_spend')
            ->values();

        $totals = [
            'stores' => $rows->pluck('store_domain')->unique()->count(),
            'subscription_orders' => (int) $rows->sum('first_subscription_orders'),
            'subscription_revenue' => round((float) $rows->sum('first_subscription_revenue'), 2),
            'orders' => (int) $rows->sum('first_orders'),
            'revenue' => round((float) $rows->sum('first_revenue'), 2),
            'ad_spend' => round((float) $rows->sum('ad_spend'), 2),
        ];
        $totals['average_order_value'] = $totals['orders'] > 0 ? $totals['revenue'] / $totals['orders'] : null;
        $totals['subscription_rate'] = $totals['orders'] > 0 ? $totals['subscription_orders'] / $totals['orders'] : null;
        $totals['roi'] = $totals['ad_spend'] > 0 ? $totals['revenue'] / $totals['ad_spend'] : null;
        $totals['total_consumption'] = round((float) $rows->pluck('account_keys')->flatten()->filter()->unique()->sum(fn ($key) => (float) ($accountSpendTotals->get($key) ?? 0)), 2);
        $page = max(1, (int) $request->query('page', 1));
        $paginator = new LengthAwarePaginator($rows->forPage($page, 30)->values(), $rows->count(), 30, $page, ['path' => $request->url(), 'query' => $request->query()]);

        return view('portals.assistant.applovin-performance-reports.index', [
            'portal' => $context['portal']->loadMissing('menus'), 'user' => $context['user'], 'rows' => $paginator, 'totals' => $totals,
            'operator' => $filters['operator'] ?? '',
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'selectedDepartmentId' => $filters['department_id'] ?? null,
            'departmentOptions' => $this->storeOwners->operationDepartments()->whereIn('id', $visibleIds)->values(),
        ]);
    }
}
