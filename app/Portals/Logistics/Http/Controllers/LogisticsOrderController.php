<?php

namespace App\Portals\Logistics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AssistantDomainStore;
use App\Models\LogisticsOrder;
use App\Models\LogisticsSyncLog;
use App\Models\LogisticsRefundDetail;
use App\Models\LogisticsTongtuSkuMapping;
use App\Models\LogisticsTrackingSyncBatch;
use App\Models\LogisticsTrackingSyncTask;
use App\Models\ProductRequestLinkSku;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portal\Services\PortalDomainService;
use App\Portals\Logistics\LogisticsOrderSpreadsheetService;
use App\Portals\Logistics\LogisticsOrderItemService;
use App\Portals\Logistics\LogisticsOrderPaymentReferenceService;
use App\Portals\Logistics\LogisticsPortalService;
use App\Portals\Logistics\LogisticsRefundImportService;
use App\Portals\Logistics\TongtuOrderSnapshotService;
use App\Portals\Logistics\StreamedXlsxWriter;
use App\Portals\Logistics\TongtuSkuMappingService;
use App\Portals\Logistics\LogisticsTrackingService;
use App\Portals\Logistics\PayPalOrderAssociationService;
use App\Portals\Assistant\AssistantStoreOwnerTimelineService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class LogisticsOrderController extends Controller
{
    private const EXCLUDED_TONGTU_RECONCILIATION_ORDERS = [
        'aerynix-2009-m9724',
        'briellev-1004.1',
        'briellev-1004.2',
    ];

    private const EXCLUDED_TONGTU_RECONCILIATION_DOMAINS = [
        'demihin.com',
        'asbaght.com',
        'soremina.com',
        'kyfren.com',
        'yeeshone.com',
    ];

    public function __construct(
        private readonly PortalEntryController $portalEntries,
        private readonly LogisticsPortalService $logistics,
        private readonly LogisticsOrderSpreadsheetService $spreadsheets,
        private readonly LogisticsRefundImportService $refundImports,
        private readonly TongtuSkuMappingService $skuMappings,
        private readonly LogisticsTrackingService $tracking,
        private readonly LogisticsOrderPaymentReferenceService $paymentReferences,
        private readonly PayPalOrderAssociationService $paypalAssociations,
        private readonly AssistantStoreOwnerTimelineService $ownerTimeline,
    ) {
    }

    public function index(Request $request): Response|View
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }

        $filters = $this->filters($request);
        $orders = $this->orderQuery($filters)
            ->with([
                'items' => fn ($query) => $query->orderBy('id'),
                'store:id,domain',
                'paypalRefunds' => fn ($query) => $query->orderByDesc('refunded_at')->orderByDesc('id'),
                'creditCardRefunds' => fn ($query) => $query->orderByDesc('occurred_at')->orderByDesc('id'),
                'paymentReferences' => fn ($query) => $query->orderByRaw("CASE reference_type WHEN 'primary' THEN 0 ELSE 1 END")->orderBy('id'),
            ])
            ->orderByDesc('order_created_at')
            ->orderByDesc('id')
            ->paginate($filters['per_page'])
            ->withQueryString();
        $trackingBatch = $this->currentTrackingBatch($request, (int) $context['user']->getKey());
        $nonRunnableProductKeys = $this->nonRunnableProductKeys();

        return view('portals.logistics.orders.index', [
            ...$this->viewContext($context),
            'orders' => $orders,
            'historicalDomains' => app(\App\Portals\Assistant\AssistantStoreDomainTimelineService::class)
                ->domainsForOrders($orders->getCollection()),
            'filters' => $filters,
            'filterOptions' => $this->filterOptions(),
            'statusOptions' => LogisticsOrder::dataStatusOptions(),
            'trackingOptions' => LogisticsOrder::logisticsStatusOptions(),
            'shopifyStores' => $this->shopifyStores(),
            'activeTrackingBatch' => $trackingBatch,
            'trackingFailureDetails' => $trackingBatch ? $this->trackingFailureDetails($trackingBatch) : [],
            'nonRunnableProductKeys' => $nonRunnableProductKeys,
        ]);
    }

    public function create(Request $request): Response|View
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) return $context;

        return view('portals.logistics.orders.create', [
            ...$this->viewContext($context),
            'stores' => $this->shopifyStores(),
        ]);
    }

    public function store(Request $request, LogisticsOrderItemService $items): RedirectResponse
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) abort(403);

        // Only the explicit manual-entry fields can be persisted. Source payloads,
        // ownership snapshots and matched payment accounts are never client inputs.
        $input = $request->all();
        array_walk_recursive($input, static function (&$value): void {
            if (is_string($value)) $value = trim($value);
        });
        $request->replace($input);
        $money = ['required', 'numeric', 'min:0', 'regex:/^\d{1,14}(?:\.\d{1,4})?$/D'];
        $data = $request->validate([
            'assistant_domain_store_id' => ['required', 'integer'],
            'shopify_order_id' => ['required', 'string', 'regex:/^[1-9][0-9]{0,99}$/D'],
            'shopify_order_number' => ['required', 'string', 'max:100', 'not_regex:/^#+$/D'],
            'order_created_at' => ['required', 'date_format:Y-m-d\TH:i,Y-m-d\TH:i:s'],
            'paid_at' => ['required', 'date_format:Y-m-d\TH:i,Y-m-d\TH:i:s'],
            'paid_amount_usd' => $money,
            'total_shipping_usd' => ['nullable', 'numeric', 'min:0', 'regex:/^\d{1,14}(?:\.\d{1,4})?$/D'],
            'buyer_name' => ['nullable', 'string', 'max:191'],
            'buyer_email' => ['nullable', 'email', 'max:191'],
            'buyer_phone' => ['nullable', 'string', 'max:100'],
            'operator_name' => ['nullable', 'string', 'max:100'],
            'destination' => ['nullable', 'string', 'max:255'],
            'payment_method' => ['required', 'string', 'max:191'],
            'payment_gateway' => ['required', 'string', 'max:191'],
            'collection_account' => ['nullable', 'string', 'max:255'],
            'collection_account_email' => ['nullable', 'email', 'max:191'],
            'transaction_id' => ['nullable', 'string', 'max:255'],
            'bill_number' => ['nullable', 'string', 'max:255'],
            'payment_gateway_order_id' => ['nullable', 'string', 'max:255'],
            'is_add_on' => ['required', Rule::in(['no', 'yes'])],
            'subscription_kind' => ['required', Rule::in(['none', 'initial', 'renewal'])],
            'renewal_number' => ['required_if:subscription_kind,renewal', 'nullable', 'integer', 'min:1', 'max:9999'],
            'subscription_origin_order_id' => ['required_if:subscription_kind,renewal', 'nullable', 'string', 'regex:/^[1-9][0-9]{0,99}$/D'],
            'shipping_company' => ['nullable', 'string', 'max:191'],
            'tracking_number' => ['nullable', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.shopify_sku' => ['required', 'string', 'max:191'],
            'items.*.product_title' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'items.*.unit_price_usd' => $money,
        ], [], __('logistics.orders.manual.fields'));

        $connection = config('admin.database.connection') ?: config('database.default');
        $domain = null;
        try {
            $order = DB::connection($connection)->transaction(function () use ($data, $context, $items, &$domain): LogisticsOrder {
                $store = AssistantDomainStore::query()->whereKey($data['assistant_domain_store_id'])
                    ->where('site_builder', 'shopify')->where('shop_status', AssistantDomainStore::SHOP_STATUS_IN_USE)
                    ->lockForUpdate()->first();
                if (!$store) throw ValidationException::withMessages(['assistant_domain_store_id' => __('logistics.orders.manual.invalid_store')]);

                $host = strtolower((string) parse_url(str_contains((string) $store->shop_login_url, '://') ? $store->shop_login_url : 'https://'.$store->shop_login_url, PHP_URL_HOST));
                $domain = preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/D', $host) ? $host : null;
                $duplicate = LogisticsOrder::query()->where('shopify_order_id', $data['shopify_order_id'])
                    ->where(function ($query) use ($store, $domain): void {
                        $query->where('assistant_domain_store_id', $store->getKey());
                        if ($domain !== null) $query->orWhere('source_shop_domain', $domain);
                    })->exists();
                if ($duplicate) throw ValidationException::withMessages(['shopify_order_id' => __('logistics.orders.manual.duplicate')]);
                if ($data['subscription_kind'] === 'renewal' && $data['subscription_origin_order_id'] === $data['shopify_order_id']) {
                    throw ValidationException::withMessages(['subscription_origin_order_id' => __('logistics.orders.manual.self_origin')]);
                }

                $attributes = Arr::except($data, ['items', 'assistant_domain_store_id', 'subscription_kind', 'renewal_number', 'is_add_on', 'subscription_origin_order_id']);
                foreach (['order_created_at', 'paid_at'] as $field) {
                    $value = $data[$field];
                    if (strlen($value) === 16) $value .= ':00';
                    $attributes[$field] = \Carbon\CarbonImmutable::createFromFormat('!Y-m-d\TH:i:s', $value, 'Asia/Shanghai')->utc();
                }
                $number = ltrim($data['shopify_order_number'], '#');
                $order = new LogisticsOrder([
                    ...$attributes,
                    'assistant_domain_store_id' => $store->getKey(), 'source_shop_domain' => $domain,
                    'shopify_order_number' => $number, 'tongtu_order_number' => $store->makeTongtuOrderNumber($number),
                    'store_name' => $store->shop_name, 'group_names' => AssistantDomainStore::displayMultiValue($store->team),
                    'staff_status' => '正常', 'payment_status' => '已付款', 'data_status' => LogisticsOrder::STATUS_NORMAL,
                    'logistics_status' => LogisticsOrder::LOGISTICS_PENDING,
                    'is_add_on' => $data['is_add_on'] === 'yes' ? '已加购' : '未加购',
                    'subscription_type' => match ($data['subscription_kind']) {
                        'initial' => '订阅首次购买', 'renewal' => '订阅复购'.$data['renewal_number'].'次', default => '无订阅',
                    },
                    'subscription_status' => $data['subscription_kind'] === 'none' ? '未订阅' : '已订阅',
                    'subscription_origin_order_id' => $data['subscription_kind'] === 'renewal' ? $data['subscription_origin_order_id'] : null,
                ]);
                $this->ownerTimeline->applySnapshotIfMissing($order, $store);
                $order->save();
                // Reuse the existing item/revenue allocation with transient input.
                // No Shopify request or fabricated original JSON is stored.
                $nodes = array_map(static fn (array $row): array => [
                    'sku' => $row['shopify_sku'], 'title' => $row['product_title'], 'quantity' => $row['quantity'], 'price' => $row['unit_price_usd'],
                ], array_values($data['items']));
                $items->syncFromShopifyPayload($order, ['lineItems' => ['nodes' => $nodes]]);
                $this->paymentReferences->syncFromAttributes($order, []);
                $this->paypalAssociations->reconcileOrder($order);
                $this->tracking->scheduleForOrder($order);
                $this->skuMappings->scheduleForOrder($order);
                LogisticsSyncLog::query()->create([
                    'logistics_order_id' => $order->getKey(), 'source' => 'manual', 'action' => 'create', 'status' => 'success',
                    'message' => __('logistics.orders.manual.audit'), 'actor_id' => $context['user']->getKey(), 'actor_name' => $context['user']->name,
                    'context' => json_encode(['item_count' => count($nodes)], JSON_THROW_ON_ERROR),
                ]);
                return $order;
            });
        } catch (\Illuminate\Database\UniqueConstraintViolationException $exception) {
            $exists = LogisticsOrder::query()->where('shopify_order_id', $data['shopify_order_id'])->where(function ($query) use ($data, $domain): void {
                $query->where('assistant_domain_store_id', $data['assistant_domain_store_id']);
                if ($domain !== null) $query->orWhere('source_shop_domain', $domain);
            })->exists();
            if (!$exists) throw $exception;
            throw ValidationException::withMessages(['shopify_order_id' => __('logistics.orders.manual.duplicate')]);
        }

        return redirect()->route('logistics.orders.index', ['shopify_order_id' => $order->shopify_order_id])->with('success', __('logistics.orders.manual.saved'));
    }

    public function missingTongtu(Request $request, PortalDomainService $domains, TongtuOrderSnapshotService $snapshots): Response|View|JsonResponse
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }

        $menu = $context['portal']->menus->firstWhere('code', 'logistics_missing_tongtu_orders');
        abort_unless($menu?->is_enabled, 403);

        $filters = $request->validate([
            'paid_from' => ['nullable', 'date_format:Y-m-d'],
            'paid_to' => ['nullable', 'date_format:Y-m-d', Rule::when($request->filled('paid_from'), 'after_or_equal:paid_from')],
            'store_domain' => ['nullable', 'string', 'max:255'],
            'tongtu_order_number' => ['nullable', 'string', 'max:191'],
            'per_page' => ['sometimes', 'integer', Rule::in([20, 50, 100])],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $today = \Carbon\CarbonImmutable::today('Asia/Shanghai');
        $paidFrom = $filters['paid_from'] ?? '';
        $paidTo = $filters['paid_to'] ?? '';
        $storeDomain = trim($filters['store_domain'] ?? '');
        $tongtuOrderNumber = trim($filters['tongtu_order_number'] ?? '');
        $coverageFrom = $paidFrom ?: $today->subDays(TongtuOrderSnapshotService::HISTORY_DAYS - 1)->toDateString();
        $coverageTo = $paidTo ?: $today->toDateString();
        $totalDays = max(0, (int) \Carbon\CarbonImmutable::parse($coverageFrom)->diffInDays($coverageTo, false) + 1);
        $db = $snapshots->connection();

        $syncDays = $db->table('logistics_tongtu_sync_days')
            ->when($paidFrom !== '', fn (QueryBuilder $query) => $query->where('paid_date', '>=', $paidFrom))
            ->when($paidTo !== '', fn (QueryBuilder $query) => $query->where('paid_date', '<=', $paidTo));
        $lastSync = (clone $syncDays)->max('synced_at');
        $syncedDays = $syncDays->whereBetween('paid_date', [$coverageFrom, $coverageTo])->count();
        if ($request->boolean('progress')) {
            return response()->json([
                'synced_days' => $syncedDays,
                'total_days' => $totalDays,
                'last_sync' => $lastSync,
                'active' => $snapshots->progress(),
            ])->header('Cache-Control', 'no-store');
        }

        $existing = $this->listedOrders()->selectRaw('1')
            ->whereColumn('logistics_orders.tongtu_order_number', 'snapshot.tongtu_order_number');
        $query = $db->table('logistics_tongtu_order_snapshots as snapshot')
            ->when($paidFrom !== '', fn (QueryBuilder $query) => $query->where('snapshot.paid_date', '>=', $paidFrom))
            ->when($paidTo !== '', fn (QueryBuilder $query) => $query->where('snapshot.paid_date', '<=', $paidTo))
            ->whereNotExists($existing->toBase())
            ->when($tongtuOrderNumber !== '', fn (QueryBuilder $query) => $query->where(
                'snapshot.tongtu_order_number', 'like', $this->contains($tongtuOrderNumber),
            ))
            ->selectRaw('snapshot.tongtu_order_number AS number, MAX(snapshot.domain) AS domain')
            ->groupBy('snapshot.tongtu_order_number')
            ->orderBy('snapshot.tongtu_order_number');
        $perPage = (int) ($filters['per_page'] ?? 20);
        $paginationFilters = array_filter([
            'paid_from' => $paidFrom,
            'paid_to' => $paidTo,
            'store_domain' => $storeDomain,
            'tongtu_order_number' => $tongtuOrderNumber,
            'per_page' => $perPage,
        ], fn ($value) => $value !== '');
        $groups = $this->groupMissingTongtuOrders($this->matchingMissingTongtuOrders($query, $storeDomain));
        if ($request->boolean('export')) {
            return app(StreamedXlsxWriter::class)->response(
                'missing-tongtu-orders-'.$today->toDateString().'.xlsx',
                '缺失通途订单',
                [
                    ['label' => '店铺域名', 'width' => 30],
                    ['label' => '通途订单号', 'width' => 110],
                ],
                static function () use ($groups): iterable {
                    foreach ($groups as $group) {
                        // Keep large groups within Excel's text and row-height limits.
                        $lines = [];
                        $line = '';
                        foreach ($group['numbers'] as $number) {
                            if ($line !== '' && mb_strwidth($line.', '.$number) > 100) {
                                $lines[] = $line;
                                $line = '';
                            }
                            $line .= ($line === '' ? '' : ', ').$number;
                        }
                        $lines[] = $line;

                        foreach (array_chunk($lines, 20) as $index => $part) {
                            yield [
                                'height' => max(18, count($part) * 17 + 4),
                                'cells' => [
                                    ['value' => $index === 0 ? ($group['domain'] ?? '未匹配域名') : ''],
                                    ['value' => implode("\n", $part), 'wrap' => true],
                                ],
                            ];
                        }
                    }
                },
            );
        }

        $orders = $this->paginateMissingTongtuOrders($groups, $perPage)->appends($paginationFilters);
        $checkUrl = $domains->portalPath($context['portal'], $menu->path);

        return view('portals.logistics.orders.missing-tongtu', [
            ...$this->viewContext($context),
            'checkUrl' => $checkUrl,
            'exportUrl' => $checkUrl.'?'.http_build_query(['export' => 1] + $paginationFilters),
            'paidFrom' => $paidFrom,
            'paidTo' => $paidTo,
            'storeDomain' => $storeDomain,
            'tongtuOrderNumber' => $tongtuOrderNumber,
            'orders' => $orders,
            'paginationFilters' => $paginationFilters,
            'syncedDays' => $syncedDays,
            'totalDays' => $totalDays,
            'coverageFrom' => $coverageFrom,
            'coverageTo' => $coverageTo,
            'lastSync' => $lastSync,
        ]);
    }

    private function matchingMissingTongtuOrders(QueryBuilder $query, string $storeDomain): iterable
    {
        $domainsByPrefix = $this->tongtuDomainsByPrefix();
        foreach ($query->cursor() as $order) {
            if (in_array(mb_strtolower(trim($order->number)), self::EXCLUDED_TONGTU_RECONCILIATION_ORDERS, true)) {
                continue;
            }
            $order = $this->resolveTongtuDomain($order, $domainsByPrefix);
            if ($order->domain !== null && in_array(strtolower($order->domain), self::EXCLUDED_TONGTU_RECONCILIATION_DOMAINS, true)) {
                continue;
            }
            if ($storeDomain === '' || ($order->domain !== null && mb_stripos($order->domain, $storeDomain) !== false)) {
                yield $order;
            }
        }
    }

    private function groupMissingTongtuOrders(iterable $matches): array
    {
        $groups = [];
        foreach ($matches as $order) {
            $key = $order->domain === null ? 'order:'.$order->number : 'domain:'.mb_strtolower($order->domain);
            $groups[$key] ??= ['domain' => $order->domain, 'numbers' => []];
            $groups[$key]['numbers'][] = $order->number;
        }

        return $groups;
    }

    private function paginateMissingTongtuOrders(array $groups, int $perPage): LengthAwarePaginator
    {
        $page = LengthAwarePaginator::resolveCurrentPage();
        $offset = ($page - 1) * $perPage;

        return new LengthAwarePaginator(
            array_values(array_slice($groups, $offset, $perPage)),
            count($groups),
            $perPage,
            $page,
            ['path' => LengthAwarePaginator::resolveCurrentPath()],
        );
    }

    private function tongtuDomainsByPrefix(): Collection
    {
        return AssistantDomainStore::query()
            ->where('site_builder', 'shopify')
            ->whereNotNull('tongtu_prefix')
            ->whereNotNull('domain')
            ->get(['tongtu_prefix', 'domain'])
            ->groupBy(fn (AssistantDomainStore $store): string => mb_strtolower(rtrim(trim($store->tongtu_prefix), '-')))
            ->forget('')
            ->map(fn ($stores) => $stores->map(fn ($store) => TongtuOrderSnapshotService::domain($store->domain))->filter()->unique()->values())
            ->filter(fn ($domains) => $domains->isNotEmpty())
            ->sortKeysUsing(fn ($a, $b): int => strlen((string) $b) <=> strlen((string) $a));
    }

    private function resolveTongtuDomain(object $order, Collection $domainsByPrefix): object
    {
        $number = mb_strtolower($order->number);
        $domains = $domainsByPrefix->first(fn ($domains, $prefix) => str_starts_with($number, $prefix.'-'));
        if ($domains?->count() === 1) {
            $order->domain = $domains->first();
        }

        return $order;
    }

    public function edit(Request $request, LogisticsOrder $order): Response|View
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }

        return view('portals.logistics.orders.form', [
            ...$this->viewContext($context),
            'order' => $order->load([
                'items' => fn ($query) => $query->orderBy('id'),
                'refundDetails' => fn ($query) => $query->orderByDesc('id'),
            ]),
            'statusOptions' => LogisticsOrder::dataStatusOptions(),
            'trackingOptions' => LogisticsOrder::logisticsStatusOptions(),
        ]);
    }

    public function detail(Request $request, LogisticsOrder $order): Response|View
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }

        $order->load([
            'items' => fn ($query) => $query->with('skuMapping')->orderBy('id'),
            'paypalAccount',
            'paypalRefunds' => fn ($query) => $query->orderByDesc('refunded_at')->orderByDesc('id'),
            'paypalDisputes' => fn ($query) => $query->orderByDesc('dispute_created_at')->orderByDesc('id'),
            'paymentReferences' => fn ($query) => $query->orderByRaw("CASE reference_type WHEN 'primary' THEN 0 ELSE 1 END")->orderBy('id'),
                'creditCardRefunds' => fn ($query) => $query->orderByDesc('occurred_at')->orderByDesc('id'),
            'logs' => fn ($query) => $query->latest('id')->limit(20),
        ]);

        // Older order rows may contain a SKU but predate the mapping-id
        // backfill. Resolve those mappings in one batch so detail values are
        // visible immediately without requiring another full historical run.
        $skuKeys = $order->items->flatMap(fn ($item): array => [
            mb_strtolower(trim((string) $item->shopify_sku)),
            mb_strtolower(trim((string) $item->tongtu_sku)),
            mb_strtolower(trim((string) $item->tongtu_product_code)),
        ])->filter()->unique()->values()->all();
        if ($skuKeys !== []) {
            $mappingBySku = LogisticsTongtuSkuMapping::query()
                ->where(function ($query) use ($skuKeys): void {
                    $query->whereIn(\Illuminate\Support\Facades\DB::raw('LOWER(TRIM(shopify_sku))'), $skuKeys)
                        ->orWhereIn(\Illuminate\Support\Facades\DB::raw('LOWER(TRIM(tongtu_sku))'), $skuKeys)
                        ->orWhereIn(\Illuminate\Support\Facades\DB::raw('LOWER(TRIM(tongtu_product_code))'), $skuKeys);
                })
                ->get()
                ->flatMap(static function (LogisticsTongtuSkuMapping $mapping): array {
                    $keys = array_filter(array_map(
                        static fn ($value): string => mb_strtolower(trim((string) $value)),
                        [$mapping->shopify_sku, $mapping->tongtu_sku, $mapping->tongtu_product_code],
                    ));

                    return array_fill_keys($keys, $mapping);
                });
            foreach ($order->items as $item) {
                if ($item->skuMapping !== null) continue;
                foreach ([$item->shopify_sku, $item->tongtu_sku, $item->tongtu_product_code] as $value) {
                    $mapping = $mappingBySku->get(mb_strtolower(trim((string) $value)));
                    if ($mapping) {
                        $item->setRelation('skuMapping', $mapping);
                        break;
                    }
                }
            }
        }

        return view('portals.logistics.orders.detail', [
            'order' => $order,
            'shopifyPayload' => $this->decodePayload($order->shopify_payload),
            'tongtuPayload' => $this->decodePayload($order->tongtu_payload),
            'fulfillmentPayload' => $this->decodePayload($order->fulfillment_payload),
            'pageSourcePayload' => $this->decodePayload($order->page_source),
        ]);
    }

    public function export(Request $request): Response
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }

        $validated = $request->validate([
            'scope' => ['required', 'in:selected,filtered'],
            'selected_ids' => ['required_if:scope,selected', 'array', 'min:1'],
            'selected_ids.*' => ['integer', 'distinct'],
        ]);
        $query = $this->orderQuery($this->filters($request));

        if ($validated['scope'] === 'selected') {
            $query->whereKey($validated['selected_ids']);
        }

        $orders = $query
            ->select($this->spreadsheets->requiredOrderColumns())
            ->with([
                'paypalRefunds' => fn ($refunds) => $refunds->orderByDesc('refunded_at')->orderByDesc('id'),
                'creditCardRefunds' => fn ($refunds) => $refunds->orderByDesc('occurred_at')->orderByDesc('id'),
            ])
            ->orderByDesc('order_created_at')
            ->orderByDesc('id');

        if (!(clone $orders)->exists()) {
            throw ValidationException::withMessages([
                'scope' => '当前导出范围内没有可导出的物流订单。',
            ]);
        }

        return $this->spreadsheets->exportResponse($orders);
    }

    public function update(Request $request, LogisticsOrder $order): RedirectResponse
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) {
            abort(403);
        }

        $data = $this->validatedData($request);
        $itemRows = $data['items'] ?? [];
        unset($data['items']);
        $duplicate = LogisticsOrder::query()
            ->where('assistant_domain_store_id', $order->assistant_domain_store_id)
            ->where('shopify_order_id', $data['shopify_order_id'])
            ->where('id', '!=', $order->getKey())
            ->exists();

        if ($duplicate) {
            return back()->withInput()->withErrors(['shopify_order_id' => '该店铺下已存在相同的 Shopify 原生订单 ID。']);
        }

        $order->fill($data);
        $trackingNumberChanged = $order->isDirty('tracking_number');
        $order->save();
        foreach ($itemRows as $itemRow) {
            $item = $order->items()->whereKey((int) $itemRow['id'])->firstOrFail();
            $newSku = trim((string) ($itemRow['shopify_sku'] ?? '')) ?: null;
            $skuChanged = mb_strtolower(trim((string) $item->shopify_sku)) !== mb_strtolower((string) $newSku);
            $changes = ['shopify_sku' => $newSku];
            if ($skuChanged) {
                $changes += [
                    'tongtu_sku_mapping_id' => null,
                    'tongtu_product_id' => null,
                    'tongtu_goods_detail_id' => null,
                    'tongtu_sku' => null,
                    'tongtu_product_code' => null,
                    'tongtu_product_name' => null,
                    'tongtu_product_type' => null,
                    'tongtu_goods_weight_gram' => null,
                    'tongtu_package_weight_gram' => null,
                ];
            }
            $item->forceFill($changes)->save();
        }
        if ($itemRows !== []) {
            $hasMissingSku = $order->items()->where(function (Builder $items): void {
                $items->whereNull('shopify_sku')->orWhereRaw("TRIM(shopify_sku) = ''");
            })->exists();
            if ($hasMissingSku) {
                $order->forceFill([
                    'data_status' => LogisticsOrder::STATUS_SKU_EXCEPTION,
                    'sku_exception_reason' => 'Shopify SKU 缺失',
                ])->save();
            } elseif ($order->data_status === LogisticsOrder::STATUS_SKU_EXCEPTION) {
                $order->forceFill([
                    'data_status' => LogisticsOrder::STATUS_NORMAL,
                    'sku_exception_reason' => null,
                ])->save();
            }
        }
        $this->paymentReferences->syncFromAttributes($order, []);
        $this->paypalAssociations->reconcileOrder($order);
        if ($trackingNumberChanged) {
            $this->tracking->scheduleForOrder($order);
        }
        $this->skuMappings->scheduleForOrder($order);

        return redirect()
            ->route('logistics.orders.index')
            ->with('success', '订单已保存。');
    }

    public function importRefunds(Request $request): RedirectResponse
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) {
            abort(403);
        }
        $validated = $request->validate([
            'refund_file' => [
                'required',
                'file',
                'max:10240',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $extension = strtolower((string) $value->getClientOriginalExtension());
                    if (!in_array($extension, ['xlsx', 'xls', 'csv'], true)) {
                        $fail(__('logistics.refund_import.errors.file_type'));
                    }
                },
            ],
        ]);

        try {
            $summary = $this->refundImports->import($validated['refund_file'], $context['user']);
        } catch (\Throwable $exception) {
            return $this->indexRedirect($request)
                ->with('error', __('logistics.refund_import.errors.import_failed', ['message' => $exception->getMessage()]))
                ->with('logistics_refund_import_failed', true);
        }

        $message = __('logistics.refund_import.messages.completed', [
            'rows' => $summary['rows'],
            'paypal_updated' => $summary['paypal_updated'],
            'credit_card_added' => $summary['credit_card_added'],
            'credit_card_updated' => $summary['credit_card_updated'],
            'invalid' => $summary['invalid'],
        ]);
        $redirect = $this->indexRedirect($request)->with($summary['invalid'] > 0 ? 'error' : 'success', $message);
        if ($summary['errors'] === []) {
            return $redirect;
        }

        $token = (string) Str::uuid();
        Cache::put($this->refundImportErrorsCacheKey($token), [
            'actor_id' => (int) $context['user']->getKey(),
            'errors' => $summary['errors'],
        ], now()->addHour());

        return $redirect
            ->with('logistics_refund_import_has_errors', true)
            ->with('logistics_refund_import_error_report', route('logistics.orders.refund-import-errors', ['token' => $token]));
    }

    public function refundImportTemplate(Request $request): Response
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }

        return $this->spreadsheets->refundImportTemplateResponse();
    }

    public function refundImportErrors(Request $request, string $token): Response
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }
        $payload = Cache::get($this->refundImportErrorsCacheKey($token));
        if (!is_array($payload) || (int) ($payload['actor_id'] ?? 0) !== (int) $context['user']->getKey()) {
            abort(404);
        }

        return $this->spreadsheets->refundImportErrorResponse(is_array($payload['errors'] ?? null) ? $payload['errors'] : []);
    }

    public function updateRefund(Request $request, LogisticsOrder $order, LogisticsRefundDetail $refund): RedirectResponse
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response || (int) $refund->logistics_order_id !== (int) $order->getKey()) {
            abort(403);
        }
        $data = $request->validate([
            'refund_number' => ['required', 'string', 'max:191'],
            'refund_amount_usd' => ['required', 'numeric', 'not_in:0'],
            'refund_type' => ['nullable', 'string', 'max:191'],
            'refund_reason' => ['nullable', 'string', 'max:2000'],
            'remark' => ['nullable', 'string', 'max:2000'],
        ]);
        $data['refund_amount_usd'] = number_format(abs((float) $data['refund_amount_usd']), 4, '.', '');
        foreach (['refund_number', 'refund_type', 'refund_reason', 'remark'] as $field) {
            $data[$field] = trim((string) ($data[$field] ?? '')) ?: null;
        }
        $data['refund_number'] ??= (string) $refund->refund_number;
        $data['fingerprint'] = $this->refundImports->fingerprintFor($data);
        if (LogisticsRefundDetail::query()
            ->where('fingerprint', $data['fingerprint'])
            ->where($refund->getKeyName(), '!=', $refund->getKey())
            ->exists()) {
            return back()->withErrors(['refund_number' => '修改后的退款明细与现有记录完全重复。']);
        }
        $refund->fill($data)->save();

        return back()->with('success', '退款明细已更新。');
    }

    public function voidRefund(Request $request, LogisticsOrder $order, LogisticsRefundDetail $refund): RedirectResponse
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response || (int) $refund->logistics_order_id !== (int) $order->getKey()) {
            abort(403);
        }
        $refund->forceFill([
            'is_void' => true,
            'voided_by' => $context['user']->getKey(),
            'voided_by_name' => $context['user']->name,
            'voided_at' => now(),
        ])->save();

        return back()->with('success', '退款明细已作废，并从退款汇总和产品统计中排除。');
    }

    public function syncTracking(Request $request): RedirectResponse
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) {
            abort(403);
        }

        $data = $request->validate([
            'selected_ids' => ['required', 'array', 'min:1', 'max:1000'],
            'selected_ids.*' => ['integer', 'distinct'],
        ]);
        if (!$this->tracking->isConfigured()) {
            return $this->indexRedirect($request)->with('error', __('logistics.tracking.configuration_missing'));
        }
        $batch = $this->tracking->queue($data['selected_ids'], $context['user']);
        $request->session()->put('logistics.tracking_sync_batch_id', $batch->getKey());

        return $this->indexRedirect($request)->with(
            'success',
            __('logistics.tracking.queued_message', ['count' => $batch->total_count])
        );
    }

    public function trackingProgress(Request $request, LogisticsTrackingSyncBatch $batch): JsonResponse
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response || (int) $batch->actor_id !== (int) $context['user']->getKey()) {
            abort(403);
        }

        $batch->refreshProgress();
        $batch = $batch->fresh();
        $payload = $batch->progressPayload();
        $payload['failures'] = $this->trackingFailureDetails($batch);

        return response()->json($payload);
    }

    public function assignStore(Request $request): RedirectResponse
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) {
            abort(403);
        }

        $data = $request->validate([
            'selected_ids' => ['required', 'array', 'min:1', 'max:100'],
            'selected_ids.*' => ['integer', 'distinct'],
            'assistant_domain_store_id' => ['required', 'integer'],
        ]);
        $store = AssistantDomainStore::query()
            ->whereKey($data['assistant_domain_store_id'])
            ->where('site_builder', 'shopify')
            ->where('shop_status', AssistantDomainStore::SHOP_STATUS_IN_USE)
            ->first();
        if (!$store) {
            return $this->indexRedirect($request)->with('error', '请选择有效的在用 Shopify 店铺。');
        }

        $orders = LogisticsOrder::query()
            ->whereIn('id', $data['selected_ids'])
            ->whereNull('assistant_domain_store_id')
            ->orderBy('id')
            ->get();
        $assigned = 0;
        $conflicts = 0;

        foreach ($orders as $order) {
            $duplicate = LogisticsOrder::query()
                ->where('assistant_domain_store_id', $store->getKey())
                ->where('shopify_order_id', $order->shopify_order_id)
                ->where('id', '!=', $order->getKey())
                ->exists();
            if ($duplicate) {
                $conflicts++;
                continue;
            }

            $orderNumber = trim((string) $order->shopify_order_number);
            $missingSku = $this->hasMissingShopifySku($order);
            $tongtuOrderNumber = filled($order->tongtu_order_number)
                ? $order->tongtu_order_number
                : $store->makeTongtuOrderNumber($orderNumber);
            $order->fill([
                'assistant_domain_store_id' => $store->getKey(),
                'store_name' => $store->shop_name,
                'operator_name' => $order->operator_name,
                'group_names' => AssistantDomainStore::displayMultiValue($store->team),
                'staff_status' => '正常',
                'tongtu_order_number' => $tongtuOrderNumber,
                'data_status' => $missingSku
                    ? LogisticsOrder::STATUS_SKU_EXCEPTION
                    : LogisticsOrder::STATUS_NORMAL,
                'sku_exception_reason' => $missingSku ? 'Shopify SKU 缺失' : null,
            ]);
            $this->ownerTimeline->applySnapshotIfMissing($order, $store);
            $order->save();
            $this->skuMappings->scheduleForOrder($order);
            $assigned++;
        }

        return $this->indexRedirect($request)
            ->with('success', '批量归属完成：已归属 '.$assigned.' 条，冲突跳过 '.$conflicts.' 条。');
    }

    private function currentTrackingBatch(Request $request, int $actorId): ?LogisticsTrackingSyncBatch
    {
        $batchId = (int) $request->session()->get('logistics.tracking_sync_batch_id', 0);
        if ($batchId > 0) {
            $batch = LogisticsTrackingSyncBatch::query()
                ->whereKey($batchId)
                ->where('actor_id', $actorId)
                ->where('created_at', '>=', now()->subDay())
                ->first();
            if ($batch) {
                return $batch;
            }
        }

        return LogisticsTrackingSyncBatch::query()
            ->where('actor_id', $actorId)
            ->whereIn('status', [LogisticsTrackingSyncBatch::STATUS_QUEUED, LogisticsTrackingSyncBatch::STATUS_PROCESSING])
            ->latest('id')
            ->first();
    }

    /** @return list<array<string, int|string>> */
    private function trackingFailureDetails(LogisticsTrackingSyncBatch $batch): array
    {
        return $batch->tasks()
            ->with('order:id,shopify_order_number,tracking_number')
            ->whereIn('status', [
                LogisticsTrackingSyncTask::STATUS_FAILED,
                LogisticsTrackingSyncTask::STATUS_SKIPPED,
            ])
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(static function (LogisticsTrackingSyncTask $task): array {
                return [
                    'order_id' => (int) $task->logistics_order_id,
                    'shopify_order_number' => (string) ($task->order?->shopify_order_number ?: '—'),
                    'tracking_number' => (string) ($task->tracking_number ?: $task->order?->tracking_number ?: '—'),
                    'reason' => (string) ($task->last_error ?: '51Tracking 请求失败。'),
                ];
            })
            ->values()
            ->all();
    }

    private function indexRedirect(Request $request): RedirectResponse
    {
        $default = route('logistics.orders.index');
        $returnTo = trim((string) $request->input('return_to'));
        $index = parse_url($default);
        $candidate = parse_url($returnTo);

        if ($returnTo !== ''
            && is_array($candidate)
            && ($candidate['host'] ?? null) === ($index['host'] ?? null)
            && ($candidate['path'] ?? null) === ($index['path'] ?? null)) {
            return redirect()->to($returnTo);
        }

        return redirect()->to($default);
    }

    /** @return array{portal: \App\Models\Portal, user: \App\Models\AdminUser}|Response */
    private function portalContext(Request $request): array|Response
    {
        $context = $this->portalEntries->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }

        $this->logistics->assertLogisticsPortal($context['portal']);

        return $context;
    }

    /** @param array{portal: \App\Models\Portal, user: \App\Models\AdminUser} $context
     *  @return array{portal: \App\Models\Portal, user: \App\Models\AdminUser}
     */
    private function viewContext(array $context): array
    {
        $context['portal']->loadMissing('menus');

        return $context;
    }

    /** @return array<string, string|int> */
    private function filters(Request $request): array
    {
        $perPage = (int) $request->input('per_page', 20);
        if (!in_array($perPage, [20, 50, 100], true)) {
            $perPage = 20;
        }

        $fields = [
            'id', 'shopify_order_id', 'shopify_order_number', 'tongtu_order_number', 'store_name',
            'owner_assignment', 'group_names', 'staff_status', 'buyer_name', 'buyer_email',
            'buyer_phone', 'is_add_on', 'subscription_type', 'subscription_status', 'payment_method', 'payment_gateway',
            'collection_account', 'collection_account_email', 'transaction_id', 'bill_number', 'logistics_status', 'refund_reason', 'data_status',
            'time_zone',
            'source_shop_domain', 'store_domain', 'product_name', 'store_assignment_status', 'refund_number', 'shopify_sku', 'shopify_sku_status',
            'product_runnable_status',
        ];
        $filters = ['per_page' => $perPage];
        foreach ($fields as $field) {
            $value = $request->input($field, '');
            $filters[$field] = is_scalar($value) ? trim((string) $value) : '';
        }

        foreach (['refunded', 'ordered', 'paid'] as $prefix) {
            foreach (['from', 'to'] as $suffix) {
                $key = $prefix.'_'.$suffix;
                $value = $request->input($key, '');
                $filters[$key] = is_scalar($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $value)
                    ? (string) $value
                    : '';
            }
        }

        return $filters;
    }

    private function listedOrders(): Builder
    {
        return LogisticsOrder::query()
            ->where(function (Builder $operator): void {
                $operator->whereNull('operator_name')
                    ->orWhere('operator_name', 'not like', $this->contains('刷单'));
            });
    }

    /** @param array<string, string|int> $filters */
    private function orderQuery(array $filters): Builder
    {
        $query = $this->listedOrders();
        $textColumns = [
            'id', 'shopify_order_id', 'shopify_order_number', 'tongtu_order_number', 'store_name',
            'group_names', 'staff_status', 'buyer_name', 'buyer_email',
            'buyer_phone', 'is_add_on', 'subscription_type', 'subscription_status', 'payment_method', 'payment_gateway',
            'collection_account_email', 'transaction_id', 'logistics_status', 'data_status',
            'time_zone', 'source_shop_domain',
        ];
        foreach ($textColumns as $column) {
            if ($filters[$column] !== '') {
                $query->where($column, 'like', $this->contains((string) $filters[$column]));
            }
        }

        if ($filters['collection_account'] !== '') {
            $query->where('collection_account', $filters['collection_account']);
        }

        if ($this->ownerSnapshotColumnsExist()) {
            if ($filters['owner_assignment'] === 'unassigned') {
                $query->whereNull('assigned_owner_user_id');
            } elseif ($filters['owner_assignment'] !== '' && ctype_digit((string) $filters['owner_assignment'])) {
                $query->where('assigned_owner_user_id', (int) $filters['owner_assignment']);
            }
        }

        if ($filters['shopify_sku'] !== '') {
            $sku = $this->contains((string) $filters['shopify_sku']);
            $query->whereHas('items', fn (Builder $items) => $items->where('shopify_sku', 'like', $sku));
        }
        if ($filters['product_name'] !== '') {
            $productName = $this->contains((string) $filters['product_name']);
            $query->whereHas('items', fn (Builder $items) => $items
                ->where(function (Builder $names) use ($productName): void {
                    $names->where('product_title', 'like', $productName)
                        ->orWhere('variant_title', 'like', $productName)
                        ->orWhere('tongtu_product_name', 'like', $productName);
                }));
        }
        if ($filters['store_domain'] !== '') {
            $domain = $this->contains((string) $filters['store_domain']);
            $query->whereHas('store', fn (Builder $store) => $store->where('domain', 'like', $domain));
        }
        if ($filters['shopify_sku_status'] === 'empty') {
            $query->whereHas('items', fn (Builder $items) => $items
                ->where(function (Builder $missingSku): void {
                    $missingSku->whereNull('shopify_sku')->orWhereRaw("TRIM(shopify_sku) = ''");
                })
                ->whereRaw("LOWER(TRIM(COALESCE(product_title, ''))) <> 'tipping'"));
        } elseif ($filters['shopify_sku_status'] === 'filled') {
            $query->whereHas('items', fn (Builder $items) => $items
                ->whereNotNull('shopify_sku')->whereRaw("TRIM(shopify_sku) <> ''"));
        }

        if (in_array($filters['product_runnable_status'], ['non_runnable', 'runnable'], true)) {
            $nonRunnableKeys = $this->nonRunnableProductKeys();

            if ($nonRunnableKeys['skus'] === [] && $nonRunnableKeys['product_codes'] === []) {
                if ($filters['product_runnable_status'] === 'non_runnable') {
                    $query->whereRaw('1 = 0');
                }
            } else {
                $matchNonRunnableItem = static function (Builder $items) use ($nonRunnableKeys): void {
                    $items->where(function (Builder $match) use ($nonRunnableKeys): void {
                        if ($nonRunnableKeys['skus'] !== []) {
                            $match->whereIn(DB::raw('LOWER(TRIM(shopify_sku))'), $nonRunnableKeys['skus'])
                                ->orWhereIn(DB::raw('LOWER(TRIM(tongtu_sku))'), $nonRunnableKeys['skus']);
                        }

                        if ($nonRunnableKeys['product_codes'] !== []) {
                            $method = $nonRunnableKeys['skus'] === [] ? 'whereIn' : 'orWhereIn';
                            $match->{$method}(DB::raw('LOWER(TRIM(tongtu_product_code))'), $nonRunnableKeys['product_codes']);
                        }
                    });
                };

                if ($filters['product_runnable_status'] === 'non_runnable') {
                    $query->whereHas('items', $matchNonRunnableItem);
                } else {
                    $query->whereDoesntHave('items', $matchNonRunnableItem);
                }
            }
        }

        if ($filters['bill_number'] !== '') {
            $value = $this->contains((string) $filters['bill_number']);
            $query->where(function (Builder $billNumbers) use ($value): void {
                $billNumbers->where('bill_number', 'like', $value)
                    ->orWhereHas('paymentReferences', fn (Builder $references) => $references->where('reference_number', 'like', $value));
            });
        }

        if ($filters['refund_number'] !== '') {
            $value = $this->contains((string) $filters['refund_number']);
            $query->where(function (Builder $refundNumbers) use ($value): void {
                $refundNumbers
                    ->whereHas('paypalRefunds', fn (Builder $refunds) => $refunds->where('refund_transaction_id', 'like', $value))
                    ->orWhereHas('creditCardRefunds', fn (Builder $refunds) => $refunds->where('refund_reference', 'like', $value));
            });
        }
        if ($filters['refund_reason'] !== '') {
            $value = $this->contains((string) $filters['refund_reason']);
            $query->where(function (Builder $reasons) use ($value): void {
                $reasons->whereHas('paypalRefunds', fn (Builder $refunds) => $refunds
                        ->where('refund_reason', 'like', $value)
                        ->orWhere('manual_reason', 'like', $value))
                    ->orWhereHas('creditCardRefunds', fn (Builder $refunds) => $refunds->where('refund_reason', 'like', $value));
            });
        }

        if ($filters['store_assignment_status'] === 'assigned') {
            $query->whereNotNull('assistant_domain_store_id');
        } elseif ($filters['store_assignment_status'] === 'unmatched') {
            $query->whereNull('assistant_domain_store_id');
        }

        foreach ([
            'ordered' => 'order_created_at',
            'paid' => 'paid_at',
        ] as $prefix => $column) {
            if ($filters[$prefix.'_from'] !== '') {
                $query->whereDate($column, '>=', $filters[$prefix.'_from']);
            }
            if ($filters[$prefix.'_to'] !== '') {
                $query->whereDate($column, '<=', $filters[$prefix.'_to']);
            }
        }

        if ($filters['refunded_from'] !== '' || $filters['refunded_to'] !== '') {
            $applyDateRange = static function (Builder $refunds, string $column) use ($filters): void {
                if ($filters['refunded_from'] !== '') {
                    $refunds->whereDate($column, '>=', $filters['refunded_from']);
                }
                if ($filters['refunded_to'] !== '') {
                    $refunds->whereDate($column, '<=', $filters['refunded_to']);
                }
            };

            $query->where(function (Builder $refundDate) use ($applyDateRange): void {
                $refundDate
                    ->whereHas('paypalRefunds', fn (Builder $refunds) => $applyDateRange($refunds, 'refunded_at'))
                    ->orWhereHas('creditCardRefunds', fn (Builder $refunds) => $applyDateRange($refunds, 'occurred_at'));
            });
        }

        return $query;
    }

    /** @return array{skus: list<string>, product_codes: list<string>} */
    private function nonRunnableProductKeys(): array
    {
        $records = ProductRequestLinkSku::query()
            ->where('is_runnable', false)
            ->get(['sku_values', 'skus', 'pcl_product_no']);

        $normalize = static fn (mixed $value): string => Str::lower(trim((string) $value));

        $skus = $records
            ->flatMap(static function (ProductRequestLinkSku $record): array {
                $structuredSkus = array_keys((array) $record->sku_values);
                $legacySkus = preg_split('/[\r\n,，;；]+/u', (string) $record->skus, -1, PREG_SPLIT_NO_EMPTY) ?: [];

                return array_merge($structuredSkus, $legacySkus);
            })
            ->map($normalize)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $productCodes = $records
            ->pluck('pcl_product_no')
            ->map($normalize)
            ->filter()
            ->unique()
            ->values()
            ->all();

        return ['skus' => $skus, 'product_codes' => $productCodes];
    }

    /** @return array<string, array<int, mixed>> */
    private function filterOptions(): array
    {
        $values = static function (string $column): array {
            return LogisticsOrder::query()
                ->where(function (Builder $operator): void {
                    $operator->whereNull('operator_name')
                        ->orWhere('operator_name', 'not like', '%刷单%');
                })
                ->whereNotNull($column)
                ->where($column, '!=', '')
                ->distinct()
                ->orderBy($column)
                ->limit(200)
                ->pluck($column)
                ->map(static fn ($value): string => (string) $value)
                ->all();
        };

        return [
            'stores' => $values('store_name'),
            'owners' => $this->ownerSnapshotColumnsExist()
                ? LogisticsOrder::query()
                    ->whereNotNull('assigned_owner_user_id')
                    ->selectRaw('assigned_owner_user_id, MAX(assigned_owner_name) as assigned_owner_name')
                    ->groupBy('assigned_owner_user_id')
                    ->orderBy('assigned_owner_name')
                    ->get()
                    ->map(static fn ($owner): array => [
                        'id' => (int) $owner->assigned_owner_user_id,
                        'name' => trim((string) $owner->assigned_owner_name) ?: ('成员 #'.$owner->assigned_owner_user_id),
                    ])
                    ->all()
                : [],
            'groups' => $values('group_names'),
            'paymentMethods' => $values('payment_method'),
            'paymentGateways' => $values('payment_gateway'),
            'collectionAccounts' => $values('collection_account'),
            'subscriptionTypes' => $values('subscription_type'),
            'subscriptionStatuses' => $values('subscription_status'),
            'sourceDomains' => $values('source_shop_domain'),
        ];
    }

    private function ownerSnapshotColumnsExist(): bool
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);

        return $schema->hasColumn('logistics_orders', 'assigned_owner_user_id')
            && $schema->hasColumn('logistics_orders', 'assigned_owner_name');
    }

    /** @return array<string, mixed> */
    private function validatedData(Request $request): array
    {
        $textFields = [
            'shopify_order_id', 'shopify_order_number', 'tongtu_order_number', 'store_name',
            'operator_name', 'group_names', 'staff_status', 'buyer_name', 'buyer_email', 'buyer_phone', 'is_add_on',
            'subscription_type', 'subscription_origin_order_id', 'subscription_status', 'tongtu_product_code', 'tongtu_sku',
            'tongtu_product_name', 'sale_quantity', 'sale_product_weight', 'payment_status', 'payment_method', 'payment_gateway',
            'refund_reason', 'collection_account', 'collection_account_email', 'transaction_id', 'bill_number',
            'destination', 'shipping_company', 'tracking_number', 'sku_exception_reason', 'time_zone',
        ];
        foreach ($textFields as $field) {
            if ($request->has($field)) {
                $request->merge([$field => trim((string) $request->input($field))]);
            }
        }

        $rules = array_fill_keys($textFields, ['nullable', 'string']);
        $rules['shopify_order_id'] = ['required', 'string', 'max:100'];
        $rules['buyer_email'] = ['nullable', 'email', 'max:191'];
        $rules['collection_account_email'] = ['nullable', 'email', 'max:191'];
        $rules['data_status'] = ['required', Rule::in(array_keys(LogisticsOrder::dataStatusOptions()))];
        $rules['logistics_status'] = ['required', Rule::in(array_keys(LogisticsOrder::logisticsStatusOptions()))];
        foreach ([
            'paid_amount_usd', 'total_shipping_usd', 'channel_refund_amount_usd', 'shopify_refund_amount_usd',
        ] as $field) {
            $rules[$field] = ['nullable', 'numeric'];
        }
        foreach (['paid_at', 'refunded_at', 'order_created_at'] as $field) {
            $rules[$field] = ['nullable', 'date'];
        }

        $rules['items'] = ['nullable', 'array'];
        $rules['items.*.id'] = ['required', 'integer'];
        $rules['items.*.shopify_sku'] = ['nullable', 'string', 'max:191'];

        return $request->validate($rules);
    }

    private function contains(string $value): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value).'%';
    }

    private function refundImportErrorsCacheKey(string $token): string
    {
        return 'logistics:refund-import-errors:'.$token;
    }

    private function decodePayload(mixed $value): mixed
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    /** @return \Illuminate\Support\Collection<int, AssistantDomainStore> */
    private function shopifyStores()
    {
        return AssistantDomainStore::query()
            ->where('site_builder', 'shopify')
            ->where('shop_status', AssistantDomainStore::SHOP_STATUS_IN_USE)
            ->orderBy('shop_name')
            ->get(['id', 'shop_name', 'user_name']);
    }

    private function hasMissingShopifySku(LogisticsOrder $order): bool
    {
        $payload = json_decode((string) $order->shopify_payload, true);
        $items = is_array($payload) ? ($payload['lineItems']['nodes'] ?? []) : [];

        return !is_array($items) || $items === [] || collect($items)
            ->contains(static fn (array $item): bool => blank($item['sku'] ?? null));
    }
}
