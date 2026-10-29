<?php

namespace App\Portals\Payment;

use App\Models\AdminUser;
use App\Models\LogisticsPayPalAccount;
use Carbon\Carbon;

class PaymentWorkbenchService
{
    public function __construct(
        private readonly PaymentOrderSearchService $paymentOrders,
        private readonly PaymentProductAlertService $productAlerts,
    ) {
    }

    /** @return array<string,mixed> */
    public function dashboard(AdminUser $user): array
    {
        $today = now('Asia/Shanghai');
        $dateFrom = $today->copy()->subDays(29)->format('Y-m-d');
        $dateTo = $today->format('Y-m-d');
        $paymentSummary = $this->paymentOrders->summary([
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
        $cutoff = (clone $accounts)->max('last_imported_at');
        $formattedCutoff = $cutoff
            ? Carbon::parse((string) $cutoff, 'UTC')->timezone('Asia/Shanghai')->format('Y-m-d H:i')
            : '-';
        $activeAlerts = $this->productAlerts->workbenchAlerts(5);

        return [
            'scope_key' => 'authorized_accounts_and_stores',
            'currency' => 'USD',
            'cutoff' => $formattedCutoff,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'kpis' => [
                ['key' => 'accounts', 'value' => number_format($accountCount), 'note' => __('payment.workbench.real.accounts_note', ['count' => number_format($attentionCount)])],
                ['key' => 'recent_receipts', 'value' => '$'.number_format((float) $paymentSummary['received_amount'], 2), 'note' => __('payment.workbench.real.receipts_note', ['count' => number_format((int) $paymentSummary['payment_count'])])],
                ['key' => 'urgent_cases', 'value' => number_format((int) $paymentSummary['risk_payment_count']), 'note' => __('payment.workbench.real.risk_note'), 'tone' => 'danger'],
                ['key' => 'urgent_products', 'value' => number_format($activeAlerts['total']), 'note' => __('payment.workbench.real.review_note'), 'tone' => 'warning'],
            ],
            'todos' => collect($activeAlerts['alerts'])->map(fn (array $alert): array => [
                'title' => __('payment.workbench.real.todo_title', ['product' => $alert['product_name']]),
                'description' => __('payment.workbench.real.todo_description', [
                    'status' => __('payment.product_alerts.statuses.'.$alert['status_key']),
                    'rate' => number_format($alert['dispute_rate'], 2),
                ]),
                'tone' => 'warning',
                'url' => route('payment.product-alerts.show', ['alert' => $alert['code']]),
            ])->values()->all(),
            'status' => [
                'payments' => __('payment.workbench.real.payment_status', ['time' => $formattedCutoff]),
                'risk' => __('payment.workbench.real.risk_status', ['count' => number_format((int) $paymentSummary['risk_payment_count'])]),
                'alerts' => __('payment.workbench.real.alert_status', ['count' => number_format($activeAlerts['total'])]),
            ],
        ];
    }
}
