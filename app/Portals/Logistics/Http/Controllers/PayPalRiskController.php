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
        $accounts = $query->get();
        $summaries = $accounts
            ->map(fn (LogisticsPayPalAccount $account): array => $this->analytics->summary($account, $filters));
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
        $this->normalizeDateModeInput($request);
        $validated = $request->validate([
            'date_mode' => ['nullable', Rule::in(['order', 'event'])],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'event_date_from' => ['nullable', 'date'],
            'event_date_to' => ['nullable', 'date', 'after_or_equal:event_date_from'],
            'tab' => ['nullable', Rule::in(['overview', 'orders', 'products', 'refunds', 'disputes'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'tab_keyword' => ['nullable', 'string', 'max:191'],
            'tab_status' => ['nullable', 'string', 'max:100'],
            'tab_type' => ['nullable', 'string', 'max:100'],
            'tab_outcome' => ['nullable', 'string', 'max:100'],
            'tab_match_status' => ['nullable', Rule::in(['', 'matched', 'unmatched'])],
            'sort' => ['nullable', Rule::in([
                '', 'payment_transaction_id', 'match_status', 'order_number', 'tongtu_order_number', 'store_name', 'products', 'paid_amount',
                'refund_status', 'dispute_status', 'tracking_number', 'logistics_status', 'paid_at', 'product_name', 'sku', 'quantity',
                'refund_orders', 'refund_rate', 'dispute_orders', 'dispute_rate', 'refund_id', 'source',
                'amount', 'reason', 'status', 'happened_at', 'dispute_id', 'stage', 'source_reason',
                'manual_reason', 'linked_disputes', 'outcome', 'seller_response_due_at', 'linked_refunds',
                'updated_at',
            ])],
            'direction' => ['nullable', Rule::in(['', 'asc', 'desc'])],
        ], $this->dateValidationMessages());
        $filters = $this->detailDateFilters($validated);

        return response()->json($this->analytics->detail(
            $account,
            $filters,
            (string) ($validated['tab'] ?? 'overview'),
            max(1, (int) ($validated['page'] ?? 1)),
            (string) ($validated['sort'] ?? ''),
            (string) ($validated['direction'] ?? ''),
            $this->detailTabFilters($validated),
        ));
    }

    public function exportDetail(Request $request, LogisticsPayPalAccount $account, string $tab): Response
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }
        abort_unless(in_array($tab, ['orders', 'products', 'refunds', 'disputes'], true), 404);

        $this->normalizeDateModeInput($request);
        $validated = $request->validate([
            'date_mode' => ['nullable', Rule::in(['order', 'event'])],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'event_date_from' => ['nullable', 'date'],
            'event_date_to' => ['nullable', 'date', 'after_or_equal:event_date_from'],
            'tab_keyword' => ['nullable', 'string', 'max:191'],
            'tab_status' => ['nullable', 'string', 'max:100'],
            'tab_type' => ['nullable', 'string', 'max:100'],
            'tab_outcome' => ['nullable', 'string', 'max:100'],
            'tab_match_status' => ['nullable', Rule::in(['', 'matched', 'unmatched'])],
        ], $this->dateValidationMessages());
        $filters = $this->detailDateFilters($validated);
        $fields = $this->exportFields($tab);
        $rows = $this->analytics->exportRows($account, $filters, $tab, $this->detailTabFilters($validated));
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

    /** @return array{keyword:string,date_from:string,date_to:string,event_date_from:string,event_date_to:string,date_mode:string,per_page:int} */
    private function filters(Request $request): array
    {
        $this->normalizeDateModeInput($request);
        $validated = $request->validate([
            'keyword' => ['nullable', 'string', 'max:191'],
            'date_mode' => ['nullable', Rule::in(['order', 'event'])],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'event_date_from' => ['nullable', 'date'],
            'event_date_to' => ['nullable', 'date', 'after_or_equal:event_date_from'],
            'per_page' => ['nullable', Rule::in([6, 12, 24])],
        ], $this->dateValidationMessages());
        $dateMode = (string) ($validated['date_mode'] ?? 'order');
        $dateMode = $dateMode === 'event' ? 'event' : 'order';
        $defaultFrom = now('Asia/Shanghai')->subDays(29)->format('Y-m-d');
        $defaultTo = now('Asia/Shanghai')->format('Y-m-d');

        return [
            'keyword' => trim((string) ($validated['keyword'] ?? '')),
            // 时间口径以 date_mode 为唯一依据。即使有人手工同时传两组日期，也只读取
            // 当前口径的一组，避免订单时间和风险发生时间被意外叠加筛选。
            'date_from' => $dateMode === 'order' ? trim((string) ($validated['date_from'] ?? $defaultFrom)) : '',
            'date_to' => $dateMode === 'order' ? trim((string) ($validated['date_to'] ?? $defaultTo)) : '',
            'event_date_from' => $dateMode === 'event' ? trim((string) ($validated['event_date_from'] ?? $defaultFrom)) : '',
            'event_date_to' => $dateMode === 'event' ? trim((string) ($validated['event_date_to'] ?? $defaultTo)) : '',
            'date_mode' => $dateMode,
            'per_page' => (int) ($validated['per_page'] ?? 6),
        ];
    }

    /** @param array<string,mixed> $validated
     *  @return array{date_from:string,date_to:string,event_date_from:string,event_date_to:string,date_mode:string}
     */
    private function detailDateFilters(array $validated): array
    {
        $dateMode = (string) ($validated['date_mode'] ?? 'order');
        $dateMode = $dateMode === 'event' ? 'event' : 'order';

        return [
            'date_from' => $dateMode === 'order' ? trim((string) ($validated['date_from'] ?? '')) : '',
            'date_to' => $dateMode === 'order' ? trim((string) ($validated['date_to'] ?? '')) : '',
            'event_date_from' => $dateMode === 'event' ? trim((string) ($validated['event_date_from'] ?? '')) : '',
            'event_date_to' => $dateMode === 'event' ? trim((string) ($validated['event_date_to'] ?? '')) : '',
            'date_mode' => $dateMode,
        ];
    }

    /**
     * 两套日期控件只允许当前时间口径的一套参与校验与查询。
     * 前端禁用隐藏字段只是体验层保障；这里再次归一化，避免旧页面、
     * 浏览器缓存或手工请求同时带入两套日期后发生交叉校验。
     */
    private function normalizeDateModeInput(Request $request): void
    {
        $submittedMode = (string) $request->input('date_mode', 'order');
        $activeMode = $submittedMode === 'event' ? 'event' : 'order';

        $request->merge([
            'date_from' => $activeMode === 'order' ? $request->input('date_from') : null,
            'date_to' => $activeMode === 'order' ? $request->input('date_to') : null,
            'event_date_from' => $activeMode === 'event' ? $request->input('event_date_from') : null,
            'event_date_to' => $activeMode === 'event' ? $request->input('event_date_to') : null,
        ]);
    }

    /** @return array<string,string> */
    private function dateValidationMessages(): array
    {
        return [
            'date_from.date' => __('logistics.paypal_risk.validation.order_date_invalid'),
            'date_to.date' => __('logistics.paypal_risk.validation.order_date_invalid'),
            'date_to.after_or_equal' => __('logistics.paypal_risk.validation.order_date_range'),
            'event_date_from.date' => __('logistics.paypal_risk.validation.event_date_invalid'),
            'event_date_to.date' => __('logistics.paypal_risk.validation.event_date_invalid'),
            'event_date_to.after_or_equal' => __('logistics.paypal_risk.validation.event_date_range'),
        ];
    }

    /** @param array<string,mixed> $validated
     *  @return array{keyword:string,status:string,type:string,outcome:string,match_status:string}
     */
    private function detailTabFilters(array $validated): array
    {
        return [
            'keyword' => trim((string) ($validated['tab_keyword'] ?? '')),
            'status' => trim((string) ($validated['tab_status'] ?? '')),
            'type' => trim((string) ($validated['tab_type'] ?? '')),
            'outcome' => trim((string) ($validated['tab_outcome'] ?? '')),
            'match_status' => trim((string) ($validated['tab_match_status'] ?? '')),
        ];
    }

    /** @return array<string,string> */
    private function exportFields(string $tab): array
    {
        $fields = __('logistics.paypal_risk.export.'.$tab);
        if ($tab === 'orders' && is_array($fields)) {
            unset($fields['order_created_at']);
        }

        return is_array($fields) ? $fields : [];
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
        $context['portal']->loadMissing('menus');

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
