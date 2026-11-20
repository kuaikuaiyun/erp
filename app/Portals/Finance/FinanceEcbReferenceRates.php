<?php

namespace App\Portals\Finance;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class FinanceEcbReferenceRates
{
    public function cachedFingerprint(string $month): string
    {
        $default = config('cache.default');
        $cache = Cache::store(config("cache.stores.{$default}.driver") === 'database' ? 'file' : $default);
        [, $usable] = $this->cachedMonth($cache, $month);
        return hash('sha256', json_encode($usable, JSON_THROW_ON_ERROR));
    }

    public function rangeFingerprint(string $from, string $to): string
    {
        $default = config('cache.default');
        $cache = Cache::store(config("cache.stores.{$default}.driver") === 'database' ? 'file' : $default);
        $rates = [];
        for ($date = CarbonImmutable::parse($from)->startOfMonth(); $date->toDateString() <= $to; $date = $date->addMonth()) {
            [, $usable] = $this->cachedMonth($cache, $date->format('Y-m'));
            foreach ($usable as $day => $values) if ($day >= $from && $day <= $to) {
                ksort($values);
                $rates[$day] = $values;
            }
        }
        ksort($rates);
        return hash('sha256', json_encode($rates, JSON_THROW_ON_ERROR));
    }

    private function cachedMonth($cache, string $month): array
    {
        $fresh = $cache->get('finance:ecb-reference:'.$month);
        $key = 'finance:ecb-reference:last-known:'.$month;
        $known = $cache->get($key, []);
        $usable = array_replace_recursive($known, $fresh ?? []);
        if ($usable !== [] && $usable !== $known) {
            // A cache TTL or temporary ECB outage must not erase previously obtained quotes.
            $cache->forever($key, $usable);
        }
        return [$fresh, $usable];
    }

    public function forDates(array $dates): array
    {
        $default = config('cache.default');
        // Exchange-rate lookups can run inside report transactions. Keep cache writes
        // outside the database transaction when the application uses a database cache.
        $cache = Cache::store(config("cache.stores.{$default}.driver") === 'database' ? 'file' : $default);
        $today = now('Asia/Shanghai')->toDateString();
        $months = [];
        foreach ($dates as $date) {
            if ($date <= $today) {
                $months[substr($date, 0, 7)] = true;
            }
        }

        $rates = [];
        $missing = [];
        foreach (array_keys($months) as $month) {
            [$cached, $usable] = $this->cachedMonth($cache, $month);
            $rates = array_replace_recursive($rates, $usable);
            if ($cached === null) {
                $missing[$month] = [];
            }
        }
        if ($missing === [] || $cache->has('finance:ecb-reference:unavailable')) {
            return $rates;
        }

        $start = min(array_keys($missing)).'-01';
        $end = CarbonImmutable::parse(max(array_keys($missing)).'-01')->endOfMonth()->toDateString();
        try {
            $response = Http::withOptions(['verify' => config('finance.relay.ca_bundle') ?: true])
                ->connectTimeout(2)->timeout(8)
                ->get('https://data-api.ecb.europa.eu/service/data/EXR/D..EUR.SP00.A', [
                    'startPeriod' => $start, 'endPeriod' => min($end, $today),
                    'detail' => 'dataonly', 'format' => 'csvdata',
                ]);
        } catch (ConnectionException) {
            $cache->put('finance:ecb-reference:unavailable', true, now()->addMinute());

            return $rates;
        }

        $lines = $response->successful() ? preg_split('/\R/', trim($response->body())) : [];
        $header = array_flip(array_filter(str_getcsv(array_shift($lines) ?? '', escape: ''), 'is_string'));
        if (! isset($header['TIME_PERIOD'], $header['CURRENCY'], $header['OBS_VALUE'])) {
            $cache->put('finance:ecb-reference:unavailable', true, now()->addMinute());

            return $rates;
        }
        foreach ($lines as $line) {
            $row = str_getcsv($line, escape: '');
            $date = $row[$header['TIME_PERIOD']] ?? '';
            $currency = $row[$header['CURRENCY']] ?? '';
            $value = $row[$header['OBS_VALUE']] ?? '';
            $month = substr($date, 0, 7);
            if (isset($missing[$month]) && preg_match('/^\d+(?:\.\d+)?$/D', $value) && bccomp($value, '0', 12) > 0) {
                $missing[$month][$date][$currency] = $value;
            }
        }
        foreach ($missing as $month => $dailyRates) {
            [, $known] = $this->cachedMonth($cache, $month);
            $dailyRates = array_replace_recursive($known, $dailyRates);
            // Recent quotes can still be published or revised.
            $expiry = $month >= substr(now('Asia/Shanghai')->subDays(2)->toDateString(), 0, 7)
                ? now()->addMinutes(30) : now()->addDays(30);
            $cache->put('finance:ecb-reference:'.$month, $dailyRates, $expiry);
            if ($dailyRates !== []) {
                $cache->forever('finance:ecb-reference:last-known:'.$month, $dailyRates);
            }
            $rates = array_replace_recursive($rates, $dailyRates);
        }

        return $rates;
    }
}
