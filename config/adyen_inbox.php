<?php

$path = storage_path('app/private/adyen-inbox.json');
$file = is_readable($path) ? json_decode(file_get_contents($path), true) : [];
$file = is_array($file) ? $file : [];

return [
    'merchant' => env('ADYEN_INBOX_MERCHANT', $file['merchant'] ?? null),
    'username' => env('ADYEN_INBOX_WEBHOOK_USERNAME', $file['username'] ?? null),
    'password' => env('ADYEN_INBOX_WEBHOOK_PASSWORD', $file['password'] ?? null),
    'hmac_keys' => array_filter(array_map('trim', explode(',', (string) env('ADYEN_INBOX_HMAC_KEYS', $file['hmac_keys'] ?? '')))),
    'pull_token' => env('ADYEN_INBOX_PULL_TOKEN', $file['pull_token'] ?? null),
];
