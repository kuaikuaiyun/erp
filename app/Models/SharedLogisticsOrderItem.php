<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;

class SharedLogisticsOrderItem extends LogisticsOrderItem
{
    protected $table = 'logistics_order_items';

    public function newModelQuery(): Builder
    {
        $db = $this->getConnection();
        $projection = $db->table('shop_order_items as item')
            ->join('shop_order_source_map as source', 'source.order_id', '=', 'item.order_id')
            ->where('source.source_table', 'logistics_orders')
            ->whereNotNull('item.legacy_logistics_item_id')
            ->select('item.legacy_logistics_item_id as id', 'item.legacy_logistics_item_id',
                'source.source_id as logistics_order_id', 'item.line_key as source_key',
                'item.external_item_id as shopify_line_item_id', 'item.external_product_id as shopify_product_id',
                'item.external_variant_id as shopify_variant_id', 'item.product_name as product_title',
                'item.variant_name as variant_title', 'item.image_url as product_image_url', 'item.sku as shopify_sku',
                'item.quantity', 'item.tongtu_sku_mapping_id', 'item.tongtu_product_id', 'item.tongtu_goods_detail_id',
                'item.tongtu_sku', 'item.tongtu_product_code', 'item.tongtu_product_name', 'item.tongtu_product_type',
                'item.tongtu_goods_weight_gram', 'item.tongtu_package_weight_gram', 'item.sale_weight_gram',
                'item.unit_price_usd', 'item.line_revenue_usd', 'item.product_cost_usd', 'item.handling_fee_usd',
                'item.total_shipping_usd', 'item.proportional_refund_usd', 'item.profit_usd', 'item.profit_rate',
                'item.created_at', 'item.updated_at');

        return parent::newModelQuery()->fromSub($projection, $this->getTable());
    }
}
