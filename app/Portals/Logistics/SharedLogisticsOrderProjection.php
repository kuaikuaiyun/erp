<?php

namespace App\Portals\Logistics;

use Illuminate\Database\Connection;

class SharedLogisticsOrderProjection
{
    public const SHARED_FIELDS = [
        'assistant_domain_store_id' => 'assistant_domain_store_id',
        'order_number' => 'shopify_order_number',
        'store_name' => 'store_name',
        'buyer_name' => 'buyer_name',
        'buyer_email' => 'buyer_email',
        'buyer_phone' => 'buyer_phone',
        'is_add_on' => 'is_add_on',
        'subscription_type' => 'subscription_type',
        'subscription_origin_order_id' => 'subscription_origin_order_id',
        'subscription_status' => 'subscription_status',
        'payment_status' => 'payment_status',
        'payment_method' => 'payment_method',
        'payment_gateway' => 'payment_gateway',
        'transaction_id' => 'transaction_id',
        'logistics_status' => 'logistics_status',
        'product_cost_usd' => 'product_cost_usd',
        'handling_fee_usd' => 'handling_fee_usd',
        'total_shipping_usd' => 'total_shipping_usd',
        'proportional_profit_usd' => 'proportional_profit_usd',
        'proportional_profit_rate' => 'proportional_profit_rate',
        'financial_profit_usd' => 'financial_profit_usd',
        'financial_profit_rate' => 'financial_profit_rate',
        'operation_profit_usd' => 'operation_profit_usd',
        'operation_profit_rate' => 'operation_profit_rate',
        'created_at' => 'created_at',
        'updated_at' => 'updated_at',
    ];

    public const PAYLOAD_FIELDS = [
        'platform_order' => 'shopify_payload',
        'tongtu_order' => 'tongtu_payload',
        'fulfillment' => 'fulfillment_payload',
        'tracking' => 'tracking_raw_payload',
        'page_source' => 'page_source',
    ];

    public static function write(Connection $db, array $order, array $columns): void
    {
        $details = array_intersect_key($order, $columns);
        $db->table('shop_order_logistics')->updateOrInsert(['id' => $order['id']], $details);
    }
}
