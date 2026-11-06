<?php

return [
    'relay' => [
        'ca_bundle' => env('FINANCE_RELAY_CA_BUNDLE'),
        'timeout' => 20,
        'clock_skew' => 300,
        'max_response_bytes' => 20 * 1024 * 1024,
        'max_pages' => 1000,
        'segment_days' => 7,
        'initial_months' => 6,
        'incremental_hours' => 5,
        'page_size' => 500,
    ],
];
