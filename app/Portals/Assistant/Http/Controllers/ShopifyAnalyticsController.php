<?php

namespace App\Portals\Assistant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\AssistantDomainStore;
use App\Models\AssistantShopifyAnalyticsSyncBatch;
use App\Models\AssistantShopifyAnalyticsSyncTask;
use App\Models\AssistantShopifyStoreMetric;
use App\Models\AssistantShopifyStoreOrder;
use App\Models\AssistantShopifyStoreMetricPeriod;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\Assistant\AssistantPortalAuthorizationService;
use App\Portals\Assistant\ShopifyAnalyticsReferenceRateService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\View\View;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpFoundation\Response;

class ShopifyAnalyticsController extends Controller
{
    public function __construct(
        private readonly PortalEntryController $portalEntries,
        private readonly AssistantPortalAuthorizationService $authorization,
        private readonly ShopifyAnalyticsReferenceRateService $referenceRates,
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
        $baseQuery = $this->visibleShopifyStores($context['user'])
            ->when($filters['shop_name'] !== '', function (Builder $stores) use ($filters): void {
                $stores->where('shop_name', 'like', '%'.$filters['shop_name'].'%');
            })
            ->when($filters['domains'] !== [], function (Builder $stores) use ($filters): void {
                $stores->whereIn('domain', $filters['domains']);
            });
        $periods = $this->periodQuery($filters);
        $summary = $this->summary(clone $baseQuery, clone $periods);
        $storesQuery = $this->withPeriod(clone $baseQuery, clone $periods);
        $this->applySorting($storesQuery, $filters);
        $stores = $storesQuery
            ->paginate($filters['per_page'])
            ->withQueryString();
        $stores->getCollection()->each(function (AssistantDomainStore $store) use ($filters): void {
            $metric = null;
            if ($store->analytics_sync_status !== null || (bool) $store->analytics_has_report_data) {
                $metric = new AssistantShopifyStoreMetricPeriod();
                $metric->setRawAttributes([
                    'shop_timezone' => $store->analytics_shop_timezone,
                    'report_timezone' => $store->analytics_report_timezone ?? $filters['timezone'],
                    'from_date' => $store->analytics_from_date ?? $filters['from_date'],
                    'to_date' => $store->analytics_to_date ?? $filters['to_date'],
                    'currency_code' => $store->analytics_currency_code ?? 'USD',
                    'original_currency_code' => $store->analytics_original_currency_code,
                    'total_sales' => $store->analytics_total_sales,
                    'original_total_sales' => $store->analytics_original_total_sales,
                    'original_refund_amount' => $store->analytics_original_refund_amount,
                    'original_net_sales' => $store->analytics_original_net_sales,
                    'orders_count' => $store->analytics_orders_count,
                    'returning_customer_rate' => $store->analytics_returning_customer_rate,
                    'fulfilled_orders_count' => $store->analytics_fulfilled_orders_count,
                    'refund_amount' => $store->analytics_refund_amount,
                    'net_sales' => $store->analytics_net_sales,
                    'average_order_value' => $store->analytics_average_order_value,
                    'sync_status' => $store->analytics_sync_status,
                    'sync_message' => $store->analytics_sync_message,
                    'synced_at' => $store->analytics_synced_at,
                    'has_report_data' => (bool) $store->analytics_has_report_data,
                    'reference_rate' => $this->referenceRates->rateFor($store->analytics_original_currency_code),
                    'reference_rate_configured' => $this->referenceRates->isConfigured($store->analytics_original_currency_code),
                ], true);
            }

            $store->setRelation('shopifyAnalyticsPeriod', $metric);
        });
        $activeSyncBatch = $this->currentSyncBatch($request, $context['user']);
        $syncIssues = $activeSyncBatch === null ? [] : $this->syncIssueDetails($activeSyncBatch);

        return view('portals.assistant.shopify-analytics.index', [
            ...$this->viewContext($context),
            'stores' => $stores,
            'filters' => $filters,
            'timezoneOptions' => $this->timezoneOptions(),
            'referenceRates' => $this->referenceRates->rates(),
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

        // A first store sync may fetch more than ninety local dates. Queue it
        // through the same worker as multi-select synchronization so a slow
        // Shopify response never holds the browser request open or allows
        // repeated clicks to create competing imports.
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

        $busy = AssistantShopifyAnalyticsSyncTask::query()
            ->where('assistant_domain_store_id', $store->getKey())
            ->whereIn('status', AssistantShopifyAnalyticsSyncTask::activeStatuses())
            ->exists();
        if ($busy) {
            return $this->indexRedirect($request)
                ->with('error', __('assistant.shopify_analytics.messages.batch_store_busy'));
        }

        $batch = $this->createSyncBatch(collect([$store]), $context['user'], $this->filters($request));
        $request->session()->put($this->syncBatchSessionKey(), $batch->getKey());

        return $this->indexRedirect($request)->with(
            'success',
            __('assistant.shopify_analytics.messages.batch_queued', ['count' => 1]),
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

        $batch = $this->createSyncBatch($stores, $context['user'], $this->filters($request));
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
    private function createSyncBatch($stores, AdminUser $user, array $filters): AssistantShopifyAnalyticsSyncBatch
    {
        $connection = config('admin.database.connection') ?: config('database.default');

        return DB::connection($connection)->transaction(function () use ($stores, $user, $filters): AssistantShopifyAnalyticsSyncBatch {
            $batch = AssistantShopifyAnalyticsSyncBatch::query()->create([
                'actor_id' => $user->getKey(),
                'actor_name' => $user->name,
                'report_timezone' => $filters['timezone'],
                'from_date' => $filters['from_date'],
                'to_date' => $filters['to_date'],
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

    /** @return array{shop_name:string, domains:list<string>, timezone:string, from_date:string, to_date:string, sort:string, direction:string, per_page:int} */
    private function filters(Request $request): array
    {
        $perPage = (int) $request->input('per_page', 20);
        $defaultTimezone = (string) config('services.assistant.shopify_analytics.default_timezone', 'Asia/Shanghai');
        $defaultTimezone = in_array($defaultTimezone, timezone_identifiers_list(), true)
            ? $defaultTimezone
            : 'Asia/Shanghai';
        $timezoneOptions = $this->timezoneOptions();
        $defaultTimezone = array_key_exists($defaultTimezone, $timezoneOptions)
            ? $defaultTimezone
            : 'Asia/Shanghai';
        $timezone = trim((string) $request->input('timezone', $defaultTimezone));
        $timezone = array_key_exists($timezone, $timezoneOptions) ? $timezone : $defaultTimezone;
        $today = now($timezone)->toDateString();
        $fromDate = $this->date(trim((string) $request->input('from_date')));
        $toDate = $this->date(trim((string) $request->input('to_date')));
        $fromDate = $fromDate === '' ? $today : $fromDate;
        $toDate = $toDate === '' ? $fromDate : $toDate;

        if ($fromDate > $toDate) {
            [$fromDate, $toDate] = [$toDate, $fromDate];
        }

        $sort = trim((string) $request->input('sort', 'total_sales'));
        $direction = strtolower(trim((string) $request->input('direction', 'desc')));

        return [
            'shop_name' => trim((string) $request->input('shop_name')),
            'domains' => $this->domains((string) $request->input('domains')),
            'timezone' => $timezone,
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'sort' => array_key_exists($sort, $this->sortableColumns()) ? $sort : 'total_sales',
            'direction' => $direction === 'asc' ? 'asc' : 'desc',
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

    private function periodQuery(array $filters): QueryBuilder
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $orderTable = (new AssistantShopifyStoreOrder())->getTable();
        [$fromUtc, $toUtc] = $this->reportingUtcRange($filters);

        // The synchronized source data remains exactly as Shopify returned it:
        // an order created timestamp in UTC plus source-currency amounts. The
        // reporting timezone is applied only here, while reading the saved
        // order rows; changing a page filter must never request Shopify.
        $orders = DB::connection($connection)->table($orderTable.' as shopify_order')
            ->selectRaw('shopify_order.assistant_domain_store_id')
            ->selectRaw('? as report_timezone', [$filters['timezone']])
            ->selectRaw('? as from_date', [$filters['from_date']])
            ->selectRaw('? as to_date', [$filters['to_date']])
            ->selectRaw("'USD' as currency_code")
            ->selectRaw('MAX(shopify_order.original_currency_code) as original_currency_code')
            ->selectRaw('COALESCE(SUM(shopify_order.original_total_price), 0) as original_total_sales')
            ->selectRaw('COALESCE(SUM('.$this->referenceUsdRowAggregate('shopify_order.original_total_price', 'shopify_order.original_currency_code').'), 0) as total_sales')
            ->selectRaw('COALESCE(SUM(shopify_order.refunded_total_amount), 0) as original_refund_amount')
            ->selectRaw('COALESCE(SUM('.$this->referenceUsdRowAggregate('shopify_order.refunded_total_amount', 'shopify_order.refunded_currency_code').'), 0) as refund_amount')
            ->selectRaw('COALESCE(SUM(shopify_order.current_total_price), 0) as original_net_sales')
            ->selectRaw('COALESCE(SUM('.$this->referenceUsdRowAggregate('shopify_order.current_total_price', 'shopify_order.current_currency_code').'), 0) as net_sales')
            ->selectRaw('COUNT(*) as orders_count')
            ->selectRaw('0 as returning_customer_rate')
            ->selectRaw("SUM(CASE WHEN UPPER(COALESCE(shopify_order.fulfillment_status, '')) = 'FULFILLED' THEN 1 ELSE 0 END) as fulfilled_orders_count")
            ->selectRaw('NULL as average_order_value')
            ->selectRaw('MAX(shopify_order.synced_at) as synced_at')
            ->selectRaw('1 as has_report_data')
            ->where('shopify_order.shopify_created_at', '>=', $fromUtc->format('Y-m-d H:i:s'))
            ->where('shopify_order.shopify_created_at', '<', $toUtc->format('Y-m-d H:i:s'))
            ->where('shopify_order.is_test', false)
            ->whereNull('shopify_order.shopify_cancelled_at')
            ->groupBy('shopify_order.assistant_domain_store_id')
            ->havingRaw('COUNT(*) > 0');

        return DB::connection($connection)
            ->query()
            ->fromSub($orders, 'analytics_periods')
            ->select('analytics_periods.*');
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function reportingUtcRange(array $filters): array
    {
        $fromUtc = CarbonImmutable::createFromFormat('!Y-m-d', $filters['from_date'], $filters['timezone'])
            ->startOfDay()
            ->setTimezone('UTC');
        $toUtc = CarbonImmutable::createFromFormat('!Y-m-d', $filters['to_date'], $filters['timezone'])
            ->addDay()
            ->startOfDay()
            ->setTimezone('UTC');

        return [$fromUtc, $toUtc];
    }

    /** @return Builder<AssistantDomainStore> */
    private function withPeriod(Builder $query, QueryBuilder $periods): Builder
    {
        return $this->joinPeriod($query, $periods)
            ->select('assistant_domain_stores.*')
            ->addSelect([
                'analytics_sync_state.shop_timezone as analytics_shop_timezone',
                'analytics_metrics.report_timezone as analytics_report_timezone',
                'analytics_metrics.from_date as analytics_from_date',
                'analytics_metrics.to_date as analytics_to_date',
                'analytics_metrics.currency_code as analytics_currency_code',
                DB::raw('COALESCE(analytics_metrics.original_currency_code, analytics_sync_state.original_currency_code) as analytics_original_currency_code'),
                'analytics_metrics.total_sales as analytics_total_sales',
                'analytics_metrics.original_total_sales as analytics_original_total_sales',
                'analytics_metrics.original_refund_amount as analytics_original_refund_amount',
                'analytics_metrics.original_net_sales as analytics_original_net_sales',
                'analytics_metrics.orders_count as analytics_orders_count',
                'analytics_metrics.returning_customer_rate as analytics_returning_customer_rate',
                'analytics_metrics.fulfilled_orders_count as analytics_fulfilled_orders_count',
                'analytics_metrics.refund_amount as analytics_refund_amount',
                'analytics_metrics.net_sales as analytics_net_sales',
                'analytics_metrics.average_order_value as analytics_average_order_value',
                'analytics_sync_state.sync_status as analytics_sync_status',
                'analytics_sync_state.sync_message as analytics_sync_message',
                'analytics_sync_state.synced_at as analytics_synced_at',
                'analytics_metrics.has_report_data as analytics_has_report_data',
            ]);
    }

    /** @return Builder<AssistantDomainStore> */
    private function joinPeriod(Builder $query, QueryBuilder $periods): Builder
    {
        $metricTable = (new AssistantShopifyStoreMetric())->getTable();

        return $query
            ->leftJoinSub($periods, 'analytics_metrics', function ($join): void {
                $join->on('assistant_domain_stores.id', '=', 'analytics_metrics.assistant_domain_store_id');
            })
            ->leftJoin($metricTable.' as analytics_sync_state', 'assistant_domain_stores.id', '=', 'analytics_sync_state.assistant_domain_store_id');
    }

    /** @return array<string, float|int> */
    private function summary(Builder $query, QueryBuilder $periods): array
    {
        $row = $this->joinPeriod($query, $periods)
            ->selectRaw('COUNT(assistant_domain_stores.id) as stores_count')
            ->selectRaw('SUM(CASE WHEN analytics_sync_state.sync_status = ? THEN 1 ELSE 0 END) as synced_stores_count', [AssistantShopifyStoreMetric::STATUS_SUCCESS])
            ->selectRaw('COALESCE(SUM(analytics_metrics.total_sales), 0) as total_sales')
            ->selectRaw('COALESCE(SUM(analytics_metrics.orders_count), 0) as orders_count')
            ->selectRaw('COALESCE(SUM(analytics_metrics.fulfilled_orders_count), 0) as fulfilled_orders_count')
            ->selectRaw('COALESCE(SUM(analytics_metrics.refund_amount), 0) as refund_amount')
            ->selectRaw('COALESCE(SUM(analytics_metrics.net_sales), 0) as net_sales')
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

    /** @return array<string, string> */
    private function timezoneOptions(): array
    {
        $options = [];
        for ($offset = -12; $offset <= 14; $offset++) {
            $timezone = $offset === 8
                ? 'Asia/Shanghai'
                : ($offset === 0 ? 'UTC' : 'Etc/GMT'.($offset > 0 ? '-'.$offset : '+'.abs($offset)));
            $options[$timezone] = $this->relativeTimezoneLabel($offset);
        }

        return $options;
    }

    /** @return array<string, string> */
    private function sortableColumns(): array
    {
        return [
            'shop_name' => 'assistant_domain_stores.shop_name',
            'domain' => 'assistant_domain_stores.domain',
            'shop_status' => 'assistant_domain_stores.shop_status',
            'team' => 'assistant_domain_stores.team',
            'email_purchased' => 'assistant_domain_stores.email_purchased',
            'shop_timezone' => 'analytics_sync_state.shop_timezone',
            'total_sales' => 'analytics_metrics.total_sales',
            'original_total_sales' => 'analytics_metrics.original_total_sales',
            'orders_count' => 'analytics_metrics.orders_count',
            'fulfilled_orders_count' => 'analytics_metrics.fulfilled_orders_count',
            'refund_amount' => 'analytics_metrics.refund_amount',
            'net_sales' => 'analytics_metrics.net_sales',
            'sync_status' => 'analytics_sync_state.sync_status',
            'synced_at' => 'analytics_sync_state.synced_at',
            'report_period' => 'analytics_metrics.to_date',
        ];
    }

    private function applySorting(Builder $query, array $filters): void
    {
        $column = $this->sortableColumns()[$filters['sort']];
        $query
            ->orderByRaw("CASE WHEN {$column} IS NULL THEN 1 ELSE 0 END")
            ->orderBy($column, $filters['direction'])
            ->orderBy('assistant_domain_stores.id');
    }

    private function relativeTimezoneLabel(int $utcOffset): string
    {
        $relativeToShanghai = $utcOffset - 8;
        $relativeLabel = match (true) {
            $relativeToShanghai === 0 => __('assistant.shopify_analytics.filters.timezone_shanghai'),
            $relativeToShanghai > 0 => __('assistant.shopify_analytics.filters.timezone_after_shanghai', ['hours' => $relativeToShanghai]),
            default => __('assistant.shopify_analytics.filters.timezone_before_shanghai', ['hours' => abs($relativeToShanghai)]),
        };

        return __('assistant.shopify_analytics.filters.timezone_relative_option', [
            'relative' => $relativeLabel,
            'offset' => 'UTC'.sprintf('%+03d:00', $utcOffset),
        ]);
    }

    private function referenceUsdAggregate(string $amountExpression, string $currencyExpression): string
    {
        $clauses = [];
        foreach ($this->referenceRates->rates() as $currency => $rate) {
            $clauses[] = "WHEN '{$currency}' THEN ({$amountExpression}) * ".number_format($rate, 6, '.', '');
        }

        return "CASE UPPER(COALESCE({$currencyExpression}, '')) ".implode(' ', $clauses).' ELSE NULL END';
    }

    private function referenceUsdRowAggregate(string $amountExpression, string $currencyExpression): string
    {
        return $this->referenceUsdAggregate($amountExpression, $currencyExpression);
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
