<?php

namespace App\Portals\Logistics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Jobs\SyncShopifyStoreJob;
use App\Models\AssistantDomainStore;
use App\Models\LogisticsShopifySyncBatch;
use App\Models\LogisticsShopifySyncTask;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\Logistics\LogisticsPortalService;
use App\Portals\Logistics\ShopifyOrderSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class ShopifyStoreController extends Controller
{
    private const MANUAL_SYNC_DAYS = 60;

    public function __construct(
        private readonly PortalEntryController $portalEntries,
        private readonly LogisticsPortalService $logistics,
        private readonly ShopifyOrderSyncService $shopify,
    ) {
    }

    public function index(Request $request): Response|View
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }

        $perPage = (int) $request->input('per_page', 20);
        if (!in_array($perPage, [20, 50, 100], true)) {
            $perPage = 20;
        }

        $stores = AssistantDomainStore::query()
            ->where('site_builder', 'shopify')
            ->where('shop_status', AssistantDomainStore::SHOP_STATUS_IN_USE)
            ->with('shopifyConnection')
            ->orderBy('shop_name')
            ->paginate($perPage)
            ->withQueryString();
        $syncBatch = $this->currentSyncBatch($request, (int) $context['user']->getKey());
        if ($syncBatch) {
            $syncBatch->refreshProgress();
            $syncBatch->refresh();
        }

        return view('portals.logistics.shopify-stores.index', [
            ...$this->viewContext($context),
            'stores' => $stores,
            'filters' => ['per_page' => $perPage],
            'activeSyncBatch' => $syncBatch,
            'syncIssueDetails' => $syncBatch ? $this->syncIssueDetails($syncBatch) : [],
            'manualSyncDays' => self::MANUAL_SYNC_DAYS,
        ]);
    }

    public function sync(Request $request, AssistantDomainStore $domainStore): RedirectResponse
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) {
            abort(403);
        }

        $this->ensureShopifyStore($domainStore);

        return $this->queueSyncBatch($request, collect([$domainStore]), $context);
    }

    public function batchSync(Request $request): RedirectResponse
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) {
            abort(403);
        }

        $data = $request->validate([
            'selected_ids' => ['required', 'array', 'min:1', 'max:100'],
            'selected_ids.*' => ['integer', 'distinct'],
        ]);
        $stores = AssistantDomainStore::query()
            ->whereIn('id', $data['selected_ids'])
            ->where('site_builder', 'shopify')
            ->where('shop_status', AssistantDomainStore::SHOP_STATUS_IN_USE)
            ->orderBy('shop_name')
            ->get();

        if ($stores->isEmpty()) {
            return $this->indexRedirect($request)->with('error', '未找到可同步的 Shopify 店铺。');
        }

        return $this->queueSyncBatch($request, $stores, $context);
    }

    public function syncProgress(Request $request, LogisticsShopifySyncBatch $batch): JsonResponse
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) {
            abort(403);
        }
        if ((int) $batch->actor_id !== (int) $context['user']->getKey()) {
            abort(403);
        }

        $batch->refreshProgress();
        $batch->refresh();
        $payload = $batch->progressPayload();
        $payload['issues'] = $this->syncIssueDetails($batch);

        return response()->json($payload);
    }

    public function cancelSync(Request $request, LogisticsShopifySyncBatch $batch): RedirectResponse
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) {
            abort(403);
        }
        if ((int) $batch->actor_id !== (int) $context['user']->getKey()) {
            abort(403);
        }

        $batch->refreshProgress();
        $batch->refresh();
        if ($batch->isTerminal()) {
            return $this->indexRedirect($request)->with('success', '该 Shopify 同步批次已经结束。');
        }

        $connection = config('admin.database.connection') ?: config('database.default');
        DB::connection($connection)->transaction(function () use ($batch): void {
            $now = now();
            $batch->forceFill([
                'status' => LogisticsShopifySyncBatch::STATUS_CANCELLING,
                'cancel_requested_at' => $now,
            ])->save();
            $batch->tasks()
                ->where('status', LogisticsShopifySyncTask::STATUS_QUEUED)
                ->update([
                    'status' => LogisticsShopifySyncTask::STATUS_CANCELLED,
                    'completed_at' => $now,
                    'updated_at' => $now,
                ]);
        });
        $batch->refreshProgress();

        return $this->indexRedirect($request)
            ->with('success', '已停止尚未开始的店铺；当前正在同步的店铺会安全完成后结束。');
    }

    public function registerWebhooks(Request $request, AssistantDomainStore $domainStore): RedirectResponse
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) {
            abort(403);
        }

        $this->ensureShopifyStore($domainStore);
        $summary = $this->shopify->registerStoreWebhooks($domainStore, $context['user']);

        return $this->indexRedirect($request)->with(
            $summary['webhook_active'] ? 'success' : 'error',
            $summary['webhook_active']
                ? $domainStore->shop_name.' Webhook 已登记。未同步历史订单。'
                : $domainStore->shop_name.' Webhook 登记失败：'.($summary['webhook_error'] ?: '未知错误'),
        );
    }

    public function batchRegisterWebhooks(Request $request): RedirectResponse
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) {
            abort(403);
        }

        $data = $request->validate([
            'selected_ids' => ['required', 'array', 'min:1', 'max:50'],
            'selected_ids.*' => ['integer', 'distinct'],
        ]);
        $stores = AssistantDomainStore::query()
            ->whereIn('id', $data['selected_ids'])
            ->where('site_builder', 'shopify')
            ->where('shop_status', AssistantDomainStore::SHOP_STATUS_IN_USE)
            ->orderBy('shop_name')
            ->get();
        if ($stores->isEmpty()) {
            return $this->indexRedirect($request)->with('error', '未找到可登记 Webhook 的 Shopify 店铺。');
        }

        $successes = [];
        $failures = [];
        foreach ($stores as $store) {
            $summary = $this->shopify->registerStoreWebhooks($store, $context['user']);
            if ($summary['webhook_active']) {
                $successes[] = $store->shop_name;
                continue;
            }

            $failures[] = $store->shop_name.'：'.($summary['webhook_error'] ?: '未知错误');
        }

        $message = '批量登记 Webhook 完成：成功 '.count($successes).' 家'.($successes ? '（'.implode('、', $successes).'）' : '');
        if ($failures !== []) {
            return $this->indexRedirect($request)
                ->with('error', $message.'；失败 '.count($failures).' 家（'.implode('；', $failures).'）');
        }

        return $this->indexRedirect($request)->with('success', $message.'。未同步历史订单。');
    }

    /**
     * @param Collection<int, AssistantDomainStore> $stores
     * @param array{portal: \App\Models\Portal, user: \App\Models\AdminUser} $context
     */
    private function queueSyncBatch(Request $request, Collection $stores, array $context): RedirectResponse
    {
        $activeBatch = $this->activeSyncBatch((int) $context['user']->getKey());
        if ($activeBatch) {
            $request->session()->put('logistics.shopify_sync_batch_id', $activeBatch->getKey());

            return $this->indexRedirect($request)
                ->with('error', '当前已有 Shopify 后台同步任务，请等待完成或先停止剩余任务。');
        }

        $storeIds = $stores
            ->map(static fn (AssistantDomainStore $store): int => (int) $store->getKey())
            ->all();
        $busyStoreIds = LogisticsShopifySyncTask::query()
            ->whereIn('assistant_domain_store_id', $storeIds)
            ->whereIn('status', LogisticsShopifySyncTask::activeStatuses())
            ->pluck('assistant_domain_store_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        $queueableStores = $stores
            ->reject(static fn (AssistantDomainStore $store): bool => in_array((int) $store->getKey(), $busyStoreIds, true))
            ->values();
        if ($queueableStores->isEmpty()) {
            return $this->indexRedirect($request)
                ->with('error', '所选店铺已在 Shopify 同步队列中，请等待当前任务完成。');
        }

        $connection = config('admin.database.connection') ?: config('database.default');
        $batch = DB::connection($connection)->transaction(function () use ($queueableStores, $context): LogisticsShopifySyncBatch {
            $batch = LogisticsShopifySyncBatch::query()->create([
                'actor_id' => $context['user']->getKey(),
                'actor_name' => $context['user']->name,
                'status' => LogisticsShopifySyncBatch::STATUS_QUEUED,
                'sync_days' => self::MANUAL_SYNC_DAYS,
                'total_count' => $queueableStores->count(),
            ]);

            foreach ($queueableStores as $store) {
                LogisticsShopifySyncTask::query()->create([
                    'logistics_shopify_sync_batch_id' => $batch->getKey(),
                    'assistant_domain_store_id' => $store->getKey(),
                    'store_name' => (string) ($store->shop_name ?: $store->shop_login_url ?: '未命名店铺'),
                    'status' => LogisticsShopifySyncTask::STATUS_QUEUED,
                ]);
            }

            return $batch;
        });

        $batch->tasks()->orderBy('id')->pluck('id')->each(function ($taskId): void {
            SyncShopifyStoreJob::dispatch((int) $taskId)
                ->onConnection('database')
                ->onQueue('logistics-shopify-high');
        });
        $request->session()->put('logistics.shopify_sync_batch_id', $batch->getKey());

        $message = '已创建近 '.self::MANUAL_SYNC_DAYS.' 天 Shopify 后台同步任务：'.$queueableStores->count().' 家店铺正在排队处理。可关闭本页，返回后仍会显示进度。';
        if ($busyStoreIds !== []) {
            $message .= ' 已跳过 '.($stores->count() - $queueableStores->count()).' 家正在处理的店铺。';
        }

        return $this->indexRedirect($request)->with('success', $message);
    }

    private function currentSyncBatch(Request $request, int $actorId): ?LogisticsShopifySyncBatch
    {
        $active = $this->activeSyncBatch($actorId);
        if ($active) {
            $request->session()->put('logistics.shopify_sync_batch_id', $active->getKey());

            return $active;
        }

        $batchId = (int) $request->session()->get('logistics.shopify_sync_batch_id', 0);
        if ($batchId > 0) {
            $latest = LogisticsShopifySyncBatch::query()
                ->whereKey($batchId)
                ->where('actor_id', $actorId)
                ->where('created_at', '>=', now()->subDay())
                ->first();
            if ($latest) {
                return $latest;
            }
        }

        return null;
    }

    private function activeSyncBatch(int $actorId): ?LogisticsShopifySyncBatch
    {
        return LogisticsShopifySyncBatch::query()
            ->where('actor_id', $actorId)
            ->whereIn('status', [
                LogisticsShopifySyncBatch::STATUS_QUEUED,
                LogisticsShopifySyncBatch::STATUS_PROCESSING,
                LogisticsShopifySyncBatch::STATUS_CANCELLING,
            ])
            ->latest('id')
            ->first();
    }

    /** @return list<array{store_name:string, status:string, reason:string}> */
    private function syncIssueDetails(LogisticsShopifySyncBatch $batch): array
    {
        return LogisticsShopifySyncTask::query()
            ->where('logistics_shopify_sync_batch_id', $batch->getKey())
            ->where(function ($query): void {
                $query->where('status', LogisticsShopifySyncTask::STATUS_FAILED)
                    ->orWhere('sku_exception_count', '>', 0)
                    ->orWhere('webhook_active', false);
            })
            ->orderBy('id')
            ->limit(100)
            ->get()
            ->map(static function (LogisticsShopifySyncTask $task): array {
                $reasons = [];
                if ($task->status === LogisticsShopifySyncTask::STATUS_FAILED) {
                    $reasons[] = (string) ($task->last_error ?: '同步失败，未返回具体原因。');
                }
                if ((int) $task->sku_exception_count > 0) {
                    $reasons[] = 'Shopify SKU 检查：'.(int) $task->sku_exception_count
                        .' 个订单存在 SKU 缺失；涉及 '.(int) $task->sku_item_count
                        .' 个商品明细，其中 '.(int) $task->sku_missing_item_count.' 个未填写 SKU';
                    foreach (array_slice($task->sku_missing_details ?: [], 0, 20) as $detail) {
                        $reasons[] = 'Shopify 订单 '.($detail['order_number'] ?? '—').'：共 '
                            .(int) ($detail['item_count'] ?? 0).' 个商品明细，其中 '
                            .(int) ($detail['missing_count'] ?? 0).' 个未填写 SKU';
                    }
                }
                if ($task->webhook_active === false) {
                    $reasons[] = 'Webhook 登记失败：'.($task->webhook_error ?: '未知错误');
                }

                return [
                    'store_name' => (string) $task->store_name,
                    'status' => (string) $task->status,
                    'reason' => implode('；', $reasons),
                ];
            })
            ->values()
            ->all();
    }

    private function indexRedirect(Request $request): RedirectResponse
    {
        $default = route('logistics.shopify-stores.index');
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

    /**
     * @param array{portal: \App\Models\Portal, user: \App\Models\AdminUser} $context
     * @return array{portal: \App\Models\Portal, user: \App\Models\AdminUser}
     */
    private function viewContext(array $context): array
    {
        $context['portal']->load('menus');

        return $context;
    }

    private function ensureShopifyStore(AssistantDomainStore $store): void
    {
        abort_unless($store->site_builder === 'shopify'
            && $store->shop_status === AssistantDomainStore::SHOP_STATUS_IN_USE, 404);
    }
}
