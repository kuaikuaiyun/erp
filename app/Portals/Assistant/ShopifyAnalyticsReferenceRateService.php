<?php

namespace App\Portals\Assistant;

class ShopifyAnalyticsReferenceRateService
{
    /** @return array<string, float> */
    public function rates(): array
    {
        $configured = config('assistant_shopify_analytics.reference_rates_to_usd', []);
        if (!is_array($configured)) {
            return [];
        }

        $rates = [];
        foreach ($configured as $currency => $rate) {
            $currency = strtoupper(trim((string) $currency));
            $rate = is_numeric($rate) ? (float) $rate : 0.0;

            if (preg_match('/^[A-Z]{3}$/', $currency) === 1 && $rate > 0) {
                $rates[$currency] = $rate;
            }
        }

        ksort($rates);

        return $rates;
    }

    public function rateFor(?string $currency): ?float
    {
        $currency = strtoupper(trim((string) $currency));

        return $this->rates()[$currency] ?? null;
    }

    public function isConfigured(?string $currency): bool
    {
        return $this->rateFor($currency) !== null;
    }

    public function convertToUsd(?string $currency, mixed $amount): ?float
    {
        $rate = $this->rateFor($currency);
        if ($rate === null || !is_numeric($amount)) {
            return null;
        }

        return (float) $amount * $rate;
    }
}
