<?php

return [
    'main_domain' => trim(strtolower(trim((string) env('PORTAL_MAIN_DOMAIN', ''))), '.'),
    'root_domain' => trim(strtolower(trim((string) env('PORTAL_ROOT_DOMAIN', ''))), '.'),
    'scheme' => env('PORTAL_SCHEME', env('APP_ENV') === 'production' ? 'https' : 'http'),
    'countdown_seconds' => (int) env('PORTAL_REDIRECT_COUNTDOWN', 5),
    'reserved_codes' => array_values(array_filter(array_map(
        static fn (string $code): string => strtolower(trim($code)),
        explode(',', (string) env('PORTAL_RESERVED_CODES', 'www,api,mail,ftp,localhost')),
    ))),
];
