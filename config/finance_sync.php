<?php

return [
    'enabled' => (bool) env('FINANCE_SYNC_ENABLED', false),
    'token_sha256' => env('FINANCE_SYNC_TOKEN_SHA256', ''),
    'token_expires_at' => (int) env('FINANCE_SYNC_TOKEN_EXPIRES_AT', 0),
    'previous_token_sha256' => env('FINANCE_SYNC_PREVIOUS_TOKEN_SHA256', ''),
    'previous_token_expires_at' => (int) env('FINANCE_SYNC_PREVIOUS_TOKEN_EXPIRES_AT', 0),
    'require_https' => (bool) env('FINANCE_SYNC_REQUIRE_HTTPS', true),
    'rate_store' => env('FINANCE_SYNC_RATE_STORE', 'file'),
    'requests_per_minute' => (int) env('FINANCE_SYNC_REQUESTS_PER_MINUTE', 60),
    'page_size' => 100,
    'max_page_size' => 200,
    'include_account_email' => (bool) env('FINANCE_SYNC_INCLUDE_ACCOUNT_EMAIL', false),
    'include_sku_cost' => (bool) env('FINANCE_SYNC_INCLUDE_SKU_COST', false),
    'source_timezone' => env('FINANCE_SYNC_SOURCE_TIMEZONE', config('app.timezone', 'UTC')),
];
