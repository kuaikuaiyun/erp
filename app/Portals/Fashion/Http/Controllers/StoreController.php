<?php

namespace App\Portals\Fashion\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\AssistantDomainStore;
use App\Models\FashionLogisticsOrder;
use App\Portal\Http\Controllers\PortalEntryController;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class StoreController extends Controller
{
    private const PORTAL_CODE = 'fuzhuang';

    public function __construct(private readonly PortalEntryController $portalEntries) {}

    public function index(Request $request): Response|View
    {
        $context = $this->context($request);
        if ($context instanceof Response) return $context;
        $validated = $request->validate([
            'keyword' => ['nullable', 'string', 'max:191'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'operator_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'in:20,50,100'],
        ]);
        $dateFrom = $validated['date_from'] ?? now('Asia/Shanghai')->startOfMonth()->toDateString();
        $dateTo = $validated['date_to'] ?? now('Asia/Shanghai')->endOfMonth()->toDateString();
        if ($dateFrom > $dateTo) [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        $user = $context['user'];

        $orderTable = (new FashionLogisticsOrder())->getTable();
        $metrics = DB::connection(config('admin.database.connection') ?: config('database.default'))->table($orderTable)
            ->selectRaw('assistant_domain_store_id, COUNT(*) order_count, COALESCE(SUM(total_amount),0) revenue')
            ->where('source_platform', 'xshoppy')
            ->whereBetween('order_created_at', [$dateFrom.' 00:00:00', $dateTo.' 23:59:59'])
            ->where(fn ($q) => $q->whereNotNull('paid_at')->orWhereIn('status', ['processing', 'completed']))
            ->groupBy('assistant_domain_store_id');
        $today = now('Asia/Shanghai')->toDateString();
        $todayMetrics = DB::connection(config('admin.database.connection') ?: config('database.default'))->table($orderTable)
            ->selectRaw('assistant_domain_store_id, COUNT(*) today_orders, COALESCE(SUM(total_amount),0) today_revenue')
            ->where('source_platform', 'xshoppy')
            ->whereBetween('order_created_at', [$today.' 00:00:00', $today.' 23:59:59'])
            ->where(fn ($q) => $q->whereNotNull('paid_at')->orWhereIn('status', ['processing', 'completed']))
            ->groupBy('assistant_domain_store_id');

        $query = $this->accessibleStores($user)->with('ownerUser:id,name,username')
            ->leftJoinSub($metrics, 'store_metrics', 'store_metrics.assistant_domain_store_id', '=', 'assistant_domain_stores.id')
            ->leftJoinSub($todayMetrics, 'today_metrics', 'today_metrics.assistant_domain_store_id', '=', 'assistant_domain_stores.id')
            ->select('assistant_domain_stores.*')
            ->selectRaw('COALESCE(store_metrics.order_count,0) order_count, COALESCE(store_metrics.revenue,0) revenue, COALESCE(today_metrics.today_orders,0) today_orders, COALESCE(today_metrics.today_revenue,0) today_revenue');
        $keyword = trim((string) ($validated['keyword'] ?? ''));
        if ($keyword !== '') $query->where(fn ($q) => $q->where('assistant_domain_stores.shop_name', 'like', "%{$keyword}%")->orWhere('assistant_domain_stores.domain', 'like', "%{$keyword}%"));
        if ($user->isAdministrator() && ! empty($validated['operator_id'])) {
            $operator = AdminUser::query()->find((int) $validated['operator_id']);
            $names = collect([$operator?->name, $operator?->username])->filter()->map(fn ($v) => trim((string) $v))->all();
            $query->where(fn ($q) => $q->where('assistant_domain_stores.owner_user_id', (int) $validated['operator_id'])->when($names, fn ($q) => $q->orWhereIn('assistant_domain_stores.user_name', $names)));
        }
        $stores = $query->orderByDesc('revenue')->orderBy('assistant_domain_stores.shop_name')->paginate((int) ($validated['per_page'] ?? 20))->withQueryString();

        return view('portals.fashion.stores.index', [
            ...$context,
            'stores' => $stores,
            'operators' => $user->isAdministrator() ? $this->storeOperators() : collect(),
            'filters' => [...$validated, 'date_from' => $dateFrom, 'date_to' => $dateTo, 'per_page' => (int) ($validated['per_page'] ?? 20)],
        ]);
    }

    public function updateCredentials(Request $request, AssistantDomainStore $store): RedirectResponse
    {
        $context = $this->context($request);
        if ($context instanceof Response) abort($context->getStatusCode());
        abort_unless($this->accessibleStores($context['user'])->whereKey($store->getKey())->exists(), 403);
        abort_unless(in_array(mb_strtolower(trim((string) $store->site_builder)), ['xshoppy', 'xshopy'], true), 422, '只能维护 XShoppy 店铺应用信息。');
        abort_if(filled($store->xshoppy_api_key) && filled($store->xshoppy_password), 422, '该店铺的 XShoppy App API 密钥和密码已配置，不能再次编辑。');
        $data = $request->validate([
            'xshoppy_api_key' => ['nullable', 'string', 'max:255'],
            'xshoppy_password' => ['nullable', 'string', 'max:255'],
        ]);
        $updates = [];
        $apiKey = trim((string) ($data['xshoppy_api_key'] ?? ''));
        $password = trim((string) ($data['xshoppy_password'] ?? ''));
        abort_if(filled($store->xshoppy_api_key) && $apiKey !== '', 422, 'XShoppy App API 密钥已配置，不能覆盖修改。');
        abort_if(filled($store->xshoppy_password) && $password !== '', 422, 'XShoppy App 密码已配置，不能覆盖修改。');
        if (! filled($store->xshoppy_api_key) && $apiKey !== '') $updates['xshoppy_api_key'] = $apiKey;
        if (! filled($store->xshoppy_password) && $password !== '') $updates['xshoppy_password'] = $password;
        if ($updates !== []) $store->update($updates);
        return back()->with('success', 'XShoppy App 应用信息已更新。');
    }

    private function context(Request $request): array|Response
    {
        $context = $this->portalEntries->portalContext($request);
        if ($context instanceof Response) return $context;
        abort_unless($context['portal']->code === self::PORTAL_CODE, 404);
        return $context;
    }

    private function accessibleStores(AdminUser $user): Builder
    {
        $query = AssistantDomainStore::query()->whereRaw("LOWER(TRIM(site_builder)) IN ('xshoppy','xshopy')");
        if ($user->isAdministrator()) return $query;
        $names = collect([$user->name, $user->username])->filter()->map(fn ($v) => trim((string) $v))->unique()->values()->all();
        return $query->where(function ($q) use ($user, $names): void {
            $q->where('owner_user_id', $user->getKey());
            if ($names !== []) $q->orWhereIn('user_name', $names);
        });
    }

    private function storeOperators()
    {
        $stores = AssistantDomainStore::query()->whereRaw("LOWER(TRIM(site_builder)) IN ('xshoppy','xshopy')")->get(['owner_user_id', 'user_name']);
        $ids = $stores->pluck('owner_user_id')->filter()->unique();
        $names = $stores->pluck('user_name')->filter()->unique();
        return AdminUser::query()->where(function ($q) use ($ids, $names): void {
            if ($ids->isNotEmpty()) $q->whereIn('id', $ids);
            if ($names->isNotEmpty()) $q->{$ids->isNotEmpty() ? 'orWhereIn' : 'whereIn'}('name', $names)->orWhereIn('username', $names);
            if ($ids->isEmpty() && $names->isEmpty()) $q->whereRaw('1=0');
        })->orderBy('name')->get(['id', 'name', 'username']);
    }
}
