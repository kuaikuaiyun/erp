<?php

namespace App\Portals\Finance;

use App\Models\FinancePaymentAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class FinanceAccountingService
{
    public const SOURCES = ['transaction_event_code', 'transaction_subject', 'transaction_note'];
    public const SUBJECTS = ['refund', 'payment_fee', 'withdrawal_fee', 'fx', 'other'];
    public const RATE_CHANNELS = [
        'paypal' => 'PayPal',
        'xuzong1' => '徐总支付 1',
        'xuzong2' => '徐总支付 2',
        'ye' => '叶总支付',
        'worldpay' => 'Worldpay',
    ];

    public function __construct(private readonly FinanceWorkbookService $workbooks) {}

    public function month(CarbonImmutable $at): string
    {
        return $at->setTimezone('Asia/Shanghai')->format('Y-m');
    }

    public function attributes(array $raw, CarbonImmutable $at): array
    {
        $db = $this->workbooks->database();
        $month = $this->month($at);
        $category = null;
        $direction = null;
        $subject = null;
        foreach ($db->table('finance_transaction_type_mappings')->where('is_enabled', true)->orderBy('priority')->orderBy('id')->get() as $rule) {
            $value = Arr::get($raw, 'transaction_info.'.$rule->source_field);
            if (! is_string($value)) {
                continue;
            }
            $matches = match ($rule->match_type) {
                'equals' => $value === $rule->match_value,
                'contains' => str_contains($value, $rule->match_value),
                'prefix' => str_starts_with($value, $rule->match_value),
                default => false,
            };
            if ($matches) {
                $category = $rule->category;
                $direction = $rule->direction;
                $subject = $rule->loss_subject;
                break;
            }
        }
        $category ??= $db->table('finance_transaction_types')->where('raw_type', Arr::get($raw, 'transaction_info.transaction_event_code', ''))->value('category');
        $category = filled($category) ? $category : null;
        $currency = Arr::get($raw, 'transaction_info.transaction_amount.currency_code');
        $rate = $this->monthlyCnyRate($month, (string) $currency, 'paypal');
        $amount = Arr::get($raw, 'transaction_info.transaction_amount.value');

        return ['accounting_month' => $month, 'category' => $category, 'direction' => $direction,
            'loss_subject' => $subject, 'monthly_rate' => $rate,
            'cny_amount' => $rate === null ? null : bcmul($amount, (string) $rate, 8)];
    }

    public function reapply(string $month): int
    {
        $db = $this->workbooks->database();
        return $db->transaction(function () use ($db, $month): int {
            $this->guardUnlocked($month);
            $from = CarbonImmutable::parse($month.'-01', 'Asia/Shanghai')->utc();
            $to = $from->setTimezone('Asia/Shanghai')->addMonth()->utc();
            $rows = $db->table('finance_payment_transactions as t')->join('finance_records as r', 'r.id', '=', 't.finance_record_id')
                ->where('r.occurred_at', '>=', $from)->where('r.occurred_at', '<', $to)->select(['t.id', 'r.occurred_at'])
                ->when(! app(FinanceSourceStorage::class)->active('payment'), fn ($query) => $query->addSelect('t.raw_payload'))->lazyById(500, 't.id', 'id');
            $count = 0;
            foreach (app(FinanceSourceStorage::class)->stream('payment', $rows) as $row) {
                $raw = app(FinanceSourceStorage::class)->values('payment', $row);
                $db->table('finance_payment_transactions')->where('id', $row->id)->update([
                    ...$this->attributes($raw, CarbonImmutable::parse($row->occurred_at, 'UTC')), 'updated_at' => now(),
                ]);
                $count++;
            }

            return $count;
        });
    }

    public function guardUnlocked(string $month): void
    {
        // A row acts as a month mutex even before closing; it serializes sync,
        // rates/reclassification and close so an in-flight page cannot alter a close.
        $db = $this->workbooks->database();
        $db->table('finance_period_locks')->insertOrIgnore(['month' => $month, 'report_snapshot' => null, 'created_at' => now(), 'updated_at' => now()]);
        $row = $db->table('finance_period_locks')->where('month', $month)->lockForUpdate()->first();
        if ($row->report_snapshot !== null) {
            throw new FinanceRelayException('period_locked');
        }
    }

    public function saveRate(array $data, int $actor): void
    {
        $db = $this->workbooks->database();
        $db->transaction(function () use ($db, $data, $actor): void {
            $this->guardUnlocked($data['month']);
            $key = ['month' => $data['month'], 'channel' => $data['channel'], 'currency' => $data['currency']];
            $existing = $db->table('finance_monthly_exchange_rates')->where($key)->first();
            $db->table('finance_monthly_exchange_rates')->updateOrInsert($key, [
                'cny_rate' => $data['cny_rate'], 'source' => null, 'updated_by' => $actor,
                'created_at' => $existing->created_at ?? now(), 'updated_at' => now(),
            ]);
            if ($data['channel'] === 'paypal') {
                $this->reapply($data['month']);
            }
        });
    }

    public function monthlyCnyRate(string $month, string $currency, string $channel): ?string
    {
        $currency = strtoupper($currency);
        if ($currency === 'CNY') {
            return '1';
        }

        $rate = $this->workbooks->database()->table('finance_monthly_exchange_rates')
            ->where(['month' => $month, 'currency' => $currency])
            ->whereIn('channel', array_values(array_unique([$channel, 'combined'])))
            ->orderByRaw('CASE WHEN channel = ? THEN 0 ELSE 1 END', [$channel])
            ->value('cny_rate');

        return $rate === null ? null : (string) $rate;
    }

    public function monthlyCnyRates(string $month, array $channels): array
    {
        $channels = array_values(array_unique($channels));
        $rows = $this->workbooks->database()->table('finance_monthly_exchange_rates')
            ->where('month', $month)->whereIn('channel', [...$channels, 'combined'])
            ->get(['channel', 'currency', 'cny_rate']);
        $combined = $rows->where('channel', 'combined')->keyBy('currency');
        $rates = [];
        foreach ($channels as $channel) {
            $rates[$channel] = ['CNY' => '1'];
            foreach ($combined as $currency => $row) {
                $rates[$channel][strtoupper((string) $currency)] = (string) $row->cny_rate;
            }
            foreach ($rows->where('channel', $channel) as $row) {
                $rates[$channel][strtoupper((string) $row->currency)] = (string) $row->cny_rate;
            }
        }

        return $rates;
    }

    public function lock(string $month, int $actor): void
    {
        if ($month >= now('Asia/Shanghai')->format('Y-m')) {
            throw ValidationException::withMessages(['month' => __('finance.accounting.close_future')]);
        }
        $db = $this->workbooks->database();
        $db->transaction(function () use ($db, $month, $actor): void {
            $this->guardUnlocked($month);
            $this->reapply($month);
            $rows = $this->liveReport($month);
            if (collect($rows)->contains(fn ($row) => $row['cny_amount'] === null || $row['unclassified'] > 0 || $row['direction'] === null)) {
                throw ValidationException::withMessages(['month' => __('finance.accounting.close_incomplete')]);
            }
            $db->table('finance_period_locks')->where('month', $month)->update([
                'report_snapshot' => json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'locked_by' => $actor, 'updated_at' => now(),
            ]);
        });
    }

    public function report(string $month, ?int $account = null, bool $includeUnclassified = true): array
    {
        $snapshot = $this->workbooks->database()->table('finance_period_locks')->where('month', $month)->value('report_snapshot');
        $rows = $snapshot === null ? $this->liveReport($month) : json_decode($snapshot, true, 512, JSON_THROW_ON_ERROR);

        return array_values(array_filter($rows, fn ($row) => ($account === null || $row['account_id'] === $account)
            && ($includeUnclassified || $row['category'] !== null)));
    }

    private function liveReport(string $month): array
    {
        $db = $this->workbooks->database();
        $accounts = FinancePaymentAccount::withTrashed()->get()->keyBy('id');
        if (app(FinanceSourceStorage::class)->active('payment') && in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) {
            $groups = $db->table('finance_payment_transactions')->where('accounting_month', $month)
                ->select('payment_account_id', 'amount_currency', 'category', 'direction', 'loss_subject')
                ->selectRaw('COUNT(*) as total, SUM(amount) as amount, SUM(fee_amount) as fee, SUM(cny_amount) as cny_amount, MIN(id) as first_id')
                ->selectRaw('SUM(amount IS NULL) as missing_amount, SUM(fee_amount IS NULL) as missing_fee, SUM(cny_amount IS NULL) as missing_cny')
                ->groupBy('payment_account_id', 'amount_currency', 'category', 'direction', 'loss_subject')->orderBy('first_id')->get();
            return $groups->map(function ($group) use ($accounts, $month): array {
                $amount = $group->missing_amount ? null : FinanceSourceStorage::money($group->amount);
                $fee = $group->missing_fee ? null : FinanceSourceStorage::money($group->fee);
                return ['account_id' => (int) $group->payment_account_id, 'account' => $accounts->get($group->payment_account_id)?->account_name ?? '—',
                    'channel' => 'paypal', 'month' => $month, 'currency' => $group->amount_currency, 'category' => $group->category,
                    'direction' => $group->direction, 'loss_subject' => $group->loss_subject,
                    'count' => (int) $group->total, 'unclassified' => $group->category === null ? (int) $group->total : 0,
                    'amount' => $amount, 'fee' => $fee, 'net' => $amount === null || $fee === null ? null : bcadd($amount, $fee, 8),
                    'cny_amount' => $group->missing_cny ? null : FinanceSourceStorage::money($group->cny_amount)];
            })->all();
        }
        $rows = $db->table('finance_payment_transactions')->where('accounting_month', $month)->lazyById(500);
        $groups = [];
        foreach (app(FinanceSourceStorage::class)->stream('payment', $rows) as $row) {
            $raw = app(FinanceSourceStorage::class)->values('payment', $row);
            $currency = Arr::get($raw, 'transaction_info.transaction_amount.currency_code');
            $key = json_encode([$row->payment_account_id, $currency, $row->category, $row->direction, $row->loss_subject]);
            $groups[$key] ??= [
                'account_id' => (int) $row->payment_account_id, 'account' => $accounts->get($row->payment_account_id)?->account_name ?? '—',
                'channel' => 'paypal', 'month' => $month, 'currency' => $currency, 'category' => $row->category,
                'direction' => $row->direction, 'loss_subject' => $row->loss_subject,
                'count' => 0, 'unclassified' => 0, 'amount' => '0', 'fee' => '0', 'net' => '0', 'cny_amount' => '0',
            ];
            $group = &$groups[$key];
            $group['count']++;
            $group['unclassified'] += $row->category === null ? 1 : 0;
            $amount = Arr::get($raw, 'transaction_info.transaction_amount.value');
            $fee = Arr::get($raw, 'transaction_info.fee_amount.value');
            $group['amount'] = bcadd($group['amount'], $amount, 8);
            $group['fee'] = $group['fee'] === null || $fee === null ? null : bcadd($group['fee'], $fee, 8);
            $group['net'] = $group['fee'] === null ? null : bcadd($group['amount'], $group['fee'], 8);
            $group['cny_amount'] = $group['cny_amount'] === null || $row->cny_amount === null ? null : bcadd($group['cny_amount'], (string) $row->cny_amount, 8);
            unset($group);
        }

        return array_values($groups);
    }

    public function aggregate(array $rows, string $layer): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $name = $layer === 'channel' ? $row['channel'] : ($row['loss_subject'] ?? 'unassigned');
            $key = $name.'|'.$row['currency'];
            $groups[$key] ??= ['name' => $name, 'currency' => $row['currency'], 'count' => 0, 'amount' => '0', 'fee' => '0', 'net' => '0', 'cny_amount' => '0'];
            $groups[$key]['count'] += $row['count'];
            foreach (['amount', 'fee', 'net', 'cny_amount'] as $field) {
                $groups[$key][$field] = $groups[$key][$field] === null || $row[$field] === null ? null : bcadd($groups[$key][$field], $row[$field], 8);
            }
        }

        return array_values($groups);
    }
}
