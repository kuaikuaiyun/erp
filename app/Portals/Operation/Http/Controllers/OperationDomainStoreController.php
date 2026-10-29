<?php

namespace App\Portals\Operation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\AssistantDomainStore;
use App\Models\AssistantShopifyAnalyticsSyncBatch;
use App\Models\AssistantShopifyAnalyticsSyncTask;
use App\Models\AssistantShopifyStoreOrder;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\Assistant\ShopifyAnalyticsReferenceRateService;
use App\Portals\ProductRequests\ProductRequestAccessService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * 运营系统「店铺管理 / 店铺列表」（Shopify 店铺分析样式）。
 *
 * 复用助理系统的 AssistantDomainStore 与 AssistantShopifyStoreOrder 模型，只读为主。
 * 仅展示 site_builder = 'shopify' 的店铺，统计数字按所选统计时区与日期区间从已同步的
 * Shopify 订单明细实时汇总，金额主口径统一换算为 USD。
 * 权限：系统管理员（Boss）看全部；其他用户只看授权部门包含其任一部门的店铺。
 */
class OperationDomainStoreController extends Controller
{
    public function __construct(
        private readonly PortalEntryController $portalEntries,
        private readonly ProductRequestAccessService $access,
        private readonly ShopifyAnalyticsReferenceRateService $referenceRates,
    ) {
    }

    public function index(Request $request): Response|View
    {
        $context = $this->portalContext($request);
        $filters = $this->filters($request);

        $storesQuery = $this->scopedStoreQuery($context['user'])
            ->where('site_builder', 'shopify');

        if ($filters['shop_name'] !== '') {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $filters['shop_name']).'%';
            $storesQuery->where('shop_name', 'like', $term);
        }
        if ($filters['domains'] !== []) {
            $storesQuery->whereIn('domain', $filters['domains']);
        }

        $periods = $this->periodQuery(clone $storesQuery, $filters);

        $summary = $this->summarize($periods);

        $stores = $this->withPeriod(clone $storesQuery, $periods)
            ->orderByRaw('CASE WHEN analytics_periods.total_sales IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('analytics_periods.total_sales')
            ->orderBy('assistant_domain_stores.id')
            ->paginate($filters['per_page'])
            ->withQueryString();

        // 店铺数与列表条数保持同一口径：取分页器的总数（含无订单店铺）。
        $summary['stores_count'] = (int) $stores->total();

        $this->attachSyncStates($stores, $context['user']);

        return view('portals.operation.domain-stores.index', [
            'portal' => $context['portal']->loadMissing('menus'),
            'user' => $context['user'],
            'stores' => $stores,
            'filters' => $filters,
            'shopStatusLabels' => AssistantDomainStore::shopStatusLabels(),
            'referenceRates' => $this->referenceRates->rates(),
            'summary' => $summary,
        ]);
    }

    /**
     * 单店铺刷新：复用助理系统的同步批次机制，真实调用 Shopify API 拉取最新订单。
     */
    public function refresh(Request $request, AssistantDomainStore $domainStore): RedirectResponse
    {
        $context = $this->portalContext($request);
        $filters = $this->filters($request);

        $store = $this->scopedStoreQuery($context['user'])
            ->where('site_builder', 'shopify')
            ->whereKey($domainStore->getKey())
            ->firstOrFail();

        if ($store->shop_status !== AssistantDomainStore::SHOP_STATUS_IN_USE) {
            return $this->indexRedirect($request)
                ->with('error', __('store_management.messages.inactive_store'));
        }

        if ($this->hasActiveBatch($context['user'])) {
            return $this->indexRedirect($request)
                ->with('error', __('store_management.messages.batch_in_progress'));
        }

        if ($this->isStoreBusy($store)) {
            return $this->indexRedirect($request)
                ->with('error', __('store_management.messages.store_busy'));
        }

        $this->createSyncBatch(collect([$store]), $context['user'], $filters);

        return $this->indexRedirect($request)
            ->with('success', __('store_management.messages.refresh_queued'));
    }

    /**
     * 按所选统计时区与日期区间，从已同步的 Shopify 订单明细实时汇总各店铺统计数字。
     * 与助理系统 Shopify 店铺分析保持完全一致的统计口径。
     */
    private function periodQuery(Builder $storesQuery, array $filters)
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $orderTable = (new AssistantShopifyStoreOrder())->getTable();
        [$fromUtc, $toUtc] = $this->reportingUtcRange($filters);
        $referenceSales = $this->referenceUsdAggregate(
            'shopify_order.original_total_price',
            'shopify_order.original_currency_code',
        );
        $referenceRefunds = $this->referenceUsdAggregate(
            'shopify_order.refunded_total_amount',
            "COALESCE(shopify_order.refunded_currency_code, shopify_order.original_currency_code)",
        );

        $orders = DB::connection($connection)->table($orderTable.' as shopify_order')
            ->joinSub($storesQuery, 'visible_stores', 'visible_stores.id', '=', 'shopify_order.assistant_domain_store_id')
            ->selectRaw('shopify_order.assistant_domain_store_id')
            ->selectRaw('MAX(shopify_order.shopify_created_at) as latest_order_at')
            ->selectRaw('MAX(shopify_order.original_currency_code) as original_currency_code')
            ->selectRaw('COUNT(*) as orders_count')
            ->selectRaw("COALESCE(SUM({$referenceSales}), 0) as total_sales")
            ->selectRaw('COALESCE(SUM(shopify_order.original_total_price), 0) as original_total_sales')
            ->selectRaw("COALESCE(SUM({$referenceRefunds}), 0) as refund_total")
            ->selectRaw("SUM(CASE WHEN shopify_order.subscription_category = ? THEN 1 ELSE 0 END) as regular_orders_count", [AssistantShopifyStoreOrder::SUBSCRIPTION_CATEGORY_REGULAR])
            ->selectRaw("COALESCE(SUM(CASE WHEN shopify_order.subscription_category = ? THEN {$referenceSales} ELSE 0 END), 0) as regular_total_sales", [AssistantShopifyStoreOrder::SUBSCRIPTION_CATEGORY_REGULAR])
            ->selectRaw('COALESCE(SUM(CASE WHEN shopify_order.subscription_category = ? THEN shopify_order.original_total_price ELSE 0 END), 0) as regular_original_total_sales', [AssistantShopifyStoreOrder::SUBSCRIPTION_CATEGORY_REGULAR])
            ->selectRaw("SUM(CASE WHEN shopify_order.subscription_category = ? THEN 1 ELSE 0 END) as subscribed_orders_count", [AssistantShopifyStoreOrder::SUBSCRIPTION_CATEGORY_SUBSCRIBED])
            ->selectRaw("COALESCE(SUM(CASE WHEN shopify_order.subscription_category = ? THEN {$referenceSales} ELSE 0 END), 0) as subscribed_total_sales", [AssistantShopifyStoreOrder::SUBSCRIPTION_CATEGORY_SUBSCRIBED])
            ->selectRaw('COALESCE(SUM(CASE WHEN shopify_order.subscription_category = ? THEN shopify_order.original_total_price ELSE 0 END), 0) as subscribed_original_total_sales', [AssistantShopifyStoreOrder::SUBSCRIPTION_CATEGORY_SUBSCRIBED])
            ->selectRaw("SUM(CASE WHEN shopify_order.subscription_category = ? THEN 1 ELSE 0 END) as recurring_orders_count", [AssistantShopifyStoreOrder::SUBSCRIPTION_CATEGORY_RECURRING])
            ->selectRaw("COALESCE(SUM(CASE WHEN shopify_order.subscription_category = ? THEN {$referenceSales} ELSE 0 END), 0) as recurring_total_sales", [AssistantShopifyStoreOrder::SUBSCRIPTION_CATEGORY_RECURRING])
            ->selectRaw('COALESCE(SUM(CASE WHEN shopify_order.subscription_category = ? THEN shopify_order.original_total_price ELSE 0 END), 0) as recurring_original_total_sales', [AssistantShopifyStoreOrder::SUBSCRIPTION_CATEGORY_RECURRING])
            ->where('shopify_order.shopify_created_at', '>=', $fromUtc->format('Y-m-d H:i:s'))
            ->where('shopify_order.shopify_created_at', '<', $toUtc->format('Y-m-d H:i:s'))
            ->where('shopify_order.is_test', false)
            ->whereNull('shopify_order.shopify_cancelled_at')
            ->groupBy('shopify_order.assistant_domain_store_id');

        return DB::connection($connection)
            ->query()
            ->fromSub($orders, 'periods')
            ->select('periods.*');
    }

    /** @return array<string, int|float> */
    private function summarize($periods): array
    {
        $connection = config('admin.database.connection') ?: config('database.default');

        $row = DB::connection($connection)
            ->query()
            ->fromSub(clone $periods, 'all_periods')
            ->selectRaw('COALESCE(SUM(orders_count), 0) as orders_count')
            ->selectRaw('COALESCE(SUM(total_sales), 0) as total_sales')
            ->selectRaw('COALESCE(SUM(original_total_sales), 0) as original_total_sales')
            ->selectRaw('COALESCE(SUM(refund_total), 0) as refund_total')
            ->selectRaw('COALESCE(SUM(regular_orders_count), 0) as regular_orders_count')
            ->selectRaw('COALESCE(SUM(regular_total_sales), 0) as regular_total_sales')
            ->selectRaw('COALESCE(SUM(subscribed_orders_count), 0) as subscribed_orders_count')
            ->selectRaw('COALESCE(SUM(subscribed_total_sales), 0) as subscribed_total_sales')
            ->selectRaw('COALESCE(SUM(recurring_orders_count), 0) as recurring_orders_count')
            ->selectRaw('COALESCE(SUM(recurring_total_sales), 0) as recurring_total_sales')
            ->first();

        $ordersCount = (int) ($row->orders_count ?? 0);

        return [
            'orders_count' => $ordersCount,
            'total_sales' => (float) ($row->total_sales ?? 0),
            'original_total_sales' => (float) ($row->original_total_sales ?? 0),
            'refund_total' => (float) ($row->refund_total ?? 0),
            'average_order_value' => $ordersCount > 0 ? (float) ($row->total_sales ?? 0) / $ordersCount : 0.0,
            'regular_orders_count' => (int) ($row->regular_orders_count ?? 0),
            'regular_total_sales' => (float) ($row->regular_total_sales ?? 0),
            'subscribed_orders_count' => (int) ($row->subscribed_orders_count ?? 0),
            'subscribed_total_sales' => (float) ($row->subscribed_total_sales ?? 0),
            'recurring_orders_count' => (int) ($row->recurring_orders_count ?? 0),
            'recurring_total_sales' => (float) ($row->recurring_total_sales ?? 0),
        ];
    }

    private function withPeriod(Builder $query, $periods): Builder
    {
        $connection = config('admin.database.connection') ?: config('database.default');

        return $query
            ->select('assistant_domain_stores.*')
            ->addSelect([
                'analytics_periods.orders_count',
                'analytics_periods.total_sales',
                'analytics_periods.original_total_sales',
                'analytics_periods.regular_orders_count',
                'analytics_periods.regular_total_sales',
                'analytics_periods.regular_original_total_sales',
                'analytics_periods.subscribed_orders_count',
                'analytics_periods.subscribed_total_sales',
                'analytics_periods.subscribed_original_total_sales',
                'analytics_periods.recurring_orders_count',
                'analytics_periods.recurring_total_sales',
                'analytics_periods.recurring_original_total_sales',
                'analytics_periods.original_currency_code',
                'analytics_periods.latest_order_at',
            ])
            ->leftJoinSub($periods, 'analytics_periods', 'analytics_periods.assistant_domain_store_id', '=', 'assistant_domain_stores.id');
    }

    /**
     * 标记每个店铺是否正在刷新，用于刷新按钮的禁用状态。
     */
    private function attachSyncStates(mixed $stores, AdminUser $user): void
    {
        $storeIds = $stores->pluck('id')->all();
        if ($storeIds === []) {
            return;
        }

        $busyStoreIds = AssistantShopifyAnalyticsSyncTask::query()
            ->whereIn('assistant_domain_store_id', $storeIds)
            ->whereIn('status', AssistantShopifyAnalyticsSyncTask::activeStatuses())
            ->pluck('assistant_domain_store_id')
            ->flip();

        $batchInProgress = $this->hasActiveBatch($user);

        foreach ($stores as $store) {
            $store->setAttribute('shopifySyncBusy', $batchInProgress || $busyStoreIds->has($store->id));
        }
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function reportingUtcRange(array $filters): array
    {
        $fromUtc = CarbonImmutable::createFromFormat('!Y-m-d', $filters['from_date'], $filters['timezone'])
            ->startOfDay()
            ->setTimezone('UTC');
        $toUtc = CarbonImmutable::createFromFormat('!Y-m-d', $filters['to_date'], $filters['timezone'])
            ->addDay()
            ->startOfDay()
            ->setTimezone('UTC');

        return [$fromUtc, $toUtc];
    }

    private function referenceUsdAggregate(string $amountExpression, string $currencyExpression): string
    {
        $clauses = [];
        foreach ($this->referenceRates->rates() as $currency => $rate) {
            $clauses[] = "WHEN '{$currency}' THEN ({$amountExpression}) * ".number_format($rate, 6, '.', '');
        }

        return 'CASE UPPER(COALESCE('.$currencyExpression.", '')) ".implode(' ', $clauses).' ELSE NULL END';
    }

    private function hasActiveBatch(AdminUser $user): bool
    {
        return AssistantShopifyAnalyticsSyncBatch::query()
            ->where('actor_id', $user->getKey())
            ->whereIn('status', [
                AssistantShopifyAnalyticsSyncBatch::STATUS_QUEUED,
                AssistantShopifyAnalyticsSyncBatch::STATUS_PROCESSING,
            ])
            ->exists();
    }

    private function isStoreBusy(AssistantDomainStore $store): bool
    {
        return AssistantShopifyAnalyticsSyncTask::query()
            ->where('assistant_domain_store_id', $store->getKey())
            ->whereIn('status', AssistantShopifyAnalyticsSyncTask::activeStatuses())
            ->exists();
    }

    private function createSyncBatch(mixed $stores, AdminUser $user, array $filters): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');

        DB::connection($connection)->transaction(function () use ($stores, $user, $filters): void {
            $batch = AssistantShopifyAnalyticsSyncBatch::query()->create([
                'actor_id' => $user->getKey(),
                'actor_name' => $user->name,
                'report_timezone' => $filters['timezone'],
                'from_date' => $filters['from_date'],
                'to_date' => $filters['to_date'],
                'status' => AssistantShopifyAnalyticsSyncBatch::STATUS_QUEUED,
                'total_count' => $stores->count(),
            ]);

            $batch->tasks()->createMany($stores->map(static fn (AssistantDomainStore $store): array => [
                'assistant_domain_store_id' => $store->getKey(),
                'store_name' => $store->shop_name,
                'status' => AssistantShopifyAnalyticsSyncTask::STATUS_QUEUED,
            ])->all());
        });
    }

    private function indexRedirect(Request $request): RedirectResponse
    {
        return redirect()->to($request->input('return_to') ?: route('operation.domain-stores.index'));
    }

    /** @return array{portal: \App\Models\Portal, user: AdminUser} */
    private function portalContext(Request $request): array
    {
        $context = $this->portalEntries->portalContext($request);
        if ($context instanceof Response) {
            abort(403);
        }

        $this->access->assertPortal(
            $context['portal'],
            ProductRequestAccessService::OPERATION_PORTAL_CODE,
        );

        return $context;
    }

    /** @return array{shop_name:string, domains:list<string>, per_page:int, timezone:string, from_date:string, to_date:string} */
    private function filters(Request $request): array
    {
        $rawPerPage = $request->input('per_page', 20);
        $perPage = is_scalar($rawPerPage) ? (int) $rawPerPage : 20;
        if (!in_array($perPage, [20, 50, 100], true)) {
            $perPage = 20;
        }

        $allowedTimezones = ['UTC', 'Asia/Shanghai'];
        $timezone = (string) $request->input('timezone', 'Asia/Shanghai');
        if (!in_array($timezone, $allowedTimezones, true)) {
            $timezone = 'Asia/Shanghai';
        }

        $fromDate = $this->filterDate($request, 'from_date');
        $toDate = $this->filterDate($request, 'to_date');
        $fromDate = $fromDate === '' ? now($timezone)->subDays(29)->toDateString() : $fromDate;
        $toDate = $toDate === '' ? now($timezone)->toDateString() : $toDate;

        if ($fromDate > $toDate) {
            [$fromDate, $toDate] = [$toDate, $fromDate];
        }

        return [
            'shop_name' => $this->filterText($request, 'shop_name'),
            'domains' => $this->domains((string) $request->input('domains', '')),
            'per_page' => $perPage,
            'timezone' => $timezone,
            'from_date' => $fromDate,
            'to_date' => $toDate,
        ];
    }

    private function filterDate(Request $request, string $field): string
    {
        $value = $request->input($field, '');
        $text = is_scalar($value) ? trim((string) $value) : '';

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) === 1 ? $text : '';
    }

    /** @return list<string> */
    private function domains(string $raw): array
    {
        $values = preg_split('/[,\x{FF0C}]+/u', $raw) ?: [];

        return array_values(array_unique(array_filter(
            array_map(static fn (string $domain): string => strtolower(trim($domain)), $values),
            static fn (string $domain): bool => $domain !== ''
        )));
    }

    private function filterText(Request $request, string $field): string
    {
        $value = $request->input($field, '');

        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function scopedStoreQuery(AdminUser $user): Builder
    {
        $query = AssistantDomainStore::query();

        if ($user->isAdministrator()) {
            return $query;
        }

        // 非管理员（含刚注册进入的新用户）只显示运营归属人为自己的店铺，
        // 无归属店铺一律不可见，避免越权看到他人的店铺数据
        return $query->where('owner_user_id', (int) $user->getKey());
    }
}
