<?php

namespace App\Portals\FashionLogistics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\AssistantDomainStore;
use App\Models\FashionLogisticsOrder;
use App\Models\FashionLogisticsSyncState;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\FashionLogistics\FashionLogisticsPortalService;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class FashionOrderController extends Controller
{
    public function __construct(
        private readonly PortalEntryController $portalEntries,
        private readonly FashionLogisticsPortalService $portalService,
    ) {
    }

    public function index(Request $request): Response|View
    {
        $context = $this->portalEntries->portalContext($request);
        if ($context instanceof Response) return $context;
        $this->portalService->assertPortal($context['portal']);

        $validated = $request->validate([
            'keyword' => ['nullable', 'string', 'max:191'],
            'store_id' => ['nullable', 'integer'],
            'store_keyword' => ['nullable', 'string', 'max:191'],
            'operator_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', 'in:completed,processing'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'per_page' => ['nullable', 'integer', 'in:20,50,100'],
        ]);

        $keyword = trim((string) ($validated['keyword'] ?? ''));
        $storeKeyword = trim((string) ($validated['store_keyword'] ?? ''));
        $selectedStatus = (string) ($validated['status'] ?? 'completed');
        $query = FashionLogisticsOrder::query()
            ->with(['items', 'store'])
            ->where('source_platform', 'xshoppy');
        if ($keyword !== '') {
            $query->where(function ($query) use ($keyword): void {
                $query->where('order_number', 'like', "%{$keyword}%")
                    ->orWhere('external_order_id', 'like', "%{$keyword}%")
                    ->orWhere('transaction_id', 'like', "%{$keyword}%")
                    ->orWhere('customer_name', 'like', "%{$keyword}%")
                    ->orWhere('customer_email', 'like', "%{$keyword}%")
                    ->orWhereHas('items', fn ($items) => $items->where('sku', 'like', "%{$keyword}%")->orWhere('product_name', 'like', "%{$keyword}%"));
            });
        }
        $query->when($validated['store_id'] ?? null, fn ($query, $id) => $query->where('assistant_domain_store_id', $id));
        if ($storeKeyword !== '') {
            $query->where(function ($stores) use ($storeKeyword): void {
                $stores->where('store_name', 'like', "%{$storeKeyword}%")
                    ->orWhere('source_domain', 'like', "%{$storeKeyword}%")
                    ->orWhereHas('store', function ($store) use ($storeKeyword): void {
                        $store->where('shop_name', 'like', "%{$storeKeyword}%")
                            ->orWhere('domain', 'like', "%{$storeKeyword}%");
                    });
            });
        }
        $selectedOperator = isset($validated['operator_id'])
            ? AdminUser::query()->find((int) $validated['operator_id'])
            : null;
        if ($selectedOperator) {
            $operatorNames = collect([$selectedOperator->name, $selectedOperator->username])
                ->filter()->map(fn ($name): string => trim((string) $name))->unique()->values()->all();
            $query->whereHas('store', function ($stores) use ($selectedOperator, $operatorNames): void {
                $stores->where(function ($owner) use ($selectedOperator, $operatorNames): void {
                    $owner->where('owner_user_id', $selectedOperator->getKey());
                    if ($operatorNames !== []) $owner->orWhereIn('user_name', $operatorNames);
                });
            });
        }
        $query->where('status', $selectedStatus);
        $query->when($validated['date_from'] ?? null, fn ($query, $date) => $query->where('order_created_at', '>=', $date.' 00:00:00'));
        $query->when($validated['date_to'] ?? null, fn ($query, $date) => $query->where('order_created_at', '<=', $date.' 23:59:59'));

        $orders = $query->latest('order_created_at')->latest('id')->paginate((int) ($validated['per_page'] ?? 20))->withQueryString();
        $stores = AssistantDomainStore::query()->whereRaw("LOWER(TRIM(site_builder)) IN ('xshoppy','xshopy')")->orderBy('shop_name')->get(['id', 'shop_name', 'domain', 'owner_user_id', 'user_name']);
        $ownerIds = $stores->pluck('owner_user_id')->filter()->map(fn ($id): int => (int) $id)->unique()->values();
        $ownerNames = $stores->pluck('user_name')->filter()->map(fn ($name): string => trim((string) $name))->unique()->values();
        $operators = AdminUser::query()
            ->where(function ($users) use ($ownerIds, $ownerNames): void {
                if ($ownerIds->isNotEmpty()) $users->whereIn('id', $ownerIds);
                if ($ownerNames->isNotEmpty()) {
                    $method = $ownerIds->isNotEmpty() ? 'orWhereIn' : 'whereIn';
                    $users->{$method}('name', $ownerNames)->orWhereIn('username', $ownerNames);
                }
                if ($ownerIds->isEmpty() && $ownerNames->isEmpty()) $users->whereRaw('1 = 0');
            })
            ->orderBy('name')->orderBy('username')->get(['id', 'name', 'username']);
        $syncStates = FashionLogisticsSyncState::query()
            ->with('store:id,shop_name,domain')
            ->whereHas('store', static fn ($stores) => $stores
                ->whereRaw("LOWER(TRIM(site_builder)) IN ('xshoppy','xshopy')"))
            ->withCount(['orders as stored_orders_count' => static fn ($orders) => $orders
                ->where('source_platform', 'xshoppy')])
            ->latest('last_started_at')
            ->get();
        return view('portals.fashion-logistics.orders.index', [
            ...$context,
            'orders' => $orders,
            'stores' => $stores,
            'operators' => $operators,
            'syncStates' => $syncStates,
            'filters' => [...$validated, 'status' => $selectedStatus],
        ]);
    }
}
