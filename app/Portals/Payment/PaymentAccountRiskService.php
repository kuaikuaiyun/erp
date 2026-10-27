<?php

namespace App\Portals\Payment;

use App\Models\LogisticsPayPalAccount;
use App\Models\LogisticsPayPalBalanceSnapshot;
use App\Portals\Logistics\PayPalRiskAnalyticsService;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class PaymentAccountRiskService
{
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
        $balanceSnapshots = LogisticsPayPalBalanceSnapshot::query()
            ->whereIn('logistics_paypal_account_id', $merchants->pluck('id')->all())
            ->where('currency_code', 'USD')->orderByDesc('as_of_time')->orderByDesc('id')->get()
            ->unique('logistics_paypal_account_id')->keyBy('logistics_paypal_account_id');

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
        $detail = $this->analytics->detail($account, $filters, $tab, $page, '', '', $tabFilters, false, $perPage);
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
    private function merchant(array $summary, ?LogisticsPayPalBalanceSnapshot $balance = null): array
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
        return $value instanceof CarbonInterface ? $value->timezone('Asia/Shanghai')->format('Y-m-d H:i') : '-';
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
