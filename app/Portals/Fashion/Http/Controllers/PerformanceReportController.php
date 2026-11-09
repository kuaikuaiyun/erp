<?php

namespace App\Portals\Fashion\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\FashionLogisticsOrder;
use App\Models\AdminUser;
use App\Models\Department;
use App\Portal\Http\Controllers\PortalEntryController;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class PerformanceReportController extends Controller
{
    private const PORTAL_CODE = 'fuzhuang';

    public function __construct(private readonly PortalEntryController $portalEntries)
    {
    }

    public function index(Request $request): Response|View
    {
        $context = $this->portalEntries->portalContext($request);
        if ($context instanceof Response) return $context;
        abort_unless($context['portal']->code === self::PORTAL_CODE, 404);

        $validated = $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'operator_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'in:20,50,100'],
        ]);

        $month = now();
        $dateFrom = $validated['date_from'] ?? $month->copy()->startOfMonth()->toDateString();
        $dateTo = $validated['date_to'] ?? $month->copy()->endOfMonth()->toDateString();
        if ($dateFrom > $dateTo) [$dateFrom, $dateTo] = [$dateTo, $dateFrom];

        $user = $context['user'];
        $connection = config('admin.database.connection') ?: config('database.default');
        $storeTable = 'assistant_domain_stores';
        $userTable = config('admin.database.users_table');
        $orderTable = (new FashionLogisticsOrder())->getTable();

        $base = DB::connection($connection)->table($orderTable.' as orders')
            ->join($storeTable.' as stores', 'stores.id', '=', 'orders.assistant_domain_store_id')
            ->leftJoin($userTable.' as owners', 'owners.id', '=', 'stores.owner_user_id')
            ->whereBetween('orders.order_created_at', [$dateFrom.' 00:00:00', $dateTo.' 23:59:59'])
            ->whereRaw("LOWER(TRIM(stores.site_builder)) IN ('xshoppy','xshopy')")
            ->where(function ($paid): void {
                $paid->whereNotNull('orders.paid_at')
                    ->orWhereIn('orders.status', ['processing', 'completed']);
            });

        if (! $user->isAdministrator()) {
            $ownerNames = collect([$user->name, $user->username])
                ->filter()->map(fn ($name): string => trim((string) $name))->unique()->values()->all();
            $base->where(function ($binding) use ($user, $ownerNames): void {
                $binding->where('stores.owner_user_id', $user->getKey());
                if ($ownerNames !== []) $binding->orWhereIn('stores.user_name', $ownerNames);
            });
        } elseif (! empty($validated['operator_id'])) {
            $operator = AdminUser::query()->find((int) $validated['operator_id']);
            $operatorNames = collect([$operator?->name, $operator?->username])
                ->filter()->map(fn ($name): string => trim((string) $name))->unique()->values()->all();
            $base->where(function ($binding) use ($validated, $operatorNames): void {
                $binding->where('stores.owner_user_id', (int) $validated['operator_id']);
                if ($operatorNames !== []) $binding->orWhereIn('stores.user_name', $operatorNames);
            });
        }

        $summary = (clone $base)->selectRaw(
            'COUNT(*) as order_count, COALESCE(SUM(orders.total_amount), 0) as revenue, COUNT(DISTINCT stores.id) as store_count'
        )->first();

        $rows = (clone $base)
            ->selectRaw('DATE(orders.order_created_at) as report_date')
            ->addSelect([
                'stores.id as store_id',
                'stores.shop_name',
                'stores.domain',
                'stores.site_builder',
                'stores.owner_user_id',
                'owners.name as owner_name',
                'owners.username as owner_username',
            ])
            ->selectRaw('COUNT(*) as order_count, COALESCE(SUM(orders.total_amount), 0) as revenue')
            ->groupByRaw('DATE(orders.order_created_at), stores.id, stores.shop_name, stores.domain, stores.site_builder, stores.owner_user_id, owners.name, owners.username')
            ->orderByDesc('report_date')
            ->orderBy('owner_name')
            ->orderBy('stores.shop_name')
            ->paginate((int) ($validated['per_page'] ?? 20))
            ->withQueryString();

        $operators = collect();
        if ($user->isAdministrator()) {
            $storeOperators = DB::connection($connection)->table($storeTable.' as stores')
                ->join($userTable.' as owners', 'owners.id', '=', 'stores.owner_user_id')
                ->whereRaw("LOWER(TRIM(stores.site_builder)) IN ('xshoppy','xshopy')")
                ->select(['owners.id', 'owners.name', 'owners.username'])
                ->distinct()->orderBy('owners.name')->get();
            $operators = $this->fashionOperators()
                ->concat($storeOperators)
                ->unique(fn ($operator) => (int) $operator->id)
                ->sortBy(fn ($operator) => mb_strtolower((string) ($operator->name ?: $operator->username)))
                ->values();
        }

        $orderCount = (int) ($summary->order_count ?? 0);
        $revenue = (float) ($summary->revenue ?? 0);

        return view('portals.fashion.performance-report.index', [
            ...$context,
            'rows' => $rows,
            'operators' => $operators,
            'filters' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'operator_id' => $validated['operator_id'] ?? '',
                'per_page' => (int) ($validated['per_page'] ?? 20),
            ],
            'summary' => [
                'order_count' => $orderCount,
                'revenue' => $revenue,
                'average_order_value' => $orderCount > 0 ? $revenue / $orderCount : 0,
                'store_count' => (int) ($summary->store_count ?? 0),
            ],
        ]);
    }

    public function orders(Request $request): Response|JsonResponse
    {
        $context = $this->portalEntries->portalContext($request);
        if ($context instanceof Response) return $context;
        abort_unless($context['portal']->code === self::PORTAL_CODE, 404);
        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'store_id' => ['required', 'integer'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $user = $context['user'];
        $store = DB::connection(config('admin.database.connection') ?: config('database.default'))
            ->table('assistant_domain_stores')
            ->where('id', (int) $validated['store_id'])
            ->whereRaw("LOWER(TRIM(site_builder)) IN ('xshoppy','xshopy')")
            ->when(! $user->isAdministrator(), function ($query) use ($user): void {
                $names = collect([$user->name, $user->username])->filter()->map(fn ($name) => trim((string) $name))->unique()->values()->all();
                $query->where(function ($binding) use ($user, $names): void {
                    $binding->where('owner_user_id', $user->getKey());
                    if ($names !== []) $binding->orWhereIn('user_name', $names);
                });
            })->first();
        abort_unless($store, 403, '无权查看该店铺订单。');

        $ordersQuery = FashionLogisticsOrder::query()
            ->where('assistant_domain_store_id', (int) $validated['store_id'])
            ->where('source_platform', 'xshoppy')
            ->whereBetween('order_created_at', [$validated['date'].' 00:00:00', $validated['date'].' 23:59:59'])
            ->where(fn ($query) => $query->whereNotNull('paid_at')->orWhereIn('status', ['processing', 'completed']));
        $amount = (float) (clone $ordersQuery)->sum('total_amount');
        $orders = $ordersQuery
            ->with(['items:id,fashion_logistics_order_id,product_name,sku,quantity,total'])
            ->orderByDesc('order_created_at')->orderByDesc('id')->paginate(20);

        return response()->json([
            'date' => $validated['date'],
            'store' => (string) ($store->shop_name ?: $store->domain),
            'count' => $orders->total(),
            'amount' => round($amount, 2),
            'pagination' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
                'from' => $orders->firstItem(),
                'to' => $orders->lastItem(),
            ],
            'orders' => $orders->getCollection()->map(fn (FashionLogisticsOrder $order): array => [
                'id' => (int) $order->getKey(),
                'order_number' => (string) ($order->order_number ?: $order->external_order_id),
                'status' => (string) ($order->status ?: '—'),
                'currency' => (string) ($order->currency ?: 'USD'),
                'total_amount' => (float) $order->total_amount,
                'customer_name' => (string) ($order->customer_name ?: '—'),
                'customer_email' => (string) ($order->customer_email ?: '—'),
                'payment_method' => (string) ($order->payment_method_title ?: $order->payment_method ?: '—'),
                'order_created_at' => $order->order_created_at?->format('Y-m-d H:i:s'),
                'paid_at' => $order->paid_at?->format('Y-m-d H:i:s'),
                'items' => $order->items->map(fn ($item): array => [
                    'product_name' => (string) ($item->product_name ?: '—'),
                    'sku' => (string) ($item->sku ?: '—'),
                    'quantity' => (float) $item->quantity,
                    'total' => (float) $item->total,
                ])->values()->all(),
            ])->values()->all(),
        ]);
    }

    private function fashionOperators()
    {
        $root = Department::business()->where('name', '服装运营部门')->first(['id']);
        if (! $root) return collect();

        $departments = Department::business()->get(['id', 'parent_id']);
        $departmentIds = [(int) $root->id];
        do {
            $children = $departments->whereIn('parent_id', $departmentIds)
                ->pluck('id')->map(fn ($id): int => (int) $id)
                ->diff($departmentIds)->values()->all();
            $before = count($departmentIds);
            $departmentIds = array_values(array_unique([...$departmentIds, ...$children]));
        } while (count($departmentIds) > $before);

        return AdminUser::query()
            ->where('status', AdminUser::STATUS_ACTIVE)
            ->whereHas('departments', fn ($query) => $query->whereIn('id', $departmentIds))
            ->get(['id', 'name', 'username']);
    }
}
