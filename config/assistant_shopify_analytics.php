<?php

return [
    /*
     * Fixed reference rates used only by the Assistant Shopify analytics
     * display. Shopify source amounts remain untouched in their original
     * currency; this map is deliberately not a financial settlement source.
     *
     * One unit of the source currency equals the configured USD amount.
     */
    'reference_rates_to_usd' => [
        'USD' => 1.000000,
        'EUR' => 1.080000,
        'GBP' => 1.270000,
        'CNY' => 0.140000,
        'CAD' => 0.720000,
        'AUD' => 0.650000,
        'JPY' => 0.006700,
        'KRW' => 0.000720,
        'HKD' => 0.128000,
        'SGD' => 0.740000,
        'TWD' => 0.031000,
        'AED' => 0.272300,
        'SAR' => 0.266700,
        'INR' => 0.012000,
        'BRL' => 0.180000,
        'CHF' => 1.120000,
        'NZD' => 0.600000,
        'MXN' => 0.056000,
    ],
];
