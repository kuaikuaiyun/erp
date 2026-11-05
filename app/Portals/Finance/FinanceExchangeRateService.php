<?php

namespace App\Portals\Finance;

use Illuminate\Support\Collection;

class FinanceExchangeRateService
{
    public const CURRENCIES = ['USD', 'CNY', 'EUR', 'HKD', 'GBP', 'CAD', 'AUD', 'NZD', 'JPY', 'SGD', 'CHF'];

    public function __construct(private readonly FinanceWorkbookService $workbooks) {}

    public function configuration(Collection $sourceCurrencies, string $selected): array
    {
        $records = $this->workbooks->database()->table('finance_exchange_rates')->orderBy('currency')->get();
        $rates = $records->pluck('units_per_usd', 'currency')->map(fn ($rate) => (string) $rate)->put('USD', '1');
        $currencies = collect(self::CURRENCIES)->merge($sourceCurrencies)->merge($rates->keys())->push($selected)->unique()
            ->sortBy(fn ($currency) => ($currency === 'USD' ? '0' : '1').$currency)->values();

        return ['rates' => $rates, 'currencies' => $currencies, 'selected' => $selected, 'updated_at' => $records->max('updated_at')];
    }

    /** Rates mean 1 USD = units_per_usd units of the given currency. */
    public function convert(Collection $amountsByCurrency, string $target, Collection $rates): array
    {
        $converted = ['available' => null, 'withheld' => null, 'total' => null];
        $missing = collect();
        if ($amountsByCurrency->isNotEmpty()) {
            foreach (['available', 'withheld', 'total'] as $field) {
                $sum = '0';
                $complete = true;
                foreach ($amountsByCurrency as $currency => $amounts) {
                    $amount = array_key_exists($field, $amounts) ? $amounts[$field]
                        : ($field === 'total' && isset($amounts['available'], $amounts['withheld']) ? bcadd($amounts['available'], $amounts['withheld'], 16) : null);
                    if ($amount === null) {
                        $complete = false;
                        continue;
                    }
                    if ($currency === $target || bccomp($amount, '0', 10) === 0) {
                        $sum = bcadd($sum, $amount, 16);
                        continue;
                    }
                    foreach ([$currency, $target] as $required) {
                        if (! $rates->has($required)) {
                            $missing->push($required);
                        }
                    }
                    if (! $rates->has($currency) || ! $rates->has($target)) {
                        $complete = false;
                        continue;
                    }
                    $sum = bcadd($sum, bcdiv(bcmul($amount, $rates[$target], 20), $rates[$currency], 16), 16);
                }
                $converted[$field] = $complete ? $sum : null;
            }
        }

        return [...$converted, 'currency' => $target, 'missing_rates' => $missing->unique()->sort()->values()];
    }
}
