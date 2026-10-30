<?php

namespace App\Portals\Payment;

use App\Models\LogisticsPayPalAccount;
use App\Models\LogisticsPayPalBalanceSnapshot;
use App\Models\LogisticsOrder;
use App\Models\PaymentPayPalAccountRiskSnapshot;
use App\Portals\Logistics\PayPalRiskAnalyticsService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class PaymentAccountRiskService
{
    private const REPORT_TIMEZONE = 'America/Los_Angeles';
    private const QUALIFYING_DISPUTE_REASONS = [
        'MERCHANDISE_OR_SERVICE_NOT_RECEIVED',
        'MERCHANDISE_OR_SERVICE_NOT_AS_DESCRIBED',
    ];
    private const QUALIFYING_DISPUTE_TYPES = ['INQUIRY', 'CHARGEBACK'];

    public function __construct(
        private readonly PayPalRiskAnalyticsService $analytics,
        private readonly PaymentAccountRiskSnapshotService $riskSnapshots,
    ) {
    }

    /** @param array{account:string,period_start:string,period_end:string,date_mode:string,import_status:string,page:int,per_page:int} $filters
     *  @return array<string,mixed>
     */
    public function dashboard(array $filters): array
    {
        $accountVersion = LogisticsPayPalAccount::query()
            ->selectRaw('MAX(updated_at) as updated_at, MAX(last_imported_at) as last_imported_at, COUNT(*) as total')
            ->first();
        $cacheKey = 'payment:account-risk-dashboard:v2:'.sha1((string) json_encode([
            'filters' => $filters,
            'accounts' => [
                'updated_at' => (string) ($accountVersion->updated_at ?? ''),
                'last_imported_at' => (string) ($accountVersion->last_imported_at ?? ''),
                'total' => (int) ($accountVersion->total ?? 0),
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return Cache::flexible(
            $cacheKey,
            [60, 300],
            fn (): array => $this->buildDashboard($filters),
        );
    }

    /** @param array{account:string,period_start:string,period_end:string,date_mode:string,import_status:string,page:int,per_page:int} $filters
     *  @return array<string,mixed>
     */
    private function buildDashboard(array $filters): array
    {
        $query = LogisticsPayPalAccount::query()->orderByDesc('created_at')->orderByDesc('id');
        if ($filters['account'] !== '') {
            $keyword = $this->contains($filters['account']);
            $query->where(function ($accounts) use ($keyword): void {
                $accounts->where('account_name', 'like', $keyword)
                    ->orWhere('account_email', 'like', $keyword)
                    ->orWhere('merchant_id', 'like', $keyword);
            });
        }

        if ($filters['import_status'] !== '') {
            if ($filters['import_status'] === 'never') {
                $query->where(function ($accounts): void {
                    $accounts->whereNull('last_import_status')
                        ->orWhere('last_import_status', '')
                        ->orWhere('last_import_status', 'never');
                });
            } else {
                $query->where('last_import_status', $filters['import_status']);
            }
        }

        $accounts = $query->get();
        $dateMode = ($filters['date_mode'] ?? 'order') === 'event' ? 'event' : 'order';
        $summaryFilters = [
                'date_from' => $dateMode === 'order' ? $filters['period_start'] : '',
                'date_to' => $dateMode === 'order' ? $filters['period_end'] : '',
                'event_date_from' => $dateMode === 'event' ? $filters['period_start'] : '',
                'event_date_to' => $dateMode === 'event' ? $filters['period_end'] : '',
                'date_mode' => $dateMode,
            ];
        $cutoff = $accounts->pluck('last_imported_at')->filter()->sortDesc()->first();
        $total = $accounts->count();
        $perPage = (int) $filters['per_page'];
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, (int) $filters['page']), $lastPage);
        $pageAccounts = $accounts->forPage($page, $perPage)->values();
        $merchants = $this->cachedCurrentSummaries($pageAccounts, $summaryFilters);
        $portfolio = $pageAccounts->count() === $accounts->count()
            ? $this->portfolioFromSummaries($accounts, $merchants)
            : $this->cachedPortfolio($accounts, $summaryFilters);
        $merchantIds = $merchants->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $accountModels = $accounts->keyBy('id');
        $weeklyRiskSnapshots = $this->riskSnapshots->currentForAccounts($merchantIds);
        $balanceSnapshots = LogisticsPayPalBalanceSnapshot::query()
            ->whereIn('logistics_paypal_account_id', $merchantIds)
            ->where('currency_code', 'USD')->orderByDesc('as_of_time')->orderByDesc('id')->get()
            ->unique('logistics_paypal_account_id')->keyBy('logistics_paypal_account_id');
        $orderTimes = DB::connection(config('admin.database.connection') ?: config('database.default'))
            ->table('logistics_paypal_transactions as payments')
            ->join('logistics_orders as orders', 'orders.id', '=', 'payments.logistics_order_id')
            ->whereIn('payments.logistics_paypal_account_id', $merchantIds)
            ->where('payments.event_code', 'like', 'T00%')
            ->where('payments.status', 'S')
            ->whereNotNull('orders.paid_at')
            ->selectRaw('payments.logistics_paypal_account_id as account_id, MIN(orders.paid_at) as first_order_at, MAX(orders.paid_at) as last_order_at')
            ->groupBy('payments.logistics_paypal_account_id')
            ->get()->keyBy('account_id');

        return [
            'period_start' => $filters['period_start'],
            'period_end' => $filters['period_end'],
            'date_mode' => $dateMode,
            'cutoff' => $this->formatDateTime($cutoff),
            'filters' => $filters,
            'account_options' => $accounts->map(fn (LogisticsPayPalAccount $account): array => [
                'id' => (int) $account->getKey(),
                'name' => (string) $account->account_name,
            ])->values()->all(),
            'kpis' => [
                ['key' => 'paid_orders', 'value' => $this->integer($portfolio['orders']).' / '.$this->currency($portfolio['paid_amount']), 'note_key' => 'paid_orders_note'],
                ['key' => 'refund', 'value' => $this->currency($portfolio['refund_amount']).' / '.$this->percentage($portfolio['refund_amount_rate']), 'note_key' => 'refund_note', 'tone' => 'warning'],
                ['key' => 'full_refund', 'value' => $this->integer($portfolio['dispute_orders']).' / '.$this->percentage($portfolio['dispute_rate']), 'note_key' => 'full_refund_note', 'tone' => 'danger'],
                ['key' => 'risk_cases', 'value' => $this->integer($portfolio['unmatched_count']).' / '.$this->integer($portfolio['attention_accounts']), 'note_key' => 'risk_cases_note', 'tone' => 'danger'],
            ],
            'merchants' => $merchants->map(fn (array $summary): array => $this->merchant(
                $summary,
                $balanceSnapshots->get((int) $summary['id']),
                $orderTimes->get((int) $summary['id']),
                $accountModels->get((int) $summary['id']),
                $weeklyRiskSnapshots->get((int) $summary['id']),
            ))->values()->all(),
            'pagination' => [
                'current_page' => $page, 'last_page' => $lastPage, 'per_page' => $perPage, 'total' => $total,
                'from' => $total === 0 ? 0 : (($page - 1) * $perPage + 1), 'to' => min($page * $perPage, $total),
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function detail(LogisticsPayPalAccount $account, array $filters, string $tab, int $page, int $perPage, array $tabFilters): array
    {
        $cacheKey = 'payment:account-risk-detail:v2:'.sha1((string) json_encode([
            'account' => (int) $account->getKey(),
            'last_imported_at' => optional($account->last_imported_at)->format('Y-m-d H:i:s.u'),
            'updated_at' => optional($account->updated_at)->format('Y-m-d H:i:s.u'),
            'filters' => $filters,
            'tab' => $tab,
            'page' => $page,
            'per_page' => $perPage,
            'tab_filters' => $tabFilters,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return Cache::flexible(
            $cacheKey,
            [60, 300],
            fn (): array => $this->buildDetail($account, $filters, $tab, $page, $perPage, $tabFilters),
        );
    }

    /** @return array<string,mixed> */
    private function buildDetail(LogisticsPayPalAccount $account, array $filters, string $tab, int $page, int $perPage, array $tabFilters): array
    {
        if ($tab === 'order_analysis') {
            $summary = $this->cachedCurrentSummary($account, $filters);
            $detail = [
                'summary' => $summary,
                'tab' => $tab,
                'order_analysis' => $this->orderAnalysis($account, $filters, $summary),
            ];
        } else {
            $detail = $this->analytics->detail(
                $account,
                $filters,
                $tab,
                $page,
                (string) ($tabFilters['sort'] ?? ''),
                (string) ($tabFilters['direction'] ?? 'desc'),
                $tabFilters,
                false,
                $perPage,
            );
            if ($tab === 'overview' && !empty($detail['exceptions'])) {
                $detail['exceptions'] = $this->enrichOverviewExceptionReferences($account, $detail['exceptions']);
            }
            if ($tab === 'overview') {
                $detail['overview_month'] = (string) ($filters['overview_month'] ?? '');
            }
        }
        $balance = LogisticsPayPalBalanceSnapshot::query()
            ->where('logistics_paypal_account_id', $account->getKey())->where('currency_code', 'USD')
            ->orderByDesc('as_of_time')->orderByDesc('id')->first();
        $balanceTime = $balance?->as_of_time ?: $balance?->last_refresh_time;
        $detail['summary']['official_balance'] = $balance ? [
            'available' => (float) $balance->available_balance,
            'withheld' => (float) $balance->withheld_balance,
            'as_of_time' => $this->formatDateTime($balanceTime),
        ] : null;

        return $detail;
    }

    /** @param array<string,mixed> $filters @return array<string,mixed> */
    private function cachedCurrentSummary(LogisticsPayPalAccount $account, array $filters): array
    {
        $cacheKey = 'payment:account-risk-summary:v2:'.sha1((string) json_encode([
            'account' => (int) $account->getKey(),
            'last_imported_at' => optional($account->last_imported_at)->format('Y-m-d H:i:s.u'),
            'updated_at' => optional($account->updated_at)->format('Y-m-d H:i:s.u'),
            'filters' => [
                'date_from' => (string) ($filters['date_from'] ?? ''),
                'date_to' => (string) ($filters['date_to'] ?? ''),
                'event_date_from' => (string) ($filters['event_date_from'] ?? ''),
                'event_date_to' => (string) ($filters['event_date_to'] ?? ''),
                'date_mode' => (string) ($filters['date_mode'] ?? 'order'),
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return Cache::flexible(
            $cacheKey,
            [300, 900],
            fn (): array => $this->analytics->currentSummary($account, $filters),
        );
    }

    /**
     * Build every account card on the current page with one set of aggregate
     * queries. Detail tabs continue to use the canonical logistics analytics
     * service, while the board avoids repeating the same table scans once per
     * account.
     *
     * @param Collection<int,LogisticsPayPalAccount> $accounts
     * @return Collection<int,array<string,mixed>>
     */
    private function cachedCurrentSummaries(Collection $accounts, array $filters): Collection
    {
        if ($accounts->isEmpty()) {
            return collect();
        }

        $cacheKey = 'payment:account-risk-page-summaries:v1:'.sha1((string) json_encode([
            'accounts' => $accounts->map(static fn (LogisticsPayPalAccount $account): array => [
                'id' => (int) $account->getKey(),
                'updated_at' => optional($account->updated_at)->format('Y-m-d H:i:s.u'),
                'last_imported_at' => optional($account->last_imported_at)->format('Y-m-d H:i:s.u'),
            ])->values()->all(),
            'filters' => $filters,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $summaries = Cache::flexible(
            $cacheKey,
            [300, 900],
            fn (): array => $this->buildCurrentSummaries($accounts, $filters),
        );

        return collect($summaries);
    }

    /**
     * @param Collection<int,LogisticsPayPalAccount> $accounts
     * @return list<array<string,mixed>>
     */
    private function buildCurrentSummaries(Collection $accounts, array $filters): array
    {
        $accountIds = $accounts->pluck('id')->map(static fn ($id): int => (int) $id)->values()->all();
        $database = DB::connection(config('admin.database.connection') ?: config('database.default'));

        $payments = $database->table('logistics_paypal_transactions as payments')
            ->whereIn('payments.logistics_paypal_account_id', $accountIds)
            ->where('payments.event_code', 'like', 'T00%')
            ->where('payments.status', 'S');
        $this->applyPortfolioRange($payments, 'payments.paid_at', $filters);

        $paymentGroups = (clone $payments)
            ->selectRaw('payments.logistics_paypal_account_id as account_id')
            ->selectRaw("COALESCE(payments.payment_transaction_id, '') as payment_key")
            ->selectRaw('SUM(COALESCE(payments.payment_amount_usd, 0)) as paid_amount')
            ->selectRaw('MAX(CASE WHEN payments.logistics_order_id IS NOT NULL THEN 1 ELSE 0 END) as is_matched')
            ->selectRaw('MIN(CASE WHEN payments.logistics_order_id IS NOT NULL THEN payments.logistics_order_id END) as matched_order_id')
            ->groupByRaw("payments.logistics_paypal_account_id, COALESCE(payments.payment_transaction_id, '')");

        $paymentStats = $database->query()->fromSub(clone $paymentGroups, 'payment_groups')
            ->selectRaw('payment_groups.account_id')
            ->selectRaw('COUNT(*) as order_count')
            ->selectRaw('COALESCE(SUM(payment_groups.paid_amount), 0) as paid_amount')
            ->selectRaw('COALESCE(SUM(payment_groups.is_matched), 0) as matched_group_count')
            ->selectRaw('COALESCE(SUM(CASE WHEN payment_groups.is_matched = 0 THEN 1 ELSE 0 END), 0) as unmatched_payments')
            ->groupBy('payment_groups.account_id')
            ->get()->keyBy('account_id');

        $orderStats = $database->query()->fromSub(
            (clone $paymentGroups)->whereNotNull('payments.logistics_order_id'),
            'payment_groups',
        )->join('logistics_orders as orders', 'orders.id', '=', 'payment_groups.matched_order_id')
            ->selectRaw('payment_groups.account_id')
            ->selectRaw("COUNT(DISTINCT NULLIF(TRIM(COALESCE(orders.store_name, '')), '')) as store_count")
            ->selectRaw('COUNT(DISTINCT CASE WHEN orders.logistics_status IN (?, ?, ?) THEN orders.id END) as shipped_order_count', [
                LogisticsOrder::LOGISTICS_IN_TRANSIT,
                LogisticsOrder::LOGISTICS_EXCEPTION,
                LogisticsOrder::LOGISTICS_DELIVERED,
            ])
            ->selectRaw('COUNT(DISTINCT CASE WHEN orders.logistics_status = ? THEN orders.id END) as delivered_order_count', [
                LogisticsOrder::LOGISTICS_DELIVERED,
            ])
            ->groupBy('payment_groups.account_id')
            ->get()->keyBy('account_id');

        $eventPaymentKeys = (clone $payments)
            ->whereNotNull('payments.payment_transaction_id')
            ->whereRaw("TRIM(payments.payment_transaction_id) <> ''")
            ->selectRaw('payments.logistics_paypal_account_id as account_id')
            ->selectRaw('payments.payment_transaction_id as payment_key')
            ->distinct();

        $refunds = $database->table('logistics_paypal_refunds as refunds')
            ->whereIn('refunds.logistics_paypal_account_id', $accountIds);
        if (($filters['date_mode'] ?? 'order') === 'event') {
            $this->applyPortfolioRange($refunds, 'refunds.refunded_at', $filters);
        } else {
            $refunds->joinSub(clone $eventPaymentKeys, 'event_payments', function ($join): void {
                $join->on('event_payments.account_id', '=', 'refunds.logistics_paypal_account_id')
                    ->on('event_payments.payment_key', '=', 'refunds.original_transaction_id');
            });
        }
        $refundStats = $refunds
            ->selectRaw('refunds.logistics_paypal_account_id as account_id')
            ->selectRaw("COUNT(DISTINCT NULLIF(CASE WHEN COALESCE(refunds.original_transaction_id, '') <> '' THEN TRIM(refunds.original_transaction_id) ELSE TRIM(COALESCE(refunds.refund_transaction_id, '')) END, '')) as refund_order_count")
            ->selectRaw('COALESCE(SUM(COALESCE(refunds.refund_amount_usd, refunds.original_amount, 0)), 0) as refund_amount')
            ->selectRaw('COALESCE(SUM(CASE WHEN refunds.logistics_order_id IS NULL THEN 1 ELSE 0 END), 0) as unmatched_refunds')
            ->groupBy('refunds.logistics_paypal_account_id')
            ->get()->keyBy('account_id');

        $allDisputes = $database->table('logistics_paypal_disputes as disputes')
            ->whereIn('disputes.logistics_paypal_account_id', $accountIds);
        if (($filters['date_mode'] ?? 'order') === 'event') {
            $this->applyPortfolioRange($allDisputes, 'disputes.dispute_created_at', $filters);
        } else {
            $allDisputes->joinSub(clone $eventPaymentKeys, 'event_payments', function ($join): void {
                $join->on('event_payments.account_id', '=', 'disputes.logistics_paypal_account_id')
                    ->on('event_payments.payment_key', '=', 'disputes.disputed_transaction_id');
            });
        }

        $overallDisputeStats = (clone $allDisputes)
            ->selectRaw('disputes.logistics_paypal_account_id as account_id')
            ->selectRaw("COUNT(DISTINCT NULLIF(CASE WHEN COALESCE(disputes.disputed_transaction_id, '') <> '' THEN TRIM(disputes.disputed_transaction_id) ELSE TRIM(COALESCE(disputes.dispute_id, '')) END, '')) as overall_dispute_order_count")
            ->selectRaw('COALESCE(SUM(CASE WHEN disputes.logistics_order_id IS NULL THEN 1 ELSE 0 END), 0) as unmatched_disputes')
            ->groupBy('disputes.logistics_paypal_account_id')
            ->get()->keyBy('account_id');

        $disputeStats = (clone $allDisputes)
            ->whereIn('disputes.reason', self::QUALIFYING_DISPUTE_REASONS)
            ->whereIn('disputes.life_cycle_stage', self::QUALIFYING_DISPUTE_TYPES)
            ->selectRaw('disputes.logistics_paypal_account_id as account_id')
            ->selectRaw("COUNT(DISTINCT NULLIF(CASE WHEN COALESCE(disputes.disputed_transaction_id, '') <> '' THEN TRIM(disputes.disputed_transaction_id) ELSE TRIM(COALESCE(disputes.dispute_id, '')) END, '')) as dispute_order_count")
            ->selectRaw('COALESCE(SUM(COALESCE(disputes.dispute_amount_usd, disputes.original_amount, 0)), 0) as dispute_amount')
            ->groupBy('disputes.logistics_paypal_account_id')
            ->get()->keyBy('account_id');

        return $accounts->map(function (LogisticsPayPalAccount $account) use (
            $paymentStats,
            $orderStats,
            $refundStats,
            $overallDisputeStats,
            $disputeStats,
        ): array {
            $accountId = (int) $account->getKey();
            $payment = $paymentStats->get($accountId);
            $order = $orderStats->get($accountId);
            $refund = $refundStats->get($accountId);
            $overallDispute = $overallDisputeStats->get($accountId);
            $dispute = $disputeStats->get($accountId);
            $orders = (int) ($payment->order_count ?? 0);
            $paidAmount = round((float) ($payment->paid_amount ?? 0), 2);
            $matchedGroups = (int) ($payment->matched_group_count ?? 0);
            $refundOrders = (int) ($refund->refund_order_count ?? 0);
            $refundAmount = round((float) ($refund->refund_amount ?? 0), 2);
            $disputeOrders = (int) ($dispute->dispute_order_count ?? 0);
            $overallDisputeOrders = (int) ($overallDispute->overall_dispute_order_count ?? 0);
            $disputeAmount = round((float) ($dispute->dispute_amount ?? 0), 2);
            $shippedOrders = (int) ($order->shipped_order_count ?? 0);
            $deliveredOrders = (int) ($order->delivered_order_count ?? 0);

            return [
                'id' => $accountId,
                'account_name' => (string) $account->account_name,
                'merchant_id' => (string) ($account->merchant_id ?: '-'),
                'account_email' => (string) ($account->account_email ?: '-'),
                'masked_email' => $this->maskEmail($account->account_email),
                'environment' => (string) $account->environment,
                'is_enabled' => (bool) $account->is_enabled,
                'order_count' => $orders,
                'paid_amount' => $paidAmount,
                'refund_order_count' => $refundOrders,
                'dispute_order_count' => $disputeOrders,
                'overall_dispute_order_count' => $overallDisputeOrders,
                'refund_rate' => $orders === 0 ? 0.0 : round($refundOrders / $orders * 100, 2),
                'dispute_rate' => $orders === 0 ? 0.0 : round($disputeOrders / $orders * 100, 2),
                'overall_dispute_rate' => $orders === 0 ? 0.0 : round($overallDisputeOrders / $orders * 100, 2),
                'refund_amount' => $refundAmount,
                'refund_amount_rate' => $paidAmount <= 0 ? 0.0 : round($refundAmount / $paidAmount * 100, 2),
                'dispute_amount' => $disputeAmount,
                'dispute_amount_rate' => $paidAmount <= 0 ? 0.0 : round($disputeAmount / $paidAmount * 100, 2),
                'store_count' => (int) ($order->store_count ?? 0),
                'shipped_order_count' => $shippedOrders,
                'delivered_order_count' => $deliveredOrders,
                'shipping_rate' => $matchedGroups === 0 ? 0.0 : round($shippedOrders / $matchedGroups * 100, 2),
                'delivery_rate' => $matchedGroups === 0 ? 0.0 : round($deliveredOrders / $matchedGroups * 100, 2),
                'unmatched_count' => (int) ($payment->unmatched_payments ?? 0)
                    + (int) ($refund->unmatched_refunds ?? 0)
                    + (int) ($overallDispute->unmatched_disputes ?? 0),
                'last_imported_at' => $this->analyticsDateTime($account->last_imported_at),
                'last_import_status' => (string) ($account->last_import_status ?: 'never'),
                'last_import_message' => (string) ($account->last_import_message ?: __('logistics.paypal_risk.messages.no_import_yet')),
            ];
        })->values()->all();
    }

    /** @param list<array<string,mixed>> $exceptions @return list<array<string,mixed>> */
    private function enrichOverviewExceptionReferences(LogisticsPayPalAccount $account, array $exceptions): array
    {
        $orderIds = collect($exceptions)->pluck('order_id')->filter()->map(static fn ($id): int => (int) $id)->unique()->all();
        if ($orderIds === []) return $exceptions;
        $connection = DB::connection(config('admin.database.connection') ?: config('database.default'));
        $refunds = $connection->table('logistics_paypal_refunds')->where('logistics_paypal_account_id', $account->getKey())
            ->whereIn('logistics_order_id', $orderIds)->orderByDesc('refunded_at')->orderByDesc('id')->get(['logistics_order_id', 'refund_transaction_id'])
            ->unique('logistics_order_id')->keyBy('logistics_order_id');
        $disputes = $connection->table('logistics_paypal_disputes')->where('logistics_paypal_account_id', $account->getKey())
            ->whereIn('logistics_order_id', $orderIds)->orderByDesc('dispute_created_at')->orderByDesc('id')->get(['logistics_order_id', 'dispute_id'])
            ->unique('logistics_order_id')->keyBy('logistics_order_id');
        $refundLabel = (string) __('logistics.paypal_risk.detail.refund');

        return collect($exceptions)->map(static function (array $row) use ($refunds, $disputes, $refundLabel): array {
            $orderId = (int) ($row['order_id'] ?? 0);
            $isRefund = (string) ($row['type'] ?? '') === $refundLabel;
            $row['reference_id'] = $isRefund
                ? (string) ($refunds->get($orderId)->refund_transaction_id ?? '')
                : (string) ($disputes->get($orderId)->dispute_id ?? '');
            return $row;
        })->all();
    }

    /** @param array<string,mixed> $filters @param array<string,mixed> $summary @return array<string,mixed> */
    private function orderAnalysis(LogisticsPayPalAccount $account, array $filters, array $summary): array
    {
        $connection = DB::connection(config('admin.database.connection') ?: config('database.default'));
        $from = Carbon::parse((string) $filters['date_from'], self::REPORT_TIMEZONE)->startOfDay()->utc();
        $to = Carbon::parse((string) $filters['date_to'], self::REPORT_TIMEZONE)->endOfDay()->utc();
        $payments = $connection->table('logistics_paypal_transactions as payments')
            ->leftJoin('logistics_orders as orders', 'orders.id', '=', 'payments.logistics_order_id')
            ->where('payments.logistics_paypal_account_id', $account->getKey())
            ->where('payments.event_code', 'like', 'T00%')
            ->where('payments.status', 'S')
            ->whereBetween('payments.paid_at', [$from, $to])
            ->orderBy('payments.paid_at')->orderBy('payments.id')
            ->get([
                'payments.id as payment_row_id', 'payments.payment_transaction_id', 'payments.payment_amount_usd', 'payments.paid_at',
                'payments.logistics_order_id', 'orders.id as matched_order_id', 'orders.subscription_type',
                'orders.subscription_origin_order_id',
            ])
            ->unique(fn (object $payment): string => trim((string) $payment->payment_transaction_id) !== ''
                ? (string) $payment->payment_transaction_id
                : 'row:'.$payment->payment_row_id)
            ->values();
        $paymentIds = $payments->pluck('payment_transaction_id')->map(static fn ($id): string => trim((string) $id))->filter()->values()->all();

        $refundAmounts = $paymentIds === [] ? collect() : $connection->table('logistics_paypal_refunds')
            ->where('logistics_paypal_account_id', $account->getKey())
            ->whereIn('original_transaction_id', $paymentIds)
            ->selectRaw('original_transaction_id, SUM(COALESCE(refund_amount_usd, original_amount, 0)) as refund_amount')
            ->groupBy('original_transaction_id')->pluck('refund_amount', 'original_transaction_id');
        $disputedPayments = $paymentIds === [] ? collect() : $connection->table('logistics_paypal_disputes')
            ->where('logistics_paypal_account_id', $account->getKey())
            ->whereIn('disputed_transaction_id', $paymentIds)
            ->whereIn('reason', self::QUALIFYING_DISPUTE_REASONS)
            ->whereIn('life_cycle_stage', self::QUALIFYING_DISPUTE_TYPES)
            ->distinct()->pluck('disputed_transaction_id')->flip();

        $groups = [];
        $seenRenewals = [];
        foreach ($payments as $payment) {
            $transactionId = trim((string) $payment->payment_transaction_id);
            $origin = trim((string) ($payment->subscription_origin_order_id ?? ''));
            $type = trim((string) ($payment->subscription_type ?? ''));
            $unmatched = $payment->logistics_order_id === null || $payment->matched_order_id === null;
            $renewalNumber = null;
            if ($unmatched) {
                $key = 'unmatched';
                $sort = PHP_INT_MAX;
            } elseif ($origin === '' && ($type === '' || str_contains($type, '无订阅'))) {
                $key = 'one_time';
                $sort = 0;
            } elseif ($origin === '') {
                $key = 'first_charge';
                $sort = 1;
            } else {
                $seenRenewals[$origin] = ($seenRenewals[$origin] ?? 0) + 1;
                preg_match('/(\d+)/u', $type, $matches);
                $renewalNumber = isset($matches[1]) && (int) $matches[1] > 0 ? (int) $matches[1] : $seenRenewals[$origin];
                $key = 'renewal_'.$renewalNumber;
                $sort = $renewalNumber + 1;
            }

            $groups[$key] ??= [
                'key' => $key, 'renewal_number' => $renewalNumber, 'sort' => $sort,
                'order_count' => 0, 'received_amount' => 0.0, 'refund_amount' => 0.0,
                'dispute_count' => 0, 'unmatched' => $unmatched,
            ];
            $groups[$key]['order_count']++;
            $groups[$key]['received_amount'] += (float) ($payment->payment_amount_usd ?? 0);
            $groups[$key]['refund_amount'] += (float) ($refundAmounts[$transactionId] ?? 0);
            $groups[$key]['dispute_count'] += $disputedPayments->has($transactionId) ? 1 : 0;
        }

        foreach ($groups as &$group) {
            $group['received_amount'] = round($group['received_amount'], 2);
            $group['refund_amount'] = round($group['refund_amount'], 2);
            $group['refund_rate'] = $group['received_amount'] > 0 ? round($group['refund_amount'] / $group['received_amount'] * 100, 2) : 0.0;
            $group['dispute_rate'] = $group['order_count'] > 0 ? round($group['dispute_count'] / $group['order_count'] * 100, 2) : 0.0;
            $group['realised_loss'] = $group['refund_amount'];
        }
        unset($group);

        $expectedOrders = (int) ($summary['order_count'] ?? 0);
        $missingOrders = max(0, $expectedOrders - $payments->count());
        if ($missingOrders > 0) {
            $groupedReceived = (float) collect($groups)->sum('received_amount');
            $groupedRefund = (float) collect($groups)->sum('refund_amount');
            $groupedDisputes = (int) collect($groups)->sum('dispute_count');
            $groups['unmatched'] ??= [
                'key' => 'unmatched', 'renewal_number' => null, 'sort' => PHP_INT_MAX,
                'order_count' => 0, 'received_amount' => 0.0, 'refund_amount' => 0.0,
                'dispute_count' => 0, 'unmatched' => true,
            ];
            $groups['unmatched']['order_count'] += $missingOrders;
            $groups['unmatched']['received_amount'] += max(0, (float) ($summary['paid_amount'] ?? 0) - $groupedReceived);
            $groups['unmatched']['refund_amount'] += max(0, (float) ($summary['refund_amount'] ?? 0) - $groupedRefund);
            $groups['unmatched']['dispute_count'] += max(0, (int) ($summary['dispute_order_count'] ?? 0) - $groupedDisputes);
            $groups['unmatched']['received_amount'] = round($groups['unmatched']['received_amount'], 2);
            $groups['unmatched']['refund_amount'] = round($groups['unmatched']['refund_amount'], 2);
            $groups['unmatched']['refund_rate'] = $groups['unmatched']['received_amount'] > 0
                ? round($groups['unmatched']['refund_amount'] / $groups['unmatched']['received_amount'] * 100, 2) : 0.0;
            $groups['unmatched']['dispute_rate'] = $groups['unmatched']['order_count'] > 0
                ? round($groups['unmatched']['dispute_count'] / $groups['unmatched']['order_count'] * 100, 2) : 0.0;
            $groups['unmatched']['realised_loss'] = $groups['unmatched']['refund_amount'];
        }
        uasort($groups, static fn (array $left, array $right): int => $left['sort'] <=> $right['sort']);

        $previousSubscriptionCount = null;
        foreach ($groups as &$group) {
            $group['churn'] = null;
            if ($group['key'] === 'first_charge') {
                $previousSubscriptionCount = (int) $group['order_count'];
                continue;
            }
            if (!str_starts_with((string) $group['key'], 'renewal_') || $previousSubscriptionCount === null) {
                continue;
            }

            $currentCount = (int) $group['order_count'];
            $churnCount = max(0, $previousSubscriptionCount - $currentCount);
            $group['churn'] = [
                'previous_count' => $previousSubscriptionCount,
                'current_count' => $currentCount,
                'lost_count' => $churnCount,
                'lost_rate' => $previousSubscriptionCount > 0
                    ? round($churnCount / $previousSubscriptionCount * 100, 2)
                    : 0.0,
                'has_growth' => $currentCount > $previousSubscriptionCount,
            ];
            $previousSubscriptionCount = $currentCount;
        }
        unset($group);

        $unmatchedOrders = (int) ($groups['unmatched']['order_count'] ?? 0);

        return [
            'groups' => array_values($groups),
            'total_orders' => $expectedOrders,
            'classified_orders' => max(0, $expectedOrders - $unmatchedOrders),
            'unmatched_orders' => $unmatchedOrders,
        ];
    }

    /** @param array{account_name:string,merchant_id:string,account_email:?string,environment:string,is_enabled:bool} $data */
    public function createAccount(array $data, int|string|null $userId, ?string $userName): LogisticsPayPalAccount
    {
        return LogisticsPayPalAccount::query()->create([
            ...$data,
            'created_by' => $userId,
            'created_by_name' => $userName,
            'updated_by' => $userId,
            'updated_by_name' => $userName,
        ]);
    }

    /** @param array{account_name:string,merchant_id:string,account_email:?string,environment:string,is_enabled:bool} $data */
    public function updateAccount(LogisticsPayPalAccount $account, array $data, int|string|null $userId, ?string $userName): LogisticsPayPalAccount
    {
        $account->fill([
            ...$data,
            'updated_by' => $userId,
            'updated_by_name' => $userName,
        ])->save();

        return $account->refresh();
    }

    /** @param Collection<int,LogisticsPayPalAccount> $accounts
     *  @return array<string,int|float>
     */
    private function cachedPortfolio(Collection $accounts, array $filters): array
    {
        $cacheKey = 'payment:account-risk-portfolio:v1:'.sha1((string) json_encode([
            'accounts' => $accounts->map(static fn (LogisticsPayPalAccount $account): array => [
                'id' => (int) $account->getKey(),
                'enabled' => (bool) $account->is_enabled,
                'status' => (string) $account->last_import_status,
                'updated_at' => optional($account->updated_at)->format('Y-m-d H:i:s.u'),
                'last_imported_at' => optional($account->last_imported_at)->format('Y-m-d H:i:s.u'),
            ])->values()->all(),
            'filters' => $filters,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return Cache::flexible(
            $cacheKey,
            [300, 900],
            fn (): array => $this->buildPortfolio($accounts, $filters),
        );
    }

    /**
     * Reuse the already-built page aggregates when the page contains every
     * account. This is the common case and avoids immediately repeating the
     * same financial scans for the KPI row.
     *
     * @param Collection<int,LogisticsPayPalAccount> $accounts
     * @param Collection<int,array<string,mixed>> $summaries
     * @return array<string,int|float>
     */
    private function portfolioFromSummaries(Collection $accounts, Collection $summaries): array
    {
        $orders = (int) $summaries->sum('order_count');
        $paidAmount = round((float) $summaries->sum('paid_amount'), 2);
        $refundAmount = round((float) $summaries->sum('refund_amount'), 2);
        $disputeOrders = (int) $summaries->sum('dispute_order_count');

        return [
            'orders' => $orders,
            'paid_amount' => $paidAmount,
            'refund_amount' => $refundAmount,
            'refund_amount_rate' => $paidAmount <= 0 ? 0.0 : round($refundAmount / $paidAmount * 100, 2),
            'dispute_orders' => $disputeOrders,
            'dispute_rate' => $orders === 0 ? 0.0 : round($disputeOrders / $orders * 100, 2),
            'unmatched_count' => (int) $summaries->sum('unmatched_count'),
            'attention_accounts' => $accounts->filter(
                fn (LogisticsPayPalAccount $account): bool => (bool) $account->is_enabled
                    && (string) $account->last_import_status !== 'success',
            )->count(),
        ];
    }

    /** @param Collection<int,LogisticsPayPalAccount> $accounts
     *  @return array<string,int|float>
     */
    private function buildPortfolio(Collection $accounts, array $filters): array
    {
        $accountIds = $accounts->pluck('id')->map(static fn ($id): int => (int) $id)->filter()->values()->all();
        $empty = [
            'orders' => 0,
            'paid_amount' => 0.0,
            'refund_amount' => 0.0,
            'refund_amount_rate' => 0.0,
            'dispute_orders' => 0,
            'dispute_rate' => 0.0,
            'unmatched_count' => 0,
            'attention_accounts' => $accounts->filter(
                fn (LogisticsPayPalAccount $account): bool => (bool) $account->is_enabled
                    && (string) $account->last_import_status !== 'success',
            )->count(),
        ];
        if ($accountIds === []) {
            return $empty;
        }

        $database = DB::connection(config('admin.database.connection') ?: config('database.default'));
        $payments = $database->table('logistics_paypal_transactions as payments')
            ->whereIn('payments.logistics_paypal_account_id', $accountIds)
            ->where('payments.event_code', 'like', 'T00%')
            ->where('payments.status', 'S');
        $this->applyPortfolioRange($payments, 'payments.paid_at', $filters);

        $paymentGroups = (clone $payments)
            ->selectRaw('payments.logistics_paypal_account_id as account_id')
            ->selectRaw("COALESCE(payments.payment_transaction_id, '') as payment_key")
            ->selectRaw('SUM(COALESCE(payments.payment_amount_usd, 0)) as paid_amount')
            ->selectRaw('MAX(CASE WHEN payments.logistics_order_id IS NOT NULL THEN 1 ELSE 0 END) as is_matched')
            ->groupByRaw("payments.logistics_paypal_account_id, COALESCE(payments.payment_transaction_id, '')");
        $paymentStats = $database->query()->fromSub($paymentGroups, 'payment_groups')
            ->selectRaw('COUNT(*) as order_count')
            ->selectRaw('COALESCE(SUM(payment_groups.paid_amount), 0) as paid_amount')
            ->selectRaw('COALESCE(SUM(CASE WHEN payment_groups.is_matched = 0 THEN 1 ELSE 0 END), 0) as unmatched_count')
            ->first();

        $eventPaymentKeys = (clone $payments)
            ->whereNotNull('payments.payment_transaction_id')
            ->whereRaw("TRIM(payments.payment_transaction_id) <> ''")
            ->selectRaw('payments.logistics_paypal_account_id as account_id')
            ->selectRaw('payments.payment_transaction_id as payment_key')
            ->distinct();

        $refunds = $database->table('logistics_paypal_refunds as refunds')
            ->whereIn('refunds.logistics_paypal_account_id', $accountIds);
        if (($filters['date_mode'] ?? 'order') === 'event') {
            $this->applyPortfolioRange($refunds, 'refunds.refunded_at', $filters);
        } else {
            $refunds->joinSub(clone $eventPaymentKeys, 'event_payments', function ($join): void {
                $join->on('event_payments.account_id', '=', 'refunds.logistics_paypal_account_id')
                    ->on('event_payments.payment_key', '=', 'refunds.original_transaction_id');
            });
        }
        $refundStats = $refunds
            ->selectRaw("COUNT(DISTINCT NULLIF(CONCAT(refunds.logistics_paypal_account_id, ':', CASE WHEN COALESCE(refunds.original_transaction_id, '') <> '' THEN TRIM(refunds.original_transaction_id) ELSE TRIM(COALESCE(refunds.refund_transaction_id, '')) END), CONCAT(refunds.logistics_paypal_account_id, ':'))) as refund_orders")
            ->selectRaw('COALESCE(SUM(COALESCE(refunds.refund_amount_usd, refunds.original_amount, 0)), 0) as refund_amount')
            ->selectRaw('COALESCE(SUM(CASE WHEN refunds.logistics_order_id IS NULL THEN 1 ELSE 0 END), 0) as unmatched_refunds')
            ->first();

        $disputes = $database->table('logistics_paypal_disputes as disputes')
            ->whereIn('disputes.logistics_paypal_account_id', $accountIds)
            ->whereIn('disputes.reason', self::QUALIFYING_DISPUTE_REASONS)
            ->whereIn('disputes.life_cycle_stage', self::QUALIFYING_DISPUTE_TYPES);
        if (($filters['date_mode'] ?? 'order') === 'event') {
            $this->applyPortfolioRange($disputes, 'disputes.dispute_created_at', $filters);
        } else {
            $disputes->joinSub(clone $eventPaymentKeys, 'event_payments', function ($join): void {
                $join->on('event_payments.account_id', '=', 'disputes.logistics_paypal_account_id')
                    ->on('event_payments.payment_key', '=', 'disputes.disputed_transaction_id');
            });
        }
        $disputeStats = $disputes
            ->selectRaw("COUNT(DISTINCT NULLIF(CONCAT(disputes.logistics_paypal_account_id, ':', CASE WHEN COALESCE(disputes.disputed_transaction_id, '') <> '' THEN TRIM(disputes.disputed_transaction_id) ELSE TRIM(COALESCE(disputes.dispute_id, '')) END), CONCAT(disputes.logistics_paypal_account_id, ':'))) as dispute_orders")
            ->first();

        $allDisputes = $database->table('logistics_paypal_disputes as disputes')
            ->whereIn('disputes.logistics_paypal_account_id', $accountIds);
        if (($filters['date_mode'] ?? 'order') === 'event') {
            $this->applyPortfolioRange($allDisputes, 'disputes.dispute_created_at', $filters);
        } else {
            $allDisputes->joinSub(clone $eventPaymentKeys, 'event_payments', function ($join): void {
                $join->on('event_payments.account_id', '=', 'disputes.logistics_paypal_account_id')
                    ->on('event_payments.payment_key', '=', 'disputes.disputed_transaction_id');
            });
        }
        $unmatchedDisputes = (int) $allDisputes
            ->whereNull('disputes.logistics_order_id')
            ->count('disputes.id');

        $orders = (int) ($paymentStats->order_count ?? 0);
        $paidAmount = round((float) ($paymentStats->paid_amount ?? 0), 2);
        $refundAmount = round((float) ($refundStats->refund_amount ?? 0), 2);
        $disputeOrders = (int) ($disputeStats->dispute_orders ?? 0);

        return [
            'orders' => $orders,
            'paid_amount' => $paidAmount,
            'refund_amount' => $refundAmount,
            'refund_amount_rate' => $paidAmount <= 0 ? 0.0 : round($refundAmount / $paidAmount * 100, 2),
            'dispute_orders' => $disputeOrders,
            'dispute_rate' => $orders === 0 ? 0.0 : round($disputeOrders / $orders * 100, 2),
            'unmatched_count' => (int) ($paymentStats->unmatched_count ?? 0)
                + (int) ($refundStats->unmatched_refunds ?? 0)
                + $unmatchedDisputes,
            'attention_accounts' => $empty['attention_accounts'],
        ];
    }

    private function applyPortfolioRange(object $query, string $column, array $filters): void
    {
        $usesEventDates = ($filters['date_mode'] ?? 'order') === 'event';
        $from = trim((string) ($usesEventDates ? ($filters['event_date_from'] ?? '') : ($filters['date_from'] ?? '')));
        $to = trim((string) ($usesEventDates ? ($filters['event_date_to'] ?? '') : ($filters['date_to'] ?? '')));
        if ($from !== '') {
            $query->where($column, '>=', Carbon::parse($from, self::REPORT_TIMEZONE)->startOfDay()->utc()->toDateTimeString());
        }
        if ($to !== '') {
            $query->where($column, '<', Carbon::parse($to, self::REPORT_TIMEZONE)->addDay()->startOfDay()->utc()->toDateTimeString());
        }
    }

    /** @param array<string,mixed> $summary
     *  @return array<string,mixed>
     */
    private function merchant(
        array $summary,
        ?LogisticsPayPalBalanceSnapshot $balance = null,
        ?object $orderTimes = null,
        ?LogisticsPayPalAccount $account = null,
        ?PaymentPayPalAccountRiskSnapshot $riskSnapshot = null,
    ): array
    {
        $balanceTime = $balance?->as_of_time ?: $balance?->last_refresh_time;
        $orders = (int) $summary['order_count'];
        $paidAmount = (float) $summary['paid_amount'];
        $refundAmount = (float) $summary['refund_amount'];
        $disputeAmount = (float) $summary['dispute_amount'];
        $disputeOrders = (int) $summary['dispute_order_count'];
        $overallDisputeOrders = (int) $summary['overall_dispute_order_count'];

        return [
            'id' => (int) $summary['id'],
            'name' => (string) $summary['account_name'],
            'meta' => trim((string) $summary['masked_email']) !== '-' ? (string) $summary['masked_email'] : (string) $summary['merchant_id'],
            'status' => $this->status($summary),
            'risk_status' => !((bool) ($account?->is_enabled ?? false)) ? 'disabled' : (((float) ($riskSnapshot?->dispute_rate ?? 0)) > PaymentAccountRiskSnapshotService::RISK_THRESHOLD ? 'risk' : 'normal'),
            'risk_rate' => $this->percentage((float) ($riskSnapshot?->dispute_rate ?? 0)),
            'risk_window' => $riskSnapshot ? $this->formatRiskWindow($riskSnapshot) : '-',
            'edit' => [
                'account_name' => (string) ($account?->account_name ?? ''),
                'merchant_id' => (string) ($account?->merchant_id ?? ''),
                'account_email' => (string) ($account?->account_email ?? ''),
                'environment' => (string) ($account?->environment ?? LogisticsPayPalAccount::ENVIRONMENT_LIVE),
                'is_enabled' => (bool) ($account?->is_enabled ?? false),
            ],
            'orders' => $this->integer($orders),
            'amount' => $this->currency($paidAmount),
            'stores' => $this->integer((int) $summary['store_count']),
            'shipping_rate' => $this->percentage((float) $summary['shipping_rate']),
            'delivery_rate' => $this->percentage((float) $summary['delivery_rate']),
            'last_import_status' => (string) $summary['last_import_status'],
            'last_imported_at' => (string) $summary['last_imported_at'],
            'first_order_at' => $this->formatDateTime($orderTimes?->first_order_at),
            'last_order_at' => $this->formatDateTime($orderTimes?->last_order_at),
            'usage_days' => $this->usageDays($orderTimes?->first_order_at, $orderTimes?->last_order_at),
            'balance' => $balance ? [
                'available' => $this->currency((float) $balance->available_balance),
                'withheld' => $this->currency((float) $balance->withheld_balance),
                'as_of_time' => $this->formatDateTime($balanceTime),
                'relative_time' => $this->relativeTime($balanceTime),
                'is_stale' => !$balanceTime || $balanceTime->lt(now()->subHours(12)),
            ] : null,
            'rates' => [
                $this->rate('refund', (float) $summary['refund_rate'], ['refunds' => $this->integer((int) $summary['refund_order_count']), 'orders' => $this->integer($orders)]),
                $this->rate('chargeback', (float) $summary['refund_amount_rate'], ['refund_amount' => $this->currency($refundAmount), 'paid_amount' => $this->currency($paidAmount)]),
                $this->rate('risk', (float) $summary['dispute_rate'], ['disputes' => $this->integer($disputeOrders), 'orders' => $this->integer($orders)]),
                $this->rate('reserve', (float) $summary['dispute_amount_rate'], ['dispute_amount' => $this->currency($disputeAmount), 'paid_amount' => $this->currency($paidAmount)]),
                $this->rate('full_refund', (float) $summary['overall_dispute_rate'], ['disputes' => $this->integer($overallDisputeOrders), 'orders' => $this->integer($orders)]),
            ],
        ];
    }

    private function formatRiskWindow(PaymentPayPalAccountRiskSnapshot $snapshot): string
    {
        return $snapshot->window_start->timezone('Asia/Shanghai')->format('Y-m-d').' 至 '
            .$snapshot->window_end->timezone('Asia/Shanghai')->format('Y-m-d');
    }

    /** @return array{key:string,value:string,tone:string,progress:int,note_params:array<string,string>} */
    private function rate(string $key, float $value, array $noteParams): array
    {
        return [
            'key' => $key,
            'value' => $this->percentage($value),
            'tone' => match ($key) {
                'refund', 'chargeback' => 'warning',
                'risk', 'reserve' => 'danger',
                default => 'primary',
            },
            'progress' => max(0, min(100, (int) round($value * 10))),
            'note_params' => $noteParams,
        ];
    }

    /** @param array<string,mixed> $summary */
    private function status(array $summary): string
    {
        if (!(bool) $summary['is_enabled']) {
            return 'disabled';
        }

        return $summary['last_import_status'] === 'success' ? 'normal' : 'attention';
    }

    private function integer(int $value): string
    {
        return number_format($value);
    }

    private function currency(float $value): string
    {
        return '$'.number_format($value, 2);
    }

    private function percentage(float $value): string
    {
        return number_format($value, 2).'%';
    }

    private function formatDateTime(mixed $value): string
    {
        if ($value instanceof CarbonInterface) {
            return $value->timezone('Asia/Shanghai')->format('Y-m-d H:i');
        }
        if (!is_string($value) || trim($value) === '') {
            return '-';
        }

        return Carbon::parse($value, 'UTC')->timezone('Asia/Shanghai')->format('Y-m-d H:i');
    }

    private function analyticsDateTime(mixed $value): string
    {
        if (!$value) {
            return '-';
        }
        try {
            return Carbon::parse($value)->timezone(self::REPORT_TIMEZONE)->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return '-';
        }
    }

    private function maskEmail(mixed $email): string
    {
        $email = trim((string) ($email ?? ''));
        if (!str_contains($email, '@')) {
            return $email ?: '-';
        }
        [$local, $domain] = explode('@', $email, 2);

        return mb_substr($local, 0, min(2, mb_strlen($local))).'***@'.$domain;
    }

    private function usageDays(mixed $firstOrderAt, mixed $lastOrderAt): ?int
    {
        if (!$firstOrderAt || !$lastOrderAt) {
            return null;
        }

        $first = $firstOrderAt instanceof CarbonInterface
            ? $firstOrderAt->copy()->timezone('Asia/Shanghai')
            : Carbon::parse((string) $firstOrderAt, 'UTC')->timezone('Asia/Shanghai');
        $last = $lastOrderAt instanceof CarbonInterface
            ? $lastOrderAt->copy()->timezone('Asia/Shanghai')
            : Carbon::parse((string) $lastOrderAt, 'UTC')->timezone('Asia/Shanghai');

        return (int) $first->startOfDay()->diffInDays($last->startOfDay(), true);
    }

    /** @return array{key:string,count:int} */
    private function relativeTime(?CarbonInterface $value): array
    {
        if (!$value) {
            return ['key' => 'unknown', 'count' => 0];
        }

        $seconds = (int) abs($value->diffInSeconds(now()));
        if ($seconds < 60) {
            return ['key' => 'just_now', 'count' => 0];
        }
        if ($seconds < 3600) {
            return ['key' => 'minutes', 'count' => max(1, (int) floor($seconds / 60))];
        }
        if ($seconds < 86400) {
            return ['key' => 'hours', 'count' => max(1, (int) floor($seconds / 3600))];
        }

        return ['key' => 'days', 'count' => max(1, (int) floor($seconds / 86400))];
    }

    private function contains(string $value): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value).'%';
    }
}
