<?php

namespace App\Portals\Assistant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\AppLovinAccountStoreBinding;
use App\Models\AppLovinSpendScreenshot;
use App\Models\AssistantDomainStore;
use App\Models\FashionAdvertisingReport;
use App\Models\OperationReport;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\Assistant\AssistantPortalAuthorizationService;
use App\Portals\Assistant\AssistantStoreOwnerService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class AppLovinAccountStatisticsController extends Controller
{
    public function __construct(
        private readonly PortalEntryController $portalEntries,
        private readonly AssistantPortalAuthorizationService $authorization,
        private readonly AssistantStoreOwnerService $storeOwners,
    ) {}

    public function index(Request $request): Response|View
    {
        $context = $this->context($request);
        if ($context instanceof Response) return $context;

        $visibleIds = $this->visibleDepartmentIds($context['user']);
        $filters = $request->validate([
            'department_id' => ['nullable', 'integer', Rule::in($visibleIds)],
            'operator' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', Rule::in([20, 50, 100])],
        ]);
        $operator = trim((string) ($filters['operator'] ?? ''));
        $departmentId = (int) ($filters['department_id'] ?? 0);
        $accounts = $this->accounts($context['user'])
            ->when($departmentId > 0, fn (Collection $rows) => $rows->where('department_id', $departmentId))
            ->when($operator !== '', fn (Collection $rows) => $rows->filter(fn (array $row) => str_contains(mb_strtolower($row['operator_name']), mb_strtolower($operator))));

        $spendBands = [
            'under_5000' => $accounts->where('total_spend', '<', 5000)->count(),
            'between_5000_10000' => $accounts->filter(fn (array $row): bool => $row['total_spend'] >= 5000 && $row['total_spend'] <= 10000)->count(),
            'over_10000' => $accounts->where('total_spend', '>', 10000)->count(),
        ];

        $operators = $accounts->groupBy('operator_user_id')->map(function (Collection $rows): array {
            $first = $rows->first();
            return [
                'operator_user_id' => (int) $first['operator_user_id'],
                'operator_name' => $first['operator_name'],
                'department_id' => (int) $first['department_id'],
                'department' => $first['department'],
                'account_count' => $rows->count(),
                'under_5000_count' => $rows->where('total_spend', '<', 5000)->count(),
                'between_5000_10000_count' => $rows->filter(fn (array $row): bool => $row['total_spend'] >= 5000 && $row['total_spend'] <= 10000)->count(),
                'over_10000_count' => $rows->where('total_spend', '>', 10000)->count(),
                'total_spend' => (float) $rows->sum('total_spend'),
            ];
        })->sortBy([['department', 'asc'], ['operator_name', 'asc']])->values();

        $perPage = (int) ($filters['per_page'] ?? 20);
        $page = max(1, (int) $request->query('page', 1));
        $operators = new LengthAwarePaginator($operators->forPage($page, $perPage)->values(), $operators->count(), $perPage, $page, [
            'path' => $request->url(), 'query' => $request->query(),
        ]);

        return view('portals.assistant.applovin-account-statistics.index', [
            'portal' => $context['portal']->loadMissing('menus'), 'user' => $context['user'],
            'operators' => $operators,
            'departmentOptions' => $this->storeOwners->operationDepartments()->whereIn('id', $visibleIds)->values(),
            'filters' => ['department_id' => $departmentId, 'operator' => $operator, 'per_page' => $perPage],
            'spendBands' => $spendBands,
        ]);
    }

    public function show(Request $request, int $operator): Response|View
    {
        $context = $this->context($request);
        if ($context instanceof Response) return $context;

        $filters = $request->validate([
            'spend_band' => ['nullable', Rule::in(['under_5000', 'between_5000_10000', 'over_10000'])],
        ]);
        $accounts = $this->accounts($context['user'])->where('operator_user_id', $operator)->values();
        abort_if($accounts->isEmpty(), 404);
        $first = $accounts->first();
        $spendBand = (string) ($filters['spend_band'] ?? '');
        $accounts = match ($spendBand) {
            'under_5000' => $accounts->where('total_spend', '<', 5000)->values(),
            'between_5000_10000' => $accounts->filter(fn (array $row): bool => $row['total_spend'] >= 5000 && $row['total_spend'] <= 10000)->values(),
            'over_10000' => $accounts->where('total_spend', '>', 10000)->values(),
            default => $accounts,
        };
        $page = max(1, (int) $request->query('page', 1));
        $paginator = new LengthAwarePaginator($accounts->forPage($page, 20)->values(), $accounts->count(), 20, $page, [
            'path' => $request->url(), 'query' => $request->query(),
        ]);

        return view('portals.assistant.applovin-account-statistics.show', [
            'portal' => $context['portal']->loadMissing('menus'), 'user' => $context['user'],
            'operatorName' => $first['operator_name'], 'department' => $first['department'],
            'accounts' => $paginator, 'totalSpend' => (float) $accounts->sum('total_spend'),
            'spendBand' => $spendBand,
            'spendBandLabel' => match ($spendBand) {
                'under_5000' => '历史总消耗小于 $5,000',
                'between_5000_10000' => '历史总消耗 $5,000–$10,000',
                'over_10000' => '历史总消耗大于 $10,000',
                default => '全部账号',
            },
        ]);
    }

    private function accounts(AdminUser $viewer): Collection
    {
        $visibleIds = $this->visibleDepartmentIds($viewer);
        $subscriptionQuery = OperationReport::query()->where('status', OperationReport::STATUS_APPROVED);
        if (! $viewer->isAdministrator()) {
            $visibleIds === [] ? $subscriptionQuery->whereRaw('1=0') : $subscriptionQuery->whereIn('operation_department_id', $visibleIds);
        }
        $subscription = $subscriptionQuery->get()->flatMap(fn (OperationReport $report) => collect($report->advertising_rows ?: [])
            ->filter(fn (array $row) => mb_strtolower(trim((string) ($row['media_source'] ?? ''))) === 'applovin')
            ->map(fn (array $row): array => [
                'operator_user_id' => (int) $report->operator_user_id, 'operator_name' => (string) $report->operator_name,
                'department_id' => (int) $report->operation_department_id, 'department' => (string) ($report->operation_department_name ?: '未设置团队'),
                'agency' => trim((string) ($row['agency'] ?? '')), 'account_name' => trim((string) ($row['account_name'] ?? '')),
                'account_number' => trim((string) ($row['account_number'] ?? '')), 'spend' => (float) ($row['actual_spend'] ?? 0),
                'date' => $report->report_date->format('Y-m-d'), 'source' => '订阅运营',
            ]));

        $fashion = FashionAdvertisingReport::query()->where('status', FashionAdvertisingReport::STATUS_APPROVED)
            ->with(['adSpendEntries', 'submitter.departments'])->get()
            ->flatMap(function (FashionAdvertisingReport $report) use ($viewer, $visibleIds): Collection {
                $department = $report->submitter?->departments?->first(fn ($item) => (bool) ($item->pivot->is_primary ?? false))
                    ?: $report->submitter?->departments?->first();
                if (! $viewer->isAdministrator() && (! $department || ! in_array((int) $department->id, $visibleIds, true))) return collect();
                return $report->adSpendEntries->filter(fn ($entry) => mb_strtolower(trim((string) $entry->media)) === 'applovin')
                    ->map(fn ($entry): array => [
                        'operator_user_id' => (int) $report->submitter_id, 'operator_name' => (string) $report->submitter_name,
                        'department_id' => (int) ($department?->id ?: 0), 'department' => (string) ($department?->name ?: '服装运营部门'),
                        'agency' => trim((string) $entry->agency), 'account_name' => trim((string) $entry->account_name),
                        'account_number' => trim((string) $entry->account_number), 'spend' => (float) $entry->actual_spend_usd,
                        'date' => $report->report_date->format('Y-m-d'), 'source' => '服装运营',
                    ]);
            });

        $accounts = $subscription->concat($fashion)->groupBy(function (array $row): string {
            $identity = $row['account_number'] !== '' ? $row['account_number'] : mb_strtolower($row['account_name']);
            return $row['operator_user_id'].'|'.mb_strtolower($row['agency']).'|'.$identity;
        })->map(function (Collection $rows): array {
            $latest = $rows->sortByDesc('date')->first();
            $accountKey = AppLovinSpendScreenshot::accountKey((int) $latest['operator_user_id'], $latest['agency'], 'AppLovin', $latest['account_name'], $latest['account_number']);
            return $latest + [
                'account_key' => $accountKey, 'total_spend' => (float) $rows->sum('spend'),
                'first_date' => (string) $rows->min('date'), 'last_date' => (string) $rows->max('date'),
                'sources' => $rows->pluck('source')->unique()->implode('、'),
            ];
        })->values();

        $bindings = AppLovinAccountStoreBinding::query()->whereIn('account_key', $accounts->pluck('account_key'))->get()->keyBy('account_key');
        $stores = AssistantDomainStore::query()->whereIn('id', $bindings->pluck('assistant_domain_store_id')->filter()->unique())->get()->keyBy('id');
        return $accounts->map(function (array $account) use ($bindings, $stores): array {
            $binding = $bindings->get($account['account_key']);
            $store = $binding ? $stores->get($binding->assistant_domain_store_id) : null;
            return $account + ['store_domain' => (string) ($store?->domain ?: '')];
        });
    }

    private function visibleDepartmentIds(AdminUser $user): array
    {
        return $user->isAdministrator()
            ? $this->storeOwners->operationDepartments()->pluck('id')->map(fn ($id) => (int) $id)->all()
            : $this->storeOwners->visibleOperationDepartmentIds($user);
    }

    private function context(Request $request): array|Response
    {
        $context = $this->portalEntries->portalContext($request);
        if ($context instanceof Response) return $context;
        $this->authorization->assertAssistantPortal($context['portal']);
        abort_unless($this->authorization->isAssistantDepartmentMember($context['user']), 403);
        return $context;
    }
}
