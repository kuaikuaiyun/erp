<?php

return [
    'shop_order_backfill' => [
        'projection_initializing' => 'The shared logistics order tables are being initialized. Please try again after synchronization completes.',
        'projection_check' => 'Full comparison. Missing orders :missing_orders, different orders :different_orders, missing or different lines :different_items, extra orders :extra_orders, extra lines :extra_items, pending :pending.',
        'description' => 'Backfill Shopify logistics orders into the shared order tables',
        'missing_table' => 'Shared order table :table is missing. Check that the tables have been created.',
        'invalid_range' => 'Order IDs must be positive integers, with the ending ID greater than or equal to the starting ID.',
        'range' => 'Database :database. Scanning legacy order IDs :from to :to.',
        'dry_run' => 'Dry run, no writes',
        'saved' => 'Backfill',
        'invalid_identity' => 'Skipped legacy order :id because it has no valid Shopify domain or order ID.',
        'conflict' => 'Legacy order :id matches a Shopify domain and order ID already in use. Review this order.',
        'unknown_money' => 'The saved payload does not verify the total or currency for legacy order :id. Shared fields are left empty.',
        'unknown_order_time' => 'The order time zone for legacy order :id could not be verified. The shared order time is left empty.',
        'unknown_paid_time' => 'The payment time zone for legacy order :id could not be verified. The shared payment time is left empty.',
        'summary' => ':mode. Eligible or written :written, already mapped :mapped, invalid identity :invalid_identity, conflicting identity :conflict, uncertain money :unknown_money, uncertain order time :unknown_order_time, uncertain payment time :unknown_paid_time. Up to 10 legacy IDs are shown per issue.',
    ],
    'orders' => [
        'amount_identifiers' => [
            'order_total' => 'Order total:',
        ],
    ],
];
