<?php

namespace App\Portals\Finance;

use App\Models\FinancePaymentAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class FinancePayPalBalanceService
{
    public function __construct(private readonly FinanceWorkbookService $workbooks) {}

    public function dashboard(string $displayCurrency = 'USD'): array
    {
        $db = $this->workbooks->database();
        $accounts = FinancePaymentAccount::query()->where('account_type', 'paypal')->orderBy('id')->get();
        $snapshots = $db->table('finance_balance_snapshots')->whereIn('payment_account_id', $accounts->modelKeys())
            ->orderByDesc('as_of')->orderByDesc('id')->get()->unique('payment_account_id')->keyBy('payment_account_id');
        $latestBalanceLogIds = $db->table('finance_sync_logs')->selectRaw('MAX(id)')
            ->whereIn('payment_account_id', $accounts->modelKeys())->where('operation', 'balances')
            ->whereIn('status', ['success', 'failed'])->groupBy('payment_account_id');
        $balanceLogs = $db->table('finance_sync_logs')->whereIn('id', $latestBalanceLogIds)
            ->get(['payment_account_id', 'status'])->keyBy('payment_account_id');
        $cards = [];
        foreach ($accounts as $account) {
            $key = 'finance-'.$account->id;
            $lastBalanceLog = $balanceLogs->get($account->id);
            $relayConfigured = filled($account->relay_url) && filled($account->relay_certificate_sha1)
                && filled($account->relay_hmac_key_id) && filled($account->getRawOriginal('relay_hmac_secret'));
            $connectionStatus = $lastBalanceLog
                ? ($lastBalanceLog->status === 'success' ? 'connected' : 'error')
                : ($snapshots->has($account->id) ? 'connected' : 'disconnected');
            $cards[$key] = [
                'currencies' => [],
                'key' => $key, 'name' => $account->activity_account,
                'finance_account_id' => $account->id, 'account_name' => $account->account_name,
                'account_code' => $account->account_code, 'main_currency' => $account->main_currency,
                'status' => $account->status, 'remark' => $account->remark ?? '',
                'relay_configured' => $relayConfigured,
                'fetched_at' => $this->time($snapshots->get($account->id)?->created_at),
                'connection_status' => $account->status === 'disabled' ? 'closed' : $connectionStatus,
            ];
            $snapshot = $snapshots->get($account->id);
            if ($snapshot) {
                $payload = json_decode($snapshot->payload, true, 512, JSON_THROW_ON_ERROR);
                foreach ($payload['balances'] as $balance) {
                    $cards[$key]['currencies'][$balance['currency']][] = [
                        'available' => $balance['available_balance']['value'] ?? null,
                        'withheld' => $balance['withheld_balance']['value'] ?? null,
                        'total' => $balance['total_balance']['value'] ?? null,
                        'as_of' => $this->time($snapshot->as_of), 'source' => 'relay',
                    ];
                }
            }
        }
        foreach ($cards as &$card) {
            foreach ($card['currencies'] as $currency => $rows) {
                $available = $this->sum($rows, 'available');
                $withheld = $this->sum($rows, 'withheld');
                $times = collect($rows)->pluck('as_of')->filter();
                $card['currencies'][$currency] = [
                    'currency' => $currency, 'available' => $available, 'withheld' => $withheld,
                    'total' => $this->sum($rows, 'total'),
                    'as_of' => $times->min(), 'latest_as_of' => $times->max(), 'source' => $rows[0]['source'],
                ];
            }
        }
        unset($card);
        $cards = collect($cards)->map(function ($card): array {
            $card['currencies'] = collect($card['currencies'])->sortBy(fn ($row) => ($row['currency'] === 'USD' ? '0' : '1').$row['currency'])->values();
            $card['activity_account'] = $card['name'];
            $card['name'] = $card['account_name'];

            return $card;
        })->sortBy(fn ($card) => match ($card['connection_status']) {
            'connected' => 0,
            'closed' => 1,
            'error' => 2,
            default => 3,
        });
        $totals = $cards->where('status', 'enabled')->flatMap(fn ($card) => $card['currencies'])->groupBy('currency')->map(function (Collection $rows): array {
            $available = $this->sum($rows->all(), 'available');
            $withheld = $this->sum($rows->all(), 'withheld');

            return ['available' => $available, 'withheld' => $withheld, 'total' => $this->sum($rows->all(), 'total')];
        })->sortKeys();
        $availableCurrencies = $cards->flatMap(fn ($card) => $card['currencies']->pluck('currency'))->unique()
            ->sortBy(fn ($currency) => ($currency === 'USD' ? '0' : '1').$currency)->values();
        if (! $availableCurrencies->contains($displayCurrency)) {
            $displayCurrency = $availableCurrencies->contains('USD') ? 'USD' : ($availableCurrencies->first() ?? 'USD');
        }
        $summary = $this->nativeBalance($totals->get($displayCurrency), $displayCurrency);
        $cards = $cards->map(function ($card) use ($displayCurrency): array {
            $card['display'] = $this->nativeBalance($card['currencies']->firstWhere('currency', $displayCurrency), $displayCurrency);
            $card['as_of'] = $card['currencies']->pluck('as_of')->filter()->min();
            $card['latest_as_of'] = $card['currencies']->pluck('latest_as_of')->filter()->max();

            return $card;
        });
        $missing = $cards->filter(fn ($card) => $card['status'] === 'enabled' && ! $card['display']['has_balance'])->pluck('name')->values();
        $incomplete = $cards->where('status', 'enabled')->contains(fn ($card) => $card['display']['has_balance']
            && ($card['display']['available'] === null || $card['display']['withheld'] === null || $card['display']['total'] === null));

        $monthlyCny = null;
        $month = $db->table('finance_monthly_exchange_rates')->where('month', '<=', now('Asia/Shanghai')->format('Y-m'))->max('month');
        if ($month !== null) {
            $rates = $db->table('finance_monthly_exchange_rates')->where('month', $month)->pluck('cny_rate', 'currency')->put('CNY', '1');
            $converted = $totals->isEmpty() ? null : '0';
            foreach ($totals as $currency => $amounts) {
                if ($amounts['total'] === null || (! $rates->has($currency) && bccomp($amounts['total'], '0', 8) !== 0)) {
                    $converted = null;
                    break;
                }
                if ($converted !== null) {
                    $converted = bcadd($converted, bcmul($amounts['total'], (string) ($rates->get($currency) ?? '0'), 16), 16);
                }
            }
            $monthlyCny = ['month' => $month, 'total' => $converted];
        }

        return compact('accounts', 'snapshots', 'cards', 'totals', 'missing', 'incomplete', 'summary', 'availableCurrencies', 'monthlyCny');
    }

    private function nativeBalance(?array $balance, string $currency): array
    {
        $display = ['currency' => $currency, 'has_balance' => $balance !== null];
        foreach (['available', 'withheld', 'total'] as $field) {
            $display[$field] = isset($balance[$field]) ? bcadd($balance[$field], '0', 16) : null;
        }

        return $display;
    }

    private function sum(array $rows, string $field): ?string
    {
        if (collect($rows)->contains(fn ($row) => $row[$field] === null)) {
            return null;
        }

        return array_reduce($rows, fn ($sum, $row) => bcadd($sum, $row[$field], 8), '0.00000000');
    }

    private function time(?string $value): ?CarbonImmutable
    {
        return $value === null ? null : CarbonImmutable::parse($value, 'UTC');
    }
}
