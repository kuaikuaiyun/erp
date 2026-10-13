<?php

namespace App\Portals\Assistant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\AssistantDomainStore;
use App\Models\AssistantShopifyAnalyticsSyncBatch;
use App\Models\AssistantShopifyAnalyticsSyncTask;
use App\Models\AssistantShopifyStoreMetric;
use App\Models\AssistantShopifyStoreMetricHistory;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\Assistant\AssistantPortalAuthorizationService;
use App\Portals\Assistant\ShopifyStoreAnalyticsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class ShopifyAnalyticsController extends Controller
{
    public function __construct(
        private readonly PortalEntryController $portalEntries,
        private readonly AssistantPortalAuthorizationService $authorization,
        private readonly ShopifyStoreAnalyticsService $analytics,
    ) {
    }

    public function index(Request $request): Response|View
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }

        $this->authorization->ensureCanViewShopifyAnalytics($context['user']);
        $filters = $this->filters($request);
        $baseQuery = $this->visibleShopifyStores($context['user']);
        $timezoneOptions = $this->timezoneOptions(clone $baseQuery, $filters['report_date']);
        $query = $this->applyTimezoneFilter(clone $baseQuery, $filters['timezone'], $filters['report_date'])
            ->when($filters['shop_name'] !== '', function (Builder $stores) use ($filters): void {
                $stores->where('shop_name', 'like', '%'.$filters['shop_name'].'%');
            })
            ->when($filters['domains'] !== [], function (Builder $stores) use ($filters): void {
                $stores->whereIn('domain', $filters['domains']);
            });
        $summary = $this->summary(clone $query, $filters['report_date']);

        $storesQuery = $query
            ->orderByRaw('CASE WHEN shop_status = ? THEN 0 ELSE 1 END', [AssistantDomainStore::SHOP_STATUS_IN_USE])
            ->orderBy('shop_name')
            ->orderBy('id');

        if ($filters['report_date'] === '') {
            $storesQuery->with('shopifyAnalyticsMetric');
        } else {
            $storesQuery->with([
                'shopifyAnalyticsHistory' => static function (HasMany $history) use ($filters): void {
                    $history->whereDate('report_date', $filters['report_date']);
                },
            ]);
        }

        $stores = $storesQuery
            ->paginate($filters['per_page'])
            ->withQueryString();
        $activeSyncBatch = $this->currentSyncBatch($request, $context['user']);
        $syncIssues = $activeSyncBatch === null ? [] : $this->syncIssueDetails($activeSyncBatch);

        return view('portals.assistant.shopify-analytics.index', [
            ...$this->viewContext($context),
            'stores' => $stores,
            'filters' => $filters,
            'timezoneOptions' => $timezoneOptions,
            'summary' => $summary,
            'metricStatuses' => AssistantShopifyStoreMetric::statusLabels(),
            'activeSyncBatch' => $activeSyncBatch,
            'syncIssues' => $syncIssues,
        ]);
    }

    public function refresh(Request $request, AssistantDomainStore $domainStore): RedirectResponse
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) {
            abort(403);
        }

        $this->authorization->ensureCanViewShopifyAnalytics($context['user']);
        $store = $this->visibleShopifyStores($context['user'])
            ->whereKey($domainStore->getKey())
            ->firstOrFail();
        abort_unless($store->site_builder === 'shopify', 404);

        if ($store->shop_status !== AssistantDomainStore::SHOP_STATUS_IN_USE) {
            return $this->indexRedirect($request)
                ->with('error', __('assistant.shopify_analytics.messages.inactive_store'));
        }

        $metric = $this->analytics->refresh($store, $context['user']);
        $messageKey = $metric->sync_status === AssistantShopifyStoreMetric::STATUS_SUCCESS
            ? 'messages.refresh_success'
            : 'messages.refresh_failed';

        return $this->indexRedirect($request)->with(
            $metric->sync_status === AssistantShopifyStoreMetric::STATUS_SUCCESS ? 'success' : 'error',
            __('assistant.shopify_analytics.'.$messageKey, ['name' => $store->shop_name]),
        );
    }

    public function refreshBatch(Request $request): RedirectResponse
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) {
            abort(403);
        }

        $this->authorization->ensureCanViewShopifyAnalytics($context['user']);

        $validated = $request->validate([
            'selected_ids' => ['required', 'array', 'min:1'],
            'selected_ids.*' => ['integer', 'distinct'],
        ]);

        $selectedIds = collect($validated['selected_ids'])
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        $stores = $this->visibleShopifyStores($context['user'])
            ->whereKey($selectedIds->all())
            ->orderBy('id')
            ->get();

        // A stale or crafted request must never refresh a store outside the
        // current user's scoped Shopify-store list, nor partially refresh it.
        if ($stores->count() !== $selectedIds->count()) {
            return $this->indexRedirect($request)
                ->with('error', __('assistant.shopify_analytics.messages.batch_selection_invalid'));
        }

        $unavailableStore = $stores->first(function (AssistantDomainStore $store): bool {
            return !$this->canRefresh($store);
        });

        if ($unavailableStore !== null) {
            return $this->indexRedirect($request)->with(
                'error',
                __('assistant.shopify_analytics.messages.batch_refresh_not_available', [
                    'name' => $unavailableStore->shop_name,
                ]),
            );
        }

        $activeBatchExists = AssistantShopifyAnalyticsSyncBatch::query()
            ->where('actor_id', $context['user']->getKey())
            ->whereIn('status', [
                AssistantShopifyAnalyticsSyncBatch::STATUS_QUEUED,
                AssistantShopifyAnalyticsSyncBatch::STATUS_PROCESSING,
            ])
            ->exists();
        if ($activeBatchExists) {
            return $this->indexRedirect($request)
                ->with('error', __('assistant.shopify_analytics.messages.batch_in_progress'));
        }

        $busyStoreId = AssistantShopifyAnalyticsSyncTask::query()
            ->whereIn('assistant_domain_store_id', $selectedIds->all())
            ->whereIn('status', AssistantShopifyAnalyticsSyncTask::activeStatuses())
            ->value('assistant_domain_store_id');
        if ($busyStoreId !== null) {
            return $this->indexRedirect($request)
                ->with('error', __('assistant.shopify_analytics.messages.batch_store_busy'));
        }

        $batch = $this->createSyncBatch($stores, $context['user']);
        $request->session()->put($this->syncBatchSessionKey(), $batch->getKey());

        return $this->indexRedirect($request)->with(
            'success',
            __('assistant.shopify_analytics.messages.batch_queued', ['count' => $batch->total_count]),
        );
    }

    public function syncProgress(Request $request, AssistantShopifyAnalyticsSyncBatch $batch): JsonResponse
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) {
            abort(403);
        }

        $this->authorization->ensureCanViewShopifyAnalytics($context['user']);
        abort_unless((int) $batch->actor_id === (int) $context['user']->getKey(), 403);

        $batch->refreshProgress();
        $batch->refresh();
        $payload = $batch->progressPayload();
        $payload['issues'] = $this->syncIssueDetails($batch);

        return response()->json($payload);
    }

    /** @return Builder<AssistantDomainStore> */
    private function visibleShopifyStores(AdminUser $user): Builder
    {
        return $this->authorization
            ->scopeDomainStores(AssistantDomainStore::query(), $user)
            ->where('site_builder', 'shopify');
    }

    private function canRefresh(AssistantDomainStore $store): bool
    {
        return $store->shop_status === AssistantDomainStore::SHOP_STATUS_IN_USE
            && filled($store->shopify_client_id)
            && filled($store->shopify_client_secret);
    }

    /** @param \Illuminate\Support\Collection<int, AssistantDomainStore> $stores */
    private function createSyncBatch($stores, AdminUser $user): AssistantShopifyAnalyticsSyncBatch
    {
        $connection = config('admin.database.connection') ?: config('database.default');

        return DB::connection($connection)->transaction(function () use ($stores, $user): AssistantShopifyAnalyticsSyncBatch {
            $batch = AssistantShopifyAnalyticsSyncBatch::query()->create([
                'actor_id' => $user->getKey(),
                'actor_name' => $user->name,
                'status' => AssistantShopifyAnalyticsSyncBatch::STATUS_QUEUED,
                'total_count' => $stores->count(),
            ]);

            $batch->tasks()->createMany($stores->map(static fn (AssistantDomainStore $store): array => [
                'assistant_domain_store_id' => $store->getKey(),
                'store_name' => $store->shop_name,
                'status' => AssistantShopifyAnalyticsSyncTask::STATUS_QUEUED,
            ])->all());

            return $batch;
        });
    }

    private function currentSyncBatch(Request $request, AdminUser $user): ?AssistantShopifyAnalyticsSyncBatch
    {
        $batchId = (int) $request->session()->get($this->syncBatchSessionKey(), 0);
        $query = AssistantShopifyAnalyticsSyncBatch::query()
            ->where('actor_id', $user->getKey());

        $batch = $batchId > 0
            ? (clone $query)->whereKey($batchId)->first()
            : null;
        if ($batch === null) {
            $batch = (clone $query)
                ->whereIn('status', [
                    AssistantShopifyAnalyticsSyncBatch::STATUS_QUEUED,
                    AssistantShopifyAnalyticsSyncBatch::STATUS_PROCESSING,
                ])
                ->latest('id')
                ->first();
        }
        if ($batch === null) {
            return null;
        }

        $request->session()->put($this->syncBatchSessionKey(), $batch->getKey());
        $batch->refreshProgress();
        $batch->refresh();

        return $batch;
    }

    /** @return list<array{store_name:string, reason:string}> */
    private function syncIssueDetails(AssistantShopifyAnalyticsSyncBatch $batch): array
    {
        return $batch->tasks()
            ->where('status', AssistantShopifyAnalyticsSyncTask::STATUS_FAILED)
            ->latest('completed_at')
            ->limit(10)
            ->get(['store_name', 'last_error'])
            ->map(function (AssistantShopifyAnalyticsSyncTask $task): array {
                return [
                    'store_name' => (string) $task->store_name,
                    'reason' => $this->syncReasonLabel($task->last_error),
                ];
            })
            ->values()
            ->all();
    }

    private function syncReasonLabel(?string $reason): string
    {
        $reason = filled($reason) ? $reason : 'unknown';
        $key = 'assistant.shopify_analytics.reasons.'.$reason;
        $translation = __($key);

        return $translation === $key
            ? __('assistant.shopify_analytics.reasons.unknown')
            : $translation;
    }

    private function syncBatchSessionKey(): string
    {
        return 'assistant.shopify_analytics.sync_batch_id';
    }

    /** @return array{shop_name:string, domains:list<string>, timezone:string, report_date:string, per_page:int} */
    private function filters(Request $request): array
    {
        $perPage = (int) $request->input('per_page', 20);
        $date = trim((string) $request->input('report_date'));

        return [
            'shop_name' => trim((string) $request->input('shop_name')),
            'domains' => $this->domains((string) $request->input('domains')),
            'timezone' => trim((string) $request->input('timezone')),
            'report_date' => $this->date($date),
            'per_page' => in_array($perPage, [20, 50, 100], true) ? $perPage : 20,
        ];
    }

    /** @return list<string> */
    private function domains(string $domains): array
    {
        $values = preg_split('/[,\\x{FF0C}]+/u', $domains) ?: [];
        $normalized = array_map(static fn (string $domain): string => strtolower(trim($domain)), $values);

        return array_values(array_unique(array_filter($normalized, static fn (string $domain): bool => $domain !== '')));
    }

    private function date(string $date): string
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $matches)) {
            return '';
        }

        return checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1]) ? $date : '';
    }

    /** @param Builder<AssistantDomainStore> $query
     *  @return Builder<AssistantDomainStore>
     */
    private function applyTimezoneFilter(Builder $query, string $timezone, string $reportDate): Builder
    {
        if ($timezone === '') {
            return $query;
        }

        $relation = $reportDate === '' ? 'shopifyAnalyticsMetric' : 'shopifyAnalyticsHistory';
        $scopeDate = static function (Builder $metric) use ($reportDate): void {
            if ($reportDate !== '') {
                $metric->whereDate('report_date', $reportDate);
            }
        };

        if ($timezone === '__pending__') {
            return $query->where(function (Builder $pending) use ($relation, $scopeDate): void {
                $pending->whereDoesntHave($relation, $scopeDate)
                    ->orWhereHas($relation, function (Builder $metric) use ($scopeDate): void {
                        $scopeDate($metric);
                        $metric->whereNull('shop_timezone');
                    });
            });
        }

        return $query->whereHas($relation, function (Builder $metric) use ($timezone, $scopeDate): void {
            $scopeDate($metric);
            $metric->where('shop_timezone', $timezone);
        });
    }

    /** @param Builder<AssistantDomainStore> $query
     *  @return array<string, string>
     */
    private function timezoneOptions(Builder $query, string $reportDate): array
    {
        $metricsTable = $this->metricsTable($reportDate);
        $timezones = $query
            ->join($metricsTable.' as analytics_metrics', function ($join) use ($reportDate): void {
                $join->on('assistant_domain_stores.id', '=', 'analytics_metrics.assistant_domain_store_id');

                if ($reportDate !== '') {
                    $join->where('analytics_metrics.report_date', '=', $reportDate);
                }
            })
            ->whereNotNull('analytics_metrics.shop_timezone')
            ->where('analytics_metrics.shop_timezone', '!=', '')
            ->orderBy('analytics_metrics.shop_timezone')
            ->distinct()
            ->pluck('analytics_metrics.shop_timezone')
            ->filter(static fn ($timezone): bool => is_string($timezone) && in_array($timezone, timezone_identifiers_list(), true))
            ->values();

        $options = ['' => __('assistant.shopify_analytics.filters.all_timezones')];
        foreach ($timezones as $timezone) {
            $options[$timezone] = $this->timezoneLabel($timezone);
        }
        $options['__pending__'] = __('assistant.shopify_analytics.filters.timezone_pending');

        return $options;
    }

    /** @param Builder<AssistantDomainStore> $query
     *  @return array<string, float|int>
     */
    private function summary(Builder $query, string $reportDate): array
    {
        $metricsTable = $this->metricsTable($reportDate);
        $row = $query
            ->leftJoin($metricsTable.' as analytics_metrics', function ($join) use ($reportDate): void {
                $join->on('assistant_domain_stores.id', '=', 'analytics_metrics.assistant_domain_store_id');

                if ($reportDate !== '') {
                    $join->where('analytics_metrics.report_date', '=', $reportDate);
                }
            })
            ->selectRaw('COUNT(assistant_domain_stores.id) as stores_count')
            ->selectRaw('SUM(CASE WHEN analytics_metrics.sync_status = ? THEN 1 ELSE 0 END) as synced_stores_count', [AssistantShopifyStoreMetric::STATUS_SUCCESS])
            ->selectRaw('COALESCE(SUM(CASE WHEN analytics_metrics.sync_status = ? THEN analytics_metrics.total_sales ELSE 0 END), 0) as total_sales', [AssistantShopifyStoreMetric::STATUS_SUCCESS])
            ->selectRaw('COALESCE(SUM(CASE WHEN analytics_metrics.sync_status = ? THEN analytics_metrics.orders_count ELSE 0 END), 0) as orders_count', [AssistantShopifyStoreMetric::STATUS_SUCCESS])
            ->selectRaw('COALESCE(SUM(CASE WHEN analytics_metrics.sync_status = ? THEN analytics_metrics.fulfilled_orders_count ELSE 0 END), 0) as fulfilled_orders_count', [AssistantShopifyStoreMetric::STATUS_SUCCESS])
            ->selectRaw('COALESCE(SUM(CASE WHEN analytics_metrics.sync_status = ? THEN analytics_metrics.refund_amount ELSE 0 END), 0) as refund_amount', [AssistantShopifyStoreMetric::STATUS_SUCCESS])
            ->selectRaw('COALESCE(SUM(CASE WHEN analytics_metrics.sync_status = ? THEN analytics_metrics.net_sales ELSE 0 END), 0) as net_sales', [AssistantShopifyStoreMetric::STATUS_SUCCESS])
            ->first();

        return [
            'stores_count' => (int) ($row?->stores_count ?? 0),
            'synced_stores_count' => (int) ($row?->synced_stores_count ?? 0),
            'total_sales' => (float) ($row?->total_sales ?? 0),
            'orders_count' => (int) ($row?->orders_count ?? 0),
            'fulfilled_orders_count' => (int) ($row?->fulfilled_orders_count ?? 0),
            'refund_amount' => (float) ($row?->refund_amount ?? 0),
            'net_sales' => (float) ($row?->net_sales ?? 0),
        ];
    }

    private function metricsTable(string $reportDate): string
    {
        return $reportDate === ''
            ? (new AssistantShopifyStoreMetric())->getTable()
            : (new AssistantShopifyStoreMetricHistory())->getTable();
    }

    private function timezoneLabel(string $timezone): string
    {
        $offset = now($timezone)->format('P');

        return __('assistant.shopify_analytics.filters.timezone_option', [
            'timezone' => $timezone,
            'offset' => 'UTC'.$offset,
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

    private function indexRedirect(Request $request): RedirectResponse
    {
        $returnTo = trim((string) $request->input('return_to'));
        $candidate = parse_url($returnTo);
        $expectedPath = '/shopify-analytics';

        if ($returnTo !== ''
            && is_array($candidate)
            && strtolower((string) ($candidate['host'] ?? '')) === strtolower($request->getHost())
            && '/'.trim((string) ($candidate['path'] ?? ''), '/') === $expectedPath) {
            return redirect()->to($returnTo);
        }

        return redirect()->to(rtrim($request->getSchemeAndHttpHost(), '/').$expectedPath);
    }
}
