<?php

namespace App\Portals\Payment;

use App\Models\LogisticsPayPalAccount;
use App\Models\LogisticsPayPalBalanceSnapshot;
use App\Portals\Logistics\PayPalRiskAnalyticsService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PaymentAccountRiskService
{
    private const REPORT_TIMEZONE = 'America/Los_Angeles';
    private const QUALIFYING_DISPUTE_REASONS = [
        'MERCHANDISE_OR_SERVICE_NOT_RECEIVED',
        'MERCHANDISE_OR_SERVICE_NOT_AS_DESCRIBED',
    ];
    private const QUALIFYING_DISPUTE_TYPES = ['INQUIRY', 'CHARGEBACK'];

    public function __construct(private readonly PayPalRiskAnalyticsService $analytics)
    {
    }

    /** @param array{account:string,period_start:string,period_end:string,import_status:string,page:int,per_page:int} $filters
     *  @return array<string,mixed>
     */
    public function dashboard(array $filters): array
    {
        $query = LogisticsPayPalAccount::query()->orderByDesc('is_enabled')->orderBy('account_name');
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
        $summaries = $accounts
            ->map(fn (LogisticsPayPalAccount $account): array => $this->analytics->currentSummary($account, [
                'date_from' => $filters['period_start'],
                'date_to' => $filters['period_end'],
                'date_mode' => 'order',
            ]));

        $portfolio = $this->portfolio($summaries);
        $cutoff = $accounts->pluck('last_imported_at')->filter()->sortDesc()->first();
        $total = $summaries->count();
        $perPage = (int) $filters['per_page'];
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, (int) $filters['page']), $lastPage);
        $merchants = $summaries->forPage($page, $perPage);
        $merchantIds = $merchants->pluck('id')->map(static fn ($id): int => (int) $id)->all();
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
        if ($tab === 'order_analysis') {
            $summary = $this->analytics->currentSummary($account, $filters);
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

    /** @param Collection<int,array<string,mixed>> $summaries
     *  @return array<string,int|float>
     */
    private function portfolio(Collection $summaries): array
    {
        $orders = (int) $summaries->sum('order_count');
        $refunds = (int) $summaries->sum('refund_order_count');
        $disputes = (int) $summaries->sum('dispute_order_count');
        $paidAmount = round((float) $summaries->sum('paid_amount'), 2);
        $refundAmount = round((float) $summaries->sum('refund_amount'), 2);

        return [
            'orders' => $orders,
            'paid_amount' => $paidAmount,
            'refund_amount' => $refundAmount,
            'refund_amount_rate' => $paidAmount <= 0 ? 0.0 : round($refundAmount / $paidAmount * 100, 2),
            'dispute_orders' => $disputes,
            'dispute_rate' => $orders === 0 ? 0.0 : round($disputes / $orders * 100, 2),
            'unmatched_count' => (int) $summaries->sum('unmatched_count'),
            'attention_accounts' => $summaries->filter(fn (array $summary): bool => $this->status($summary) === 'attention')->count(),
        ];
    }

    /** @param array<string,mixed> $summary
     *  @return array<string,mixed>
     */
    private function merchant(array $summary, ?LogisticsPayPalBalanceSnapshot $balance = null, ?object $orderTimes = null): array
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
            'orders' => $this->integer($orders),
            'amount' => $this->currency($paidAmount),
            'stores' => $this->integer((int) $summary['store_count']),
            'shipping_rate' => $this->percentage((float) $summary['shipping_rate']),
            'delivery_rate' => $this->percentage((float) $summary['delivery_rate']),
            'last_import_status' => (string) $summary['last_import_status'],
            'last_imported_at' => (string) $summary['last_imported_at'],
            'first_order_at' => $this->formatDateTime($orderTimes?->first_order_at),
            'last_order_at' => $this->formatDateTime($orderTimes?->last_order_at),
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
