<?php

namespace App\Portals\Payment;

use App\Models\LogisticsPayPalAccount;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PaymentPayPalMonthlyReportService
{
    public const DIMENSION_PAYMENT = 'payment';
    public const DIMENSION_EVENT = 'event';
    public const REPORT_TIMEZONE = 'America/Los_Angeles';

    private const DISPUTE_FEE_CODES = ['T0114'];
    private const CHARGEBACK_FEE_CODES = ['T0106'];
    private const REALISED_DISPUTE_CODES = ['T1106', 'T1201', 'T1202', 'T1205', 'T1207', 'T1208'];

    public function __construct(private readonly PaymentPayPalMonthlyCalculator $calculator)
    {
    }

    /** @return array<string,mixed> */
    public function report(
        LogisticsPayPalAccount $account,
        int $year,
        string $dimension,
        ?int $detailMonth = null,
    ): array {
        $dimension = $dimension === self::DIMENSION_EVENT ? self::DIMENSION_EVENT : self::DIMENSION_PAYMENT;
        $year = max(2000, min(now(self::REPORT_TIMEZONE)->year, $year));
        $detailMonth = $detailMonth !== null && $detailMonth >= 1 && $detailMonth <= 12 ? $detailMonth : null;
        $result = $this->aggregate($account, $year, $dimension, $detailMonth, false);

        return [
            'account' => [
                'id' => (int) $account->getKey(),
                'name' => (string) $account->account_name,
                'merchant_id' => (string) $account->merchant_id,
            ],
            'year' => $year,
            'dimension' => $dimension,
            'currency' => 'USD',
            'available_years' => $this->availableYears($account, $year),
            'rows' => $result['rows'],
            'detail_month' => $detailMonth,
            'details' => $result['details'],
            'formula_version' => '2026-10-28',
        ];
    }

    /** @return array{rows:list<array<string,mixed>>,details:list<array<string,mixed>>} */
    public function exportData(LogisticsPayPalAccount $account, int $year, string $dimension): array
    {
        $dimension = $dimension === self::DIMENSION_EVENT ? self::DIMENSION_EVENT : self::DIMENSION_PAYMENT;

        return $this->aggregate($account, $year, $dimension, null, true);
    }

    /** @return array{rows:list<array<string,mixed>>,details:list<array<string,mixed>>} */
    private function aggregate(
        LogisticsPayPalAccount $account,
        int $year,
        string $dimension,
        ?int $detailMonth,
        bool $allDetails,
    ): array {
        $connection = DB::connection(config('admin.database.connection') ?: config('database.default'));
        [$from, $to] = $this->yearBoundary($year);
        $months = $this->emptyMonths($year);
        $details = [];

        $payments = $this->paymentRows($connection, $account, $from, $to);
        foreach ($payments as $payment) {
            $month = $this->monthKey($payment->occurred_at);
            if (!isset($months[$month])) {
                continue;
            }
            $amount = $this->amount($payment->amount);
            $months[$month]['payment_order_count']++;
            $months[$month]['received_amount'] += $amount;
            $this->addDetail($details, $detailMonth, $allDetails, $month, [
                'type' => 'receipt',
                'event_code' => (string) ($payment->event_code ?? ''),
                'transaction_id' => (string) ($payment->transaction_id ?? ''),
                'reference_id' => '',
                'amount' => $amount,
                'happened_at' => $this->formatDateTime($payment->occurred_at),
                'status' => (string) ($payment->status ?? ''),
                'note' => (string) ($payment->subject ?? ''),
            ]);
        }

        if ($dimension === self::DIMENSION_PAYMENT) {
            $refunds = $this->paymentDimensionRefunds($connection, $account, $from, $to);
            $disputes = $this->paymentDimensionDisputes($connection, $account, $from, $to);
            $ledger = $this->paymentDimensionLedger($connection, $account, $from, $to);
        } else {
            $refunds = $this->eventDimensionRefunds($connection, $account, $from, $to);
            $disputes = $this->eventDimensionDisputes($connection, $account, $from, $to);
            $ledger = $this->eventDimensionLedger($connection, $account, $from, $to);
        }

        foreach ($refunds as $refund) {
            $month = $this->monthKey($refund->occurred_at);
            if (!isset($months[$month])) {
                continue;
            }
            $amount = abs($this->amount($refund->amount));
            $orderKey = trim((string) ($refund->origin_id ?? '')) ?: 'refund:'.(string) ($refund->transaction_id ?? '');
            $months[$month]['_refund_orders'][$orderKey] = true;
            $months[$month]['refund_amount'] += $amount;
            $this->addDetail($details, $detailMonth, $allDetails, $month, [
                'type' => 'refund',
                'event_code' => (string) ($refund->event_code ?? ''),
                'transaction_id' => (string) ($refund->transaction_id ?? ''),
                'reference_id' => (string) ($refund->origin_id ?? ''),
                'amount' => $amount,
                'happened_at' => $this->formatDateTime($refund->event_at ?? $refund->occurred_at),
                'status' => (string) ($refund->status ?? ''),
                'note' => (string) ($refund->reason ?? ''),
            ]);
        }

        foreach ($disputes as $dispute) {
            $month = $this->monthKey($dispute->occurred_at);
            if (!isset($months[$month])) {
                continue;
            }
            $amount = abs($this->amount($dispute->amount));
            $orderKey = trim((string) ($dispute->origin_id ?? '')) ?: 'dispute:'.(string) ($dispute->dispute_id ?? '');
            $months[$month]['_dispute_orders'][$orderKey] = true;
            $months[$month]['dispute_amount'] += $amount;
            $this->addDetail($details, $detailMonth, $allDetails, $month, [
                'type' => 'dispute_principal',
                'event_code' => '',
                'transaction_id' => (string) ($dispute->dispute_id ?? ''),
                'reference_id' => (string) ($dispute->origin_id ?? ''),
                'amount' => $amount,
                'happened_at' => $this->formatDateTime($dispute->event_at ?? $dispute->occurred_at),
                'status' => (string) ($dispute->status ?? ''),
                'note' => (string) ($dispute->reason ?? ''),
            ]);
        }

        foreach ($ledger as $entry) {
            $month = $this->monthKey($entry->occurred_at);
            if (!isset($months[$month])) {
                continue;
            }
            $code = strtoupper(trim((string) ($entry->event_code ?? '')));
            $loss = round(-1 * $this->ledgerMovementAmount($entry), 2);
            $type = null;
            if (in_array($code, self::DISPUTE_FEE_CODES, true)) {
                $months[$month]['dispute_fee_amount'] += $loss;
                $type = 'dispute_fee';
            } elseif (in_array($code, self::CHARGEBACK_FEE_CODES, true)) {
                $months[$month]['chargeback_fee_amount'] += $loss;
                $type = 'chargeback_fee';
            } elseif (in_array($code, self::REALISED_DISPUTE_CODES, true)) {
                $months[$month]['realised_dispute_loss'] += $loss;
                $type = 'dispute_settlement';
            }
            if ($type === null) {
                continue;
            }
            $this->addDetail($details, $detailMonth, $allDetails, $month, [
                'type' => $type,
                'event_code' => $code,
                'transaction_id' => (string) ($entry->transaction_id ?? ''),
                'reference_id' => (string) ($entry->reference_id ?? ''),
                'amount' => $loss,
                'happened_at' => $this->formatDateTime($entry->event_at ?? $entry->occurred_at),
                'status' => (string) ($entry->status ?? ''),
                'note' => (string) ($entry->subject ?? ''),
            ]);
        }

        $coverage = $this->coverageByMonth($connection, $account, $year);
        $rows = collect($months)->map(function (array $month, string $key) use ($dimension, $coverage): array {
            $month['refund_order_count'] = count($month['_refund_orders']);
            $month['dispute_order_count'] = count($month['_dispute_orders']);
            unset($month['_refund_orders'], $month['_dispute_orders']);

            return [
                'month' => $key,
                'month_number' => (int) substr($key, 5, 2),
                ...$this->calculator->calculate($month, $dimension === self::DIMENSION_EVENT),
                'coverage' => $coverage[$key] ?? ['status' => 'missing', 'covered_days' => 0, 'expected_days' => 0],
            ];
        })->sortByDesc('month')->values()->all();

        usort($details, static fn (array $left, array $right): int => strcmp((string) $right['happened_at'], (string) $left['happened_at']));

        return ['rows' => $rows, 'details' => $details];
    }

    private function paymentRows(ConnectionInterface $connection, LogisticsPayPalAccount $account, CarbonInterface $from, CarbonInterface $to): Collection
    {
        return $connection->table('logistics_paypal_transactions')
            ->where('logistics_paypal_account_id', $account->getKey())
            ->where('event_code', 'like', 'T00%')->where('status', 'S')
            ->whereBetween('paid_at', [$from, $to])
            ->get([
                'payment_transaction_id as transaction_id', 'event_code', 'status',
                'payment_amount_usd as amount', 'payment_subject as subject', 'paid_at as occurred_at',
            ]);
    }

    private function paymentDimensionRefunds(ConnectionInterface $connection, LogisticsPayPalAccount $account, CarbonInterface $from, CarbonInterface $to): Collection
    {
        return $connection->table('logistics_paypal_refunds as refunds')
            ->join('logistics_paypal_transactions as payments', function ($join): void {
                $join->on('payments.payment_transaction_id', '=', 'refunds.original_transaction_id')
                    ->on('payments.logistics_paypal_account_id', '=', 'refunds.logistics_paypal_account_id');
            })
            ->where('payments.logistics_paypal_account_id', $account->getKey())
            ->where('payments.status', 'S')->where('refunds.status', 'S')
            ->whereBetween('payments.paid_at', [$from, $to])
            ->get([
                'refunds.refund_transaction_id as transaction_id', 'refunds.original_transaction_id as origin_id',
                'refunds.event_code', 'refunds.status', 'refunds.refund_reason as reason',
                'refunds.refund_amount_usd as amount', 'refunds.refunded_at as event_at', 'payments.paid_at as occurred_at',
            ]);
    }

    private function eventDimensionRefunds(ConnectionInterface $connection, LogisticsPayPalAccount $account, CarbonInterface $from, CarbonInterface $to): Collection
    {
        return $connection->table('logistics_paypal_refunds')
            ->where('logistics_paypal_account_id', $account->getKey())->where('status', 'S')
            ->whereBetween('refunded_at', [$from, $to])
            ->get([
                'refund_transaction_id as transaction_id', 'original_transaction_id as origin_id', 'event_code',
                'status', 'refund_reason as reason', 'refund_amount_usd as amount', 'refunded_at as event_at', 'refunded_at as occurred_at',
            ]);
    }

    private function paymentDimensionDisputes(ConnectionInterface $connection, LogisticsPayPalAccount $account, CarbonInterface $from, CarbonInterface $to): Collection
    {
        return $connection->table('logistics_paypal_disputes as disputes')
            ->join('logistics_paypal_transactions as payments', function ($join): void {
                $join->on('payments.payment_transaction_id', '=', 'disputes.disputed_transaction_id')
                    ->on('payments.logistics_paypal_account_id', '=', 'disputes.logistics_paypal_account_id');
            })
            ->where('payments.logistics_paypal_account_id', $account->getKey())->where('payments.status', 'S')
            ->whereBetween('payments.paid_at', [$from, $to])
            ->get([
                'disputes.dispute_id', 'disputes.disputed_transaction_id as origin_id', 'disputes.status',
                'disputes.reason', 'disputes.dispute_amount_usd as amount', 'disputes.dispute_created_at as event_at',
                'payments.paid_at as occurred_at',
            ]);
    }

    private function eventDimensionDisputes(ConnectionInterface $connection, LogisticsPayPalAccount $account, CarbonInterface $from, CarbonInterface $to): Collection
    {
        return $connection->table('logistics_paypal_disputes')
            ->where('logistics_paypal_account_id', $account->getKey())
            ->whereBetween('dispute_created_at', [$from, $to])
            ->get([
                'dispute_id', 'disputed_transaction_id as origin_id', 'status', 'reason',
                'dispute_amount_usd as amount', 'dispute_created_at as event_at', 'dispute_created_at as occurred_at',
            ]);
    }

    private function paymentDimensionLedger(ConnectionInterface $connection, LogisticsPayPalAccount $account, CarbonInterface $from, CarbonInterface $to): Collection
    {
        return $connection->table('payment_paypal_ledger_entries as ledger')
            ->join('logistics_paypal_transactions as payments', function ($join): void {
                $join->on('payments.payment_transaction_id', '=', 'ledger.origin_payment_transaction_id')
                    ->on('payments.logistics_paypal_account_id', '=', 'ledger.logistics_paypal_account_id');
            })
            ->where('payments.logistics_paypal_account_id', $account->getKey())
            ->where('ledger.currency_code', 'USD')->where('payments.status', 'S')
            ->where(function ($query): void { $query->whereNull('ledger.status')->orWhere('ledger.status', 'S'); })
            ->whereIn('ledger.event_code', $this->ledgerCodes())
            ->whereBetween('payments.paid_at', [$from, $to])
            ->get([
                'ledger.transaction_id', 'ledger.reference_id', 'ledger.event_code', 'ledger.status',
                'ledger.transaction_amount', 'ledger.fee_amount', 'ledger.net_amount',
                'ledger.subject', 'ledger.event_at', 'payments.paid_at as occurred_at',
            ]);
    }

    private function eventDimensionLedger(ConnectionInterface $connection, LogisticsPayPalAccount $account, CarbonInterface $from, CarbonInterface $to): Collection
    {
        return $connection->table('payment_paypal_ledger_entries')
            ->where('logistics_paypal_account_id', $account->getKey())->where('currency_code', 'USD')
            ->where(function ($query): void { $query->whereNull('status')->orWhere('status', 'S'); })
            ->whereIn('event_code', $this->ledgerCodes())->whereBetween('event_at', [$from, $to])
            ->get([
                'transaction_id', 'reference_id', 'event_code', 'status', 'transaction_amount', 'fee_amount',
                'net_amount', 'subject', 'event_at', 'event_at as occurred_at',
            ]);
    }

    /** @return list<string> */
    private function ledgerCodes(): array
    {
        return array_values(array_unique([...self::DISPUTE_FEE_CODES, ...self::CHARGEBACK_FEE_CODES, ...self::REALISED_DISPUTE_CODES]));
    }

    /** @return array<string,array<string,mixed>> */
    private function emptyMonths(int $year): array
    {
        $current = now(self::REPORT_TIMEZONE);
        $lastMonth = $year === $current->year ? $current->month : 12;
        $months = [];
        for ($month = 1; $month <= $lastMonth; $month++) {
            $key = sprintf('%04d-%02d', $year, $month);
            $months[$key] = [
                'payment_order_count' => 0, 'received_amount' => 0.0,
                'refund_order_count' => 0, 'refund_amount' => 0.0,
                'dispute_order_count' => 0, 'dispute_amount' => 0.0,
                'dispute_fee_amount' => 0.0, 'chargeback_fee_amount' => 0.0,
                'realised_dispute_loss' => 0.0, '_refund_orders' => [], '_dispute_orders' => [],
            ];
        }

        return $months;
    }

    /** @return array<string,array{status:string,covered_days:int,expected_days:int}> */
    private function coverageByMonth(ConnectionInterface $connection, LogisticsPayPalAccount $account, int $year): array
    {
        $current = now(self::REPORT_TIMEZONE);
        $lastMonth = $year === $current->year ? $current->month : 12;
        $imports = $connection->table('logistics_paypal_imports')
            ->where('logistics_paypal_account_id', $account->getKey())
            ->whereIn('status', ['success', 'partial'])->whereNotNull('period_from')->whereNotNull('period_to')
            ->whereExists(function ($ledger): void {
                $ledger->selectRaw('1')
                    ->from('payment_paypal_ledger_import_runs')
                    ->whereColumn(
                        'payment_paypal_ledger_import_runs.logistics_paypal_import_id',
                        'logistics_paypal_imports.id',
                    )
                    ->where('payment_paypal_ledger_import_runs.error_count', 0);
            })
            ->get(['period_from', 'period_to']);
        $result = [];
        for ($month = 1; $month <= $lastMonth; $month++) {
            $start = Carbon::create($year, $month, 1, 0, 0, 0, self::REPORT_TIMEZONE)->startOfDay();
            $end = $year === $current->year && $month === $current->month ? $current->copy()->endOfDay() : $start->copy()->endOfMonth();
            $days = [];
            foreach ($imports as $import) {
                $from = Carbon::parse((string) $import->period_from, 'UTC')->timezone(self::REPORT_TIMEZONE)->startOfDay();
                $to = Carbon::parse((string) $import->period_to, 'UTC')->timezone(self::REPORT_TIMEZONE)->endOfDay();
                $cursor = $from->greaterThan($start) ? $from->copy() : $start->copy();
                $limit = $to->lessThan($end) ? $to->copy() : $end->copy();
                while ($cursor->lte($limit)) {
                    $days[$cursor->format('Y-m-d')] = true;
                    $cursor->addDay();
                }
            }
            $expected = $start->diffInDays($end) + 1;
            $covered = count($days);
            $status = $year === $current->year && $month === $current->month
                ? 'current'
                : ($covered >= $expected ? 'complete' : ($covered > 0 ? 'partial' : 'missing'));
            $result[$start->format('Y-m')] = ['status' => $status, 'covered_days' => $covered, 'expected_days' => $expected];
        }

        return $result;
    }

    /** @return list<int> */
    private function availableYears(LogisticsPayPalAccount $account, int $selected): array
    {
        $connection = DB::connection(config('admin.database.connection') ?: config('database.default'));
        $dates = collect([
            $connection->table('logistics_paypal_transactions')->where('logistics_paypal_account_id', $account->getKey())->min('paid_at'),
            $connection->table('logistics_paypal_refunds')->where('logistics_paypal_account_id', $account->getKey())->min('refunded_at'),
            $connection->table('logistics_paypal_disputes')->where('logistics_paypal_account_id', $account->getKey())->min('dispute_created_at'),
            $connection->table('payment_paypal_ledger_entries')->where('logistics_paypal_account_id', $account->getKey())->min('event_at'),
        ])->filter();
        $current = now(self::REPORT_TIMEZONE)->year;
        $first = $dates->map(fn ($date): int => Carbon::parse((string) $date, 'UTC')->timezone(self::REPORT_TIMEZONE)->year)->min() ?: min($selected, $current);

        return collect(range((int) $first, max($current, $selected)))->reverse()->values()->all();
    }

    /** @return array{0:CarbonInterface,1:CarbonInterface} */
    private function yearBoundary(int $year): array
    {
        $from = Carbon::create($year, 1, 1, 0, 0, 0, self::REPORT_TIMEZONE)->startOfYear()->utc();

        return [$from, $from->copy()->addYear()->subMicrosecond()];
    }

    /** @param list<array<string,mixed>> $details @param array<string,mixed> $row */
    private function addDetail(array &$details, ?int $detailMonth, bool $allDetails, string $month, array $row): void
    {
        if (!$allDetails && $detailMonth !== (int) substr($month, 5, 2)) {
            return;
        }
        $details[] = ['month' => $month, ...$row];
    }

    private function monthKey(mixed $date): string
    {
        return blank($date) ? '' : Carbon::parse((string) $date, 'UTC')->timezone(self::REPORT_TIMEZONE)->format('Y-m');
    }

    private function formatDateTime(mixed $date): string
    {
        return blank($date) ? '' : Carbon::parse((string) $date, 'UTC')->timezone('Asia/Shanghai')->format('Y-m-d H:i:s');
    }

    private function amount(mixed $amount): float
    {
        return is_numeric($amount) ? round((float) $amount, 4) : 0.0;
    }

    private function ledgerMovementAmount(object $entry): float
    {
        foreach (['transaction_amount', 'net_amount', 'fee_amount'] as $field) {
            if (isset($entry->{$field}) && is_numeric($entry->{$field}) && abs((float) $entry->{$field}) > 0.000001) {
                return round((float) $entry->{$field}, 4);
            }
        }

        return 0.0;
    }
}
