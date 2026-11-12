<?php

namespace App\Portals\Assistant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\AssistantDomainStore;
use App\Models\AssistantDomainStoreQuickOption;
use App\Models\FashionLogisticsOrder;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\Assistant\AssistantPortalAuthorizationService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class FashionStoreAnalyticsController extends Controller
{
    public function __construct(
        private readonly PortalEntryController $portalEntries,
        private readonly AssistantPortalAuthorizationService $authorization,
    ) {}

    public function index(Request $request): Response|View
    {
        $context = $this->portalEntries->portalContext($request);
        if ($context instanceof Response) return $context;
        $this->authorization->assertAssistantPortal($context['portal']);
        $this->authorization->ensureCanViewShopifyAnalytics($context['user']);

        $filters = $this->filters($request);
        $query = $this->stores($context['user'], $filters);
        $metrics = $this->orderMetrics($filters);
        $query->leftJoinSub($metrics, 'fashion_metrics', fn ($join) => $join
            ->on('assistant_domain_stores.id', '=', 'fashion_metrics.assistant_domain_store_id'))
            ->select('assistant_domain_stores.*')
            ->addSelect([
                'fashion_metrics.orders_count as analytics_orders_count',
                'fashion_metrics.total_sales as analytics_total_sales',
                'fashion_metrics.average_order_value as analytics_average_order_value',
                'fashion_metrics.last_paid_at as analytics_last_paid_at',
                'fashion_metrics.last_synced_at as analytics_last_synced_at',
            ]);

        $summaryRow = (clone $query)->reorder()->select([
            DB::raw('COUNT(assistant_domain_stores.id) stores_count'),
            DB::raw('COALESCE(SUM(fashion_metrics.orders_count),0) orders_count'),
            DB::raw('COALESCE(SUM(fashion_metrics.total_sales),0) total_sales'),
            DB::raw('SUM(CASE WHEN COALESCE(fashion_metrics.orders_count,0)>0 THEN 1 ELSE 0 END) active_stores_count'),
        ])
            ->first();
        $sortColumns = [
            'shop_name' => 'assistant_domain_stores.shop_name', 'domain' => 'assistant_domain_stores.domain',
            'platform' => 'assistant_domain_stores.site_builder', 'orders_count' => 'fashion_metrics.orders_count',
            'total_sales' => 'fashion_metrics.total_sales', 'average_order_value' => 'fashion_metrics.average_order_value',
            'last_paid_at' => 'fashion_metrics.last_paid_at',
        ];
        $sortColumn = $sortColumns[$filters['sort']] ?? $sortColumns['total_sales'];
        $query->orderByRaw("CASE WHEN {$sortColumn} IS NULL THEN 1 ELSE 0 END")
            ->orderBy($sortColumn, $filters['direction'])->orderBy('assistant_domain_stores.id');
        $stores = $query->paginate($filters['per_page'])->withQueryString();
        $stores->getCollection()->each(fn (AssistantDomainStore $store) => $store->setAttribute(
            'owner_name',
            $store->ownerUser?->name ?: $store->ownerUser?->username
        ));

        $totalSales = (float) ($summaryRow?->total_sales ?? 0);
        $ordersCount = (int) ($summaryRow?->orders_count ?? 0);
        return view('portals.assistant.fashion-store-analytics.index', [
            'portal' => $context['portal']->loadMissing('menus'), 'user' => $context['user'],
            'stores' => $stores, 'filters' => $filters,
            'teamOptions' => AssistantDomainStoreQuickOption::valuesByField()['team'] ?? [],
            'summary' => ['stores_count' => (int) ($summaryRow?->stores_count ?? 0),
                'active_stores_count' => (int) ($summaryRow?->active_stores_count ?? 0),
                'orders_count' => $ordersCount, 'total_sales' => $totalSales,
                'average_order_value' => $ordersCount > 0 ? $totalSales / $ordersCount : 0],
        ]);
    }

    /** @param array<string,mixed> $filters */
    private function stores(AdminUser $user, array $filters): Builder
    {
        return $this->authorization->scopeDomainStores(AssistantDomainStore::query(), $user)
            ->with('ownerUser:id,name,username')
            ->whereRaw("LOWER(TRIM(site_builder)) IN ('xshoppy','xshopy','shopline')")
            ->when($filters['shop_name'] !== '', fn (Builder $q) => $q->where('shop_name', 'like', '%'.$filters['shop_name'].'%'))
            ->when($filters['domain'] !== '', fn (Builder $q) => $q->where('domain', 'like', '%'.$filters['domain'].'%'))
            ->when($filters['platform'] !== '', fn (Builder $q) => $filters['platform'] === 'xshoppy'
                ? $q->whereRaw("LOWER(TRIM(site_builder)) IN ('xshoppy','xshopy')")
                : $q->whereRaw("LOWER(TRIM(site_builder)) = 'shopline'"))
            ->when($filters['team'] !== '', fn (Builder $q) => $q->whereJsonContains('team', $filters['team']))
            ->when($filters['owner_assignment'] === 'assigned', fn (Builder $q) => $q->whereNotNull('owner_user_id'))
            ->when($filters['owner_assignment'] === 'unassigned', fn (Builder $q) => $q->whereNull('owner_user_id'));
    }

    /** @param array<string,mixed> $filters */
    private function orderMetrics(array $filters)
    {
        $from = CarbonImmutable::parse($filters['from_date'].' 00:00:00', $filters['timezone'])
            ->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s');
        $to = CarbonImmutable::parse($filters['to_date'].' 23:59:59', $filters['timezone'])
            ->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s');

        return FashionLogisticsOrder::query()->whereNotNull('paid_at_utc')->whereBetween('paid_at_utc', [$from, $to])
            ->selectRaw('assistant_domain_store_id, COUNT(*) orders_count, COALESCE(SUM(total_amount),0) total_sales')
            ->selectRaw('COALESCE(AVG(total_amount),0) average_order_value, MAX(paid_at_utc) last_paid_at, MAX(source_synced_at) last_synced_at')
            ->groupBy('assistant_domain_store_id');
    }

    /** @return array<string,mixed> */
    private function filters(Request $request): array
    {
        $timezone = in_array((string) $request->input('timezone'), timezone_identifiers_list(), true)
            ? (string) $request->input('timezone') : 'Asia/Shanghai';
        $today = now($timezone)->toDateString();
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->input('from_date')) ? (string) $request->input('from_date') : $today;
        $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->input('to_date')) ? (string) $request->input('to_date') : $from;
        if ($from > $to) [$from, $to] = [$to, $from];
        $sort = (string) $request->input('sort', 'total_sales');
        $allowedSorts = ['shop_name','domain','platform','orders_count','total_sales','average_order_value','last_paid_at'];
        $perPage = (int) $request->input('per_page', 20);

        return ['shop_name' => trim((string) $request->input('shop_name')), 'domain' => trim((string) $request->input('domain')),
            'platform' => in_array($request->input('platform'), ['xshoppy','shopline'], true) ? $request->input('platform') : '',
            'team' => trim((string) $request->input('team')), 'owner_assignment' => in_array($request->input('owner_assignment'), ['assigned','unassigned'], true) ? $request->input('owner_assignment') : '',
            'timezone' => $timezone, 'from_date' => $from, 'to_date' => $to,
            'sort' => in_array($sort, $allowedSorts, true) ? $sort : 'total_sales',
            'direction' => strtolower((string) $request->input('direction')) === 'asc' ? 'asc' : 'desc',
            'per_page' => in_array($perPage, [20,50,100], true) ? $perPage : 20];
    }
}
