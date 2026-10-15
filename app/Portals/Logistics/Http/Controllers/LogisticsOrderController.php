<?php

namespace App\Portals\Logistics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Jobs\MatchTongtuOrderJob;
use App\Models\AssistantDomainStore;
use App\Models\LogisticsOrder;
use App\Models\LogisticsRefundDetail;
use App\Models\LogisticsTongtuMatchBatch;
use App\Models\LogisticsTongtuMatchTask;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\Logistics\LogisticsOrderSpreadsheetService;
use App\Portals\Logistics\LogisticsPortalService;
use App\Portals\Logistics\LogisticsRefundImportService;
use App\Portals\Logistics\LogisticsTongtuAutomationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
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
        private readonly LogisticsTongtuAutomationService $automation,
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
            ->with(['activeRefundDetails' => fn ($query) => $query->orderBy('id')])
            ->orderByDesc('order_created_at')
            ->orderByDesc('id')
            ->paginate($filters['per_page'])
            ->withQueryString();
        $matchBatch = $this->currentMatchBatch($request, (int) $context['user']->getKey());

        return view('portals.logistics.orders.index', [
            ...$this->viewContext($context),
            'orders' => $orders,
            'filters' => $filters,
            'filterOptions' => $this->filterOptions(),
            'statusOptions' => LogisticsOrder::dataStatusOptions(),
            'trackingOptions' => LogisticsOrder::logisticsStatusOptions(),
            'shopifyStores' => $this->shopifyStores(),
            'activeMatchBatch' => $matchBatch,
            'matchFailureDetails' => $matchBatch ? $this->matchFailureDetails($matchBatch) : [],
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
            'order' => $order->load(['refundDetails' => fn ($query) => $query->orderByDesc('id')]),
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
            'items' => fn ($query) => $query->orderBy('id'),
            'activeRefundDetails' => fn ($query) => $query->orderByDesc('created_at')->orderByDesc('id'),
            'paypalAccount',
            'paypalRefunds' => fn ($query) => $query->orderByDesc('refunded_at')->orderByDesc('id'),
            'paypalDisputes' => fn ($query) => $query->orderByDesc('dispute_created_at')->orderByDesc('id'),
            'logs' => fn ($query) => $query->latest('id')->limit(20),
        ]);

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
            ->with(['activeRefundDetails' => fn ($refunds) => $refunds->orderBy('id')])
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
        $duplicate = LogisticsOrder::query()
            ->where('assistant_domain_store_id', $order->assistant_domain_store_id)
            ->where('shopify_order_id', $data['shopify_order_id'])
            ->where('id', '!=', $order->getKey())
            ->exists();

        if ($duplicate) {
            return back()->withInput()->withErrors(['shopify_order_id' => '该店铺下已存在相同的 Shopify 原生订单 ID。']);
        }

        $order->fill($data)->save();
        $this->automation->scheduleForOrder($order);

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

    public function matchTongtu(Request $request): RedirectResponse
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) {
            abort(403);
        }

        $data = $request->validate([
            'selected_ids' => ['required', 'array', 'min:1', 'max:50'],
            'selected_ids.*' => ['integer', 'distinct'],
        ]);

        $orders = LogisticsOrder::query()
            ->with('store')
            ->whereIn('id', $data['selected_ids'])
            ->whereNotNull('assistant_domain_store_id')
            ->whereNotNull('paid_at')
            ->orderBy('id')
            ->get();
        if ($orders->isEmpty()) {
            return $this->indexRedirect($request)->with('error', '请选择已付款且已归属店铺的订单后再匹配通途。');
        }

        $busyOrderIds = LogisticsTongtuMatchTask::query()
            ->whereIn('logistics_order_id', $orders->modelKeys())
            ->whereIn('status', LogisticsTongtuMatchTask::activeStatuses())
            ->pluck('logistics_order_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        $queueableOrders = $orders
            ->reject(static fn (LogisticsOrder $order): bool => in_array((int) $order->getKey(), $busyOrderIds, true))
            ->values();
        if ($queueableOrders->isEmpty()) {
            return $this->indexRedirect($request)->with('error', '所选订单已在通途匹配队列中，请等待当前任务完成。');
        }

        $connection = config('admin.database.connection') ?: config('database.default');
        $batch = DB::connection($connection)->transaction(function () use ($queueableOrders, $context): LogisticsTongtuMatchBatch {
            $batch = LogisticsTongtuMatchBatch::query()->create([
                'actor_id' => $context['user']->getKey(),
                'actor_name' => $context['user']->name,
                'status' => LogisticsTongtuMatchBatch::STATUS_QUEUED,
                'total_count' => $queueableOrders->count(),
            ]);

            foreach ($queueableOrders as $order) {
                LogisticsTongtuMatchTask::query()->create([
                    'logistics_tongtu_match_batch_id' => $batch->getKey(),
                    'logistics_order_id' => $order->getKey(),
                    'status' => LogisticsTongtuMatchTask::STATUS_QUEUED,
                    'priority' => 10,
                    'source' => 'manual',
                    'stage' => null,
                ]);
            }

            return $batch;
        });

        $batch->tasks()->orderBy('id')->pluck('id')->each(function ($taskId): void {
            MatchTongtuOrderJob::dispatch((int) $taskId)
                ->onConnection('database')
                ->onQueue('logistics-tongtu-high');
        });
        $request->session()->put('logistics.tongtu_match_batch_id', $batch->getKey());

        $message = '已创建通途后台匹配任务：'.$queueableOrders->count().' 条订单正在排队处理。';
        if ($busyOrderIds !== []) {
            $message .= ' 已跳过 '.($orders->count() - $queueableOrders->count()).' 条正在处理的订单。';
        }

        return $this->indexRedirect($request)->with('success', $message);
    }

    public function matchProgress(Request $request, LogisticsTongtuMatchBatch $batch): JsonResponse
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) {
            abort(403);
        }
        if ((int) $batch->actor_id !== (int) $context['user']->getKey()) {
            abort(403);
        }

        $batch->refreshProgress();

        $batch = $batch->fresh();
        $payload = $batch->progressPayload();
        $payload['failures'] = $this->matchFailureDetails($batch);

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
                'data_status' => blank($tongtuOrderNumber)
                    ? LogisticsOrder::STATUS_STORE_CONFIG_EXCEPTION
                    : ($missingSku
                        ? LogisticsOrder::STATUS_SKU_EXCEPTION
                        : LogisticsOrder::STATUS_PENDING_TONGTU),
                'sku_exception_reason' => $missingSku ? 'Shopify SKU 缺失' : null,
            ])->save();
            $this->automation->scheduleForOrder($order);
            $assigned++;
        }

        return $this->indexRedirect($request)
            ->with('success', '批量归属完成：已归属 '.$assigned.' 条，冲突跳过 '.$conflicts.' 条。');
    }

    private function currentMatchBatch(Request $request, int $actorId): ?LogisticsTongtuMatchBatch
    {
        $batchId = (int) $request->session()->get('logistics.tongtu_match_batch_id', 0);
        if ($batchId > 0) {
            $latest = LogisticsTongtuMatchBatch::query()
                ->whereKey($batchId)
                ->where('actor_id', $actorId)
                ->where('created_at', '>=', now()->subDay())
                ->first();
            if ($latest) {
                return $latest;
            }
        }

        return $this->activeMatchBatch($actorId);
    }

    private function activeMatchBatch(int $actorId): ?LogisticsTongtuMatchBatch
    {
        return LogisticsTongtuMatchBatch::query()
            ->where('actor_id', $actorId)
            ->whereIn('status', [
                LogisticsTongtuMatchBatch::STATUS_QUEUED,
                LogisticsTongtuMatchBatch::STATUS_PROCESSING,
            ])
            ->latest('id')
            ->first();
    }

    /** @return list<array<string, int|string>> */
    private function matchFailureDetails(LogisticsTongtuMatchBatch $batch): array
    {
        return LogisticsTongtuMatchTask::query()
            ->with('order:id,shopify_order_number,tongtu_order_number')
            ->where('logistics_tongtu_match_batch_id', $batch->getKey())
            ->where('status', LogisticsTongtuMatchTask::STATUS_FAILED)
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(static function (LogisticsTongtuMatchTask $task): array {
                return [
                    'order_id' => (int) $task->logistics_order_id,
                    'shopify_order_number' => (string) ($task->order?->shopify_order_number ?: '—'),
                    'tongtu_order_number' => (string) ($task->order?->tongtu_order_number ?: '—'),
                    'reason' => (string) ($task->last_error ?: '通途接口请求失败。'),
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
        $context['portal']->load('menus');

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
            'source_shop_domain', 'store_assignment_status', 'refund_number', 'refund_type', 'refund_remark',
        ];
        $filters = ['per_page' => $perPage];
        foreach ($fields as $field) {
            $value = $request->input($field, '');
            $filters[$field] = is_scalar($value) ? trim((string) $value) : '';
        }

        foreach (['refunded', 'ordered', 'created'] as $prefix) {
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
        $query = LogisticsOrder::query();
        $textColumns = [
            'id', 'shopify_order_id', 'shopify_order_number', 'tongtu_order_number', 'store_name',
            'operator_name', 'group_names', 'staff_status', 'buyer_name', 'buyer_email',
            'buyer_phone', 'is_add_on', 'subscription_type', 'subscription_status', 'payment_method', 'payment_gateway',
            'collection_account_email', 'transaction_id', 'bill_number', 'logistics_status', 'data_status',
            'time_zone', 'source_shop_domain',
        ];
        foreach ($textColumns as $column) {
            if ($filters[$column] !== '') {
                $query->where($column, 'like', $this->contains((string) $filters[$column]));
            }
        }

        foreach (['refund_number' => 'refund_number', 'refund_type' => 'refund_type', 'refund_remark' => 'remark'] as $filter => $column) {
            if ($filters[$filter] !== '') {
                $query->whereHas('activeRefundDetails', fn (Builder $refunds) => $refunds->where($column, 'like', $this->contains((string) $filters[$filter])));
            }
        }
        if ($filters['refund_reason'] !== '') {
            $value = $this->contains((string) $filters['refund_reason']);
            $query->where(function (Builder $reasons) use ($value): void {
                $reasons->where('refund_reason', 'like', $value)
                    ->orWhereHas('activeRefundDetails', fn (Builder $refunds) => $refunds->where('refund_reason', 'like', $value));
            });
        }

        if ($filters['store_assignment_status'] === 'assigned') {
            $query->whereNotNull('assistant_domain_store_id');
        } elseif ($filters['store_assignment_status'] === 'unmatched') {
            $query->whereNull('assistant_domain_store_id');
        }

        foreach ([
            'refunded' => 'refunded_at',
            'ordered' => 'order_created_at',
            'created' => 'created_at',
        ] as $prefix => $column) {
            if ($filters[$prefix.'_from'] !== '') {
                $query->whereDate($column, '>=', $filters[$prefix.'_from']);
            }
            if ($filters[$prefix.'_to'] !== '') {
                $query->whereDate($column, '<=', $filters[$prefix.'_to']);
            }
        }

        return $query;
    }

    /** @return array<string, array<int, string>> */
    private function filterOptions(): array
    {
        $values = static function (string $column): array {
            return LogisticsOrder::query()
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
            'subscription_type', 'subscription_origin_order_id', 'subscription_status', 'tongtu_sku',
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
            'paid_amount_usd', 'product_cost_usd', 'handling_fee_usd', 'total_shipping_usd',
            'proportional_refund_usd', 'proportional_profit_usd', 'proportional_profit_rate',
            'financial_profit_usd', 'financial_profit_rate', 'operation_profit_usd', 'operation_profit_rate',
            'channel_refund_amount_usd', 'shopify_refund_amount_usd', 'received_amount_usd',
            'platform_fee_usd', 'net_amount_usd',
        ] as $field) {
            $rules[$field] = ['nullable', 'numeric'];
        }
        foreach (['paid_at', 'refunded_at', 'order_created_at'] as $field) {
            $rules[$field] = ['nullable', 'date'];
        }

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
