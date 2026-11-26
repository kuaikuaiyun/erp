<?php

return [
    'auth_mode' => env('ERP_AUTH_MODE', 'password'),
    'challenge_seconds' => max(60, (int) env('DINGTALK_CHALLENGE_SECONDS', 300)),
    'http_timeout' => max(1, (int) env('DINGTALK_HTTP_TIMEOUT', 5)),
    'login_rate' => max(1, (int) env('DINGTALK_LOGIN_RATE', 120)),
    'callback_rate' => max(1, (int) env('DINGTALK_CALLBACK_RATE', 120)),
    'selection_rate' => max(1, (int) env('DINGTALK_SELECTION_RATE', 60)),
];
