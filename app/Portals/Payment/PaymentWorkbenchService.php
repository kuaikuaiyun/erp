<?php

namespace App\Portals\Payment;

use App\Models\AdminUser;
use App\Models\LogisticsPayPalAccount;
use App\Models\PaymentProductAlertAssignment;

class PaymentWorkbenchService
{
    public function __construct(
        private readonly PaymentAccountRiskService $accountRisk,
        private readonly PaymentOrderSearchService $paymentOrders,
    ) {
    }

    /** @return array<string,mixed> */
    public function dashboard(AdminUser $user): array
    {
        $today = now('Asia/Shanghai');
        $dateFrom = $today->copy()->subDays(29)->format('Y-m-d');
        $dateTo = $today->format('Y-m-d');
        $accountDashboard = $this->accountRisk->dashboard([
            'account' => '', 'period_start' => $dateFrom, 'period_end' => $dateTo,
            'import_status' => '', 'page' => 1, 'per_page' => 100,
        ]);
        $paymentDashboard = $this->paymentOrders->page([
            'keyword' => '', 'account' => '', 'channel' => '', 'deduction_type' => '',
            'date_from' => $dateFrom, 'date_to' => $dateTo, 'sort' => 'paid_at',
            'direction' => 'desc', 'page' => 1, 'per_page' => 50,
        ]);
        $accounts = LogisticsPayPalAccount::query();
        $accountCount = (clone $accounts)->count();
        $attentionCount = (clone $accounts)->where(function ($query): void {
            $query->where('is_enabled', false)->orWhere('last_import_status', '!=', 'success')
                ->orWhereNull('last_import_status');
        })->count();
        $paymentSummary = $paymentDashboard['summary'];
        $pendingReview = PaymentProductAlertAssignment::query()->where('status', 'pending_review')->count();
        $todos = PaymentProductAlertAssignment::query()
            ->where('assignee_id', $user->getKey())->where('status', '!=', 'completed')
            ->orderByRaw('deadline_at IS NULL')->orderBy('deadline_at')->limit(5)->get();

        return [
            'scope_key' => 'authorized_accounts_and_stores',
            'currency' => 'USD',
            'cutoff' => $accountDashboard['cutoff'],
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'kpis' => [
                ['key' => 'accounts', 'value' => number_format($accountCount), 'note' => __('payment.workbench.real.accounts_note', ['count' => number_format($attentionCount)])],
                ['key' => 'recent_receipts', 'value' => '$'.number_format((float) $paymentSummary['received_amount'], 2), 'note' => __('payment.workbench.real.receipts_note', ['count' => number_format((int) $paymentSummary['payment_count'])])],
                ['key' => 'urgent_cases', 'value' => number_format((int) $paymentSummary['risk_payment_count']), 'note' => __('payment.workbench.real.risk_note'), 'tone' => 'danger'],
                ['key' => 'urgent_products', 'value' => number_format($pendingReview), 'note' => __('payment.workbench.real.review_note'), 'tone' => 'warning'],
            ],
            'todos' => $todos->map(fn (PaymentProductAlertAssignment $assignment): array => [
                'title' => __('payment.workbench.real.todo_title', ['code' => $assignment->alert_code]),
                'description' => __('payment.workbench.real.todo_description', [
                    'status' => __('payment.product_alerts.statuses.'.$assignment->status),
                    'deadline' => $assignment->deadline_at?->timezone('Asia/Shanghai')->format('Y-m-d H:i') ?: __('payment.workbench.real.no_deadline'),
                ]),
                'tone' => $assignment->deadline_at?->isPast() ? 'danger' : 'warning',
                'url' => route('payment.product-alerts.show', ['alert' => $assignment->alert_code]),
            ])->values()->all(),
            'status' => [
                'payments' => __('payment.workbench.real.payment_status', ['time' => $accountDashboard['cutoff']]),
                'risk' => __('payment.workbench.real.risk_status', ['count' => number_format((int) $paymentSummary['risk_payment_count'])]),
                'alerts' => __('payment.workbench.real.alert_status', ['count' => number_format($todos->count())]),
            ],
        ];
    }
}
