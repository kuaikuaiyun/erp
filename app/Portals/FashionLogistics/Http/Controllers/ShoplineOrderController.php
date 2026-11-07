<?php

namespace App\Portals\FashionLogistics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\AssistantDomainStore;
use App\Models\FashionLogisticsOrder;
use App\Models\FashionLogisticsSyncState;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\FashionLogistics\FashionLogisticsPortalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class ShoplineOrderController extends Controller
{
    private const PLATFORM = 'shopline';

    public function __construct(
        private readonly PortalEntryController $portalEntries,
        private readonly FashionLogisticsPortalService $portalService,
    ) {
    }

    public function index(Request $request): Response|View
    {
        $context = $this->portalEntries->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }
        $this->portalService->assertPortal($context['portal']);

        $validated = $request->validate([
            'keyword' => ['nullable', 'string', 'max:191'],
            'store_id' => ['nullable', 'integer'],
            'operator_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:open,cancelled,any'],
            'payment_status' => ['nullable', 'in:unpaid,authorized,pending,partially_paid,paid,partially_refunded,refunded'],
            'fulfillment_status' => ['nullable', 'in:unshipped,unfulfilled,partial,partially_fulfilled,shipped,fulfilled'],
            'marketing_source' => ['nullable', 'in:google_ads,tiktok,meta,other_utm,external_referral,direct_or_unknown'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'per_page' => ['nullable', 'integer', 'in:20,50,100'],
        ]);

        $stores = AssistantDomainStore::query()
            ->whereRaw("LOWER(TRIM(site_builder)) = 'shopline'")
            ->orderBy('shop_name')
            ->get(['id', 'shop_name', 'shopline_app_token', 'owner_user_id', 'user_name']);
        $query = FashionLogisticsOrder::query()
            ->with(['items', 'store'])
            ->where('source_platform', self::PLATFORM);
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
        $query->when(($validated['status'] ?? '') !== '' && ($validated['status'] ?? '') !== 'any', fn ($orders) => $orders->where('status', $validated['status']));
        $query->when($validated['payment_status'] ?? null, fn ($orders, $status) => $orders->where('payment_status', $status));
        $query->when($validated['fulfillment_status'] ?? null, fn ($orders, $status) => $orders->where('fulfillment_status', $status));
        $query->when($validated['marketing_source'] ?? null, fn ($orders, $source) => $orders->where('marketing_source', $source));
        $query->when($validated['date_from'] ?? null, fn ($orders, $date) => $orders->where('order_created_at', '>=', $date.' 00:00:00'));
        $query->when($validated['date_to'] ?? null, fn ($orders, $date) => $orders->where('order_created_at', '<=', $date.' 23:59:59'));

        $selectedOperator = isset($validated['operator_id']) ? AdminUser::query()->find((int) $validated['operator_id']) : null;
        if ($selectedOperator) {
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

        return view('portals.fashion-logistics.shopline-orders.index', [
            ...$context,
            'orders' => $orders,
            'stores' => $stores,
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

        $validated = $request->validate([
            'keyword' => ['nullable', 'string', 'max:191'],
            'configuration' => ['nullable', 'in:ready,missing'],
            'sync_status' => ['nullable', 'in:pending,syncing,success,failed'],
            'per_page' => ['nullable', 'integer', 'in:20,50,100'],
        ]);
        $base = AssistantDomainStore::query()->whereRaw("LOWER(TRIM(site_builder)) = 'shopline'");
        $summaryBase = clone $base;
        $summary = [
            'total' => (clone $summaryBase)->count(),
            'ready' => (clone $summaryBase)->whereNotNull('shop_name')->where('shop_name', '!=', '')
                ->whereNotNull('shopline_app_token')->where('shopline_app_token', '!=', '')->count(),
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
            $stores->whereNotNull('shop_name')->where('shop_name', '!=', '')
                ->whereNotNull('shopline_app_token')->where('shopline_app_token', '!=', '');
        }
        if (($validated['configuration'] ?? '') === 'missing') {
            $stores->where(function ($query): void {
                $query->whereNull('shop_name')->orWhere('shop_name', '')
                    ->orWhereNull('shopline_app_token')->orWhere('shopline_app_token', '');
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
            ->where('source_platform', self::PLATFORM)->whereIn('assistant_domain_store_id', $storeIds)
            ->groupBy('assistant_domain_store_id')->pluck('total_count', 'assistant_domain_store_id');
        $todayCounts = FashionLogisticsOrder::query()->selectRaw('assistant_domain_store_id, COUNT(*) as total_count')
            ->where('source_platform', self::PLATFORM)->whereIn('assistant_domain_store_id', $storeIds)
            ->whereDate('order_created_at', today())->groupBy('assistant_domain_store_id')->pluck('total_count', 'assistant_domain_store_id');
        $rows = $stores->getCollection()->map(function (AssistantDomainStore $store) use ($states, $orderCounts, $todayCounts): array {
            $state = $states->get($store->getKey());
            $hasHandle = preg_match('/^[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?$/', strtolower(trim((string) $store->shop_name))) === 1;
            return [
                'handle' => (string) $store->shop_name,
                'operator' => (string) ($store->user_name ?: '未绑定运营'),
                'ready' => $hasHandle && filled($store->shopline_app_token),
                'last_succeeded_at' => $state?->last_succeeded_at?->format('Y-m-d H:i:s'),
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
        abort_unless($order->source_platform === self::PLATFORM, 404);
        $order->load(['items', 'store']);

        return response()->json([
            'order' => $order,
            'items' => $order->items,
            'store' => $order->store?->only(['id', 'shop_name', 'user_name']),
        ]);
    }
}
