<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Portals\Logistics\SharedLogisticsOrderProjection;

class SharedLogisticsOrder extends LogisticsOrder
{
    protected $table = 'logistics_orders';

    public function newModelQuery(): Builder
    {
        $db = $this->getConnection();
        $projection = $db->table('shop_order_logistics as detail')
            ->join('shop_order_source_map as source', function ($join): void {
                $join->on('source.source_id', '=', 'detail.id')->where('source.source_table', 'logistics_orders');
            })
            ->join('shop_order_list as shared', 'shared.id', '=', 'source.order_id')
            ->where('shared.source_platform', 'shopify')->where('shared.source_system', 'logistics')
            ->select('detail.*', 'shared.id as shared_order_id', 'shared.total_amount', 'shared.currency');

        foreach (SharedLogisticsOrderProjection::SHARED_FIELDS as $column => $alias) {
            $projection->addSelect('shared.'.$column.' as '.$alias);
        }

        return parent::newModelQuery()->fromSub($projection, $this->getTable());
    }

    public function scopeWithPayloads(Builder $query, ?array $types = null): void
    {
        if ($query->getQuery()->columns === null) {
            $query->select($this->getTable().'.*');
        }

        foreach ($types ?? array_keys(SharedLogisticsOrderProjection::PAYLOAD_FIELDS) as $type) {
            $column = SharedLogisticsOrderProjection::PAYLOAD_FIELDS[$type];
            $query->selectSub($this->getConnection()->table('shop_order_payloads as payload')
                ->select('payload.payload')
                ->whereColumn('payload.order_id', $this->getTable().'.shared_order_id')
                ->where('payload.payload_type', $type), $column);
        }
    }

    public function items(): HasMany
    {
        return $this->hasMany(SharedLogisticsOrderItem::class, 'logistics_order_id');
    }
}
