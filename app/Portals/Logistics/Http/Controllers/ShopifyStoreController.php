<?php

namespace App\Portals\Logistics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AssistantDomainStore;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\Logistics\LogisticsPortalService;
use App\Portals\Logistics\ShopifyOrderSyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class ShopifyStoreController extends Controller
{
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

        return view('portals.logistics.shopify-stores.index', [
            ...$this->viewContext($context),
            'stores' => $stores,
            'filters' => ['per_page' => $perPage],
        ]);
    }

    public function sync(Request $request, AssistantDomainStore $domainStore): RedirectResponse
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) {
            abort(403);
        }

        $this->ensureShopifyStore($domainStore);

        try {
            $summary = $this->shopify->syncStore($domainStore, $context['user']);
        } catch (\Throwable $exception) {
            return $this->indexRedirect($request)
                ->with('error', '同步失败：'.$exception->getMessage());
        }

        return $this->indexRedirect($request)
            ->with($summary['webhook_active'] ? 'success' : 'error', $this->syncMessage($domainStore, $summary));
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

    public function batchSync(Request $request): RedirectResponse
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

        $successes = [];
        $failures = [];
        foreach ($stores as $store) {
            try {
                $summary = $this->shopify->syncStore($store, $context['user']);
                $successes[] = $store->shop_name.'（新增 '.$summary['created'].'，更新 '.$summary['updated'].'，未付款跳过 '.($summary['skipped_unpaid'] ?? 0).'）'
                    .($summary['webhook_active'] ? '' : '，Webhook 登记失败');
            } catch (\Throwable $exception) {
                $failures[] = $store->shop_name.'：'.$exception->getMessage();
            }
        }

        if ($stores->isEmpty()) {
            return $this->indexRedirect($request)->with('error', '未找到可同步的 Shopify 店铺。');
        }

        $message = '批量同步完成：成功 '.count($successes).' 家'.($successes ? '（'.implode('；', $successes).'）' : '');
        if ($failures !== []) {
            return $this->indexRedirect($request)
                ->with('error', $message.'；失败 '.count($failures).' 家（'.implode('；', $failures).'）');
        }

        return $this->indexRedirect($request)->with('success', $message);
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

        $message = '批量登记 Webhook 完成：成功 '.count($successes).' 家'.($successes ? '（'.implode('；', $successes).'）' : '');
        if ($failures !== []) {
            return $this->indexRedirect($request)
                ->with('error', $message.'；失败 '.count($failures).' 家（'.implode('；', $failures).'）');
        }

        return $this->indexRedirect($request)->with('success', $message.'。未同步历史订单。');
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

    /** @param array{portal: \App\Models\Portal, user: \App\Models\AdminUser} $context
     *  @return array{portal: \App\Models\Portal, user: \App\Models\AdminUser}
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

    /** @param array{created:int, updated:int, sku_exception:int, skipped_unpaid:int, webhook_active:bool, webhook_error:?string} $summary */
    private function syncMessage(AssistantDomainStore $store, array $summary): string
    {
        $message = $store->shop_name.' 同步完成：新增 '.$summary['created'].' 条，更新 '.$summary['updated'].' 条，SKU 异常 '.$summary['sku_exception'].' 条，未付款跳过 '.($summary['skipped_unpaid'] ?? 0).' 条。';

        return $summary['webhook_active']
            ? $message.' Webhook 已登记。'
            : $message.' Webhook 登记失败：'.($summary['webhook_error'] ?: '未知错误');
    }

}
