<?php

namespace App\Portals\Assistant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\AssistantDomainStore;
use App\Models\Department;
use App\Models\FashionAdvertisingReport;
use App\Models\FashionLogisticsOrder;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\Assistant\AssistantPortalAuthorizationService;
use App\Portals\Assistant\AssistantStoreOwnerService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class FashionOperationReportController extends Controller
{
    public function __construct(
        private readonly PortalEntryController $portalEntries,
        private readonly AssistantPortalAuthorizationService $authorization,
        private readonly AssistantStoreOwnerService $storeOwners,
    ) {}

    public function index(Request $request): Response|View
    {
        $context = $this->portalEntries->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }
        $this->authorization->assertAssistantPortal($context['portal']);
        $visibleDepartmentIds = $context['user']->isAdministrator()
            ? null
            : $this->storeOwners->visibleOperationDepartmentIds($context['user']);

        $timezones = $this->timezoneOptions();
        $validated = $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'timezone' => ['nullable', Rule::in(array_keys($timezones))],
            'department_ids' => ['nullable', 'array'],
            'department_ids.*' => ['integer', 'distinct'],
            'operator' => ['nullable', 'string', 'max:100'],
            'sort_by' => ['nullable', Rule::in(['order_count', 'revenue'])],
            'sort_order' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);
        $timezone = (string) ($validated['timezone'] ?? '-08:00');
        $month = now($timezone);
        $dateFrom = $validated['date_from'] ?? $month->copy()->startOfMonth()->toDateString();
        $dateTo = $validated['date_to'] ?? $month->copy()->endOfMonth()->toDateString();
        if ($dateFrom > $dateTo) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }
        // Keep the assistant report on the exact same time basis as the fashion
        // performance report. The legacy paid_at_utc column stores a
        // China-normalized payment time for both XShoppy and Shopline orders.
        $storageFrom = CarbonImmutable::parse($dateFrom.' 00:00:00', $timezone)
            ->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s');
        $storageTo = CarbonImmutable::parse($dateTo.' 23:59:59', $timezone)
            ->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s');

        $stores = AssistantDomainStore::query()
            ->whereRaw("LOWER(TRIM(site_builder)) IN ('xshoppy','xshopy','shopline')")
            ->with('ownerUser.departments')
            ->orderBy('shop_name')->get();
        $nameUsers = AdminUser::query()->with('departments')->whereIn('name', $stores->pluck('user_name')->filter()->unique())
            ->orWhereIn('username', $stores->pluck('user_name')->filter()->unique())->get()
            ->keyBy(fn (AdminUser $user) => mb_strtolower((string) ($user->name ?: $user->username)));

        $storesByOperator = $stores->groupBy(function (AssistantDomainStore $store) use ($nameUsers): int {
            if ($store->owner_user_id) {
                return (int) $store->owner_user_id;
            }

            return (int) ($nameUsers->get(mb_strtolower(trim((string) $store->user_name)))?->getKey() ?? 0);
        })->forget(0);
        $operatorIds = $storesByOperator->keys()->map(fn ($id) => (int) $id)->values();
        $operators = AdminUser::query()->with('departments')->whereIn('id', $operatorIds)
            ->when($visibleDepartmentIds !== null, fn ($query) => $visibleDepartmentIds === []
                ? $query->whereRaw('1 = 0')
                : $query->whereHas('departments', fn ($departments) => $departments->whereIn('admin_roles.id', $visibleDepartmentIds)))
            ->get()->keyBy('id');
        $operatorIds = $operators->keys()->map(fn ($id): int => (int) $id)->values();
        // groupBy 后每个值都是一个店铺集合，不是 Eloquent 模型；按分组键过滤，
        // 避免 Eloquent Collection::only() 把子集合当模型调用 getKey()。
        $storesByOperator = $storesByOperator
            ->filter(fn ($operatorStores, $operatorId): bool => $operatorIds->contains((int) $operatorId));
        $stores = $stores->filter(fn (AssistantDomainStore $store): bool => $storesByOperator->has((int) ($store->owner_user_id
            ?: ($nameUsers->get(mb_strtolower(trim((string) $store->user_name)))?->getKey() ?? 0))))->values();
        $departmentRows = Department::business()->get(['id', 'name', 'parent_id', 'sort']);
        $fashionDepartment = $departmentRows->firstWhere('name', '服装运营部门');
        $fashionDepartmentIds = collect($fashionDepartment ? [(int) $fashionDepartment->id] : []);
        do {
            $childIds = $departmentRows->whereIn('parent_id', $fashionDepartmentIds)
                ->pluck('id')->map(fn ($id): int => (int) $id)->diff($fashionDepartmentIds)->values();
            $before = $fashionDepartmentIds->count();
            $fashionDepartmentIds = $fashionDepartmentIds->merge($childIds)->unique()->values();
        } while ($fashionDepartmentIds->count() > $before);
        $orderMetrics = FashionLogisticsOrder::query()
            ->whereIn('assistant_domain_store_id', $stores->pluck('id'))
            ->where(function ($platform) use ($stores): void {
                $platform->where(function ($xshoppy) use ($stores): void {
                    $xshoppy->where('source_platform', 'xshoppy')
                        ->whereIn('assistant_domain_store_id', $stores->filter(fn (AssistantDomainStore $store) => in_array(mb_strtolower(trim((string) $store->site_builder)), ['xshoppy', 'xshopy'], true))->pluck('id'));
                })->orWhere(function ($shopline) use ($stores): void {
                    $shopline->where('source_platform', 'shopline')
                        ->whereIn('assistant_domain_store_id', $stores->filter(fn (AssistantDomainStore $store) => mb_strtolower(trim((string) $store->site_builder)) === 'shopline')->pluck('id'));
                });
            })
            ->whereNotNull('paid_at_utc')
            ->whereBetween('paid_at_utc', [$storageFrom, $storageTo])
            ->selectRaw('assistant_domain_store_id, COUNT(*) order_count, COALESCE(SUM(total_amount),0) revenue')
            ->groupBy('assistant_domain_store_id')->get()->keyBy('assistant_domain_store_id');

        $reports = FashionAdvertisingReport::query()
            ->whereBetween('report_date', [$dateFrom, $dateTo])
            ->whereIn('submitter_id', $operatorIds)
            ->with('adSpendEntries')->get()->groupBy('submitter_id');

        $fashionRootDepartmentId = (int) ($fashionDepartment?->id ?? 0);
        $selectedDepartmentIds = $request->has('department_ids')
            ? collect($validated['department_ids'] ?? [])->map(fn ($id) => (int) $id)->intersect($fashionDepartmentIds)->unique()->values()->all()
            : array_values(array_filter([$fashionRootDepartmentId]));
        $allRows = $storesByOperator->map(function ($operatorStores, int $operatorId) use ($operators, $orderMetrics, $reports, $dateTo, $fashionDepartmentIds, $fashionRootDepartmentId): ?array {
            $operator = $operators->get($operatorId);
            if (! $operator) {
                return null;
            }
            $department = $operator->departments->first(fn ($item) => (int) $item->id !== $fashionRootDepartmentId && $fashionDepartmentIds->contains((int) $item->id))
                ?: $operator->departments->first(fn ($item) => $fashionDepartmentIds->contains((int) $item->id))
                ?: $operator->departments->first(fn ($item) => (bool) ($item->pivot->is_primary ?? false))
                ?: $operator->departments->first();
            $storeRows = $operatorStores->map(function (AssistantDomainStore $store) use ($orderMetrics): array {
                $metric = $orderMetrics->get($store->getKey());

                return ['name' => (string) ($store->shop_name ?: $store->domain), 'domain' => (string) $store->domain,
                    'site_builder' => in_array(mb_strtolower(trim((string) $store->site_builder)), ['xshoppy', 'xshopy'], true) ? 'XShoppy' : 'Shopline',
                    'orders' => (int) ($metric->order_count ?? 0), 'revenue' => (float) ($metric->revenue ?? 0)];
            })->values();
            $orderCount = (int) $storeRows->sum('orders');
            $revenue = round((float) $storeRows->sum('revenue'), 2);
            $operatorReports = $reports->get($operatorId, collect());
            $includedReports = $operatorReports->whereIn('status', [FashionAdvertisingReport::STATUS_APPROVED, FashionAdvertisingReport::STATUS_REJECTED]);
            $media = $includedReports->flatMap->adSpendEntries->groupBy(fn ($entry) => trim((string) $entry->media) ?: '未设置媒体')
                ->map(function ($entries, string $name) use ($revenue): array {
                    $spend = round((float) $entries->sum('actual_spend_usd'), 2);

                    return ['name' => $name, 'spend' => $spend, 'roi' => $spend > 0 ? $revenue / $spend : null];
                })->sortKeys()->values();
            $totalSpend = round((float) $media->sum('spend'), 2);
            $submissionStatus = $operatorReports->isEmpty()
                ? 'not_submitted'
                : ($operatorReports->contains('status', FashionAdvertisingReport::STATUS_PENDING) ? 'pending' : 'submitted');
            $hireDays = $operator->hired_at ? max(1, $operator->hired_at->diffInDays(CarbonImmutable::parse($dateTo)) + 1) : null;

            return ['department' => (string) ($department?->name ?: '未分配部门'), 'department_id' => (int) ($department?->id ?? 0), 'operator' => (string) ($operator->name ?: $operator->username),
                'hired_at' => $operator->hired_at?->format('Y-m-d'), 'hire_days' => $hireDays, 'stores' => $storeRows,
                'order_count' => $orderCount, 'revenue' => $revenue, 'aov' => $orderCount > 0 ? $revenue / $orderCount : null,
                'media' => $media, 'ad_spend' => $totalSpend, 'roi' => $totalSpend > 0 ? $revenue / $totalSpend : null,
                'submission_status' => $submissionStatus,
                'submission_label' => match ($submissionStatus) {
                    'pending' => '已提报·待审核',
                    'submitted' => '已提报',
                    default => '未提报',
                }];
        })->filter()->values();

        $departmentOptions = $fashionDepartment
            ? $departmentRows->filter(fn (Department $department): bool => $fashionDepartmentIds->contains((int) $department->id))
                ->when($visibleDepartmentIds !== null, fn ($departments) => $departments->whereIn('id', $visibleDepartmentIds))
                ->sortBy(fn (Department $department): string => ((int) $department->id === $fashionRootDepartmentId ? '0' : '1')
                    .str_pad((string) ((int) $department->sort), 8, '0', STR_PAD_LEFT)
                    .mb_strtolower((string) $department->name))
                ->values()
            : collect();

        $rows = $allRows->filter(function (array $row) use ($validated, $selectedDepartmentIds, $fashionDepartmentIds, $fashionRootDepartmentId): bool {
            $operator = trim((string) ($validated['operator'] ?? ''));
            $matchesDepartment = $selectedDepartmentIds === []
                || (in_array($fashionRootDepartmentId, $selectedDepartmentIds, true) && $fashionDepartmentIds->contains((int) $row['department_id']))
                || in_array((int) $row['department_id'], $selectedDepartmentIds, true);

            return $matchesDepartment
                && ($operator === '' || str_contains(mb_strtolower($row['operator']), mb_strtolower($operator)));
        });
        $sortBy = (string) ($validated['sort_by'] ?? 'order_count');
        $sortOrder = (string) ($validated['sort_order'] ?? 'desc');
        $rows = $rows->sort(function (array $left, array $right) use ($sortBy, $sortOrder): int {
                $comparison = ((float) $left[$sortBy]) <=> ((float) $right[$sortBy]);
                if ($comparison === 0) {
                    $comparison = [$left['department'], $left['operator']] <=> [$right['department'], $right['operator']];
                }

                return $sortOrder === 'desc' ? -$comparison : $comparison;
            })->values();

        $summaryRevenue = (float) $rows->sum('revenue');
        $summarySpend = (float) $rows->sum('ad_spend');
        $roiRevenue = (float) $rows
            ->filter(fn (array $row): bool => (float) ($row['ad_spend'] ?? 0) > 0)
            ->sum('revenue');
        return view('portals.assistant.fashion-operation-reports.index', [
            ...$context, 'rows' => $rows, 'timezoneOptions' => $timezones,
            'departmentOptions' => $departmentOptions,
            'filters' => [...$validated, 'date_from' => $dateFrom, 'date_to' => $dateTo, 'timezone' => $timezone,
                'department_ids' => $selectedDepartmentIds, 'sort_by' => $sortBy, 'sort_order' => $sortOrder],
            'summary' => ['people' => $rows->count(), 'stores' => $rows->sum(fn ($row) => count($row['stores'])),
                'orders' => $rows->sum('order_count'), 'revenue' => $summaryRevenue, 'ad_spend' => $summarySpend,
                'aov' => $rows->sum('order_count') > 0 ? $summaryRevenue / $rows->sum('order_count') : null,
                'roi' => $summarySpend > 0 ? $roiRevenue / $summarySpend : null],
        ]);
    }

    private function timezoneOptions(): array
    {
        $options = [];
        for ($offset = -12; $offset <= 14; $offset++) {
            $value = sprintf('%s%02d:00', $offset < 0 ? '-' : '+', abs($offset));
            $options[$value] = 'UTC'.($offset >= 0 ? '+' : '').$offset.($offset === -8 ? '（默认）' : ($offset === 8 ? '（北京时间）' : ''));
        }

        return $options;
    }
}
