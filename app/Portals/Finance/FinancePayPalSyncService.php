<?php

namespace App\Portals\Finance;

use App\Models\FinancePaymentAccount;
use App\Models\FinanceRecord;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;

class FinancePayPalSyncService
{
    public function __construct(private readonly FinanceRelayClient $relay, private readonly FinanceWorkbookService $workbooks) {}

    public function balances(FinancePaymentAccount $account, int $actor): array
    {
        return $this->run($account, 'balances', $actor, null, null, function (int $logId) use ($account): array {
            $response = $this->relay->read($account, 'balances');
            $payload = $response['payload'];
            if (! isset($payload['balances']) || ! is_array($payload['balances']) || ! array_is_list($payload['balances'])
                || ! is_string($payload['as_of_time'] ?? null)) {
                throw new FinanceRelayException('payload');
            }
            $asOf = $this->date($payload['as_of_time']);
            $currencies = [];
            foreach ($payload['balances'] as $row) {
                $currency = $row['currency'] ?? null;
                if (! is_string($currency) || ! preg_match('/^[A-Z]{3}$/', $currency) || in_array($currency, $currencies, true)) {
                    throw new FinanceRelayException('payload');
                }
                $currencies[] = $currency;
                foreach (['total_balance', 'available_balance', 'withheld_balance'] as $field) {
                    if (isset($row[$field])) {
                        $this->money($row[$field]['value'] ?? null);
                        if (($row[$field]['currency_code'] ?? $currency) !== $currency) {
                            throw new FinanceRelayException('payload');
                        }
                    }
                }
            }
            $db = $this->workbooks->database();
            $db->transaction(function () use ($db, $logId, $response, $account, $payload, $asOf): void {
                $this->guardAccount($account->id);
                $this->page($logId, [], $response);
                $db->table('finance_balance_snapshots')->insert([
                    'payment_account_id' => $account->id, 'sync_log_id' => $logId,
                    'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'as_of' => $asOf, 'created_at' => now(), 'updated_at' => now(),
                ]);
            });

            return ['pages' => 1, 'records' => count($payload['balances'])];
        });
    }

    public function refreshOrders(FinancePaymentAccount $account, int $actor): array
    {
        $db = $this->workbooks->database();
        $to = CarbonImmutable::now('UTC')->startOfSecond();
        $last = $db->table('finance_sync_logs')->where('payment_account_id', $account->id)
            ->where('operation', 'transaction_search')->where('status', 'success')->max('range_to');
        $initialMonths = max(1, min(12, (int) config('finance.relay.initial_months', 6)));
        $lookbackHours = max(1, min(168, (int) config('finance.relay.incremental_hours', 5)));
        $from = $last ? CarbonImmutable::parse($last, 'UTC')->min($to->subHours($lookbackHours))
            : $to->subMonthsNoOverflow($initialMonths);
        // A later manual sync must not hide an earlier failed or interrupted window.
        $logs = $db->table('finance_sync_logs')->where('payment_account_id', $account->id)
            ->where('operation', 'transaction_search')->whereNotNull('range_from')->whereNotNull('range_to')
            ->orderBy('range_from')->get(['status', 'range_from', 'range_to']);
        $coverage = [];
        foreach ($logs->where('status', 'success') as $covered) {
            $coveredFrom = CarbonImmutable::parse($covered->range_from, 'UTC');
            $coveredTo = CarbonImmutable::parse($covered->range_to, 'UTC');
            $index = count($coverage) - 1;
            if ($index >= 0 && $coveredFrom->lessThanOrEqualTo($coverage[$index][1]->addSecond())) {
                $coverage[$index][1] = $coverage[$index][1]->max($coveredTo);
            } else {
                $coverage[] = [$coveredFrom, $coveredTo];
            }
        }
        // A recent success alone does not prove the initial history is complete.
        $historyCursor = $to->subMonthsNoOverflow($initialMonths);
        foreach ($coverage as [$coveredFrom, $coveredTo]) {
            if ($coveredTo->lessThan($historyCursor)) continue;
            if ($coveredFrom->greaterThan($historyCursor)) break;
            $historyCursor = $coveredTo->addSecond();
            if ($historyCursor->greaterThan($to)) break;
        }
        if ($historyCursor->lessThanOrEqualTo($to)) $from = $from->min($historyCursor);
        foreach ($logs->whereIn('status', ['failed', 'running']) as $unfinished) {
            $cursor = CarbonImmutable::parse($unfinished->range_from, 'UTC');
            $until = CarbonImmutable::parse($unfinished->range_to, 'UTC');
            foreach ($coverage as [$coveredFrom, $coveredTo]) {
                if ($coveredTo->lessThan($cursor)) continue;
                if ($coveredFrom->greaterThan($cursor)) break;
                $cursor = $coveredTo->addSecond();
                if ($cursor->greaterThan($until)) break;
            }
            if ($cursor->lessThanOrEqualTo($until)) $from = $from->min($cursor);
        }
        $from = $from->min($to);
        $count = 0;
        $lockedGaps = [];
        // Closed accounting months retain their saved transactions.
        for ($start = $from; $start->lessThanOrEqualTo($to); $start = $end->addSecond()) {
            $month = $start->setTimezone('Asia/Shanghai')->format('Y-m');
            $end = $start->setTimezone('Asia/Shanghai')->endOfMonth()->utc()->startOfSecond()->min($to);
            if ($db->table('finance_period_locks')->where('month', $month)->whereNotNull('report_snapshot')->exists()) {
                $lockedCursor = $start;
                foreach ($coverage as [$coveredFrom, $coveredTo]) {
                    if ($coveredTo->lessThan($lockedCursor)) continue;
                    if ($coveredFrom->greaterThan($lockedCursor)) break;
                    $lockedCursor = $coveredTo->addSecond();
                    if ($lockedCursor->greaterThan($end)) break;
                }
                if ($lockedCursor->lessThanOrEqualTo($end)) $lockedGaps[] = $month;
                continue;
            }
            $result = $this->transactions($account, $start, $end, $actor);
            $count += $result['count'] ?? 0;
            if (! $result['ok']) {
                return [...$result, 'count' => $count];
            }
        }

        if ($lockedGaps) return ['ok' => false, 'count' => $count, 'error' => '历史回补未完成：'.implode('、', array_unique($lockedGaps)).'已锁定，需核对历史完整性后处理。'];
        return ['ok' => true, 'count' => $count, 'error' => null, 'history_complete' => true];
    }

    public function transactions(FinancePaymentAccount $account, CarbonImmutable $from, CarbonImmutable $to, int $actor): array
    {
        if ($from->greaterThan($to) || $from->diffInDays($to) > 366) {
            throw new FinanceRelayException('range');
        }

        return $this->run($account, 'transaction_search', $actor, $from, $to, function (int $logId) use ($account, $from, $to, $actor): array {
            $counts = ['pages' => 0, 'records' => 0];
            $requests = 0;
            $pageSize = max(1, min(500, (int) config('finance.relay.page_size', 500)));
            $segmentDays = max(1, min(30, (int) config('finance.relay.segment_days', 7)));
            for ($start = $from; $start->lessThanOrEqualTo($to); $start = $end->addSecond()) {
                $end = $start->addDays($segmentDays)->subSecond()->min($to);
                $segmentCounts = $counts;
                // Restart the segment if pagination changes; unique keys prevent duplicate transactions.
                for ($attempt = 1; ; $attempt++) {
                    $counts = $segmentCounts;
                    try {
                        $totalPages = null;
                        $expectedItems = null;
                        $segmentItems = 0;
                        $seenTransactions = [];
                        for ($page = 1; $totalPages === null || $page <= $totalPages; $page++) {
                            if ($requests >= (int) config('finance.relay.max_pages', 1000)) {
                                throw new FinanceRelayException('pagination');
                            }
                            $query = ['start_date' => $start->utc()->format('Y-m-d\TH:i:s\Z'), 'end_date' => $end->utc()->format('Y-m-d\TH:i:s\Z'),
                                'fields' => 'all', 'page_size' => (string) $pageSize, 'page' => (string) $page, 'balance_affecting_records_only' => 'Y'];
                            $requests++;
                            $response = $this->relay->read($account, 'transaction_search', $query);
                            $payload = $response['payload'];
                            $rows = $payload['transaction_details'] ?? null;
                            $pages = filter_var($payload['total_pages'] ?? null, FILTER_VALIDATE_INT);
                            $returnedPage = filter_var($payload['page'] ?? null, FILTER_VALIDATE_INT);
                            $totalItems = filter_var($payload['total_items'] ?? null, FILTER_VALIDATE_INT);
                            // PayPal may omit transaction_details for an explicitly empty result.
                            if (! array_key_exists('transaction_details', $payload) && $pages === 0 && $totalItems === 0) {
                                $rows = [];
                            }
                            if (! is_array($rows) || ! array_is_list($rows) || count($rows) > $pageSize
                                || $pages === false || $returnedPage !== $page || $pages < 0
                                || $totalItems === false || $totalItems < 0
                                || ($expectedItems !== null && $expectedItems !== $totalItems)
                                || $pages > config('finance.relay.max_pages', 1000)
                                || ($pages === 0 && ($rows !== [] || $totalItems !== 0))
                                || ($pages > 0 && $page > $pages) || ($page < $pages && $rows === [])
                                || ($totalPages !== null && $totalPages !== max(1, $pages))) {
                                throw new FinanceRelayException('pagination');
                            }
                            $totalPages = max(1, $pages);
                            $expectedItems = $totalItems;
                            $segmentItems += count($rows);
                            if ($segmentItems > $expectedItems || ($page === $totalPages && $segmentItems !== $expectedItems)) {
                                throw new FinanceRelayException('pagination');
                            }
                            $prepared = array_map(fn ($row) => $this->transaction($account, $row, $actor), $rows);
                            foreach ($prepared as $entry) {
                                $identity = $entry['record']['fingerprint'];
                                if (isset($seenTransactions[$identity])) {
                                    throw new FinanceRelayException('pagination');
                                }
                                $seenTransactions[$identity] = true;
                            }
                            $db = $this->workbooks->database();
                            $db->transaction(function () use ($db, $prepared, $account, $response, $query, $logId): void {
                                $this->guardAccount($account->id);
                                $accounting = app(FinanceAccountingService::class);
                                $months = collect($prepared)->map(fn ($entry) => $accounting->month($entry['record']['occurred_at']))->unique()->sort();
                                foreach ($months as $month) {
                                    $accounting->guardUnlocked($month);
                                }
                                $this->page($logId, $query, $response);
                                foreach ($prepared as $entry) {
                                    $record = FinanceRecord::query()->updateOrCreate(['fingerprint' => $entry['record']['fingerprint']], $entry['record']);
                                    $db->table('finance_payment_transactions')->updateOrInsert([
                                        'payment_account_id' => $account->id, 'transaction_id' => $entry['id'], 'event_code' => $entry['event'],
                                    ], [
                                        'finance_record_id' => $record->id, 'raw_payload' => json_encode($entry['raw'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                                        ...$accounting->attributes($entry['raw'], $entry['record']['occurred_at']),
                                        'created_at' => $record->created_at, 'updated_at' => now(),
                                    ]);
                                    if ($record->raw_type) {
                                        app(FinanceWorkbookService::class)->registerType($record->raw_type);
                                    }
                                }
                            });
                            $counts['pages']++;
                            $counts['records'] += count($prepared);
                            $db->table('finance_sync_logs')->where('id', $logId)->update([
                                'page_count' => $counts['pages'], 'record_count' => $counts['records'], 'updated_at' => now(),
                            ]);
                        }
                        break;
                    } catch (FinanceRelayException $e) {
                        if ($e->reason !== 'pagination' || $attempt >= 3
                            || $requests >= (int) config('finance.relay.max_pages', 1000)) {
                            throw $e;
                        }
                    }
                }
            }

            return $counts;
        });
    }

    public function transaction(FinancePaymentAccount $account, mixed $row, int $actor): array
    {
        if (! is_array($row) || ! is_array($row['transaction_info'] ?? null)) {
            throw new FinanceRelayException('payload');
        }
        $info = $row['transaction_info'];
        $id = $info['transaction_id'] ?? null;
        $event = $info['transaction_event_code'] ?? '';
        $currency = Arr::get($info, 'transaction_amount.currency_code');
        if (! is_string($id) || $id === '' || strlen($id) > 100 || ! is_string($event) || strlen($event) > 40
            || ! is_string($currency) || ! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new FinanceRelayException('payload');
        }
        $at = $this->date($info['transaction_initiation_date'] ?? null);
        $amount = $this->money(Arr::get($info, 'transaction_amount.value'));
        $fee = Arr::get($info, 'fee_amount.value');
        if ($fee !== null) {
            $fee = $this->money($fee);
            if (Arr::get($info, 'fee_amount.currency_code', $currency) !== $currency) {
                throw new FinanceRelayException('payload');
            }
        }
        $headers = FinanceCatalog::dataset('paypal-orders')['headers'];
        $status = $info['transaction_status'] ?? '';
        if (! is_string($status) || strlen($status) > 100) {
            throw new FinanceRelayException('payload');
        }
        $values = [$at->format('Y-m-d'), $at->format('H:i:s'), 'UTC',
            Arr::get($row, 'payer_info.payer_name.alternate_full_name', ''), $event, $status, $currency,
            $amount, $fee ?? '', $fee === null ? '' : bcadd($amount, $fee, 8),
            Arr::get($row, 'payer_info.email_address', ''), $id, '', $info['invoice_id'] ?? '',
            bccomp(bcadd($amount, $fee ?? '0', 8), '0', 8) < 0 ? 'Debit' : 'Credit'];
        $payload = [...array_combine(array_slice($headers, 0, 15), array_map(fn ($value) => is_scalar($value) ? (string) $value : '', $values)),
            ...FinancePayPalDetails::payload($row)];

        return ['id' => $id, 'event' => $event, 'raw' => $row, 'record' => [
            'dataset' => 'paypal-orders', 'account' => $account->activity_account, 'reference' => $id,
            'occurred_at' => $at, 'source_timezone' => 'UTC', 'domain' => null, 'raw_type' => $event ?: null,
            'currency' => $currency, 'status' => $status ?: null,
            'fingerprint' => hash('sha256', json_encode(['relay', $account->id, $id, $event], JSON_THROW_ON_ERROR)),
            'payload' => $payload, 'imported_by' => $actor,
        ]];
    }

    private function run(FinancePaymentAccount $account, string $operation, int $actor, ?CarbonImmutable $from, ?CarbonImmutable $to, callable $action): array
    {
        $db = $this->workbooks->database();
        $logId = $db->table('finance_sync_logs')->insertGetId([
            'payment_account_id' => $account->id, 'operation' => $operation, 'range_from' => $from, 'range_to' => $to,
            'status' => 'running', 'actor_id' => $actor, 'created_at' => now(), 'updated_at' => now(),
        ]);
        try {
            if ($account->trashed()) {
                throw new FinanceRelayException('account');
            }
            $counts = $action($logId);
            $db->table('finance_sync_logs')->where('id', $logId)->update([
                'status' => 'success', 'page_count' => $counts['pages'], 'record_count' => $counts['records'], 'updated_at' => now(),
            ]);

            return ['ok' => true, 'account' => $account->account_name, 'count' => $counts['records'], 'error' => null];
        } catch (\Throwable $e) {
            $reason = $e instanceof FinanceRelayException ? $e->reason : 'internal';
            $db->table('finance_sync_logs')->where('id', $logId)->update(['status' => 'failed', 'error_code' => $reason, 'updated_at' => now()]);

            return ['ok' => false, 'account' => $account->account_name, 'count' => (int) $db->table('finance_sync_logs')->where('id', $logId)->value('record_count'), 'error' => __('finance.sync.errors.'.$reason)];
        }
    }

    private function page(int $logId, array $query, array $response): void
    {
        $this->workbooks->database()->table('finance_sync_pages')->insert([
            'sync_log_id' => $logId, 'query' => json_encode((object) $query, JSON_THROW_ON_ERROR),
            'http_status' => $response['status'], 'raw_response' => $response['raw'], 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function guardAccount(int $id): void
    {
        $account = FinancePaymentAccount::query()->lockForUpdate()->find($id);
        if (! $account || $account->status !== 'enabled' || $account->account_type !== 'paypal') {
            throw new FinanceRelayException('account');
        }
    }

    private function date(mixed $value): CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/', $value)
            || date_parse($value)['warning_count'] > 0 || date_parse($value)['error_count'] > 0) {
            throw new FinanceRelayException('payload');
        }
        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (\Throwable) {
            throw new FinanceRelayException('payload');
        }
    }

    private function money(mixed $value): string
    {
        if (! is_string($value) || ! preg_match('/^-?\d{1,18}(?:\.\d{1,8})?$/', $value)) {
            throw new FinanceRelayException('payload');
        }

        return $value;
    }
}
