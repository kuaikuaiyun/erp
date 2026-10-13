<?php

return [
    'shopify' => [
        'api_version' => env('SHOPIFY_API_VERSION', '2026-01'),
        'sync_days' => (int) env('SHOPIFY_SYNC_DAYS', 10),
        'webhook_url' => env('SHOPIFY_WEBHOOK_URL', 'https://wuliu.digilabreview.com/shopify/webhooks/orders'),
    ],
    'profit' => [
        'cny_per_usd' => (float) env('LOGISTICS_CNY_PER_USD', 6.7049),
        'handling_fee_rate' => (float) env('LOGISTICS_HANDLING_FEE_RATE', 0.06),
        'proportional_refund_rate' => (float) env('LOGISTICS_PROPORTIONAL_REFUND_RATE', 0.08),
    ],
    'tongtu' => [
        'app_key' => 'dc10c1ffab5e4b74b1a948c0aac49f12',
        'app_secret' => 'e021ce952acb42a6a8db74316b8133631ff1d61378b64befaa7c3398c7fd22db',
        // 留空时由 getAppBuyerList 接口返回的 partnerOpenId 自动确定。
        // 仅当一个应用下存在多个合作伙伴时，才通过环境变量显式指定 partnerOpenId。
        'merchant_id' => env('LOGISTICS_TONGTU_MERCHANT_ID', ''),
        'request_interval_seconds' => (int) env('LOGISTICS_TONGTU_REQUEST_INTERVAL_SECONDS', 13),
        'app_token_cache_minutes' => (int) env('LOGISTICS_TONGTU_APP_TOKEN_CACHE_MINUTES', 50),
        'goods_cache_minutes' => (int) env('LOGISTICS_TONGTU_GOODS_CACHE_MINUTES', 360),
        'app_token_url' => 'https://open.tongtool.com/open-platform-service/devApp/appToken',
        'partner_list_url' => 'https://open.tongtool.com/open-platform-service/partnerOpenInfo/getAppBuyerList',
        'shopify_order_query_url' => 'https://open.tongtool.com/api-service/openapi/tongtool/shopifyOrderQuery',
        'goods_query_url' => 'https://open.tongtool.com/api-service/openapi/tongtool/goodsQuery',
    ],
    'paypal' => [
        'import_secret' => env('LOGISTICS_PAYPAL_IMPORT_SECRET', ''),
        'import_max_bytes' => (int) env('LOGISTICS_PAYPAL_IMPORT_MAX_BYTES', 20 * 1024 * 1024),
        'import_clock_skew_seconds' => (int) env('LOGISTICS_PAYPAL_IMPORT_CLOCK_SKEW_SECONDS', 300),
    ],
];
