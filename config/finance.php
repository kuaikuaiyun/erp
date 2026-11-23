<?php

return [
    'overviews' => [
        'background' => env('FINANCE_BACKGROUND_OVERVIEWS', true),
        'auto_start' => env('FINANCE_OVERVIEW_AUTO_START', true),
        'php_binary' => env('FINANCE_OVERVIEW_PHP_BINARY'),
    ],
    'diagnostics' => [
        'enabled' => env('FINANCE_QUERY_DIAGNOSTICS', true),
    ],
    'summaries' => [
        'paypal_rebuild_cooldown_seconds' => 300,
        'dependency_changes_per_run' => 1000,
        'notification_batch_size' => 50,
        'notification_scan_limit' => 2000,
        'notification_read_scan_limit' => 32,
        'notification_seconds_per_run' => 5,
        // Used only by bounded source-row scans; smaller workers retain 500.
        'source_batch_size' => 2000,
        'source_request_concurrency' => 2,
        'source_cache_path' => env('FINANCE_SUMMARY_SOURCE_CACHE_PATH'),
        'external_change_path' => env('FINANCE_SOURCE_CHANGE_PATH'),
    ],
    'relay' => [
        'ca_bundle' => env('FINANCE_RELAY_CA_BUNDLE'),
        'timeout' => 20,
        'clock_skew' => 300,
        'max_response_bytes' => 20 * 1024 * 1024,
        'max_pages' => 1000,
        'segment_days' => 7,
        'initial_months' => 6,
        'incremental_hours' => 72,
        'page_size' => 500,
    ],
];
