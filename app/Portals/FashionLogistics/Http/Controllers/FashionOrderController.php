<?php

namespace App\Portals\FashionLogistics\Http\Controllers;

use App\Http\Controllers\Controller;
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
            'status' => ['nullable', 'string', 'max:50'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'per_page' => ['nullable', 'integer', 'in:20,50,100'],
        ]);

        $keyword = trim((string) ($validated['keyword'] ?? ''));
        // Keep the default list consistent with the sync summary, which counts
        // every status returned by XShoppy.
        $selectedStatus = (string) ($validated['status'] ?? 'all');
        $query = FashionLogisticsOrder::query()->with(['items', 'store']);
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
        if ($selectedStatus === 'effective') {
            $query->whereIn('status', ['processing', 'completed']);
        } elseif ($selectedStatus !== '' && $selectedStatus !== 'all') {
            $query->where('status', $selectedStatus);
        }
        $query->when($validated['date_from'] ?? null, fn ($query, $date) => $query->where('order_created_at', '>=', $date.' 00:00:00'));
        $query->when($validated['date_to'] ?? null, fn ($query, $date) => $query->where('order_created_at', '<=', $date.' 23:59:59'));

        $orders = $query->latest('order_created_at')->latest('id')->paginate((int) ($validated['per_page'] ?? 20))->withQueryString();
        $stores = AssistantDomainStore::query()->whereRaw("LOWER(TRIM(site_builder)) IN ('xshoppy','xshopy')")->orderBy('shop_name')->get(['id', 'shop_name', 'domain']);
        $syncStates = FashionLogisticsSyncState::query()->with('store:id,shop_name,domain')->latest('last_started_at')->get();
        $statuses = FashionLogisticsOrder::query()->whereNotNull('status')->where('status', '!=', '')->distinct()->orderBy('status')->pluck('status');

        return view('portals.fashion-logistics.orders.index', [
            ...$context,
            'orders' => $orders,
            'stores' => $stores,
            'syncStates' => $syncStates,
            'statuses' => $statuses,
            'filters' => [...$validated, 'status' => $selectedStatus],
        ]);
    }
}
