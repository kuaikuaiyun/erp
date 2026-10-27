<?php

return [
    'shopify' => [
        'api_version' => env('SHOPIFY_API_VERSION', '2026-01'),
        'sync_days' => (int) env('SHOPIFY_SYNC_DAYS', 60),
        'webhook_url' => env('SHOPIFY_WEBHOOK_URL', 'https://wuliu.digilabreview.com/shopify/webhooks/orders'),
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
    'sku_database' => [
        'url' => env('LOGISTICS_SKU_DATABASE_URL', 'https://www.kuaiyun888.com/blog/sku-sync/index.php?action=database_lookup'),
        'token' => env('LOGISTICS_SKU_DATABASE_TOKEN', ''),
        'timeout' => (int) env('LOGISTICS_SKU_DATABASE_TIMEOUT', 15),
        'cache_minutes' => (int) env('LOGISTICS_SKU_DATABASE_CACHE_MINUTES', 10),
    ],
    'paypal' => [
        'import_secret' => env('LOGISTICS_PAYPAL_IMPORT_SECRET', ''),
        'import_max_bytes' => (int) env('LOGISTICS_PAYPAL_IMPORT_MAX_BYTES', 20 * 1024 * 1024),
        'import_clock_skew_seconds' => (int) env('LOGISTICS_PAYPAL_IMPORT_CLOCK_SKEW_SECONDS', 300),
    ],
    'tracking' => [
        'api_key' => env('TRACKING51_API_KEY', ''),
        'base_url' => env('TRACKING51_BASE_URL', 'https://api.51tracking.com/v4'),
        'timeout' => (int) env('TRACKING51_TIMEOUT', 30),
        'initial_query_delay_minutes' => (int) env('TRACKING51_INITIAL_QUERY_DELAY_MINUTES', 2),
    ],
];
