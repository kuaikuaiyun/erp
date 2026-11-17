<?php

return [
    'nbc' => [
        'endpoint' => 'https://openapi.oemapps.com/orders',
        'page_size' => 10,
        'max_pages' => 10000,
        // Stay below the lowest documented plan limit (20 requests / 10 seconds).
        'request_interval_ms' => 600,
    ],
];
