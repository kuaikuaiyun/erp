<?php

namespace App\Portals\Payment\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\LogisticsPayPalAccount;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\Payment\PaymentAccountRiskService;
use App\Portals\Payment\PaymentPayPalBalanceRefreshException;
use App\Portals\Payment\PaymentPayPalBalanceRefreshService;
use App\Portals\Payment\PaymentCreditCardRiskService;
use App\Portals\Payment\PaymentOrderSearchService;
use App\Portals\Payment\PaymentPayPalMonthlyReportExportService;
use App\Portals\Payment\PaymentPayPalMonthlyReportService;
use App\Portals\Payment\PaymentPortalService;
use App\Portals\Payment\PaymentProductAlertExportService;
use App\Portals\Payment\PaymentProductAlertService;
use App\Portals\Payment\PaymentProductRiskService;
use App\Portals\Payment\PaymentRenewalAnalysisExportService;
use App\Portals\Payment\PaymentRenewalAnalysisService;
use App\Portals\Payment\PaymentWorkbenchService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class PaymentPortalController extends Controller
{
    public function __construct(
        private readonly PortalEntryController $portalEntries,
        private readonly PaymentPortalService $payment,
        private readonly PaymentAccountRiskService $accountRisk,
        private readonly PaymentPayPalBalanceRefreshService $balanceRefresh,
        private readonly PaymentCreditCardRiskService $creditCardRisk,
        private readonly PaymentProductRiskService $productRisk,
        private readonly PaymentOrderSearchService $paymentOrders,
        private readonly PaymentProductAlertService $productAlerts,
        private readonly PaymentProductAlertExportService $productAlertExport,
        private readonly PaymentWorkbenchService $workbench,
        private readonly PaymentPayPalMonthlyReportService $monthlyReports,
        private readonly PaymentPayPalMonthlyReportExportService $monthlyReportExport,
        private readonly PaymentRenewalAnalysisService $renewalAnalysis,
        private readonly PaymentRenewalAnalysisExportService $renewalAnalysisExport,
    ) {
    }

    /** @param array{portal: \App\Models\Portal, user: AdminUser} $context */
    public function workbenchFromContext(array $context): Response
    {
        return response()->view('portals.payment.workbench', [
            ...$this->viewContext($context),
            'dashboard' => $this->workbench->dashboard($context['user']),
        ]);
    }

    public function accountRisk(Request $request): Response
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }

        $filters = $this->accountRiskFilters($request);

        return response()->view('portals.payment.account-risk', [
            ...$this->viewContext($context),
            'dashboard' => $filters['module'] === 'credit_card'
                ? $this->creditCardRisk->dashboard($filters)
                : $this->accountRisk->dashboard($filters),
        ]);
    }

    public function storeAccountRiskAccount(Request $request): RedirectResponse
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) {
            return redirect()->route('payment.account-risk.index');
        }

        $data = $this->validatedAccount($request);
        $this->accountRisk->createAccount(
            $data,
            $context['user']->getKey(),
            $context['user']->name,
        );

        return redirect()->route('payment.account-risk.index')
            ->with('success', __('payment.account_risk.create_modal.success_message'));
    }

    public function updateAccountRiskAccount(Request $request, LogisticsPayPalAccount $account): RedirectResponse
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) {
            return redirect()->route('payment.account-risk.index');
        }

        $this->accountRisk->updateAccount(
            $account,
            $this->validatedAccount($request, $account),
            $context['user']->getKey(),
            $context['user']->name,
        );

        return redirect()->route('payment.account-risk.index')
            ->with('success', __('payment.account_risk.edit_modal.success_message'));
    }

    public function refreshAccountRiskBalance(Request $request, LogisticsPayPalAccount $account): Response
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }

        try {
            $this->balanceRefresh->refresh($account);
        } catch (PaymentPayPalBalanceRefreshException $exception) {
            return response()->json(['ok' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'ok' => true,
            'balance_html' => view('portals.payment.partials.account-risk-balance', [
                'balance' => $this->accountRisk->balanceForAccount($account),
            ])->render(),
        ]);
    }

    public function accountRiskDetail(Request $request, LogisticsPayPalAccount $account): Response
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }

        $validated = $request->validate([
            'tab' => ['nullable', Rule::in(['overview', 'monthly_report', 'order_analysis', 'orders', 'products', 'refunds', 'disputes'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', Rule::in([50, 100, '50', '100'])],
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date', 'after_or_equal:period_start'],
            'date_mode' => ['nullable', Rule::in(['order', 'event'])],
            'keyword' => ['nullable', 'string', 'max:191'],
            'sort' => ['nullable', 'string', 'max:64'],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'status' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', 'string', 'max:100'],
            'outcome' => ['nullable', 'string', 'max:100'],
            'match_status' => ['nullable', Rule::in(['', 'matched', 'unmatched'])],
            'report_year' => ['nullable', 'integer', 'min:2000', 'max:'.now('America/Los_Angeles')->year],
            'report_dimension' => ['nullable', Rule::in(['payment', 'event'])],
            'report_month' => ['nullable', 'integer', 'between:1,12'],
            'overview_month_from' => ['nullable', 'date_format:Y-m'],
            'overview_month_to' => ['nullable', 'date_format:Y-m'],
        ]);
        $today = now('Asia/Shanghai');
        $tab = (string) ($validated['tab'] ?? 'overview');

        if ($tab === 'monthly_report') {
            return response()->json($this->monthlyReports->report(
                $account,
                (int) ($validated['report_year'] ?? now('America/Los_Angeles')->year),
                (string) ($validated['report_dimension'] ?? 'payment'),
                isset($validated['report_month']) ? (int) $validated['report_month'] : null,
            ));
        }

        $dateMode = (string) ($validated['date_mode'] ?? 'order');
        $periodStart = (string) ($validated['period_start'] ?? $today->copy()->subDays(29)->format('Y-m-d'));
        $periodEnd = (string) ($validated['period_end'] ?? $today->format('Y-m-d'));

        if ($tab === 'overview') {
            $now = now('America/Los_Angeles');
            $currentMonth = $now->copy()->startOfMonth();
            $monthFrom = isset($validated['overview_month_from'])
                ? Carbon::createFromFormat('Y-m', (string) $validated['overview_month_from'], 'America/Los_Angeles')->startOfMonth()
                : $now->copy()->startOfMonth();
            $monthTo = isset($validated['overview_month_to'])
                ? Carbon::createFromFormat('Y-m', (string) $validated['overview_month_to'], 'America/Los_Angeles')->startOfMonth()
                : $monthFrom->copy();
            if ($monthFrom->greaterThan($currentMonth)) {
                $monthFrom = $currentMonth->copy();
            }
            if ($monthTo->greaterThan($currentMonth)) {
                $monthTo = $currentMonth->copy();
            }
            if ($monthFrom->greaterThan($monthTo)) {
                $monthFrom = $monthTo->copy();
            }
            $dateMode = 'event';
            $periodStart = $monthFrom->format('Y-m-d');
            $periodEnd = $monthTo->isSameMonth($now) ? $now->format('Y-m-d') : $monthTo->copy()->endOfMonth()->format('Y-m-d');
            $validated['overview_month_from'] = $monthFrom->format('Y-m');
            $validated['overview_month_to'] = $monthTo->format('Y-m');
        }

        return response()->json($this->accountRisk->detail(
            $account,
            [
                'date_from' => $dateMode === 'order' ? $periodStart : '',
                'date_to' => $dateMode === 'order' ? $periodEnd : '',
                'event_date_from' => $dateMode === 'event' ? $periodStart : '',
                'event_date_to' => $dateMode === 'event' ? $periodEnd : '',
                'date_mode' => $dateMode,
                'overview_month_from' => (string) ($validated['overview_month_from'] ?? ''),
                'overview_month_to' => (string) ($validated['overview_month_to'] ?? ''),
            ],
            $tab,
            (int) ($validated['page'] ?? 1),
            (int) ($validated['per_page'] ?? 50),
            [
                'keyword' => trim((string) ($validated['keyword'] ?? '')),
                'sort' => trim((string) ($validated['sort'] ?? '')),
                'direction' => (string) ($validated['direction'] ?? 'desc'),
                'status' => trim((string) ($validated['status'] ?? '')),
                'type' => trim((string) ($validated['type'] ?? '')),
                'outcome' => trim((string) ($validated['outcome'] ?? '')),
                'match_status' => trim((string) ($validated['match_status'] ?? '')),
            ],
        ));
    }

    public function exportAccountMonthlyReport(Request $request, LogisticsPayPalAccount $account): Response
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }
        $validated = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:'.now('America/Los_Angeles')->year],
            'dimension' => ['required', Rule::in(['payment', 'event'])],
        ]);

        return $this->monthlyReportExport->download(
            $account,
            (int) $validated['year'],
            (string) $validated['dimension'],
        );
    }

    public function productRiskProfit(Request $request): Response
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }

        return response()->view('portals.payment.product-risk-profit', [
            ...$this->viewContext($context),
            'dashboard' => $this->productRisk->dashboard($this->productRiskFilters($request)),
        ]);
    }

    public function productRiskProfitTab(Request $request): Response
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }
        $validated = $request->validate([
            'tab' => ['required', Rule::in(['risk_metrics', 'loss_profit', 'refund_reason'])],
        ]);
        $tab = (string) $validated['tab'];

        return response()->view('portals.payment.partials.product-risk-tab', [
            'tab' => $tab,
            'dashboard' => $this->productRisk->tab($this->productRiskFilters($request), $tab),
        ]);
    }

    public function productRiskProfitDetail(Request $request): Response
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }
        $validated = $request->validate([
            'product_id' => ['required', 'string', 'max:512'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'date_mode' => ['nullable', Rule::in(['order', 'event'])],
            'store' => ['nullable', 'string', 'max:191'],
            'name' => ['nullable', 'string', 'max:191'],
            'keyword' => ['nullable', 'string', 'max:191'],
            'detail_tab' => ['nullable', Rule::in(['overview', 'first_renewal', 'orders', 'variants', 'refunds', 'disputes'])],
            'detail_page' => ['nullable', 'integer', 'min:1'],
            'detail_per_page' => ['nullable', Rule::in([50, 100, '50', '100'])],
            'detail_keyword' => ['nullable', 'string', 'max:191'],
            'detail_sort' => ['nullable', 'string', 'max:64'],
            'detail_direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'detail_logistics_status' => ['nullable', 'string', 'max:100'],
            'detail_payment_method' => ['nullable', Rule::in(['', 'paypal', 'credit_card'])],
            'detail_status' => ['nullable', 'string', 'max:100'],
            'detail_source_reason' => ['nullable', 'string', 'max:255'],
            'detail_manual_reason' => ['nullable', 'string', 'max:255'],
            'detail_type' => ['nullable', 'string', 'max:100'],
            'detail_outcome' => ['nullable', 'string', 'max:100'],
        ]);
        $filters = $this->normaliseProductRiskFilters($validated);
        $detail = $this->productRisk->detail($filters, (string) $validated['product_id'], [
            'tab' => (string) ($validated['detail_tab'] ?? 'overview'),
            'page' => max(1, (int) ($validated['detail_page'] ?? 1)),
            'per_page' => (int) ($validated['detail_per_page'] ?? 50),
            'sort' => trim((string) ($validated['detail_sort'] ?? '')),
            'direction' => (string) ($validated['detail_direction'] ?? 'desc'),
            'keyword' => trim((string) ($validated['detail_keyword'] ?? '')),
            'logistics_status' => trim((string) ($validated['detail_logistics_status'] ?? '')),
            'payment_method' => trim((string) ($validated['detail_payment_method'] ?? '')),
            'status' => trim((string) ($validated['detail_status'] ?? '')),
            'source_reason' => trim((string) ($validated['detail_source_reason'] ?? '')),
            'manual_reason' => trim((string) ($validated['detail_manual_reason'] ?? '')),
            'type' => trim((string) ($validated['detail_type'] ?? '')),
            'outcome' => trim((string) ($validated['detail_outcome'] ?? '')),
        ]);

        return $detail === null
            ? response()->json(['message' => __('payment.product_risk_profit.detail.not_found')], 404)
            : response()->json($detail);
    }

    public function productAlerts(Request $request): Response
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }

        $filters = $this->productAlertFilters($request);

        return response()->view('portals.payment.product-alerts', [
            ...$this->viewContext($context),
            'dashboard' => $this->productAlerts->dashboard(
                $filters,
                max(1, $request->integer('page', 1)),
                in_array($request->integer('per_page', 50), [50, 100], true) ? $request->integer('per_page', 50) : 50,
            ),
        ]);
    }

    public function exportProductRiskProfit(Request $request): Response
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }

        return $this->productAlertExport->download(
            $this->productAlerts->exportRows(),
        );
    }

    public function exportProductAlerts(Request $request): Response
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }

        return $this->productAlertExport->downloadAlerts(
            $this->productAlerts->exportAlertRows(),
        );
    }

    public function updateProductAlertStatus(Request $request, string $alert): Response
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) {
            return redirect()->route('payment.product-alerts.index');
        }
        abort_unless($this->productAlerts->hasAlert($alert), 404);
        $request->merge(['remark' => trim((string) $request->input('remark'))]);
        $validated = $request->validate([
            'status' => ['required', Rule::in(['stopped', 'observing', 'backend_optimized', 'other', 'completed'])],
            'remark' => ['required', 'string', 'max:1000'],
        ]);
        $status = (string) $validated['status'];
        $this->productAlerts->updateStatus($alert, $status, 'process', (string) $validated['remark'], $context['user']);

        if ($request->expectsJson()) {
            $workflowStatus = $status === 'completed' ? 'completed' : 'processing';
            $updatedAt = now('Asia/Shanghai')->format('Y-m-d H:i:s');

            return response()->json([
                'message' => __('payment.product_alerts.status_update.success'),
                'status' => $status,
                'status_label' => __('payment.product_alerts.statuses.'.$status),
                'workflow_status' => $workflowStatus,
                'workflow_status_label' => __('payment.product_alerts.statuses.'.$workflowStatus),
                'history_line' => __('payment.product_alerts.history.line', [
                    'status' => __('payment.product_alerts.statuses.'.$status),
                    'remark' => (string) $validated['remark'],
                    'actor' => (string) $context['user']->name,
                    'time' => $updatedAt,
                ]),
            ]);
        }

        return redirect()->route('payment.product-alerts.index')
            ->with('success', __('payment.product_alerts.status_update.success'));
    }

    public function productAlertDetail(Request $request, string $alert): Response
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }
        $record = $this->productAlerts->find($alert);
        abort_if($record === null, 404);

        if ($request->ajax()) {
            return response()->view('portals.payment.partials.product-alert-detail-content', ['alert' => $record]);
        }

        return response()->view('portals.payment.product-alert-detail', [
            ...$this->viewContext($context),
            'alert' => $record,
        ]);
    }

    public function paymentOrders(Request $request): Response
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }

        return response()->view('portals.payment.payment-orders', [
            ...$this->viewContext($context),
            'dashboard' => $this->paymentOrders->page($this->paymentOrderFilters($request)),
        ]);
    }

    public function renewalAnalysis(Request $request): Response
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }

        return response()->view('portals.payment.renewal-analysis', [
            ...$this->viewContext($context),
            'dashboard' => $this->renewalAnalysis->dashboard($this->renewalAnalysisFilters($request)),
        ]);
    }

    public function exportRenewalAnalysis(Request $request): Response
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }

        return $this->renewalAnalysisExport->download($this->renewalAnalysisFilters($request));
    }

    public function paymentOrderDetail(Request $request): Response
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }
        $validated = $request->validate(['record_key' => ['required', 'string', 'regex:/^(paypal|card):\d+$/']]);
        $detail = $this->paymentOrders->detail((string) $validated['record_key']);

        return $detail === null
            ? response()->json(['message' => __('payment.payment_orders.detail.not_found')], 404)
            : response()->json($detail);
    }

    private function page(Request $request, string $view, array $data): Response
    {
        $context = $this->portalContext($request);

        if ($context instanceof Response) {
            return $context;
        }

        return response()->view($view, [...$this->viewContext($context), ...$data]);
    }

    /** @return array{portal: \App\Models\Portal, user: AdminUser}|Response */
    private function portalContext(Request $request): array|Response
    {
        $context = $this->portalEntries->portalContext($request);

        if ($context instanceof Response) {
            return $context;
        }

        $this->payment->assertPaymentPortal($context['portal']);

        return $context;
    }

    /** @param array{portal: \App\Models\Portal, user: AdminUser} $context
     *  @return array{portal: \App\Models\Portal, user: AdminUser}
     */
    private function viewContext(array $context): array
    {
        $context['portal']->loadMissing('menus');

        return $context;
    }

    /** @return array{module:string,account:string,reasons:list<string>,period_start:string,period_end:string,date_mode:string,import_status:string,page:int,per_page:int} */
    private function accountRiskFilters(Request $request): array
    {
        if ($request->has('reason') && ! is_array($request->input('reason'))) {
            $request->merge(['reason' => [$request->input('reason')]]);
        }

        $validated = $request->validate([
            'module' => ['nullable', Rule::in(['paypal', 'credit_card'])],
            'account' => ['nullable', 'string', 'max:191'],
            'reason' => ['nullable', 'array', 'max:50'],
            'reason.*' => ['string', 'max:191'],
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date', 'after_or_equal:period_start'],
            'date_mode' => ['nullable', Rule::in(['order', 'event'])],
            'import_status' => ['nullable', Rule::in(['', 'never', 'success', 'partial', 'failed'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', Rule::in([50, 100, '50', '100'])],
        ]);
        $today = now('Asia/Shanghai');

        return [
            'module' => (string) ($validated['module'] ?? 'paypal'),
            'account' => trim((string) ($validated['account'] ?? '')),
            'reasons' => collect($validated['reason'] ?? [])
                ->map(static fn ($reason): string => trim((string) $reason))
                ->filter()
                ->unique()
                ->values()
                ->all(),
            'period_start' => (string) ($validated['period_start'] ?? $today->copy()->subDays(29)->format('Y-m-d')),
            'period_end' => (string) ($validated['period_end'] ?? $today->format('Y-m-d')),
            'date_mode' => (string) ($validated['date_mode'] ?? 'order'),
            'import_status' => (string) ($validated['import_status'] ?? ''),
            'page' => max(1, (int) ($validated['page'] ?? 1)),
            'per_page' => (int) ($validated['per_page'] ?? 50),
        ];
    }

    /** @return array<string,mixed> */
    private function validatedAccount(Request $request, ?LogisticsPayPalAccount $account = null): array
    {
        foreach (['account_name', 'merchant_id', 'account_email', 'relay_url', 'relay_certificate_thumbprint_sha1', 'relay_hmac_secret'] as $field) {
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

        $secretRequired = ! $account || blank($account->relay_hmac_secret);
        $data = $request->validate([
            'account_name' => ['required', 'string', 'max:191'],
            'merchant_id' => ['required', 'string', 'max:100', $merchantUnique],
            'account_email' => ['nullable', 'email', 'max:191', $emailUnique],
            'environment' => ['required', Rule::in([LogisticsPayPalAccount::ENVIRONMENT_LIVE, LogisticsPayPalAccount::ENVIRONMENT_SANDBOX])],
            'is_enabled' => ['required', 'boolean'],
            'relay_url' => ['required', 'string', 'max:500', 'url', 'regex:/^https:\/\//i'],
            'relay_certificate_thumbprint_sha1' => ['required', 'regex:/^[A-Fa-f0-9]{40}$/'],
            'relay_hmac_secret' => [
                $secretRequired ? 'required' : 'nullable',
                'string',
                'max:512',
                static function (string $attribute, mixed $value, \Closure $fail): void {
                    if (blank($value)) {
                        return;
                    }
                    $decoded = base64_decode((string) $value, true);
                    if ($decoded === false || strlen($decoded) !== 32) {
                        $fail('中转 HMAC 密钥必须是有效的 32 字节 Base64 值。');
                    }
                },
            ],
        ]);

        $result = [
            ...$data,
            'merchant_id' => mb_strtoupper((string) $data['merchant_id']),
            'account_email' => blank($data['account_email'] ?? null) ? null : mb_strtolower((string) $data['account_email']),
            'is_enabled' => (bool) $data['is_enabled'],
            'relay_certificate_thumbprint_sha1' => mb_strtoupper((string) $data['relay_certificate_thumbprint_sha1']),
        ];
        if (blank($result['relay_hmac_secret'] ?? null)) {
            unset($result['relay_hmac_secret']);
        }

        return $result;
    }

    /** @return array<string,mixed> */
    private function paymentOrderFilters(Request $request): array
    {
        $validated = $request->validate([
            'keyword' => ['nullable', 'string', 'max:191'],
            'payment_gateway' => ['nullable', 'string', 'max:100'],
            'deduction_type' => ['nullable', Rule::in(['', 'one_time', 'subscription', 'renewal', 'unmatched'])],
            'date_mode' => ['nullable', Rule::in(['order', 'paid'])],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'sort' => ['nullable', Rule::in(['', 'paid_at', 'amount', 'transaction_id', 'account', 'channel'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', Rule::in([50, 100, '50', '100'])],
        ]);
        $today = now('Asia/Shanghai');

        return [
            'keyword' => trim((string) ($validated['keyword'] ?? '')),
            'payment_gateway' => trim((string) ($validated['payment_gateway'] ?? '')),
            'deduction_type' => (string) ($validated['deduction_type'] ?? ''),
            'date_mode' => (string) ($validated['date_mode'] ?? 'paid'),
            'date_from' => (string) ($validated['date_from'] ?? $today->copy()->subDays(29)->format('Y-m-d')),
            'date_to' => (string) ($validated['date_to'] ?? $today->format('Y-m-d')),
            'sort' => (string) ($validated['sort'] ?? 'paid_at'),
            'direction' => (string) ($validated['direction'] ?? 'desc'),
            'page' => max(1, (int) ($validated['page'] ?? 1)),
            'per_page' => (int) ($validated['per_page'] ?? 50),
        ];
    }

    /** @return array<string,mixed> */
    private function renewalAnalysisFilters(Request $request): array
    {
        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'risk_date_mode' => ['nullable', Rule::in(['payment', 'event'])],
            'channel' => ['nullable', Rule::in(['', 'paypal', 'credit_card'])],
            'detail_channel' => ['nullable', Rule::in(['', 'paypal', 'credit_card'])],
            'stage' => ['nullable', 'regex:/^(renewal_\d+)?$/'],
            'store' => ['nullable', 'string', 'max:191'],
            'keyword' => ['nullable', 'string', 'max:191'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', Rule::in([50, 100, '50', '100'])],
        ]);
        $today = now('Asia/Shanghai');

        return [
            'date_from' => (string) ($validated['date_from'] ?? $today->copy()->subDays(89)->format('Y-m-d')),
            'date_to' => (string) ($validated['date_to'] ?? $today->format('Y-m-d')),
            'risk_date_mode' => (string) ($validated['risk_date_mode'] ?? 'payment'),
            'channel' => (string) ($validated['channel'] ?? ''),
            'detail_channel' => (string) ($validated['detail_channel'] ?? ''),
            'stage' => (string) ($validated['stage'] ?? ''),
            'store' => trim((string) ($validated['store'] ?? '')),
            'keyword' => trim((string) ($validated['keyword'] ?? '')),
            'page' => max(1, (int) ($validated['page'] ?? 1)),
            'per_page' => (int) ($validated['per_page'] ?? 50),
        ];
    }

    /** @return array<string,mixed> */
    private function productRiskFilters(Request $request): array
    {
        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'date_mode' => ['nullable', Rule::in(['order', 'event'])],
            'store' => ['nullable', 'string', 'max:191'],
            'name' => ['nullable', 'string', 'max:191'],
            'keyword' => ['nullable', 'string', 'max:191'],
            'only_abnormal' => ['nullable', Rule::in(['', '1'])],
            'sort' => ['nullable', Rule::in(['', 'name', 'product_code', 'shop_count', 'sales', 'revenue', 'paypal_refund_amount', 'paypal_refund_rate', 'paypal_dispute_count', 'paypal_dispute_rate', 'credit_card_refund_amount', 'credit_card_refund_rate', 'combined_refund_rate'])],
            'direction' => ['nullable', Rule::in(['', 'asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', Rule::in([50, 100, '50', '100'])],
        ]);

        return $this->normaliseProductRiskFilters($validated);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function normaliseProductRiskFilters(array $input): array
    {
        $today = now('Asia/Shanghai');

        return [
            'date_from' => (string) ($input['date_from'] ?? $today->copy()->subDays(29)->format('Y-m-d')),
            'date_to' => (string) ($input['date_to'] ?? $today->format('Y-m-d')),
            'date_mode' => (string) ($input['date_mode'] ?? 'order'),
            'store' => trim((string) ($input['store'] ?? '')),
            'name' => trim((string) ($input['name'] ?? '')),
            'keyword' => trim((string) ($input['keyword'] ?? '')),
            'match_status' => '',
            'sort' => trim((string) ($input['sort'] ?? '')) ?: 'revenue',
            'direction' => trim((string) ($input['direction'] ?? '')) ?: 'desc',
            'page' => max(1, (int) ($input['page'] ?? 1)),
            'per_page' => (int) ($input['per_page'] ?? 50),
            'only_abnormal' => (string) ($input['only_abnormal'] ?? ''),
        ];
    }

    /** @return array<string,mixed> */
    private function productAlertFilters(Request $request): array
    {
        $flowStatus = trim((string) $request->query('flow_status', 'open'));
        $processingStatus = trim((string) $request->query('processing_status', ''));
        $channel = trim((string) $request->query('channel', ''));
        $severity = trim((string) $request->query('severity', ''));
        $sample = trim((string) $request->query('sample', '50_500'));
        return [
            'flow_status' => in_array($flowStatus, ['open', 'completed', 'all'], true) ? $flowStatus : 'open',
            'processing_status' => in_array($processingStatus, ['pending', 'stopped', 'observing', 'backend_optimized', 'other', 'completed'], true) ? $processingStatus : '',
            'channel' => in_array($channel, ['paypal', 'credit_card'], true) ? $channel : '',
            'severity' => in_array($severity, ['warning', 'critical'], true) ? $severity : '',
            'sample' => in_array($sample, ['under_50', '50_500', 'over_500'], true) ? $sample : '50_500',
            'product' => mb_substr(trim((string) $request->query('product', '')), 0, 191),
        ];
    }

}
