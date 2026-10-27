<?php

namespace App\Portals\Payment\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\LogisticsPayPalAccount;
use App\Models\PaymentProductAlertRule;
use App\Models\PaymentProductAlertAssignment;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\Payment\PaymentAccountRiskService;
use App\Portals\Payment\PaymentOrderSearchService;
use App\Portals\Payment\PaymentPortalService;
use App\Portals\Payment\PaymentProductRiskService;
use App\Portals\Payment\PaymentProductAlertAssignmentService;
use App\Portals\Payment\PaymentWorkbenchService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class PaymentPortalController extends Controller
{
    public function __construct(
        private readonly PortalEntryController $portalEntries,
        private readonly PaymentPortalService $payment,
        private readonly PaymentAccountRiskService $accountRisk,
        private readonly PaymentProductRiskService $productRisk,
        private readonly PaymentOrderSearchService $paymentOrders,
        private readonly PaymentProductAlertAssignmentService $alertAssignments,
        private readonly PaymentWorkbenchService $workbench,
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
            'dashboard' => $this->accountRisk->dashboard($filters),
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

    public function accountRiskDetail(Request $request, LogisticsPayPalAccount $account): Response
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }

        $validated = $request->validate([
            'tab' => ['nullable', Rule::in(['overview', 'orders', 'products', 'refunds', 'disputes'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', Rule::in([50, 100, '50', '100'])],
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date', 'after_or_equal:period_start'],
            'keyword' => ['nullable', 'string', 'max:191'],
        ]);
        $today = now('Asia/Shanghai');

        return response()->json($this->accountRisk->detail(
            $account,
            [
                'date_from' => (string) ($validated['period_start'] ?? $today->copy()->subDays(29)->format('Y-m-d')),
                'date_to' => (string) ($validated['period_end'] ?? $today->format('Y-m-d')),
                'date_mode' => 'order',
            ],
            (string) ($validated['tab'] ?? 'overview'),
            (int) ($validated['page'] ?? 1),
            (int) ($validated['per_page'] ?? 50),
            ['keyword' => trim((string) ($validated['keyword'] ?? ''))],
        ));
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
            'store' => ['nullable', 'string', 'max:191'],
            'name' => ['nullable', 'string', 'max:191'],
            'keyword' => ['nullable', 'string', 'max:191'],
            'detail_tab' => ['nullable', Rule::in(['overview', 'first_renewal', 'paypal_orders', 'credit_card_orders', 'refunds', 'disputes', 'orders'])],
            'detail_page' => ['nullable', 'integer', 'min:1'],
            'detail_per_page' => ['nullable', Rule::in([50, 100, '50', '100'])],
            'detail_keyword' => ['nullable', 'string', 'max:191'],
        ]);
        $filters = $this->normaliseProductRiskFilters($validated);
        $detail = $this->productRisk->detail($filters, (string) $validated['product_id'], [
            'tab' => (string) ($validated['detail_tab'] ?? 'overview'),
            'page' => max(1, (int) ($validated['detail_page'] ?? 1)),
            'per_page' => (int) ($validated['detail_per_page'] ?? 50),
            'sort' => '', 'direction' => '',
            'keyword' => trim((string) ($validated['detail_keyword'] ?? '')),
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

        return response()->view('portals.payment.product-alerts', [
            ...$this->viewContext($context),
            'dashboard' => $this->productAlertsData(
                max(1, $request->integer('page', 1)),
                in_array($request->integer('per_page', 50), [50, 100], true) ? $request->integer('per_page', 50) : 50,
            ),
            'alertRules' => PaymentProductAlertRule::query()->orderBy('id')->get(),
            'assignmentContext' => $this->alertAssignments->context($context['portal'], $context['user']),
        ]);
    }

    public function assignProductAlert(Request $request, string $alert): RedirectResponse
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) {
            return redirect()->route('payment.product-alerts.index');
        }
        abort_unless(collect($this->baseProductAlerts())->contains('code', $alert), 404);

        $validated = $request->validate([
            'department_id' => ['required', 'integer'],
            'assignee_id' => ['required', 'integer'],
            'deadline_at' => ['required', 'date', 'after:now'],
            'assignment_note' => ['nullable', 'string', 'max:1000'],
        ]);
        $this->alertAssignments->assign($context['portal'], $context['user'], $alert, $validated);

        return redirect()->route('payment.product-alerts.index')
            ->with('success', __('payment.product_alerts.assignment.save_success', ['code' => $alert]));
    }

    public function updateProductAlertRules(Request $request): RedirectResponse
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) {
            return redirect()->route('payment.product-alerts.index');
        }

        $validated = $request->validate([
            'rules' => ['required', 'array', 'min:1'],
            'rules.*.yellow_condition' => ['required', 'string', 'max:255'],
            'rules.*.red_condition' => ['required', 'string', 'max:255'],
            'rules.*.minimum_sample' => ['required', 'integer', 'min:0', 'max:1000000'],
            'rules.*.action_text' => ['nullable', 'string', 'max:500'],
            'rules.*.is_enabled' => ['required', 'boolean'],
        ]);
        $ruleIds = PaymentProductAlertRule::query()->pluck('id')->map(static fn ($id): string => (string) $id)->all();
        $submittedIds = array_keys($validated['rules']);
        if (array_diff($submittedIds, $ruleIds) !== []) {
            return back()->withErrors(['rules' => __('payment.product_alerts.rule_modal.invalid_rule')])->withInput();
        }

        DB::connection(config('admin.database.connection') ?: config('database.default'))->transaction(function () use ($validated, $context): void {
            foreach ($validated['rules'] as $id => $values) {
                PaymentProductAlertRule::query()->whereKey((int) $id)->update([
                    'yellow_condition' => trim((string) $values['yellow_condition']),
                    'red_condition' => trim((string) $values['red_condition']),
                    'minimum_sample' => (int) $values['minimum_sample'],
                    'action_text' => trim((string) ($values['action_text'] ?? '')),
                    'is_enabled' => (bool) $values['is_enabled'],
                    'updated_by' => $context['user']->getKey(),
                    'updated_by_name' => $context['user']->name,
                    'updated_at' => now(),
                ]);
            }
        });

        return redirect()->route('payment.product-alerts.index')
            ->with('success', __('payment.product_alerts.rule_modal.save_success'));
    }

    public function productAlertDetail(Request $request, string $alert): Response
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }
        $record = collect($this->productAlertsData()['alerts'])
            ->first(static fn (array $row): bool => $row['code'] === $alert);
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
        $context['portal']->load('menus');

        return $context;
    }

    /** @return array{account:string,period_start:string,period_end:string,import_status:string,page:int,per_page:int} */
    private function accountRiskFilters(Request $request): array
    {
        $validated = $request->validate([
            'account' => ['nullable', 'string', 'max:191'],
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date', 'after_or_equal:period_start'],
            'import_status' => ['nullable', Rule::in(['', 'never', 'success', 'partial', 'failed'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', Rule::in([50, 100, '50', '100'])],
        ]);
        $today = now('Asia/Shanghai');

        return [
            'account' => trim((string) ($validated['account'] ?? '')),
            'period_start' => (string) ($validated['period_start'] ?? $today->copy()->subDays(29)->format('Y-m-d')),
            'period_end' => (string) ($validated['period_end'] ?? $today->format('Y-m-d')),
            'import_status' => (string) ($validated['import_status'] ?? ''),
            'page' => max(1, (int) ($validated['page'] ?? 1)),
            'per_page' => (int) ($validated['per_page'] ?? 50),
        ];
    }

    /** @return array{account_name:string,merchant_id:string,account_email:?string,environment:string,is_enabled:bool} */
    private function validatedAccount(Request $request): array
    {
        foreach (['account_name', 'merchant_id', 'account_email'] as $field) {
            if ($request->has($field)) {
                $request->merge([$field => trim((string) $request->input($field))]);
            }
        }

        $data = $request->validate([
            'account_name' => ['required', 'string', 'max:191'],
            'merchant_id' => ['required', 'string', 'max:100', Rule::unique(LogisticsPayPalAccount::class, 'merchant_id')],
            'account_email' => ['nullable', 'email', 'max:191', Rule::unique(LogisticsPayPalAccount::class, 'account_email')],
            'environment' => ['required', Rule::in([LogisticsPayPalAccount::ENVIRONMENT_LIVE, LogisticsPayPalAccount::ENVIRONMENT_SANDBOX])],
            'is_enabled' => ['required', 'boolean'],
        ]);

        return [
            ...$data,
            'merchant_id' => mb_strtoupper((string) $data['merchant_id']),
            'account_email' => blank($data['account_email'] ?? null) ? null : mb_strtolower((string) $data['account_email']),
            'is_enabled' => (bool) $data['is_enabled'],
        ];
    }

    /** @return array<string,mixed> */
    private function paymentOrderFilters(Request $request): array
    {
        $validated = $request->validate([
            'keyword' => ['nullable', 'string', 'max:191'],
            'account' => ['nullable', 'string', 'max:255'],
            'channel' => ['nullable', Rule::in(['', 'paypal', 'card'])],
            'deduction_type' => ['nullable', Rule::in(['', 'one_time', 'subscription', 'renewal', 'unmatched'])],
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
            'account' => (string) ($validated['account'] ?? ''),
            'channel' => (string) ($validated['channel'] ?? ''),
            'deduction_type' => (string) ($validated['deduction_type'] ?? ''),
            'date_from' => (string) ($validated['date_from'] ?? $today->copy()->subDays(29)->format('Y-m-d')),
            'date_to' => (string) ($validated['date_to'] ?? $today->format('Y-m-d')),
            'sort' => (string) ($validated['sort'] ?? 'paid_at'),
            'direction' => (string) ($validated['direction'] ?? 'desc'),
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
            'store' => trim((string) ($input['store'] ?? '')),
            'name' => trim((string) ($input['name'] ?? '')),
            'keyword' => trim((string) ($input['keyword'] ?? '')),
            'match_status' => '',
            'sort' => trim((string) ($input['sort'] ?? '')),
            'direction' => trim((string) ($input['direction'] ?? '')),
            'page' => max(1, (int) ($input['page'] ?? 1)),
            'per_page' => (int) ($input['per_page'] ?? 50),
            'only_abnormal' => (string) ($input['only_abnormal'] ?? ''),
        ];
    }

    /** @return array<string, mixed> */
    private function productAlertsData(int $page = 1, int $perPage = 50): array
    {
        $alerts = $this->baseProductAlerts();
        $assignments = PaymentProductAlertAssignment::query()
            ->whereIn('alert_code', array_column($alerts, 'code'))->get()->keyBy('alert_code');
        $alerts = array_map(function (array $alert) use ($assignments): array {
            $assignment = $assignments->get($alert['code']);
            if (!$assignment) {
                return $alert;
            }
            $alert['owner_name'] = $assignment->assignee_name_snapshot;
            $alert['owner_note'] = $assignment->department_name_snapshot;
            $alert['owner_filter'] = (string) $assignment->assignee_id;
            $alert['status_key'] = $assignment->status;
            $alert['deadline'] = optional($assignment->deadline_at)->format('Y-m-d H:i');
            $alert['assignment'] = [
                'department_id' => (int) $assignment->department_id,
                'assignee_id' => (int) $assignment->assignee_id,
                'deadline_at' => optional($assignment->deadline_at)->format('Y-m-d\TH:i'),
                'note' => (string) $assignment->assignment_note,
            ];
            return $alert;
        }, $alerts);

        $total = count($alerts);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $lastPage);

        return [
            'kpis' => [
                ['key' => 'to_assign', 'value' => (string) collect($alerts)->where('status_key', 'to_assign')->count(), 'note_key' => 'to_assign_note', 'tone' => 'danger'],
                ['key' => 'in_progress', 'value' => (string) collect($alerts)->where('status_key', 'in_progress')->count(), 'note_key' => 'in_progress_note', 'tone' => 'warning'],
                ['key' => 'pending_review', 'value' => (string) collect($alerts)->where('status_key', 'pending_review')->count(), 'note_key' => 'pending_review_note', 'tone' => 'primary'],
                ['key' => 'overdue', 'value' => (string) collect($alerts)->filter(fn (array $row): bool => !empty($row['deadline']) && strtotime($row['deadline']) < time())->count(), 'note_key' => 'overdue_note', 'tone' => 'danger'],
            ],
            'alerts' => array_slice($alerts, ($page - 1) * $perPage, $perPage),
            'pagination' => ['current_page' => $page, 'last_page' => $lastPage, 'per_page' => $perPage, 'total' => $total, 'from' => $total === 0 ? 0 : (($page - 1) * $perPage + 1), 'to' => min($page * $perPage, $total)],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function baseProductAlerts(): array
    {
        return [
            ['code' => 'PW-001', 'severity_key' => 'critical', 'product_key' => 'vacuum', 'channel_key' => 'paypal_renewal', 'sample' => '400 / 400', 'threshold_key' => 'refund_amount_ratio', 'owner_key' => 'product_manager_a', 'status_key' => 'in_progress', 'deadline_key' => 'october_26_1800', 'action_key' => 'recent_orders'],
            ['code' => 'PW-002', 'severity_key' => 'warning', 'product_key' => 'insurance_set', 'channel_key' => 'card_one_time', 'sample' => '40 / 40', 'threshold_key' => 'refund_count_ratio', 'owner_key' => 'to_assign', 'status_key' => 'to_assign', 'deadline_key' => 'today_1800', 'action_key' => 'assign_owner'],
            ['code' => 'PW-003', 'severity_key' => 'review', 'product_key' => 'foam_cleaner', 'channel_key' => 'all_subscription', 'sample' => '80 / 80', 'threshold_key' => 'estimated_profit', 'owner_key' => 'product_manager_b', 'status_key' => 'pending_review', 'deadline_key' => 'october_27_1800', 'action_key' => 'view_review'],
        ];
    }

}
