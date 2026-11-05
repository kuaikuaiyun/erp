<?php

namespace App\Portals\Finance;

use App\Models\FinancePaymentBalance;
use Illuminate\Support\Collection;

class FinancePaymentBalanceService
{
    public const COMPONENTS = ['withdrawable', 'pending', 'reserve'];

    public function dashboard(?string $account = null): array
    {
        $query = FinancePaymentBalance::query()->where('channel', 'xuzong2');
        $accounts = (clone $query)->distinct()->orderBy('account')->pluck('account');
        if ($account !== null && $account !== '') {
            $query->where('account', $account);
        }
        $balances = $query->orderBy('currency')->orderBy('account')->get();
        $rows = $balances->groupBy('currency')->map(function (Collection $balances, string $currency): array {
            $amounts = [];
            foreach (self::COMPONENTS as $field) {
                $amounts[$field] = $balances->reduce(fn ($sum, $row) => bcadd($sum, $row->$field, 4), '0.0000');
            }

            return [
                ...$amounts, 'currency' => $currency, 'total' => $this->total($amounts),
                'accounts' => $balances, 'as_of' => $balances->min('as_of'),
            ];
        })->sortBy(fn ($row) => ($row['currency'] === 'USD' ? '0' : '1').$row['currency'])->values();
        $missingRates = $balances->filter(fn ($row) => $row->currency !== 'USD' && $row->usd_rate === null)->pluck('currency')->unique()->values();
        $summary = array_fill_keys([...self::COMPONENTS, 'total'], null);
        if ($balances->isNotEmpty() && $missingRates->isEmpty()) {
            foreach (self::COMPONENTS as $field) {
                $summary[$field] = $balances->reduce(
                    fn ($sum, $row) => bcadd($sum, bcmul($row->$field, $row->currency === 'USD' ? '1' : $row->usd_rate, 12), 12),
                    '0.000000000000',
                );
            }
            $summary['total'] = $this->total($summary, 12);
        }

        return compact('accounts', 'balances', 'rows', 'summary', 'missingRates') + [
            'account' => $account, 'as_of' => $balances->max('as_of'),
            'oldest_as_of' => $balances->min('as_of'),
        ];
    }

    public function total(array $amounts, int $scale = 4): string
    {
        return array_reduce(self::COMPONENTS, fn ($sum, $key) => bcadd($sum, $amounts[$key], $scale), '0');
    }

    public function format(?string $amount): string
    {
        if ($amount === null) {
            return '—';
        }
        $rounded = bccomp($amount, '0', 12) < 0 ? bcsub($amount, '0.005', 2) : bcadd($amount, '0.005', 2);
        [$whole, $fraction] = explode('.', $rounded);

        return preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $whole).'.'.$fraction;
    }
}
