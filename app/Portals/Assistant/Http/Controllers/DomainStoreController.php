<?php

namespace App\Portals\Assistant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\AssistantDomainStore;
use App\Models\AssistantDomainStoreChangeLog;
use App\Models\AssistantDomainStoreQuickOption;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\Assistant\AssistantDomainCheckService;
use App\Portals\Assistant\AssistantDomainStoreHistoryService;
use App\Portals\Assistant\AssistantEmailPurchaseSyncService;
use App\Portals\Assistant\AssistantPortalAuthorizationService;
use App\Portals\Assistant\AssistantDomainStoreSpreadsheetService;
use App\Portals\Logistics\ShopifyOrderSyncService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class DomainStoreController extends Controller
{
    public function __construct(
        private readonly PortalEntryController $portalEntries,
        private readonly AssistantPortalAuthorizationService $authorization,
        private readonly AssistantDomainStoreHistoryService $history,
        private readonly AssistantDomainStoreSpreadsheetService $spreadsheets,
        private readonly AssistantDomainCheckService $domainChecks,
        private readonly AssistantEmailPurchaseSyncService $emailPurchases,
        private readonly ShopifyOrderSyncService $shopify,
    ) {
    }

    public function index(Request $request): Response|View
    {
        $context = $this->portalContext($request);

        if ($context instanceof Response) {
            return $context;
        }

        $filters = $this->filters($request);
        $stores = $this->storeQuery($context['user'], $filters)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($filters['per_page'])
            ->withQueryString();
        $emailPurchaseStatuses = $this->emailPurchases->statusesFor($stores->getCollection()->pluck('email')->all());
        $stores->getCollection()->each(function (AssistantDomainStore $store) use ($emailPurchaseStatuses): void {
            $normalized = $this->emailPurchases->normalizedEmail($store->email);
            $store->setAttribute(
                'email_purchase_sync_status',
                $normalized === null
                    ? AssistantEmailPurchaseSyncService::STATUS_NEVER
                    : ($emailPurchaseStatuses[$normalized] ?? AssistantEmailPurchaseSyncService::STATUS_NEVER),
            );
        });

        return view('portals.assistant.domain-stores.index', [
            ...$this->viewContext($context),
            ...$this->formOptions(),
            'stores' => $stores,
            'filters' => $filters,
            'canManage' => $this->authorization->canManageDomainStores($context['user']),
            'canManageDepartmentAccess' => $this->authorization->canManageAllDomainStores($context['user']),
            'canSyncEmailPurchases' => $this->authorization->canManageQuickOptions($context['user']),
            'assistantChildDepartments' => $this->authorization->assistantChildDepartments(),
            'domainCheckLabels' => AssistantDomainStore::domainCheckLabels(),
            'shopStatusLabels' => AssistantDomainStore::shopStatusLabels(),
        ]);
    }

    public function syncEmailPurchases(Request $request): RedirectResponse
    {
        $context = $this->portalContext($request);

        if ($context instanceof Response) {
            abort(403);
        }

        $this->authorization->ensureCanManageQuickOptions($context['user']);

        try {
            $result = $this->emailPurchases->synchronize($context['user']);
        } catch (\LogicException $exception) {
            $message = match ($exception->getMessage()) {
                'email_purchase_source_not_configured' => __('assistant.domain_stores.messages.email_purchase_source_not_configured'),
                'email_purchase_source_token_missing' => __('assistant.domain_stores.messages.email_purchase_source_token_missing'),
                'email_purchase_storage_not_ready' => __('assistant.domain_stores.messages.email_purchase_storage_not_ready'),
                default => __('assistant.domain_stores.messages.email_purchase_sync_failed'),
            };

            return $this->domainStoresIndexRedirect($request)->with('error', $message);
        } catch (\Throwable) {
            return $this->domainStoresIndexRedirect($request)
                ->with('error', __('assistant.domain_stores.messages.email_purchase_sync_failed'));
        }

        return $this->domainStoresIndexRedirect($request)->with('success', __('assistant.domain_stores.messages.email_purchase_sync_completed', [
            'source' => $result['source_count'],
            'matched' => $result['matched_store_count'],
            'unmatched' => $result['unmatched_store_count'],
        ]));
    }

    public function emailPurchaseStatus(Request $request): JsonResponse
    {
        $context = $this->portalContext($request);

        if ($context instanceof Response) {
            abort(403);
        }

        $this->authorization->ensureCanManageDomainStores($context['user']);
        $validated = $request->validate(['email' => ['nullable', 'email', 'max:191']]);
        $status = $this->emailPurchases->statusFor($validated['email'] ?? null);
        $labels = $this->emailPurchases->statusLabels();

        return response()->json([
            'status' => $status,
            'label' => $labels[$status] ?? $labels[AssistantEmailPurchaseSyncService::STATUS_UNSYNCED],
            'purchased' => $status === AssistantEmailPurchaseSyncService::STATUS_CURRENT,
        ]);
    }

    public function export(Request $request): Response
    {
        $context = $this->portalContext($request);

        if ($context instanceof Response) {
            return $context;
        }

        $this->authorization->ensureCanManageDomainStores($context['user']);
        $validated = $request->validate([
            'scope' => ['required', 'in:selected,filtered'],
            'selected_ids' => ['required_if:scope,selected', 'array'],
            'selected_ids.*' => ['integer', 'distinct'],
        ]);
        $query = $this->storeQuery($context['user'], $this->filters($request));

        if ($validated['scope'] === 'selected') {
            $query->whereKey($validated['selected_ids'] ?? []);
        }

        $stores = $query->orderByDesc('created_at')->orderByDesc('id')->get();

        if ($stores->isEmpty()) {
            throw ValidationException::withMessages([
                'scope' => __('assistant.domain_stores.export.none'),
            ]);
        }

        return $this->spreadsheets->exportResponse($stores);
    }

    public function importTemplate(Request $request): Response
    {
        $context = $this->portalContext($request);

        if ($context instanceof Response) {
            return $context;
        }

        $this->authorization->ensureCanManageDomainStores($context['user']);

        return $this->spreadsheets->templateResponse();
    }

    public function import(Request $request): Response|RedirectResponse
    {
        $context = $this->portalContext($request);

        if ($context instanceof Response) {
            return $context;
        }

        $this->authorization->ensureCanManageDomainStores($context['user']);
        $departmentAccess = $this->departmentAccessForRequest($request, $context['user']);
        $request->validate([
            'import_file' => [
                'required',
                'file',
                'max:10240',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $extension = strtolower((string) $value->getClientOriginalExtension());

                    if (!in_array($extension, ['csv', 'xls', 'xlsx'], true)) {
                        $fail(__('assistant.domain_stores.import.invalid_extension'));
                    }
                },
            ],
        ]);

        try {
            $rows = $this->spreadsheets->readImportFile($request->file('import_file'));
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['import_file' => $exception->getMessage()]);
        }

        $result = DB::connection(config('admin.database.connection') ?: config('database.default'))
            ->transaction(function () use ($rows, $context, $departmentAccess): array {
                $created = 0;
                $errors = [];
                $webhookCandidates = [];

                foreach ($rows as $row) {
                    $validator = Validator::make($row['data'], $this->storeRules());

                    if ($validator->fails()) {
                        $errors[] = [
                            'row' => $row['row'],
                            'issues' => $this->formatImportValidationIssues($row['data'], $validator->failed()),
                        ];

                        continue;
                    }

                    $data = $this->normalizeValidatedData($validator->validated());

                    if ($this->storeDomainPairExists($data)) {
                        $errors[] = [
                            'row' => $row['row'],
                            'issues' => [$this->duplicateStoreDomainImportIssue($data)],
                        ];

                        continue;
                    }

                    $store = new AssistantDomainStore($data);
                    $this->setLastOperator($store, $context['user']);
                    $store->save();
                    $this->syncDepartmentAccess($store, $departmentAccess['department_ids']);
                    $this->history->recordImported($store, $context['user']);
                    $webhookCandidates[] = ['row' => $row['row'], 'store' => $store];
                    $created++;
                }

                return compact('created', 'errors', 'webhookCandidates');
            });

        foreach ($result['webhookCandidates'] as $candidate) {
            $webhook = $this->autoRegisterWebhook($candidate['store'], $context['user']);
            if ($webhook['attempted'] && !$webhook['webhook_active']) {
                $result['errors'][] = [
                    'row' => $candidate['row'],
                    'issues' => [[
                        'field' => 'Shopify Webhook',
                        'value' => (string) $candidate['store']->shop_name,
                        'reason' => '店铺已导入，但 Webhook 自动登记失败。',
                        'hint' => $webhook['webhook_error'] ?: '请在 Shopify 店铺同步页重新登记 Webhook。',
                    ]],
                ];
            }
        }

        return redirect()
            ->route('assistant.domain-stores.index')
            ->with('success', __('assistant.domain_stores.import.success', ['count' => $result['created']]))
            ->with('import_errors', $result['errors']);
    }

    public function addQuickOption(Request $request): Response|JsonResponse
    {
        $context = $this->portalContext($request);

        if ($context instanceof Response) {
            return $context;
        }

        $this->authorization->ensureCanManageQuickOptions($context['user']);
        $rawValue = $request->input('value');

        if (is_scalar($rawValue)) {
            $request->merge(['value' => trim((string) $rawValue)]);
        }

        $validated = $request->validate([
            'field' => ['required', 'string', Rule::in(AssistantDomainStoreQuickOption::supportedFields())],
            'value' => ['required', 'string', 'max:100'],
        ]);

        $option = AssistantDomainStoreQuickOption::query()->firstOrCreate([
            'field' => $validated['field'],
            'value' => $validated['value'],
        ]);

        return response()->json([
            'option' => [
                'field' => $option->field,
                'value' => $option->value,
            ],
        ]);
    }

    public function removeQuickOption(Request $request): Response|JsonResponse
    {
        $context = $this->portalContext($request);

        if ($context instanceof Response) {
            return $context;
        }

        $this->authorization->ensureCanManageQuickOptions($context['user']);
        $rawValue = $request->input('value');

        if (is_scalar($rawValue)) {
            $request->merge(['value' => trim((string) $rawValue)]);
        }

        $validated = $request->validate([
            'field' => ['required', 'string', Rule::in(AssistantDomainStoreQuickOption::supportedFields())],
            'value' => ['required', 'string', 'max:100'],
        ]);

        AssistantDomainStoreQuickOption::query()
            ->where('field', $validated['field'])
            ->where('value', $validated['value'])
            ->delete();

        return response()->json([
            'option' => [
                'field' => $validated['field'],
                'value' => $validated['value'],
            ],
        ]);
    }

    public function create(Request $request): Response|View
    {
        $context = $this->portalContext($request);

        if ($context instanceof Response) {
            return $context;
        }

        $this->authorization->ensureCanManageDomainStores($context['user']);

        return view('portals.assistant.domain-stores.form', [
            ...$this->viewContext($context),
            ...$this->formOptions(),
            ...$this->departmentAccessFormData($context['user']),
            'store' => new AssistantDomainStore([
                'domain_check_status' => AssistantDomainStore::DOMAIN_CHECK_NOT_CHECKED,
                'shop_status' => AssistantDomainStore::SHOP_STATUS_IN_USE,
                'email_bound' => false,
                'email_purchased' => false,
            ]),
            'emailPurchaseStatus' => $this->emailPurchases->statusFor(null),
            'canManageQuickOptions' => $this->authorization->canManageQuickOptions($context['user']),
            'formMode' => 'create',
            'modalForm' => $this->isModalFormRequest($request),
        ]);
    }

    public function store(Request $request): Response|RedirectResponse
    {
        $context = $this->portalContext($request);

        if ($context instanceof Response) {
            return $context;
        }

        $this->authorization->ensureCanManageDomainStores($context['user']);
        $data = $this->validatedData($request);
        $departmentAccess = $this->departmentAccessForRequest($request, $context['user']);

        $store = DB::connection(config('admin.database.connection') ?: config('database.default'))
            ->transaction(function () use ($data, $context, $departmentAccess): AssistantDomainStore {
                $store = new AssistantDomainStore($data);
                $this->setLastOperator($store, $context['user']);
                $store->save();
                $this->syncDepartmentAccess($store, $departmentAccess['department_ids']);
                $this->history->recordCreated($store, $context['user']);

                return $store;
            });

        $webhook = $this->autoRegisterWebhook($store, $context['user']);

        if ($this->isModalFormRequest($request)) {
            return response()->json([
                'message' => __('assistant.domain_stores.messages.created', ['name' => $store->shop_name]),
                'store_id' => $store->getKey(),
                'webhook_warning' => $webhook['attempted'] && !$webhook['webhook_active'],
            ]);
        }

        $redirect = redirect()
            ->route('assistant.domain-stores.index')
            ->with('success', __('assistant.domain_stores.messages.created', ['name' => $store->shop_name])
                .($webhook['webhook_active'] ? ' Webhook 已自动登记。' : ''));

        return $webhook['attempted'] && !$webhook['webhook_active']
            ? $redirect->with('error', '店铺已保存，但 Webhook 自动登记失败：'.($webhook['webhook_error'] ?: '未知错误'))
            : $redirect;
    }

    public function edit(Request $request, AssistantDomainStore $domainStore): Response|View
    {
        $context = $this->portalContext($request);

        if ($context instanceof Response) {
            return $context;
        }

        $this->authorization->ensureCanManageDomainStores($context['user']);
        $domainStore = $this->findAccessibleStoreOrFail($context['user'], $domainStore->getKey());

        return view('portals.assistant.domain-stores.form', [
            ...$this->viewContext($context),
            ...$this->formOptions(),
            ...$this->departmentAccessFormData($context['user'], $domainStore),
            'store' => $domainStore,
            'emailPurchaseStatus' => $this->emailPurchases->statusFor($domainStore->email),
            'canManageQuickOptions' => $this->authorization->canManageQuickOptions($context['user']),
            'formMode' => 'edit',
            'modalForm' => $this->isModalFormRequest($request),
        ]);
    }

    public function update(Request $request, AssistantDomainStore $domainStore): Response|RedirectResponse
    {
        $context = $this->portalContext($request);

        if ($context instanceof Response) {
            return $context;
        }

        $this->authorization->ensureCanManageDomainStores($context['user']);
        $domainStore = $this->findAccessibleStoreOrFail($context['user'], $domainStore->getKey());
        $data = $this->validatedData($request, $domainStore);
        $departmentAccess = $this->departmentAccessForRequest($request, $context['user'], $domainStore);

        DB::connection($domainStore->getConnectionName())->transaction(function () use ($domainStore, $data, $context, $departmentAccess): void {
            $before = $domainStore->historySnapshot();
            $domainStore->fill($data);
            $this->setLastOperator($domainStore, $context['user']);
            $domainStore->save();
            $this->syncDepartmentAccess($domainStore, $departmentAccess['department_ids']);
            $this->history->recordUpdated($domainStore, $before, $domainStore->historySnapshot(), $context['user']);
        });

        $webhook = $this->autoRegisterWebhook($domainStore, $context['user']);

        if ($this->isModalFormRequest($request)) {
            return response()->json([
                'message' => __('assistant.domain_stores.messages.updated', ['name' => $domainStore->shop_name]),
                'store_id' => $domainStore->getKey(),
                'webhook_warning' => $webhook['attempted'] && !$webhook['webhook_active'],
            ]);
        }

        $redirect = redirect()
            ->route('assistant.domain-stores.index')
            ->with('success', __('assistant.domain_stores.messages.updated', ['name' => $domainStore->shop_name])
                .($webhook['webhook_active'] ? ' Webhook 已自动登记。' : ''));

        return $webhook['attempted'] && !$webhook['webhook_active']
            ? $redirect->with('error', '店铺已保存，但 Webhook 自动登记失败：'.($webhook['webhook_error'] ?: '未知错误'))
            : $redirect;
    }

    public function destroy(Request $request, AssistantDomainStore $domainStore): Response|RedirectResponse
    {
        $context = $this->portalContext($request);

        if ($context instanceof Response) {
            return $context;
        }

        $this->authorization->ensureCanManageDomainStores($context['user']);
        $domainStore = $this->findAccessibleStoreOrFail($context['user'], $domainStore->getKey());
        $shopName = $domainStore->shop_name;

        DB::connection($domainStore->getConnectionName())->transaction(function () use ($domainStore): void {
            $domainStore->changeLogs()->delete();
            $domainStore->authorizedDepartments()->detach();
            $domainStore->delete();
        });

        return redirect()
            ->route('assistant.domain-stores.index')
            ->with('success', __('assistant.domain_stores.messages.deleted', ['name' => $shopName]));
    }

    public function destroyBatch(Request $request): Response|RedirectResponse
    {
        $context = $this->portalContext($request);

        if ($context instanceof Response) {
            return $context;
        }

        $this->authorization->ensureCanManageDomainStores($context['user']);
        $validated = $request->validate([
            'selected_ids' => ['required', 'array', 'min:1', 'max:100'],
            'selected_ids.*' => ['required', 'integer', 'distinct'],
        ]);
        $selectedIds = collect($validated['selected_ids'])->map(static fn (mixed $id): int => (int) $id)->unique()->values();

        $deletedCount = DB::connection(config('admin.database.connection') ?: config('database.default'))
            ->transaction(function () use ($selectedIds, $context): int {
                $storeIds = $this->scopedStoreQuery($context['user'])
                    ->whereKey($selectedIds->all())
                    ->pluck('id');

                if ($storeIds->isEmpty()) {
                    return 0;
                }

                AssistantDomainStoreChangeLog::query()
                    ->whereIn('domain_store_id', $storeIds->all())
                    ->delete();
                DB::connection(config('admin.database.connection') ?: config('database.default'))
                    ->table('assistant_domain_store_department_accesses')
                    ->whereIn('domain_store_id', $storeIds->all())
                    ->delete();
                AssistantDomainStore::query()
                    ->whereKey($storeIds->all())
                    ->delete();

                return $storeIds->count();
            });

        if ($deletedCount === 0) {
            return redirect()
                ->route('assistant.domain-stores.index')
                ->with('error', __('assistant.domain_stores.messages.batch_missing'));
        }

        return redirect()
            ->route('assistant.domain-stores.index')
            ->with('success', __('assistant.domain_stores.messages.batch_deleted', ['count' => $deletedCount]));
    }

    public function batchEdit(Request $request): Response|View
    {
        $context = $this->portalContext($request);

        if ($context instanceof Response) {
            return $context;
        }

        $this->authorization->ensureCanManageDomainStores($context['user']);
        $selectedIds = $this->selectedBatchStoreIds($request);
        $selectedFields = $this->selectedBatchEditableFields($request);
        $stores = $this->batchEditableStores($context['user'], $selectedIds);

        return view('portals.assistant.domain-stores.partials.batch-edit-form', [
            ...$this->formOptions(),
            'stores' => $stores,
            'selectedFields' => $selectedFields,
            'domainCheckLabels' => AssistantDomainStore::domainCheckLabels(),
            'shopStatusLabels' => AssistantDomainStore::shopStatusLabels(),
        ]);
    }

    public function updateBatch(Request $request): Response|RedirectResponse
    {
        $context = $this->portalContext($request);

        if ($context instanceof Response) {
            return $context;
        }

        $this->authorization->ensureCanManageDomainStores($context['user']);

        try {
            $payload = $this->batchUpdatePayload($request);
            $updatedCount = DB::connection(config('admin.database.connection') ?: config('database.default'))
                ->transaction(function () use ($payload, $context): int {
                    $stores = $this->batchEditableStores($context['user'], $payload['selected_ids']);

                    if (array_intersect($payload['fields'], ['shop_name', 'domain']) !== []) {
                        $finalPairs = [];

                        foreach ($stores as $store) {
                            $record = $payload['records'][(int) $store->getKey()];
                            $finalPairs[(int) $store->getKey()] = [
                                'shop_name' => $record['shop_name'] ?? $store->shop_name,
                                'domain' => $record['domain'] ?? $store->domain,
                            ];
                        }

                        $this->ensureBatchStoreDomainPairsAreUnique($finalPairs, $payload['selected_ids']);
                    }
                    $updated = 0;

                    foreach ($stores as $store) {
                        $before = $store->historySnapshot();
                        $store->fill($payload['records'][(int) $store->getKey()]);

                        if (!$store->isDirty()) {
                            continue;
                        }

                        $this->setLastOperator($store, $context['user']);
                        $store->save();
                        $this->history->recordUpdated($store, $before, $store->historySnapshot(), $context['user']);
                        $updated++;
                    }

                    return $updated;
                });
        } catch (ValidationException $exception) {
            throw $exception->errorBag('batchUpdate');
        }

        $message = $updatedCount === 0
            ? __('assistant.domain_stores.messages.batch_unchanged')
            : __('assistant.domain_stores.messages.batch_updated', ['count' => $updatedCount]);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'updated_count' => $updatedCount,
                'type' => $updatedCount === 0 ? 'warning' : 'success',
            ]);
        }

        return $this->domainStoresIndexRedirect($request)
            ->with($updatedCount === 0 ? 'error' : 'success', $message);
    }

    public function grantDepartmentAccessBatch(Request $request): Response|RedirectResponse
    {
        return $this->updateDepartmentAccessBatch($request, true);
    }

    public function revokeDepartmentAccessBatch(Request $request): Response|RedirectResponse
    {
        return $this->updateDepartmentAccessBatch($request, false);
    }

    public function checkBatch(Request $request): Response|RedirectResponse
    {
        $context = $this->portalContext($request);

        if ($context instanceof Response) {
            return $context;
        }

        $this->authorization->ensureCanManageDomainStores($context['user']);
        $validated = $request->validate([
            'selected_ids' => ['required', 'array', 'min:1', 'max:100'],
            'selected_ids.*' => ['required', 'integer', 'distinct'],
        ]);
        $selectedIds = collect($validated['selected_ids'])
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        if ($selectedIds->count() > 20) {
            return redirect()
                ->route('assistant.domain-stores.index')
                ->with('error', __('assistant.domain_stores.domain_check.max_records'));
        }

        // This is intentionally a synchronous manual action. It does not require
        // a queue worker or scheduled task, and each request is short time-limited.
        @set_time_limit(180);
        $stores = $this->scopedStoreQuery($context['user'])
            ->whereKey($selectedIds->all())
            ->orderBy('id')
            ->get();

        if ($stores->isEmpty()) {
            return redirect()
                ->route('assistant.domain-stores.index')
                ->with('error', __('assistant.domain_stores.domain_check.none'));
        }

        $counts = [
            AssistantDomainStore::DOMAIN_CHECK_NORMAL => 0,
            AssistantDomainStore::DOMAIN_CHECK_ABNORMAL => 0,
            AssistantDomainStore::DOMAIN_CHECK_REVIEW => 0,
            AssistantDomainStore::DOMAIN_CHECK_FAILED => 0,
        ];

        foreach ($stores as $store) {
            $before = $store->historySnapshot();
            $result = $this->domainChecks->check($store->domain);
            $store->fill([
                'domain_check_status' => $result['status'],
                'domain_check_root' => $result['root_domain'],
                'domain_checked_at' => now(),
                'domain_check_http_status' => $result['http_status'],
                'domain_check_message' => $result['message'],
            ]);
            $this->setLastOperator($store, $context['user']);
            $store->save();
            $this->history->recordUpdated($store, $before, $store->historySnapshot(), $context['user']);

            if (array_key_exists($result['status'], $counts)) {
                $counts[$result['status']]++;
            }
        }

        return redirect()
            ->route('assistant.domain-stores.index')
            ->with('success', __('assistant.domain_stores.messages.domain_check_completed', [
                'normal' => $counts[AssistantDomainStore::DOMAIN_CHECK_NORMAL],
                'abnormal' => $counts[AssistantDomainStore::DOMAIN_CHECK_ABNORMAL],
                'review' => $counts[AssistantDomainStore::DOMAIN_CHECK_REVIEW],
                'failed' => $counts[AssistantDomainStore::DOMAIN_CHECK_FAILED],
            ]));
    }

    public function history(Request $request, AssistantDomainStore $domainStore): Response|JsonResponse
    {
        $context = $this->portalContext($request);

        if ($context instanceof Response) {
            return $context;
        }

        $domainStore = $this->findAccessibleStoreOrFail($context['user'], $domainStore->getKey());

        $logs = $domainStore->changeLogs()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'html' => view('portals.assistant.domain-stores.partials.history-cards', ['logs' => $logs])->render(),
        ]);
    }

    /** @return array{portal: \App\Models\Portal, user: AdminUser}|Response */
    private function portalContext(Request $request): array|Response
    {
        $context = $this->portalEntries->portalContext($request);

        if ($context instanceof Response) {
            return $context;
        }

        $this->authorization->assertAssistantPortal($context['portal']);

        return $context;
    }

    /** @param array{portal: \App\Models\Portal, user: AdminUser} $context
     *  @return array{portal: \App\Models\Portal, user: AdminUser}
     */
    private function viewContext(array $context): array
    {
        $context['portal']->load('menus');

        return $context;
    }

    /** @return array<string, int|string> */
    private function filters(Request $request): array
    {
        $shopStatus = $this->filterEnum($request, 'shop_status', AssistantDomainStore::shopStatusLabels());
        $domainCheckStatus = $this->filterEnum($request, 'domain_check_status', AssistantDomainStore::domainCheckLabels());
        $rawPerPage = $request->input('per_page', 20);
        $perPage = is_scalar($rawPerPage) ? (int) $rawPerPage : 20;

        if (!in_array($perPage, [20, 50, 100], true)) {
            $perPage = 20;
        }

        return [
            'keyword' => $this->filterKeyword($request),
            'created_from' => $this->filterDate($request, 'created_from'),
            'created_to' => $this->filterDate($request, 'created_to'),
            'site_builder' => $this->filterText($request, 'site_builder'),
            'shop_name' => $this->filterText($request, 'shop_name'),
            'domain' => $this->filterText($request, 'domain'),
            'domain_expires_from' => $this->filterDate($request, 'domain_expires_from'),
            'domain_expires_to' => $this->filterDate($request, 'domain_expires_to'),
            'shop_status' => $shopStatus,
            'domain_check_status' => $domainCheckStatus,
            'department_id' => $this->filterPositiveInteger($request, 'department_id'),
            'email' => $this->filterText($request, 'email'),
            'email_bound' => $this->filterBoolean($request, 'email_bound'),
            'email_purchased' => $this->filterBoolean($request, 'email_purchased'),
            'team' => $this->filterText($request, 'team'),
            'project' => $this->filterText($request, 'project'),
            'user_name' => $this->filterText($request, 'user_name'),
            'erp' => $this->filterText($request, 'erp'),
            'credit_card' => $this->filterText($request, 'credit_card'),
            'wallet' => $this->filterText($request, 'wallet'),
            'paypal' => $this->filterText($request, 'paypal'),
            'other_payment' => $this->filterText($request, 'other_payment'),
            'shop_login_url' => $this->filterText($request, 'shop_login_url'),
            'plan_expires_from' => $this->filterDate($request, 'plan_expires_from'),
            'plan_expires_to' => $this->filterDate($request, 'plan_expires_to'),
            'payment_card' => $this->filterText($request, 'payment_card'),
            'last_operator_name' => $this->filterText($request, 'last_operator_name'),
            'updated_from' => $this->filterDate($request, 'updated_from'),
            'updated_to' => $this->filterDate($request, 'updated_to'),
            'remark' => $this->filterText($request, 'remark'),
            'per_page' => $perPage,
        ];
    }

    /** @param array<string, int|string> $filters */
    private function storeQuery(AdminUser $user, array $filters): Builder
    {
        $keywordTerms = $this->keywordTerms((string) $filters['keyword']);
        $query = $this->scopedStoreQuery($user)
            ->when($keywordTerms !== [], function (Builder $query) use ($keywordTerms): void {
                $query->where(function (Builder $matches) use ($keywordTerms): void {
                    foreach ($keywordTerms as $term) {
                        $matches->orWhere(function (Builder $search) use ($term): void {
                            $this->applyKeywordSearch($search, $term);
                        });
                    }
                });
            })
            ->when($filters['shop_status'] !== '', fn (Builder $query) => $query->where('shop_status', $filters['shop_status']))
            ->when($filters['domain_check_status'] !== '', fn (Builder $query) => $query->where('domain_check_status', $filters['domain_check_status']));

        $departmentId = (int) $filters['department_id'];
        if (
            $departmentId > 0
            && $this->authorization->canManageAllDomainStores($user)
            && $this->authorization->assistantChildDepartments()->contains('id', $departmentId)
        ) {
            $query->whereHas('authorizedDepartments', fn (Builder $access) => $access->whereKey($departmentId));
        }

        foreach ([
            'site_builder' => 'site_builder',
            'shop_name' => 'shop_name',
            'domain' => 'domain',
            'email' => 'email',
            'project' => 'project',
            'user_name' => 'user_name',
            'credit_card' => 'credit_card',
            'wallet' => 'wallet',
            'paypal' => 'paypal',
            'other_payment' => 'other_payment',
            'shop_login_url' => 'shop_login_url',
            'payment_card' => 'payment_card',
            'last_operator_name' => 'last_operator_name',
            'remark' => 'remark',
        ] as $filter => $column) {
            if ($filters[$filter] !== '') {
                $query->where($column, 'like', $this->containsValue((string) $filters[$filter]));
            }
        }

        foreach (['team', 'erp'] as $filter) {
            if ($filters[$filter] !== '') {
                $query->whereJsonContains($filter, (string) $filters[$filter]);
            }
        }

        foreach ([
            'created_from' => ['created_at', '>='],
            'created_to' => ['created_at', '<='],
            'domain_expires_from' => ['domain_expires_at', '>='],
            'domain_expires_to' => ['domain_expires_at', '<='],
            'plan_expires_from' => ['plan_expires_at', '>='],
            'plan_expires_to' => ['plan_expires_at', '<='],
            'updated_from' => ['updated_at', '>='],
            'updated_to' => ['updated_at', '<='],
        ] as $filter => [$column, $operator]) {
            if ($filters[$filter] !== '') {
                $query->whereDate($column, $operator, $filters[$filter]);
            }
        }

        foreach (['email_bound', 'email_purchased'] as $filter) {
            if ($filters[$filter] !== '') {
                $query->where($filter, (bool) $filters[$filter]);
            }
        }

        return $query;
    }

    private function applyKeywordSearch(Builder $query, string $term): void
    {
        $keyword = $this->containsValue($term);

        $query->where('shop_name', 'like', $keyword)
            ->orWhere('domain', 'like', $keyword)
            ->orWhere('email', 'like', $keyword)
            ->orWhere('team', 'like', $keyword)
            ->orWhere('project', 'like', $keyword)
            ->orWhere('user_name', 'like', $keyword)
            ->orWhere('erp', 'like', $keyword)
            ->orWhere('credit_card', 'like', $keyword)
            ->orWhere('wallet', 'like', $keyword)
            ->orWhere('paypal', 'like', $keyword)
            ->orWhere('other_payment', 'like', $keyword)
            ->orWhere('shop_login_url', 'like', $keyword)
            ->orWhere('payment_card', 'like', $keyword)
            ->orWhere('last_operator_name', 'like', $keyword)
            ->orWhere('remark', 'like', $keyword);
    }

    /**
     * Restrict Assistant department users before any search, filter, pagination,
     * export, or batch condition is applied. Other portal-authorized departments
     * retain their existing read-only view of all records.
     */
    private function scopedStoreQuery(AdminUser $user): Builder
    {
        if (!$this->authorization->isAssistantDepartmentMember($user)) {
            return AssistantDomainStore::query();
        }

        return $this->authorization->scopeDomainStores(AssistantDomainStore::query(), $user);
    }

    private function findAccessibleStoreOrFail(AdminUser $user, int|string|null $storeId): AssistantDomainStore
    {
        return $this->scopedStoreQuery($user)
            ->whereKey($storeId)
            ->firstOrFail();
    }

    /** @return array<string, array<string, string>> */
    private function formOptions(): array
    {
        return [
            'siteBuilderOptions' => AssistantDomainStore::siteBuilderOptions(),
            'erpOptions' => AssistantDomainStore::erpOptions(),
            'creditCardOptions' => AssistantDomainStore::creditCardOptions(),
            'walletOptions' => AssistantDomainStore::walletOptions(),
            'paypalOptions' => AssistantDomainStore::paypalOptions(),
            'otherPaymentOptions' => AssistantDomainStore::otherPaymentOptions(),
            'quickOptions' => AssistantDomainStoreQuickOption::valuesByField(),
            'domainCheckLabels' => AssistantDomainStore::domainCheckLabels(),
            'shopStatusLabels' => AssistantDomainStore::shopStatusLabels(),
        ];
    }

    /** @return array<string, mixed> */
    private function departmentAccessFormData(AdminUser $user, ?AssistantDomainStore $store = null): array
    {
        $departments = $this->authorization->assistantChildDepartments();
        $canManageDepartmentAccess = $this->authorization->canManageAllDomainStores($user);
        $childDepartmentId = $this->authorization->assistantChildDepartmentFor($user)?->getKey();
        $authorizedDepartmentIds = old(
            'authorized_department_ids',
            $store?->exists
                ? $store->authorizedDepartments()->pluck('admin_roles.id')->map(static fn ($id): int => (int) $id)->all()
                : ($canManageDepartmentAccess || $childDepartmentId === null ? [] : [(int) $childDepartmentId]),
        );

        return [
            'canManageDepartmentAccess' => $canManageDepartmentAccess,
            'assistantChildDepartments' => $departments,
            'authorizedDepartmentIds' => collect(is_array($authorizedDepartmentIds) ? $authorizedDepartmentIds : [])
                ->map(static fn ($id): int => (int) $id)
                ->unique()
                ->values()
                ->all(),
        ];
    }

    /**
     * A primary manager or administrator chooses the child departments explicitly.
     * A child-department member cannot alter access and new records are shared with
     * that member's own child department automatically.
     *
     * @return array{department_ids: list<int>}
     */
    private function departmentAccessForRequest(
        Request $request,
        AdminUser $user,
        ?AssistantDomainStore $existingStore = null,
    ): array
    {
        if (!$this->authorization->canManageAllDomainStores($user)) {
            $department = $this->authorization->assistantChildDepartmentFor($user);

            abort_unless($department !== null, 403);

            if ($existingStore !== null) {
                return [
                    'department_ids' => $existingStore->authorizedDepartments()
                        ->pluck('admin_roles.id')
                        ->map(static fn ($id): int => (int) $id)
                        ->values()
                        ->all(),
                ];
            }

            return [
                'department_ids' => [(int) $department->getKey()],
            ];
        }

        $validated = $request->validate([
            'authorized_department_ids' => ['nullable', 'array'],
            'authorized_department_ids.*' => ['integer', 'distinct'],
        ]);
        $departments = $this->authorization->assistantChildDepartments()->keyBy('id');
        $departmentIds = collect($validated['authorized_department_ids'] ?? [])
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values();

        if ($departmentIds->diff($departments->keys())->isNotEmpty()) {
            throw ValidationException::withMessages([
                'authorized_department_ids' => __('assistant.domain_stores.validation.invalid_department_access'),
            ]);
        }

        return [
            'department_ids' => $departmentIds->all(),
        ];
    }

    private function updateDepartmentAccessBatch(Request $request, bool $grant): Response|RedirectResponse
    {
        $context = $this->portalContext($request);

        if ($context instanceof Response) {
            return $context;
        }

        abort_unless($this->authorization->canManageAllDomainStores($context['user']), 403);
        $validated = $request->validate([
            'selected_ids' => ['required', 'array', 'min:1', 'max:100'],
            'selected_ids.*' => ['required', 'integer', 'distinct'],
            'department_ids' => ['required', 'array', 'min:1'],
            'department_ids.*' => ['required', 'integer', 'distinct'],
        ]);
        $departmentIds = collect($validated['department_ids'])
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();
        $availableDepartmentIds = $this->authorization->assistantChildDepartments()
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id);

        if ($departmentIds->diff($availableDepartmentIds)->isNotEmpty()) {
            throw ValidationException::withMessages([
                'department_ids' => __('assistant.domain_stores.validation.invalid_department_access'),
            ]);
        }

        $selectedIds = collect($validated['selected_ids'])
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();
        $affected = DB::connection(config('admin.database.connection') ?: config('database.default'))
            ->transaction(function () use ($context, $departmentIds, $grant, $selectedIds): int {
                $stores = $this->scopedStoreQuery($context['user'])
                    ->whereKey($selectedIds->all())
                    ->orderBy('id')
                    ->get();
                $affected = 0;

                foreach ($stores as $store) {
                    $currentIds = $store->authorizedDepartments()
                        ->pluck('admin_roles.id')
                        ->map(static fn (mixed $id): int => (int) $id);
                    $changedIds = $grant
                        ? $departmentIds->diff($currentIds)
                        : $departmentIds->intersect($currentIds);

                    if ($changedIds->isEmpty()) {
                        continue;
                    }

                    $before = $store->historySnapshot();

                    if ($grant) {
                        $store->authorizedDepartments()->syncWithoutDetaching($changedIds->all());
                    } else {
                        $store->authorizedDepartments()->detach($changedIds->all());
                    }

                    $store->unsetRelation('authorizedDepartments');
                    $this->setLastOperator($store, $context['user']);
                    $store->save();
                    $this->history->recordUpdated($store, $before, $store->historySnapshot(), $context['user']);
                    $affected++;
                }

                return $affected;
            });

        return redirect()
            ->route('assistant.domain-stores.index')
            ->with($affected === 0 ? 'error' : 'success', $affected === 0
                ? __('assistant.domain_stores.messages.batch_access_unchanged')
                : __($grant
                    ? 'assistant.domain_stores.messages.batch_access_granted'
                    : 'assistant.domain_stores.messages.batch_access_revoked', ['count' => $affected]));
    }

    /** @param list<int> $departmentIds */
    private function syncDepartmentAccess(AssistantDomainStore $store, array $departmentIds): void
    {
        $store->authorizedDepartments()->sync($departmentIds);
        $store->unsetRelation('authorizedDepartments');
    }

    /** @return array<string, mixed> */
    private function validatedData(Request $request, ?AssistantDomainStore $ignoreStore = null): array
    {
        foreach ([
            'site_builder', 'shop_name', 'domain', 'email', 'project', 'user_name',
            'credit_card', 'wallet', 'paypal', 'other_payment', 'shop_login_url', 'store_prefix',
            'shopify_client_id', 'shopify_client_secret', 'payment_card', 'remark',
        ] as $field) {
            if ($request->has($field)) {
                $request->merge([$field => trim((string) $request->input($field))]);
            }
        }

        $data = $this->normalizeValidatedData($request->validate($this->storeRules()));

        if ($this->storeDomainPairExists($data, $ignoreStore)) {
            throw ValidationException::withMessages([
                'shop_name' => __('assistant.domain_stores.validation.store_domain_exists'),
            ]);
        }

        return $data;
    }

    /** @param array<string, mixed> $data
     *  @return array<string, mixed>
     */
    private function normalizeValidatedData(array $data): array
    {
        foreach (['team', 'erp'] as $field) {
            $values = AssistantDomainStore::normalizeMultiValue($data[$field] ?? null);
            $data[$field] = $values === [] ? null : $values;
        }

        $data['email_purchased'] = $this->emailPurchases->isPurchased($data['email'] ?? null);

        return $data;
    }

    /** @param array<string, mixed> $data */
    private function storeDomainPairExists(array $data, ?AssistantDomainStore $ignoreStore = null): bool
    {
        $query = AssistantDomainStore::query()
            ->where('shop_name', (string) $data['shop_name'])
            ->where('domain', (string) $data['domain']);

        if ($ignoreStore?->getKey() !== null) {
            $query->where('id', '<>', $ignoreStore->getKey());
        }

        return $query->exists();
    }

    /**
     * @return array{selected_ids: list<int>, fields: list<string>, records: array<int, array<string, mixed>>}
     */
    private function batchUpdatePayload(Request $request): array
    {
        $selectedIds = $this->selectedBatchStoreIds($request);
        $selectedFields = $this->selectedBatchEditableFields($request);
        $submittedRecords = $request->input('records');

        if (!is_array($submittedRecords)) {
            throw ValidationException::withMessages([
                'records' => __('assistant.domain_stores.batch_update.records_required'),
            ]);
        }

        $submittedIds = collect(array_keys($submittedRecords))
            ->filter(static fn (mixed $id): bool => is_numeric($id))
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();
        $expectedIds = collect($selectedIds)->sort()->values()->all();

        if ($submittedIds !== $expectedIds) {
            throw ValidationException::withMessages([
                'records' => __('assistant.domain_stores.batch_update.selection_changed'),
            ]);
        }

        $records = [];
        $errors = [];

        foreach ($selectedIds as $storeId) {
            $data = $submittedRecords[$storeId] ?? $submittedRecords[(string) $storeId] ?? null;

            if (!is_array($data)) {
                $errors['records.'.$storeId] = [__('assistant.domain_stores.batch_update.record_data_required')];

                continue;
            }

            $data = array_intersect_key($data, array_flip($selectedFields));

            foreach (array_intersect($this->batchEditableStringFields(), $selectedFields) as $field) {
                if (array_key_exists($field, $data) && is_scalar($data[$field])) {
                    $data[$field] = trim((string) $data[$field]);
                }
            }

            foreach (array_intersect(['team', 'erp'], $selectedFields) as $field) {
                $data[$field] = AssistantDomainStore::normalizeMultiValue($data[$field] ?? null);
            }

            $validator = Validator::make($data, $this->batchRulesForFields($selectedFields));

            if ($validator->fails()) {
                foreach ($validator->errors()->toArray() as $field => $messages) {
                    $errors['records.'.$storeId.'.'.$field] = $messages;
                }

                continue;
            }

            $records[$storeId] = $this->normalizeValidatedData($validator->validated());
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'selected_ids' => $selectedIds,
            'fields' => $selectedFields,
            'records' => $records,
        ];
    }

    /**
     * Validate every final pair before writing any selected record, so batch
     * editing cannot create duplicate store/domain records or partial saves.
     *
     * @param array<int, array<string, mixed>> $records
     * @param list<int> $selectedIds
     */
    private function ensureBatchStoreDomainPairsAreUnique(array $records, array $selectedIds): void
    {
        $pairs = [];

        foreach ($records as $storeId => $data) {
            $shopName = (string) $data['shop_name'];
            $domain = (string) $data['domain'];
            $key = strtolower($shopName)."\0".strtolower($domain);

            if (isset($pairs[$key])) {
                throw ValidationException::withMessages([
                    'records.'.$storeId.'.shop_name' => __('assistant.domain_stores.batch_update.duplicate_pair'),
                ]);
            }

            $pairs[$key] = [
                'store_id' => $storeId,
                'shop_name' => $shopName,
                'domain' => $domain,
            ];
        }

        $conflicts = AssistantDomainStore::query()
            ->whereNotIn('id', $selectedIds)
            ->where(function (Builder $query) use ($pairs): void {
                foreach ($pairs as $pair) {
                    $query->orWhere(function (Builder $pairQuery) use ($pair): void {
                        $pairQuery
                            ->where('shop_name', $pair['shop_name'])
                            ->where('domain', $pair['domain']);
                    });
                }
            })
            ->get(['shop_name', 'domain']);

        if ($conflicts->isNotEmpty()) {
            $errors = [];

            foreach ($conflicts as $conflict) {
                $key = strtolower((string) $conflict->shop_name)."\0".strtolower((string) $conflict->domain);
                $storeId = $pairs[$key]['store_id'] ?? null;

                if ($storeId !== null) {
                    $errors['records.'.$storeId.'.shop_name'] = [
                        __('assistant.domain_stores.validation.store_domain_exists'),
                    ];
                }
            }

            if ($errors === []) {
                $errors['records'] = [__('assistant.domain_stores.validation.store_domain_exists')];
            }

            throw ValidationException::withMessages($errors);
        }
    }

    /** @return list<int> */
    private function selectedBatchStoreIds(Request $request): array
    {
        $validated = Validator::make($request->all(), [
            'selected_ids' => ['required', 'array', 'min:1', 'max:100'],
            'selected_ids.*' => ['required', 'integer', 'distinct'],
        ])->validate();
        $selectedIds = collect($validated['selected_ids'])
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        if ($selectedIds->count() > 20) {
            throw ValidationException::withMessages([
                'selected_ids' => __('assistant.domain_stores.batch_update.max_records', ['count' => 20]),
            ]);
        }

        return $selectedIds->all();
    }

    /** @return list<string> */
    private function selectedBatchEditableFields(Request $request): array
    {
        $validated = Validator::make($request->all(), [
            'fields' => ['required', 'array', 'min:1', 'max:'.count($this->batchEditableFields())],
            'fields.*' => ['required', 'string', 'distinct'],
        ])->validate();
        $requestedFields = collect($validated['fields'])
            ->filter(static fn (mixed $field): bool => is_string($field))
            ->values()
            ->all();
        $fields = collect($requestedFields)
            ->filter(fn (string $field): bool => in_array($field, $this->batchEditableFields(), true))
            ->values()
            ->all();

        if ($fields === [] || count($fields) !== count($requestedFields)) {
            throw ValidationException::withMessages([
                'fields' => __('assistant.domain_stores.batch_update.choose_fields'),
            ]);
        }

        return $fields;
    }

    /** @return list<string> */
    private function batchEditableFields(): array
    {
        return [
            'shop_name', 'domain', 'shop_login_url', 'email', 'domain_expires_at', 'domain_check_status', 'email_bound',
            'team', 'project', 'user_name',
            'site_builder', 'erp', 'credit_card', 'wallet', 'paypal', 'other_payment', 'payment_card',
            'store_prefix', 'shopify_client_id', 'shopify_client_secret',
            'shop_status', 'plan_expires_at', 'remark',
        ];
    }

    /** @param list<string> $fields
     *  @return array<string, array<int, string>>
     */
    private function batchRulesForFields(array $fields): array
    {
        $rules = $this->storeRules();
        $selectedRules = [];

        foreach ($fields as $field) {
            if (isset($rules[$field])) {
                $selectedRules[$field] = $rules[$field];
            }

            if (in_array($field, ['team', 'erp'], true) && isset($rules[$field.'.*'])) {
                $selectedRules[$field.'.*'] = $rules[$field.'.*'];
            }
        }

        return $selectedRules;
    }

    /** @return list<string> */
    private function batchEditableStringFields(): array
    {
        return [
            'site_builder', 'shop_name', 'domain', 'email', 'project', 'user_name',
            'credit_card', 'wallet', 'paypal', 'other_payment', 'shop_login_url', 'store_prefix',
            'shopify_client_id', 'shopify_client_secret', 'payment_card', 'remark',
        ];
    }

    /** @return \Illuminate\Support\Collection<int, AssistantDomainStore> */
    private function batchEditableStores(AdminUser $user, array $selectedIds)
    {
        $stores = $this->scopedStoreQuery($user)
            ->with('authorizedDepartments')
            ->whereKey($selectedIds)
            ->orderBy('id')
            ->get();

        if ($stores->count() !== count($selectedIds)) {
            throw ValidationException::withMessages([
                'selected_ids' => __('assistant.domain_stores.messages.batch_update_missing'),
            ]);
        }

        return $stores;
    }

    private function domainStoresIndexRedirect(Request $request): RedirectResponse
    {
        $fallback = redirect()->route('assistant.domain-stores.index');
        $returnTo = $request->input('return_to');

        if (!is_string($returnTo) || $returnTo === '') {
            return $fallback;
        }

        $expected = parse_url(route('assistant.domain-stores.index'));
        $candidate = parse_url($returnTo);

        if (!is_array($expected) || !is_array($candidate)) {
            return $fallback;
        }

        foreach (['scheme', 'host', 'port', 'path'] as $part) {
            if (($candidate[$part] ?? null) !== ($expected[$part] ?? null)) {
                return $fallback;
            }
        }

        return redirect()->to($returnTo);
    }

    private function isModalFormRequest(Request $request): bool
    {
        return $request->boolean('modal') && $request->ajax();
    }

    /** @return array{attempted:bool, webhook_active:bool, webhook_error:?string} */
    private function autoRegisterWebhook(AssistantDomainStore $store, AdminUser $actor): array
    {
        if (!$this->canAutoRegisterWebhook($store)) {
            return ['attempted' => false, 'webhook_active' => false, 'webhook_error' => null];
        }

        $result = $this->shopify->registerStoreWebhooks($store, $actor);

        return ['attempted' => true, ...$result];
    }

    private function canAutoRegisterWebhook(AssistantDomainStore $store): bool
    {
        return $store->site_builder === 'shopify'
            && $store->shop_status === AssistantDomainStore::SHOP_STATUS_IN_USE
            && filled($store->shop_login_url)
            && filled($store->shopify_client_id)
            && filled($store->shopify_client_secret);
    }

    /** @param array<string, mixed> $data
     *  @return array{field: string, value: string, reason: string, hint: string}
     */
    private function duplicateStoreDomainImportIssue(array $data): array
    {
        return [
            'field' => __('assistant.domain_stores.validation.store_domain_pair'),
            'value' => __('assistant.domain_stores.validation.store_domain_pair_value', [
                'shop_name' => (string) $data['shop_name'],
                'domain' => (string) $data['domain'],
            ]),
            'reason' => __('assistant.domain_stores.validation.store_domain_exists'),
            'hint' => __('assistant.domain_stores.validation.store_domain_exists_hint'),
        ];
    }

    /** @return array<string, array<int, string>> */
    private function storeRules(): array
    {
        return [
            'site_builder' => ['nullable', 'string', 'max:100'],
            'shop_name' => ['required', 'string', 'max:100'],
            'domain' => ['required', 'string', 'max:191'],
            'domain_expires_at' => ['nullable', 'date'],
            'domain_check_status' => ['required', 'in:'.implode(',', array_keys(AssistantDomainStore::domainCheckLabels()))],
            'email' => ['nullable', 'email', 'max:191'],
            'email_bound' => ['required', 'boolean'],
            'team' => ['nullable', 'array', 'max:20'],
            'team.*' => ['required', 'string', 'max:100'],
            'project' => ['nullable', 'string', 'max:100'],
            'user_name' => ['nullable', 'string', 'max:100'],
            'erp' => ['nullable', 'array', 'max:20'],
            'erp.*' => ['required', 'string', 'max:100'],
            'credit_card' => ['nullable', 'string', 'max:100'],
            'wallet' => ['nullable', 'string', 'max:100'],
            'paypal' => ['nullable', 'string', 'max:100'],
            'other_payment' => ['nullable', 'string', 'max:100'],
            'shop_login_url' => ['nullable', 'string', 'max:2048'],
            'store_prefix' => ['nullable', 'string', 'max:100'],
            'shopify_client_id' => ['nullable', 'string', 'max:255'],
            'shopify_client_secret' => ['nullable', 'string', 'max:255'],
            'shop_status' => ['required', 'in:'.implode(',', array_keys(AssistantDomainStore::shopStatusLabels()))],
            'plan_expires_at' => ['nullable', 'date'],
            'payment_card' => ['nullable', 'string', 'max:255'],
            'remark' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Convert internal validator rule names into import-friendly, field-level messages.
     *
     * @param array<string, mixed> $data
     * @param array<string, array<string, array<int, mixed>>> $failedRules
     * @return list<array{field: string, value: string, reason: string, hint: ?string}>
     */
    private function formatImportValidationIssues(array $data, array $failedRules): array
    {
        $issues = [];

        foreach ($failedRules as $attribute => $rules) {
            $field = explode('.', $attribute)[0];
            $value = data_get($data, $attribute);

            foreach ($rules as $rule => $parameters) {
                $rule = strtolower($rule);

                $issues[] = [
                    'field' => $this->importFieldLabel($field, $attribute),
                    'value' => $this->formatImportIssueValue($value),
                    'reason' => $this->importValidationReason($field, $attribute, $rule),
                    'hint' => $this->importValidationHint($field, $attribute, $rule, $parameters),
                ];
            }
        }

        return $issues;
    }

    private function importFieldLabel(string $field, string $attribute): string
    {
        $label = AssistantDomainStore::fieldLabel($field);
        $segments = explode('.', $attribute);

        if (isset($segments[1]) && ctype_digit($segments[1])) {
            return __('assistant.domain_stores.import.nested_field', [
                'field' => $label,
                'number' => (int) $segments[1] + 1,
            ]);
        }

        return $label;
    }

    private function formatImportIssueValue(mixed $value): string
    {
        if ($value === null || $value === '' || (is_array($value) && $value === [])) {
            return __('assistant.domain_stores.validation.empty_value');
        }

        if (is_bool($value)) {
            return $value ? __('assistant.domain_stores.common.yes') : __('assistant.domain_stores.common.no');
        }

        if (is_array($value)) {
            return implode(',', array_map(static fn (mixed $item): string => (string) $item, $value));
        }

        return (string) $value;
    }

    private function importValidationReason(string $field, string $attribute, string $rule): string
    {
        return match ($rule) {
            'required' => __('assistant.domain_stores.validation.required'),
            'email' => __('assistant.domain_stores.validation.email'),
            'url' => __('assistant.domain_stores.validation.url'),
            'date' => __('assistant.domain_stores.validation.date'),
            'in' => __('assistant.domain_stores.validation.in'),
            'boolean' => __('assistant.domain_stores.validation.boolean'),
            'array' => __('assistant.domain_stores.validation.array'),
            'max' => in_array($field, ['team', 'erp'], true) && !str_contains($attribute, '.')
                ? __('assistant.domain_stores.validation.max_items')
                : __('assistant.domain_stores.validation.max_length'),
            default => __('assistant.domain_stores.validation.invalid'),
        };
    }

    /** @param array<int, mixed> $parameters */
    private function importValidationHint(string $field, string $attribute, string $rule, array $parameters): ?string
    {
        if ($rule === 'email') {
            return __('assistant.domain_stores.validation.email_hint');
        }

        if ($rule === 'url') {
            return __('assistant.domain_stores.validation.url_hint');
        }

        if ($rule === 'date') {
            return __('assistant.domain_stores.validation.date_hint');
        }

        if ($rule === 'boolean') {
            return __('assistant.domain_stores.validation.boolean_hint');
        }

        if ($rule === 'array') {
            return __('assistant.domain_stores.validation.array_hint');
        }

        if ($rule === 'max') {
            if (in_array($field, ['team', 'erp'], true) && !str_contains($attribute, '.')) {
                return __('assistant.domain_stores.validation.max_items_hint');
            }

            return isset($parameters[0])
                ? __('assistant.domain_stores.validation.max_length_hint', ['max' => $parameters[0]])
                : null;
        }

        if ($rule !== 'in') {
            return null;
        }

        $options = match ($field) {
            'site_builder' => array_values(AssistantDomainStore::siteBuilderOptions()),
            'domain_check_status' => array_values(AssistantDomainStore::domainCheckLabels()),
            'shop_status' => array_values(AssistantDomainStore::shopStatusLabels()),
            'credit_card' => array_values(AssistantDomainStore::creditCardOptions()),
            'wallet' => array_values(AssistantDomainStore::walletOptions()),
            'paypal' => array_values(AssistantDomainStore::paypalOptions()),
            'other_payment' => array_values(AssistantDomainStore::otherPaymentOptions()),
            default => [],
        };

        return $options === []
            ? null
            : __('assistant.domain_stores.validation.allowed_values_hint', ['values' => implode('、', $options)]);
    }

    /** @param array<string, string> $allowed */
    private function filterEnum(Request $request, string $field, array $allowed): string
    {
        $rawValue = $request->input($field, '');
        $value = is_scalar($rawValue) ? trim((string) $rawValue) : '';

        return array_key_exists($value, $allowed) ? $value : '';
    }

    private function filterText(Request $request, string $field): string
    {
        $value = $request->input($field, '');

        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function filterKeyword(Request $request): string
    {
        $keyword = $this->filterText($request, 'keyword');

        return $this->keywordTerms($keyword) === [] ? '' : $keyword;
    }

    private function filterBoolean(Request $request, string $field): string
    {
        $rawValue = $request->input($field, '');
        $value = is_scalar($rawValue) ? (string) $rawValue : '';

        return in_array($value, ['0', '1'], true) ? $value : '';
    }

    private function filterPositiveInteger(Request $request, string $field): string
    {
        $rawValue = $request->input($field, '');
        $value = is_scalar($rawValue) ? (int) $rawValue : 0;

        return $value > 0 ? (string) $value : '';
    }

    private function filterDate(Request $request, string $field): string
    {
        $rawValue = $request->input($field, '');
        $value = is_scalar($rawValue) ? trim((string) $rawValue) : '';
        $date = \DateTime::createFromFormat('!Y-m-d', $value);

        return $date instanceof \DateTimeInterface && $date->format('Y-m-d') === $value ? $value : '';
    }

    /** @return list<string> */
    private function keywordTerms(string $keyword): array
    {
        $terms = [];

        foreach (preg_split('/[,，]+/u', $keyword) ?: [] as $term) {
            $term = trim($term);

            if ($term !== '' && !in_array($term, $terms, true)) {
                $terms[] = $term;
            }
        }

        return $terms;
    }

    private function containsValue(string $value): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value).'%';
    }

    private function setLastOperator(AssistantDomainStore $store, AdminUser $user): void
    {
        $store->last_operator_id = $user->getKey();
        $store->last_operator_name = $user->name;
    }
}
