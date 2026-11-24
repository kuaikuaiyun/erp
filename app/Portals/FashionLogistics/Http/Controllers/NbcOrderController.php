<?php

namespace App\Portals\FashionLogistics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\AssistantDomainStore;
use App\Models\FashionLogisticsOrder;
use App\Models\FashionLogisticsSyncState;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\FashionLogistics\FashionLogisticsPortalService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class NbcOrderController extends Controller
{
    private const PLATFORMS = ['nbc', 'shopyy'];

    public function __construct(
        private readonly PortalEntryController $portalEntries,
        private readonly FashionLogisticsPortalService $portalService,
    ) {}

    public function index(Request $request): Response|View
    {
        $context = $this->portalEntries->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }
        $this->portalService->assertPortal($context['portal']);
        abort_unless($context['user']->can('portal.fuzhuangwuliu.orders'), 403, __('nbc_orders.errors.permission'));
        /** @var AdminUser $user */
        $user = $context['user'];

        $validated = $request->validate([
            'keyword' => ['nullable', 'string', 'max:191'],
            'store_id' => ['nullable', 'integer'],
            'store_handle' => ['nullable', 'string', 'max:191'],
            'operator_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:100,110,180,190'],

            'fulfillment_status' => ['nullable', 'in:300,310,320,330'],
            'marketing_source' => ['nullable', 'in:google_ads,tiktok,meta,other_utm,external_referral,direct_or_unknown'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('date_from') ? ['after_or_equal:date_from'] : [])],
            'per_page' => ['nullable', 'integer', 'in:20,50,100'],
        ], __('nbc_orders.validation'), __('nbc_orders.attributes'));

        $stores = $this->applyStoreVisibility(AssistantDomainStore::query()
            ->whereRaw("LOWER(TRIM(site_builder)) IN ('nbc', 'shopyy')")
            ->where('shop_status', AssistantDomainStore::SHOP_STATUS_IN_USE), $user)
            ->orderBy('shop_name')
            ->get(['id', 'shop_name', 'site_builder', 'owner_user_id', 'user_name']);
        $query = FashionLogisticsOrder::query()
            ->select([
                'id', 'assistant_domain_store_id', 'source_platform', 'order_number', 'external_order_id', 'order_created_at',
                'store_name', 'source_domain', 'status', 'fulfillment_status', 'marketing_source',
                'customer_name', 'customer_email', 'customer_phone', 'currency', 'total_amount', 'discount_amount',
            ])
            ->with([
                'items' => static fn ($items) => $items
                    ->select(['id', 'fashion_logistics_order_id', 'product_name', 'sku', 'quantity'])
                    ->orderBy('id')->limit(2),
                'store:id,owner_user_id,user_name,team',
                'store.ownerUser:id,name,username',
            ])
            ->withCount('items')
            ->whereIn('source_platform', self::PLATFORMS)
            ->where('payment_status', '230')
            ->whereHas('store', function (Builder $stores) use ($user): void {
                $this->applyStoreVisibility($stores, $user)
                    ->where('shop_status', AssistantDomainStore::SHOP_STATUS_IN_USE)
                    ->whereRaw("LOWER(TRIM(site_builder)) IN ('nbc', 'shopyy')");
            });
        $keyword = trim((string) ($validated['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where(function ($orders) use ($keyword): void {
                $orders->where('order_number', 'like', "%{$keyword}%")
                    ->orWhere('external_order_id', 'like', "%{$keyword}%")
                    ->orWhere('transaction_id', 'like', "%{$keyword}%")
                    ->orWhere('customer_name', 'like', "%{$keyword}%")
                    ->orWhere('customer_email', 'like', "%{$keyword}%")
                    ->orWhere('customer_phone', 'like', "%{$keyword}%")
                    ->orWhereHas('items', fn ($items) => $items->where('sku', 'like', "%{$keyword}%")->orWhere('product_name', 'like', "%{$keyword}%"));
            });
        }
        $query->when($validated['store_id'] ?? null, fn ($orders, $storeId) => $orders->where('assistant_domain_store_id', $storeId));
        $storeHandle = strtolower(trim((string) ($validated['store_handle'] ?? '')));
        if ($storeHandle !== '') {
            $query->whereHas('store', static fn ($stores) => $stores
                ->whereRaw('LOWER(TRIM(shop_name)) = ?', [$storeHandle]));
        }
        $query->when(($validated['status'] ?? '') !== '', fn ($orders) => $orders->where('status', $validated['status']));
        $query->when($validated['fulfillment_status'] ?? null, fn ($orders, $status) => $orders->where('fulfillment_status', $status));
        $query->when($validated['marketing_source'] ?? null, fn ($orders, $source) => $orders->where('marketing_source', $source));
        $query->when($validated['date_from'] ?? null, fn ($orders, $date) => $orders->where('order_created_at', '>=', $date.' 00:00:00'));
        $query->when($validated['date_to'] ?? null, fn ($orders, $date) => $orders->where('order_created_at', '<=', $date.' 23:59:59'));

        $selectedOperator = isset($validated['operator_id']) ? AdminUser::query()->find((int) $validated['operator_id']) : null;
        if ($user->isAdministrator() && $selectedOperator) {
            $names = collect([$selectedOperator->name, $selectedOperator->username])->filter()->map(fn ($name) => trim((string) $name))->unique()->all();
            $query->whereHas('store', function ($shop) use ($selectedOperator, $names): void {
                $shop->where('owner_user_id', $selectedOperator->getKey());
                if ($names !== []) {
                    $shop->orWhereIn('user_name', $names);
                }
            });
        }

        $orders = $query->latest('order_created_at')->latest('id')
            ->paginate((int) ($validated['per_page'] ?? 20))
            ->withQueryString();

        $ownerIds = $stores->pluck('owner_user_id')->filter()->map(fn ($id) => (int) $id)->unique()->values();
        $ownerNames = $stores->pluck('user_name')->filter()->map(fn ($name) => trim((string) $name))->filter()->unique()->values();
        $operators = AdminUser::query()
            ->where(function ($users) use ($ownerIds, $ownerNames): void {
                if ($ownerIds->isNotEmpty()) {
                    $users->whereIn('id', $ownerIds);
                }
                if ($ownerNames->isNotEmpty()) {
                    $ownerIds->isNotEmpty() ? $users->orWhereIn('name', $ownerNames) : $users->whereIn('name', $ownerNames);
                    $users->orWhereIn('username', $ownerNames);
                }
                if ($ownerIds->isEmpty() && $ownerNames->isEmpty()) {
                    $users->whereRaw('1 = 0');
                }
            })
            ->orderBy('name')->orderBy('username')->get(['id', 'name', 'username']);

        return view('portals.fashion-logistics.nbc-orders.index', [
            ...$context,
            'orders' => $orders,
            'stores' => $stores,
            'canSelectOperator' => $user->isAdministrator(),
            'operators' => $operators,
            'filters' => $validated,
        ]);
    }

    public function syncStatus(Request $request): Response|JsonResponse
    {
        $context = $this->portalEntries->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }
        $this->portalService->assertPortal($context['portal']);
        abort_unless($context['user']->can('portal.fuzhuangwuliu.orders'), 403, __('nbc_orders.errors.permission'));
        /** @var AdminUser $user */
        $user = $context['user'];

        $validated = $request->validate([
            'keyword' => ['nullable', 'string', 'max:191'],
            'configuration' => ['nullable', 'in:ready,missing'],
            'sync_status' => ['nullable', 'in:pending,syncing,success,failed'],
            'per_page' => ['nullable', 'integer', 'in:20,50,100'],
        ], __('nbc_orders.validation'), __('nbc_orders.attributes'));
        $base = $this->applyStoreVisibility(AssistantDomainStore::query()
            ->whereRaw("LOWER(TRIM(site_builder)) IN ('nbc', 'shopyy')")
            ->where('shop_status', AssistantDomainStore::SHOP_STATUS_IN_USE), $user);
        $summaryBase = clone $base;
        $summary = [
            'total' => (clone $summaryBase)->count(),
            'ready' => (clone $summaryBase)->whereRaw("TRIM(COALESCE(nbc_app_token, '')) <> ''")->count(),
        ];
        $summary['missing'] = max(0, $summary['total'] - $summary['ready']);
        $summary['failed'] = FashionLogisticsSyncState::query()->where('status', 'failed')
            ->whereIn('assistant_domain_store_id', (clone $summaryBase)->select('id'))->count();

        $stores = clone $base;
        $keyword = trim((string) ($validated['keyword'] ?? ''));
        if ($keyword !== '') {
            $stores->where(function ($query) use ($keyword): void {
                $query->where('shop_name', 'like', "%{$keyword}%")
                    ->orWhere('user_name', 'like', "%{$keyword}%");
            });
        }
        if (($validated['configuration'] ?? '') === 'ready') {
            $stores->whereRaw("TRIM(COALESCE(nbc_app_token, '')) <> ''");
        }
        if (($validated['configuration'] ?? '') === 'missing') {
            $stores->where(function ($query): void {
                $query->whereRaw("TRIM(COALESCE(nbc_app_token, '')) = ''");
            });
        }
        $syncStatus = $validated['sync_status'] ?? null;
        if ($syncStatus === 'pending') {
            $stores->whereNotIn('id', FashionLogisticsSyncState::query()->select('assistant_domain_store_id'));
        } elseif ($syncStatus) {
            $stores->whereIn('id', FashionLogisticsSyncState::query()->where('status', $syncStatus)->select('assistant_domain_store_id'));
        }

        $stores = $stores->orderBy('shop_name')->paginate((int) ($validated['per_page'] ?? 20));
        $storeIds = $stores->getCollection()->pluck('id');
        $states = FashionLogisticsSyncState::query()->whereIn('assistant_domain_store_id', $storeIds)->get()->keyBy('assistant_domain_store_id');
        $orderCounts = FashionLogisticsOrder::query()->selectRaw('assistant_domain_store_id, COUNT(*) as total_count')
            ->whereIn('source_platform', self::PLATFORMS)->where('payment_status', '230')->whereIn('assistant_domain_store_id', $storeIds)
            ->groupBy('assistant_domain_store_id')->pluck('total_count', 'assistant_domain_store_id');
        $todayCounts = FashionLogisticsOrder::query()->selectRaw('assistant_domain_store_id, COUNT(*) as total_count')
            ->whereIn('source_platform', self::PLATFORMS)->where('payment_status', '230')->whereIn('assistant_domain_store_id', $storeIds)
            ->whereDate('order_created_at', now('Asia/Shanghai')->toDateString())->groupBy('assistant_domain_store_id')->pluck('total_count', 'assistant_domain_store_id');
        $rows = $stores->getCollection()->map(function (AssistantDomainStore $store) use ($states, $orderCounts, $todayCounts): array {
            $state = $states->get($store->getKey());

            return [
                'handle' => (string) $store->shop_name,
                'platform' => strtolower(trim((string) $store->site_builder)),
                'operator' => (string) ($store->user_name ?: __('nbc_orders.unassigned')),
                'ready' => filled(trim((string) $store->nbc_app_token)),
                'last_succeeded_at' => $state?->last_succeeded_at?->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s'),
                'status' => (string) ($state?->status ?: 'pending'),
                'total_count' => (int) ($orderCounts[$store->getKey()] ?? 0),
                'today_count' => (int) ($todayCounts[$store->getKey()] ?? 0),
                'last_error' => (string) ($state?->last_error ?: ''),
            ];
        })->values();

        return response()->json([
            'summary' => $summary,
            'rows' => $rows,
            'pagination' => [
                'current_page' => $stores->currentPage(),
                'last_page' => $stores->lastPage(),
                'per_page' => $stores->perPage(),
                'total' => $stores->total(),
                'from' => $stores->firstItem(),
                'to' => $stores->lastItem(),
            ],
        ]);
    }

    public function show(Request $request, FashionLogisticsOrder $order): Response|JsonResponse
    {
        $context = $this->portalEntries->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }
        $this->portalService->assertPortal($context['portal']);
        abort_unless($context['user']->can('portal.fuzhuangwuliu.orders'), 403, __('nbc_orders.errors.permission'));
        abort_unless(in_array($order->source_platform, self::PLATFORMS, true) && $order->payment_status === '230', 404);
        abort_unless(
            $this->applyStoreVisibility(AssistantDomainStore::query()->whereRaw("LOWER(TRIM(site_builder)) IN ('nbc', 'shopyy')")->where('shop_status', AssistantDomainStore::SHOP_STATUS_IN_USE), $context['user'])
                ->whereKey($order->assistant_domain_store_id)
                ->exists(),
            403,
        );
        $order->load(['items', 'store']);

        $attributes = $order->attributesToArray();
        foreach (['order_created_at', 'paid_at_utc'] as $field) {
            $attributes[$field] = $order->{$field}?->format('Y-m-d H:i:s');
        }
        foreach (['order_modified_at', 'source_synced_at'] as $field) {
            $attributes[$field] = $order->{$field}?->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s');
        }

        return response()->json([
            'order' => $attributes,
            'items' => $order->items,
            'store' => $order->store?->only(['id', 'shop_name', 'user_name']),
        ]);
    }

    private function applyStoreVisibility(Builder $query, AdminUser $user): Builder
    {
        if ($user->isAdministrator()) {
            return $query;
        }

        $names = collect([$user->name, $user->username])
            ->filter()
            ->map(fn ($name): string => trim((string) $name))
            ->unique()
            ->values()
            ->all();

        return $query->where(function (Builder $stores) use ($user, $names): void {
            $stores->where('owner_user_id', $user->getKey());
            if ($names !== []) {
                $stores->orWhereIn('user_name', $names);
            }
        });
    }
}
