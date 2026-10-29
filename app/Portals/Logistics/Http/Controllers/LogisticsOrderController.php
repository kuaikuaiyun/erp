<?php

namespace App\Portals\Logistics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AssistantDomainStore;
use App\Models\LogisticsOrder;
use App\Models\LogisticsRefundDetail;
use App\Models\LogisticsTongtuSkuMapping;
use App\Models\LogisticsTrackingSyncBatch;
use App\Models\LogisticsTrackingSyncTask;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\Logistics\LogisticsOrderSpreadsheetService;
use App\Portals\Logistics\LogisticsOrderPaymentReferenceService;
use App\Portals\Logistics\LogisticsPortalService;
use App\Portals\Logistics\LogisticsRefundImportService;
use App\Portals\Logistics\TongtuSkuMappingService;
use App\Portals\Logistics\LogisticsTrackingService;
use App\Portals\Logistics\PayPalOrderAssociationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class LogisticsOrderController extends Controller
{
    public function __construct(
        private readonly PortalEntryController $portalEntries,
        private readonly LogisticsPortalService $logistics,
        private readonly LogisticsOrderSpreadsheetService $spreadsheets,
        private readonly LogisticsRefundImportService $refundImports,
        private readonly TongtuSkuMappingService $skuMappings,
        private readonly LogisticsTrackingService $tracking,
        private readonly LogisticsOrderPaymentReferenceService $paymentReferences,
        private readonly PayPalOrderAssociationService $paypalAssociations,
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
                'creditCardRefunds' => fn ($query) => $query->orderByDesc('last_imported_at')->orderByDesc('id'),
                'paymentReferences' => fn ($query) => $query->orderByRaw("CASE reference_type WHEN 'primary' THEN 0 ELSE 1 END")->orderBy('id'),
            ])
            ->orderByDesc('order_created_at')
            ->orderByDesc('id')
            ->paginate($filters['per_page'])
            ->withQueryString();
        $trackingBatch = $this->currentTrackingBatch($request, (int) $context['user']->getKey());

        return view('portals.logistics.orders.index', [
            ...$this->viewContext($context),
            'orders' => $orders,
            'filters' => $filters,
            'filterOptions' => $this->filterOptions(),
            'statusOptions' => LogisticsOrder::dataStatusOptions(),
            'trackingOptions' => LogisticsOrder::logisticsStatusOptions(),
            'shopifyStores' => $this->shopifyStores(),
            'activeTrackingBatch' => $trackingBatch,
            'trackingFailureDetails' => $trackingBatch ? $this->trackingFailureDetails($trackingBatch) : [],
        ]);
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
            'creditCardRefunds' => fn ($query) => $query->orderByDesc('last_imported_at')->orderByDesc('id'),
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
                'creditCardRefunds' => fn ($refunds) => $refunds->orderByDesc('last_imported_at')->orderByDesc('id'),
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
            $tongtuOrderNumber = $store->makeTongtuOrderNumber($orderNumber);
            $order->fill([
                'assistant_domain_store_id' => $store->getKey(),
                'store_name' => $store->shop_name,
                'operator_name' => $store->user_name,
                'group_names' => AssistantDomainStore::displayMultiValue($store->team),
                'staff_status' => '正常',
                'tongtu_order_number' => $tongtuOrderNumber,
                'data_status' => $missingSku
                    ? LogisticsOrder::STATUS_SKU_EXCEPTION
                    : LogisticsOrder::STATUS_NORMAL,
                'sku_exception_reason' => $missingSku ? 'Shopify SKU 缺失' : null,
            ])->save();
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
            'operator_name', 'group_names', 'staff_status', 'buyer_name', 'buyer_email',
            'buyer_phone', 'is_add_on', 'subscription_type', 'subscription_status', 'payment_method', 'payment_gateway',
            'collection_account_email', 'transaction_id', 'bill_number', 'logistics_status', 'refund_reason', 'data_status',
            'time_zone',
            'source_shop_domain', 'store_domain', 'product_name', 'store_assignment_status', 'refund_number', 'shopify_sku', 'shopify_sku_status',
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

    /** @param array<string, string|int> $filters */
    private function orderQuery(array $filters): Builder
    {
        $query = LogisticsOrder::query()
            ->where(function (Builder $operator): void {
                $operator->whereNull('operator_name')
                    ->orWhere('operator_name', 'not like', $this->contains('刷单'));
            });
        $textColumns = [
            'id', 'shopify_order_id', 'shopify_order_number', 'tongtu_order_number', 'store_name',
            'operator_name', 'group_names', 'staff_status', 'buyer_name', 'buyer_email',
            'buyer_phone', 'is_add_on', 'subscription_type', 'subscription_status', 'payment_method', 'payment_gateway',
            'collection_account_email', 'transaction_id', 'logistics_status', 'data_status',
            'time_zone', 'source_shop_domain',
        ];
        foreach ($textColumns as $column) {
            if ($filters[$column] !== '') {
                $query->where($column, 'like', $this->contains((string) $filters[$column]));
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
                    ->orWhereHas('creditCardRefunds', fn (Builder $refunds) => $applyDateRange($refunds, 'last_imported_at'));
            });
        }

        return $query;
    }

    /** @return array<string, array<int, string>> */
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
            'operators' => $values('operator_name'),
            'groups' => $values('group_names'),
            'paymentMethods' => $values('payment_method'),
            'paymentGateways' => $values('payment_gateway'),
            'subscriptionTypes' => $values('subscription_type'),
            'subscriptionStatuses' => $values('subscription_status'),
            'sourceDomains' => $values('source_shop_domain'),
        ];
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
