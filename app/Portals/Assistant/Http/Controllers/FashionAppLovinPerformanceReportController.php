<?php

namespace App\Portals\Assistant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AppLovinAccountStoreBinding;
use App\Models\AppLovinSpendScreenshot;
use App\Models\AssistantDomainStore;
use App\Models\Department;
use App\Models\FashionAdvertisingReport;
use App\Models\FashionLogisticsOrder;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\Assistant\AssistantPortalAuthorizationService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class FashionAppLovinPerformanceReportController extends Controller
{
    public function __construct(
        private readonly PortalEntryController $portalEntries,
        private readonly AssistantPortalAuthorizationService $authorization,
    ) {}

    public function index(Request $request): Response|View
    {
        $context = $this->portalEntries->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }
        $this->authorization->assertAssistantPortal($context['portal']);
        abort_unless($this->authorization->isAssistantDepartmentMember($context['user']), 403);

        $submitterIds = FashionAdvertisingReport::query()->whereHas('adSpendEntries', fn ($query) => $query->whereRaw("LOWER(TRIM(media)) = 'applovin'"))
            ->whereIn('status', [FashionAdvertisingReport::STATUS_APPROVED, FashionAdvertisingReport::STATUS_REJECTED])
            ->pluck('submitter_id')->filter()->unique()->values();
        $departments = Department::business()->whereHas('departmentMembers', fn ($query) => $query->whereIn('admin_users.id', $submitterIds))
            ->orderBy('sort')->orderBy('name')->get(['id', 'name']);
        $departmentIds = $departments->pluck('id')->map(fn ($id) => (int) $id)->all();
        $timezoneOptions = $this->timezoneOptions();
        $filters = $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'date_range' => ['nullable', Rule::in(['custom', 'yesterday', 'last_3', 'last_7', 'last_15', 'last_30', 'last_60', 'last_90', 'last_6_months', 'last_year'])],
            'timezone' => ['nullable', Rule::in(array_keys($timezoneOptions))],
            'department_id' => ['nullable', 'integer', Rule::in($departmentIds)],
            'operator' => ['nullable', 'string', 'max:100'],
            'store_domain' => ['nullable', 'string', 'max:191'],
            'gift_status' => ['nullable', Rule::in(['yes', 'no'])],
            'consumption_range' => ['nullable', Rule::in(['lt_5000', '5000_10000', 'gt_10000', 'custom'])],
            'consumption_custom' => ['nullable', 'numeric', 'min:0', 'required_if:consumption_range,custom'],
            'consumption_custom_max' => ['nullable', 'numeric', 'min:0', 'required_if:consumption_range,custom', 'gte:consumption_custom'],
        ]);
        $timezone = (string) ($filters['timezone'] ?? '-08:00');
        $today = now($timezone)->startOfDay();
        $dateRange = (string) ($filters['date_range'] ?? ($request->hasAny(['date_from', 'date_to']) ? 'custom' : 'last_7'));
        [$presetFrom, $presetTo] = match ($dateRange) {
            'yesterday' => [$today->copy()->subDay(), $today->copy()->subDay()],
            'last_3' => [$today->copy()->subDays(2), $today->copy()],
            'last_15' => [$today->copy()->subDays(14), $today->copy()],
            'last_30' => [$today->copy()->subDays(29), $today->copy()],
            'last_60' => [$today->copy()->subDays(59), $today->copy()],
            'last_90' => [$today->copy()->subDays(89), $today->copy()],
            'last_6_months' => [$today->copy()->subMonths(6)->addDay(), $today->copy()],
            'last_year' => [$today->copy()->subYear()->addDay(), $today->copy()],
            default => [$today->copy()->subDays(6), $today->copy()],
        };
        $dateFrom = $dateRange === 'custom' ? (string) ($filters['date_from'] ?? $presetFrom->toDateString()) : $presetFrom->toDateString();
        $dateTo = $dateRange === 'custom' ? (string) ($filters['date_to'] ?? $presetTo->toDateString()) : $presetTo->toDateString();
        if ($dateFrom > $dateTo) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }

        $reportQuery = FashionAdvertisingReport::query()
            ->whereIn('status', [FashionAdvertisingReport::STATUS_APPROVED, FashionAdvertisingReport::STATUS_REJECTED])
            ->whereHas('adSpendEntries', fn ($query) => $query->whereRaw("LOWER(TRIM(media)) = 'applovin'"))
            ->with(['adSpendEntries' => fn ($query) => $query->whereRaw("LOWER(TRIM(media)) = 'applovin'"), 'submitter.departments'])
            ->when($filters['department_id'] ?? null, fn ($query, $id) => $query->whereHas('submitter.departments', fn ($department) => $department->whereKey($id)))
            ->when($filters['operator'] ?? null, fn ($query, $name) => $query->where('submitter_name', 'like', '%'.$name.'%'));
        $accountSpendReports = (clone $reportQuery)->get();
        $reports = (clone $reportQuery)->whereBetween('report_date', [$dateFrom, $dateTo])->orderBy('report_date')->get();

        $accountBindings = AppLovinAccountStoreBinding::query()->pluck('assistant_domain_store_id', 'account_key');
        $accountIdentity = static function (int $operatorId, string $agency, string $accountNumber): string {
            $normalizedAgency = mb_strtolower(trim($agency));
            $normalizedNumber = preg_replace('/[\s\p{Cf}]+/u', '', mb_strtolower($accountNumber)) ?? '';

            return implode('|', [$operatorId, $normalizedAgency, $normalizedNumber]);
        };
        $bindingByIdentity = collect();
        $accountSpendReports->each(function (FashionAdvertisingReport $report) use ($accountBindings, $accountIdentity, $bindingByIdentity): void {
            $report->adSpendEntries->each(function ($entry) use ($report, $accountBindings, $accountIdentity, $bindingByIdentity): void {
                $exactKey = AppLovinSpendScreenshot::accountKey((int) $report->submitter_id, (string) $entry->agency, (string) $entry->media, (string) $entry->account_name, (string) $entry->account_number);
                $storeId = (int) ($entry->assistant_domain_store_id ?: ($accountBindings->get($exactKey) ?? 0));
                if ($storeId > 0) {
                    $bindingByIdentity->put($accountIdentity((int) $report->submitter_id, (string) $entry->agency, (string) $entry->account_number), $storeId);
                }
            });
        });
        $storeIds = $reports->flatMap->adSpendEntries->pluck('assistant_domain_store_id')->merge($accountBindings->values())->merge($bindingByIdentity->values())
            ->filter()->map(fn ($id) => (int) $id)->unique()->values();
        $stores = AssistantDomainStore::query()->whereIn('id', $storeIds)->get(['id', 'domain', 'site_builder'])->keyBy('id');
        $accountSpendTotals = $accountSpendReports->flatMap(fn (FashionAdvertisingReport $report) => $report->adSpendEntries->map(fn ($entry): array => [
            'account_key' => AppLovinSpendScreenshot::accountKey((int) $report->submitter_id, (string) $entry->agency, (string) $entry->media, (string) $entry->account_name, (string) $entry->account_number),
            'spend' => (float) $entry->actual_spend_usd,
        ]))->groupBy('account_key')->map(fn ($items) => (float) $items->sum('spend'));
        $giftScreenshots = AppLovinSpendScreenshot::query()->whereIn('account_key', $accountSpendTotals->keys())->get()->keyBy('account_key');
        $dailyRows = $reports->flatMap(function (FashionAdvertisingReport $report) use ($stores, $accountBindings, $accountSpendTotals, $accountIdentity, $bindingByIdentity, $timezone): array {
            return $report->adSpendEntries->map(function ($entry) use ($report, $accountBindings, $accountIdentity, $bindingByIdentity) {
                $accountKey = AppLovinSpendScreenshot::accountKey((int) $report->submitter_id, (string) $entry->agency, (string) $entry->media, (string) $entry->account_name, (string) $entry->account_number);
                $identity = $accountIdentity((int) $report->submitter_id, (string) $entry->agency, (string) $entry->account_number);
                $storeId = (int) ($entry->assistant_domain_store_id ?: ($accountBindings->get($accountKey) ?? $bindingByIdentity->get($identity, 0)));
                $entry->setAttribute('resolved_account_key', $accountKey);
                $entry->setAttribute('resolved_store_id', $storeId);
                $entry->setAttribute('resolved_group_key', $storeId > 0 ? 'store:'.$storeId : 'account:'.$accountKey);

                return $entry;
            })->groupBy('resolved_group_key')->map(function ($entries) use ($report, $stores, $accountSpendTotals, $timezone): array {
                $storeId = (int) $entries->first()->resolved_store_id;
                $store = $stores->get((int) $storeId);
                $from = CarbonImmutable::parse($report->report_date->format('Y-m-d').' 00:00:00', $timezone)->utc();
                $to = CarbonImmutable::parse($report->report_date->format('Y-m-d').' 23:59:59', $timezone)->utc();
                $platform = $store && mb_strtolower(trim((string) $store->site_builder)) === 'shopline' ? 'shopline' : 'xshoppy';
                $metrics = $storeId > 0 ? FashionLogisticsOrder::query()->where('assistant_domain_store_id', $storeId)->where('source_platform', $platform)
                    ->whereBetween('order_created_at', [$from, $to])->selectRaw('COUNT(*) order_count, COALESCE(SUM(total_amount),0) revenue')->first() : null;
                $orders = (int) ($metrics?->order_count ?? 0);
                $revenue = round((float) ($metrics?->revenue ?? 0), 2);
                $spend = round((float) $entries->sum('actual_spend_usd'), 2);

                return [
                    'date' => $report->report_date->format('Y-m-d'),
                    'department' => $report->submitter?->departments?->pluck('name')->filter()->unique()->implode('、') ?: '—',
                    'operator_id' => (int) $report->submitter_id,
                    'operator' => (string) $report->submitter_name,
                    'store_id' => (int) $storeId,
                    'store_domain' => (string) ($entries->pluck('store_domain')->filter()->first() ?: $store?->domain ?: '未绑定店铺'),
                    'site_builder' => $storeId > 0 ? ($platform === 'shopline' ? 'Shopline' : 'XShoppy') : '未绑定',
                    'account_numbers' => $entries->pluck('account_number')->map(fn ($number) => trim((string) $number))->filter()->unique()->values()->all(),
                    'account_keys' => $entries->pluck('resolved_account_key')->filter()->unique()->values()->all(),
                    'orders' => $orders,
                    'revenue' => $revenue,
                    'ad_spend' => $spend,
                    'roi' => $spend > 0 ? $revenue / $spend : null,
                    'total_consumption' => round((float) $entries->pluck('resolved_account_key')->filter()->unique()->sum(fn ($key) => (float) ($accountSpendTotals->get($key) ?? 0)), 2),
                ];
            })->filter()->values()->all();
        })->values();

        $rows = $dailyRows->groupBy(fn (array $row) => $row['operator_id'].'|'.$row['store_id'])
            ->map(function ($group) use ($accountSpendTotals, $giftScreenshots): array {
                $first = $group->first();
                $orders = (int) $group->sum('orders');
                $revenue = round((float) $group->sum('revenue'), 2);
                $spend = round((float) $group->sum('ad_spend'), 2);
                $accountKeys = $group->pluck('account_keys')->flatten()->filter()->unique()->values();
                $giftEligibleKeys = $accountKeys->filter(fn ($key) => (float) ($accountSpendTotals->get($key) ?? 0) >= 5000);
                $giftStatus = $giftEligibleKeys->isEmpty()
                    ? 'not_eligible'
                    : ($giftEligibleKeys->contains(fn ($key) => $giftScreenshots->has($key)) ? 'available' : 'pending_upload');

                return [...$first, 'date_label' => $group->min('date') === $group->max('date') ? $group->min('date') : $group->min('date').' 至 '.$group->max('date'),
                    'orders' => $orders, 'revenue' => $revenue, 'ad_spend' => $spend, 'roi' => $spend > 0 ? $revenue / $spend : null,
                    'account_numbers' => $group->pluck('account_numbers')->flatten()->filter()->unique()->implode('、'),
                    'account_keys' => $accountKeys->all(),
                    'total_consumption' => round((float) $accountKeys->sum(fn ($key) => (float) ($accountSpendTotals->get($key) ?? 0)), 2),
                    'gift_status' => $giftStatus,
                    'details' => $group->sortByDesc('date')->map(function (array $detail): array {
                        $detail['account_numbers'] = implode('、', $detail['account_numbers']);

                        return $detail;
                    })->values()->all()];
            })->values();
        if (($filters['gift_status'] ?? '') === 'yes') {
            $rows = $rows->where('gift_status', 'available')->values();
        } elseif (($filters['gift_status'] ?? '') === 'no') {
            $rows = $rows->where('gift_status', '!=', 'available')->values();
        }
        $domainOptions = $rows->pluck('store_domain')->filter()->unique(fn ($domain) => mb_strtolower((string) $domain))->sort()->values();
        if (($filters['store_domain'] ?? '') !== '') {
            $selectedDomain = mb_strtolower(trim((string) $filters['store_domain']));
            $rows = $rows->filter(fn (array $row): bool => mb_strtolower(trim((string) $row['store_domain'])) === $selectedDomain)->values();
        }
        if (($filters['consumption_range'] ?? '') === 'lt_5000') {
            $rows = $rows->filter(fn (array $row): bool => (float) $row['total_consumption'] < 5000)->values();
        } elseif (($filters['consumption_range'] ?? '') === '5000_10000') {
            $rows = $rows->filter(fn (array $row): bool => (float) $row['total_consumption'] >= 5000 && (float) $row['total_consumption'] <= 10000)->values();
        } elseif (($filters['consumption_range'] ?? '') === 'gt_10000') {
            $rows = $rows->filter(fn (array $row): bool => (float) $row['total_consumption'] > 10000)->values();
        } elseif (($filters['consumption_range'] ?? '') === 'custom') {
            $minimumConsumption = (float) ($filters['consumption_custom'] ?? 0);
            $maximumConsumption = (float) ($filters['consumption_custom_max'] ?? 0);
            $rows = $rows->filter(fn (array $row): bool => (float) $row['total_consumption'] >= $minimumConsumption
                && (float) $row['total_consumption'] <= $maximumConsumption)->values();
        }
        $rows = $rows->sort(function (array $left, array $right): int {
            $orderComparison = (int) $right['orders'] <=> (int) $left['orders'];

            return $orderComparison !== 0
                ? $orderComparison
                : ((float) $right['ad_spend'] <=> (float) $left['ad_spend']);
        })->values();
        $totalAccountKeys = $rows->pluck('account_keys')->flatten()->filter()->unique()->values();
        $totals = ['stores' => $rows->pluck('store_id')->filter(fn ($id) => (int) $id > 0)->unique()->count(), 'orders' => (int) $rows->sum('orders'),
            'revenue' => round((float) $rows->sum('revenue'), 2), 'ad_spend' => round((float) $rows->sum('ad_spend'), 2),
            'total_consumption' => round((float) $totalAccountKeys->sum(fn ($key) => (float) ($accountSpendTotals->get($key) ?? 0)), 2)];
        $totals['roi'] = $totals['ad_spend'] > 0 ? $totals['revenue'] / $totals['ad_spend'] : null;
        $exportRows = $rows->map(fn (array $row): array => [
            'date_label' => $row['date_label'],
            'department' => $row['department'],
            'operator' => $row['operator'],
            'store_domain' => $row['store_domain'],
            'orders' => $row['orders'],
            'revenue' => $row['revenue'],
            'ad_spend' => $row['ad_spend'],
            'roi' => $row['roi'],
            'gift_status' => $row['gift_status'],
            'account_numbers' => $row['account_numbers'],
            'total_consumption' => $row['total_consumption'],
        ])->values();
        $page = max(1, (int) $request->query('page', 1));
        $rows = new LengthAwarePaginator($rows->forPage($page, 30)->values(), $rows->count(), 30, $page, ['path' => $request->url(), 'query' => $request->query()]);

        return view('portals.assistant.fashion-applovin-performance-reports.index', [
            'portal' => $context['portal']->loadMissing('menus'), 'user' => $context['user'], 'rows' => $rows, 'exportRows' => $exportRows, 'totals' => $totals,
            'dateFrom' => $dateFrom, 'dateTo' => $dateTo, 'operator' => $filters['operator'] ?? '',
            'timezone' => $timezone, 'timezoneOptions' => $timezoneOptions,
            'dateRange' => $dateRange, 'selectedStoreDomain' => $filters['store_domain'] ?? '', 'domainOptions' => $domainOptions,
            'selectedGiftStatus' => $filters['gift_status'] ?? '', 'selectedConsumptionRange' => $filters['consumption_range'] ?? '',
            'consumptionCustom' => $filters['consumption_custom'] ?? '', 'consumptionCustomMax' => $filters['consumption_custom_max'] ?? '',
            'selectedDepartmentId' => $filters['department_id'] ?? null, 'departmentOptions' => $departments,
        ]);
    }

    /** @return array<string,string> */
    private function timezoneOptions(): array
    {
        $options = [];
        for ($offset = -12; $offset <= 14; $offset++) {
            $value = sprintf('%s%02d:00', $offset < 0 ? '-' : '+', abs($offset));
            $label = 'UTC'.($offset >= 0 ? '+' : '').$offset;
            if ($value === '+08:00') $label .= '（北京时间）';
            if ($value === '-08:00') $label .= '（默认）';
            $options[$value] = $label;
        }

        return $options;
    }
}
