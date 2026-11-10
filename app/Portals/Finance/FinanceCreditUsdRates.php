<?php

namespace App\Portals\Finance;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class FinanceCreditUsdRates
{
    public const TABLE = 'finance_credit_usd_rates';

    public const SCOPES = ['day' => '单日', 'range' => '日期区间', 'month' => '整月'];

    public function __construct(private readonly FinanceWorkbookService $workbooks) {}

    public function save(array $data, int $actor): void
    {
        $scope = $data['usd_scope'];
        $start = $scope === 'month' ? $data['usd_month'].'-01' : $data['usd_start'];
        $end = match ($scope) {
            'month' => CarbonImmutable::parse($start)->endOfMonth()->toDateString(),
            'range' => $data['usd_end'],
            default => $start,
        };
        $db = $this->workbooks->database();
        $db->transaction(function () use ($db, $data, $actor, $scope, $start, $end): void {
            $accounting = app(FinanceAccountingService::class);
            for ($month = CarbonImmutable::parse($start)->startOfMonth(); $month->toDateString() <= $end; $month = $month->addMonth()) {
                $accounting->guardUnlocked($month->format('Y-m'));
            }
            $now = now();
            $db->table(self::TABLE)->upsert([[
                'currency' => $data['usd_currency'], 'scope' => $scope, 'starts_on' => $start, 'ends_on' => $end,
                'usd_rate' => $data['usd_rate'], 'updated_by' => $actor, 'created_at' => $now, 'updated_at' => $now,
            ]], ['currency', 'scope', 'starts_on', 'ends_on'], ['usd_rate', 'updated_by', 'updated_at']);
        });
    }

    public function forOrders(Collection $orders): array
    {
        $currencies = $orders->pluck('currency')->filter()->unique()->reject(fn ($currency) => $currency === 'USD');
        if ($currencies->isEmpty()) {
            return [collect(), []];
        }
        $dates = $orders->pluck('occurred_at');

        $manual = $this->workbooks->database()->table(self::TABLE)
            ->whereIn('currency', $currencies)
            ->where('starts_on', '<=', $dates->max()->setTimezone('Asia/Shanghai')->toDateString())
            ->where('ends_on', '>=', $dates->min()->setTimezone('Asia/Shanghai')->toDateString())
            ->orderByRaw("case scope when 'day' then 1 when 'range' then 2 else 3 end")
            ->orderByDesc('starts_on')->orderBy('ends_on')->get()->groupBy('currency');
        $missingDates = [];
        foreach ($orders as $order) {
            if (! $currencies->contains($order->currency) || isset($order->payload['_manual_import_month'])) {
                continue;
            }
            $date = $order->occurred_at->setTimezone('Asia/Shanghai')->toDateString();
            if ($this->manualRate($manual, $order->currency, $date) === null) {
                $missingDates[$date] = true;
            }
        }

        return [$manual, app(FinanceEcbReferenceRates::class)->forDates(array_keys($missingDates))];
    }

    public function convert(array $rates, string $currency, string $date, string $amount): string
    {
        if ($currency === 'USD') {
            return $amount;
        }
        [$manual, $reference] = $rates;
        $rate = $this->manualRate($manual, $currency, $date);
        if ($rate === null) {
            $usdPerEuro = $reference[$date]['USD'] ?? null;
            $originalPerEuro = $currency === 'EUR' ? '1' : ($reference[$date][$currency] ?? null);
            if ($usdPerEuro === null || $originalPerEuro === null) {
                return '汇率未填写';
            }
            $converted = bcdiv(bcmul($amount, $usdPerEuro, 20), $originalPerEuro, 16);
        } else {
            $converted = bcmul($amount, $rate, 16);
        }

        return bcadd($converted, bccomp($converted, '0', 16) < 0 ? '-0.005' : '0.005', 2);
    }

    private function manualRate(Collection $rates, string $currency, string $date): ?string
    {
        $rate = $rates->get($currency)?->first(fn ($rate) => $rate->starts_on <= $date && $rate->ends_on >= $date);

        return $rate ? (string) $rate->usd_rate : null;
    }
}
