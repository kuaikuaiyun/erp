<?php

return [
    'summaries' => [
        // Legacy report-snapshot options; ordinary reports no longer use them.
        'web_refresh' => env('FINANCE_SUMMARY_WEB_REFRESH', false),
        'snapshots_per_run' => 2,
        'snapshot_budget_seconds' => 45,
        'paypal_rebuild_cooldown_seconds' => 300,
        'dependency_changes_per_run' => 100,
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
