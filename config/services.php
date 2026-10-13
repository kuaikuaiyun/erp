<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'assistant' => [
        'shopify_analytics' => [
            // All dashboard statistics use this reporting timezone by default.
            'default_timezone' => env('ASSISTANT_SHOPIFY_ANALYTICS_TIMEZONE', 'Asia/Shanghai'),
        ],
        'email_purchase' => [
            // Filled after the external mailbox purchase source is confirmed.
            'sync_url' => env('ASSISTANT_EMAIL_PURCHASE_SYNC_URL', ''),
            'sync_method' => env('ASSISTANT_EMAIL_PURCHASE_SYNC_METHOD', 'GET'),
            'sync_token' => env('ASSISTANT_EMAIL_PURCHASE_SYNC_TOKEN', ''),
            'sync_origin' => env('ASSISTANT_EMAIL_PURCHASE_ORIGIN', ''),
            'emails_path' => env('ASSISTANT_EMAIL_PURCHASE_EMAILS_PATH', 'data'),
            'timeout_seconds' => (int) env('ASSISTANT_EMAIL_PURCHASE_TIMEOUT', 30),
        ],
    ],

];
