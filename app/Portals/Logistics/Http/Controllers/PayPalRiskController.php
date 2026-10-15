<?php

namespace App\Portals\Logistics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\LogisticsPayPalAccount;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\Logistics\LogisticsPortalService;
use App\Portals\Logistics\PayPalRiskAnalyticsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class PayPalRiskController extends Controller
{
    public function __construct(
        private readonly PortalEntryController $portalEntries,
        private readonly LogisticsPortalService $logistics,
        private readonly PayPalRiskAnalyticsService $analytics,
    ) {
    }

    public function index(Request $request): Response|View
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }
        $filters = $this->filters($request);
        $query = LogisticsPayPalAccount::query()->orderByDesc('is_enabled')->orderBy('account_name');
        if ($filters['keyword'] !== '') {
            $keyword = $this->contains($filters['keyword']);
            $query->where(function ($accounts) use ($keyword): void {
                $accounts->where('account_name', 'like', $keyword)
                    ->orWhere('account_email', 'like', $keyword)
                    ->orWhere('merchant_id', 'like', $keyword);
            });
        }
        if ($filters['import_status'] !== '') {
            if ($filters['import_status'] === 'never') {
                $query->whereNull('last_import_status');
            } else {
                $query->where('last_import_status', $filters['import_status']);
            }
        }

        $accounts = $query->get();
        $summaries = $accounts
            ->map(fn (LogisticsPayPalAccount $account): array => $this->analytics->summary($account, $filters))
            ->when($filters['risk'] !== '', fn (Collection $items): Collection => $items
                ->where('risk', $filters['risk'])
                ->values());
        $page = max(1, (int) $request->input('page', 1));
        $perPage = (int) $filters['per_page'];
        $page = min($page, max(1, (int) ceil($summaries->count() / $perPage)));
        $paginator = new LengthAwarePaginator(
            $summaries->forPage($page, $perPage)->values(),
            $summaries->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->except('page')],
        );

        return view('portals.logistics.paypal-risk.index', [
            ...$this->viewContext($context),
            'accounts' => $paginator,
            'accountModels' => $accounts->keyBy('id'),
            'filters' => $filters,
            'portfolio' => $this->portfolio($summaries),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) {
            return redirect()->to(route('logistics.paypal-risk.index'));
        }
        $data = $this->validatedAccount($request);
        $account = LogisticsPayPalAccount::query()->create([
            ...$data,
            'created_by' => $context['user']->getKey(),
            'created_by_name' => $context['user']->name,
            'updated_by' => $context['user']->getKey(),
            'updated_by_name' => $context['user']->name,
        ]);
        return $this->indexRedirect($request)
            ->with('success', __('logistics.paypal_risk.messages.account_created'));
    }

    public function update(Request $request, LogisticsPayPalAccount $account): RedirectResponse
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) {
            return redirect()->to(route('logistics.paypal-risk.index'));
        }
        $data = $this->validatedAccount($request, $account);
        $account->fill([
            ...$data,
            'updated_by' => $context['user']->getKey(),
            'updated_by_name' => $context['user']->name,
        ])->save();
        return $this->indexRedirect($request)
            ->with('success', __('logistics.paypal_risk.messages.account_updated'));
    }

    public function detail(Request $request, LogisticsPayPalAccount $account): Response
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }
        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'event_date_from' => ['nullable', 'date'],
            'event_date_to' => ['nullable', 'date', 'after_or_equal:event_date_from'],
            'tab' => ['nullable', Rule::in(['overview', 'orders', 'products', 'refunds', 'disputes'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'sort' => ['nullable', Rule::in([
                '', 'order_number', 'tongtu_order_number', 'store_name', 'products', 'paid_amount',
                'refund_status', 'dispute_status', 'tracking_number', 'logistics_status', 'order_created_at', 'product_name', 'sku', 'quantity',
                'refund_orders', 'refund_rate', 'dispute_orders', 'dispute_rate', 'refund_id', 'source',
                'amount', 'reason', 'status', 'happened_at', 'dispute_id', 'stage', 'source_reason',
                'manual_reason', 'linked_disputes', 'outcome', 'seller_response_due_at', 'linked_refunds',
                'updated_at',
            ])],
            'direction' => ['nullable', Rule::in(['', 'asc', 'desc'])],
        ]);
        $filters = $this->detailDateFilters($validated);

        return response()->json($this->analytics->detail(
            $account,
            $filters,
            (string) ($validated['tab'] ?? 'overview'),
            max(1, (int) ($validated['page'] ?? 1)),
            (string) ($validated['sort'] ?? ''),
            (string) ($validated['direction'] ?? ''),
        ));
    }

    public function exportDetail(Request $request, LogisticsPayPalAccount $account, string $tab): Response
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }
        abort_unless(in_array($tab, ['orders', 'products', 'refunds', 'disputes'], true), 404);

        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'event_date_from' => ['nullable', 'date'],
            'event_date_to' => ['nullable', 'date', 'after_or_equal:event_date_from'],
        ]);
        $filters = $this->detailDateFilters($validated);
        $fields = $this->exportFields($tab);
        $rows = $this->analytics->exportRows($account, $filters, $tab);
        $filename = 'paypal-'.$tab.'-'.now('Asia/Shanghai')->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($fields, $rows): void {
            $output = fopen('php://output', 'wb');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, array_values($fields));
            foreach ($rows as $row) {
                $line = [];
                foreach (array_keys($fields) as $field) {
                    $value = $row[$field] ?? '';
                    if (is_array($value)) {
                        $value = implode(', ', array_map(static fn ($item): string => is_array($item)
                            ? implode(' ', array_map('strval', $item))
                            : (string) $item, $value));
                    }
                    $line[] = $value;
                }
                fputcsv($output, $line);
            }
            fclose($output);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array<string,mixed> */
    private function validatedAccount(Request $request, ?LogisticsPayPalAccount $account = null): array
    {
        foreach (['account_name', 'merchant_id', 'account_email'] as $field) {
            if ($request->has($field)) {
                $request->merge([$field => trim((string) $request->input($field))]);
            }
        }
        $merchantUnique = Rule::unique(LogisticsPayPalAccount::class, 'merchant_id');
        $emailUnique = Rule::unique(LogisticsPayPalAccount::class, 'account_email');
        if ($account) {
            $merchantUnique->ignore($account->getKey());
            $emailUnique->ignore($account->getKey());
        }
        $rules = [
            'account_name' => ['required', 'string', 'max:191'],
            'merchant_id' => ['required', 'string', 'max:100', $merchantUnique],
            'account_email' => ['nullable', 'email', 'max:191', $emailUnique],
            'environment' => ['required', Rule::in(['live', 'sandbox'])],
            'is_enabled' => ['required', 'boolean'],
        ];
        $data = $request->validate($rules);
        $data['merchant_id'] = blank($data['merchant_id'] ?? null) ? null : mb_strtoupper((string) $data['merchant_id']);
        $data['account_email'] = blank($data['account_email'] ?? null) ? null : mb_strtolower((string) $data['account_email']);

        return $data;
    }

    /** @return array{keyword:string,risk:string,import_status:string,date_from:string,date_to:string,event_date_from:string,event_date_to:string,date_mode:string,per_page:int} */
    private function filters(Request $request): array
    {
        $validated = $request->validate([
            'keyword' => ['nullable', 'string', 'max:191'],
            'risk' => ['nullable', Rule::in(['', 'low', 'medium', 'high'])],
            'import_status' => ['nullable', Rule::in(['', 'never', 'success', 'partial', 'failed'])],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'event_date_from' => ['nullable', 'date'],
            'event_date_to' => ['nullable', 'date', 'after_or_equal:event_date_from'],
            'per_page' => ['nullable', Rule::in([6, 12, 24])],
        ]);
        // HTML date inputs are always submitted by the browser, including
        // when empty.  Check the validated values so an empty event range is
        // not mistaken for an active filter.
        $hasOrderDateFilter = filled($validated['date_from'] ?? null)
            || filled($validated['date_to'] ?? null);
        $hasEventDateFilter = filled($validated['event_date_from'] ?? null)
            || filled($validated['event_date_to'] ?? null);
        if ($hasOrderDateFilter && $hasEventDateFilter) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'event_date_from' => __('logistics.paypal_risk.filters.date_ranges_exclusive'),
            ]);
        }

        return [
            'keyword' => trim((string) ($validated['keyword'] ?? '')),
            'risk' => trim((string) ($validated['risk'] ?? '')),
            'import_status' => trim((string) ($validated['import_status'] ?? '')),
            'date_from' => $hasOrderDateFilter ? trim((string) ($validated['date_from'] ?? '')) : ($hasEventDateFilter ? '' : now('Asia/Shanghai')->subDays(29)->format('Y-m-d')),
            'date_to' => $hasOrderDateFilter ? trim((string) ($validated['date_to'] ?? '')) : ($hasEventDateFilter ? '' : now('Asia/Shanghai')->format('Y-m-d')),
            'event_date_from' => $hasEventDateFilter ? trim((string) ($validated['event_date_from'] ?? '')) : '',
            'event_date_to' => $hasEventDateFilter ? trim((string) ($validated['event_date_to'] ?? '')) : '',
            'date_mode' => $hasEventDateFilter ? 'event' : 'order',
            'per_page' => (int) ($validated['per_page'] ?? 6),
        ];
    }

    /** @param array<string,mixed> $validated
     *  @return array{date_from:string,date_to:string,event_date_from:string,event_date_to:string,date_mode:string}
     */
    private function detailDateFilters(array $validated): array
    {
        $hasOrderRange = filled($validated['date_from'] ?? null) || filled($validated['date_to'] ?? null);
        $hasEventRange = filled($validated['event_date_from'] ?? null) || filled($validated['event_date_to'] ?? null);
        if ($hasOrderRange && $hasEventRange) {
            throw ValidationException::withMessages([
                'event_date_from' => __('logistics.paypal_risk.filters.date_ranges_exclusive'),
            ]);
        }

        return [
            'date_from' => $hasOrderRange ? trim((string) ($validated['date_from'] ?? '')) : '',
            'date_to' => $hasOrderRange ? trim((string) ($validated['date_to'] ?? '')) : '',
            'event_date_from' => $hasEventRange ? trim((string) ($validated['event_date_from'] ?? '')) : '',
            'event_date_to' => $hasEventRange ? trim((string) ($validated['event_date_to'] ?? '')) : '',
            'date_mode' => $hasEventRange ? 'event' : 'order',
        ];
    }

    /** @return array<string,string> */
    private function exportFields(string $tab): array
    {
        return __('logistics.paypal_risk.export.'.$tab);
    }

    /** @param Collection<int,array<string,mixed>> $summaries */
    private function portfolio(Collection $summaries): array
    {
        $orders = (int) $summaries->sum('order_count');
        $refunds = (int) $summaries->sum('refund_order_count');
        $disputes = (int) $summaries->sum('dispute_order_count');

        return [
            'accounts' => $summaries->count(),
            'orders' => $orders,
            'paid_amount' => round((float) $summaries->sum('paid_amount'), 2),
            'refund_rate' => $orders > 0 ? round($refunds / $orders * 100, 2) : 0.0,
            'dispute_rate' => $orders > 0 ? round($disputes / $orders * 100, 2) : 0.0,
        ];
    }

    /** @return array{portal:\App\Models\Portal,user:\App\Models\AdminUser}|Response */
    private function portalContext(Request $request): array|Response
    {
        $context = $this->portalEntries->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }
        $this->logistics->assertLogisticsPortal($context['portal']);

        return $context;
    }

    private function viewContext(array $context): array
    {
        $context['portal']->load('menus');

        return $context;
    }

    private function indexRedirect(Request $request): RedirectResponse
    {
        $default = route('logistics.paypal-risk.index');
        $returnTo = trim((string) $request->input('return_to'));
        $defaultUrl = parse_url($default);
        $candidate = parse_url($returnTo);
        if ($returnTo !== ''
            && is_array($candidate)
            && ($candidate['host'] ?? null) === ($defaultUrl['host'] ?? null)
            && ($candidate['path'] ?? null) === ($defaultUrl['path'] ?? null)) {
            return redirect()->to($returnTo);
        }

        return redirect()->to($default);
    }

    private function contains(string $value): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value).'%';
    }
}
