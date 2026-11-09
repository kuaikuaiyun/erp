<?php

namespace App\Portals\Assistant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\AssistantDomainStore;
use App\Models\FashionAdvertisingReport;
use App\Models\FashionLogisticsOrder;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\Assistant\AssistantPortalAuthorizationService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class FashionOperationReportController extends Controller
{
    public function __construct(
        private readonly PortalEntryController $portalEntries,
        private readonly AssistantPortalAuthorizationService $authorization,
    ) {}

    public function index(Request $request): Response|View
    {
        $context = $this->portalEntries->portalContext($request);
        if ($context instanceof Response) return $context;
        $this->authorization->assertAssistantPortal($context['portal']);

        $timezones = $this->timezoneOptions();
        $validated = $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'timezone' => ['nullable', Rule::in(array_keys($timezones))],
            'department' => ['nullable', 'string', 'max:100'],
            'operator' => ['nullable', 'string', 'max:100'],
        ]);
        $timezone = (string) ($validated['timezone'] ?? '+00:00');
        $month = now($timezone);
        $dateFrom = $validated['date_from'] ?? $month->copy()->startOfMonth()->toDateString();
        $dateTo = $validated['date_to'] ?? $month->copy()->endOfMonth()->toDateString();
        if ($dateFrom > $dateTo) [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        $utcFrom = CarbonImmutable::parse($dateFrom.' 00:00:00', $timezone)->utc()->format('Y-m-d H:i:s');
        $utcTo = CarbonImmutable::parse($dateTo.' 23:59:59', $timezone)->utc()->format('Y-m-d H:i:s');

        $stores = AssistantDomainStore::query()
            ->whereRaw("LOWER(TRIM(site_builder)) IN ('xshoppy','xshopy')")
            ->with('ownerUser.departments')
            ->orderBy('shop_name')->get();
        $nameUsers = AdminUser::query()->with('departments')->whereIn('name', $stores->pluck('user_name')->filter()->unique())
            ->orWhereIn('username', $stores->pluck('user_name')->filter()->unique())->get()
            ->keyBy(fn (AdminUser $user) => mb_strtolower((string) ($user->name ?: $user->username)));

        $storesByOperator = $stores->groupBy(function (AssistantDomainStore $store) use ($nameUsers): int {
            if ($store->owner_user_id) return (int) $store->owner_user_id;
            return (int) ($nameUsers->get(mb_strtolower(trim((string) $store->user_name)))?->getKey() ?? 0);
        })->forget(0);
        $operatorIds = $storesByOperator->keys()->map(fn ($id) => (int) $id)->values();
        $operators = AdminUser::query()->with('departments')->whereIn('id', $operatorIds)->get()->keyBy('id');

        $orderMetrics = FashionLogisticsOrder::query()
            ->whereIn('assistant_domain_store_id', $stores->pluck('id'))
            ->where('source_platform', 'xshoppy')
            ->whereBetween('order_created_at', [$utcFrom, $utcTo])
            ->selectRaw('assistant_domain_store_id, COUNT(*) order_count, COALESCE(SUM(total_amount),0) revenue')
            ->groupBy('assistant_domain_store_id')->get()->keyBy('assistant_domain_store_id');

        $reports = FashionAdvertisingReport::query()
            ->whereIn('status', [FashionAdvertisingReport::STATUS_APPROVED, FashionAdvertisingReport::STATUS_REJECTED])
            ->whereBetween('report_date', [$dateFrom, $dateTo])
            ->whereIn('submitter_id', $operatorIds)
            ->with('adSpendEntries')->get()->groupBy('submitter_id');

        $rows = $storesByOperator->map(function ($operatorStores, int $operatorId) use ($operators, $orderMetrics, $reports, $dateTo): ?array {
            $operator = $operators->get($operatorId);
            if (! $operator) return null;
            $department = $operator->departments->first(fn ($item) => (bool) ($item->pivot->is_primary ?? false)) ?: $operator->departments->first();
            $storeRows = $operatorStores->map(function (AssistantDomainStore $store) use ($orderMetrics): array {
                $metric = $orderMetrics->get($store->getKey());
                return ['name' => (string) ($store->shop_name ?: $store->domain), 'domain' => (string) $store->domain,
                    'orders' => (int) ($metric->order_count ?? 0), 'revenue' => (float) ($metric->revenue ?? 0)];
            })->values();
            $orderCount = (int) $storeRows->sum('orders');
            $revenue = round((float) $storeRows->sum('revenue'), 2);
            $media = $reports->get($operatorId, collect())->flatMap->adSpendEntries->groupBy(fn ($entry) => trim((string) $entry->media) ?: '未设置媒体')
                ->map(function ($entries, string $name) use ($revenue): array {
                    $spend = round((float) $entries->sum('actual_spend_usd'), 2);
                    return ['name' => $name, 'spend' => $spend, 'roi' => $spend > 0 ? $revenue / $spend : null];
                })->sortKeys()->values();
            $totalSpend = round((float) $media->sum('spend'), 2);
            $hireDays = $operator->hired_at ? max(1, $operator->hired_at->diffInDays(CarbonImmutable::parse($dateTo)) + 1) : null;
            return ['department' => (string) ($department?->name ?: '未分配部门'), 'operator' => (string) ($operator->name ?: $operator->username),
                'hired_at' => $operator->hired_at?->format('Y-m-d'), 'hire_days' => $hireDays, 'stores' => $storeRows,
                'order_count' => $orderCount, 'revenue' => $revenue, 'aov' => $orderCount > 0 ? $revenue / $orderCount : null,
                'media' => $media, 'ad_spend' => $totalSpend, 'roi' => $totalSpend > 0 ? $revenue / $totalSpend : null];
        })->filter()->filter(function (array $row) use ($validated): bool {
            $department = trim((string) ($validated['department'] ?? ''));
            $operator = trim((string) ($validated['operator'] ?? ''));
            return ($department === '' || str_contains(mb_strtolower($row['department']), mb_strtolower($department)))
                && ($operator === '' || str_contains(mb_strtolower($row['operator']), mb_strtolower($operator)));
        })->sortBy(fn (array $row) => $row['department'].'|'.$row['operator'])->values();

        $summaryRevenue = (float) $rows->sum('revenue');
        $summarySpend = (float) $rows->sum('ad_spend');
        return view('portals.assistant.fashion-operation-reports.index', [
            ...$context, 'rows' => $rows, 'timezoneOptions' => $timezones,
            'filters' => [...$validated, 'date_from' => $dateFrom, 'date_to' => $dateTo, 'timezone' => $timezone],
            'summary' => ['people' => $rows->count(), 'stores' => $rows->sum(fn ($row) => count($row['stores'])),
                'orders' => $rows->sum('order_count'), 'revenue' => $summaryRevenue, 'ad_spend' => $summarySpend,
                'aov' => $rows->sum('order_count') > 0 ? $summaryRevenue / $rows->sum('order_count') : null,
                'roi' => $summarySpend > 0 ? $summaryRevenue / $summarySpend : null],
        ]);
    }

    private function timezoneOptions(): array
    {
        $options = [];
        for ($offset = -12; $offset <= 14; $offset++) {
            $value = sprintf('%s%02d:00', $offset < 0 ? '-' : '+', abs($offset));
            $options[$value] = 'UTC'.($offset >= 0 ? '+' : '').$offset.($offset === 0 ? '（默认）' : ($offset === 8 ? '（北京时间）' : ''));
        }
        return $options;
    }
}
