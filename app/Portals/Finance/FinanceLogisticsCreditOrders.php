<?php

namespace App\Portals\Finance;

use App\Models\FinanceRecord;
use Illuminate\Database\Eloquent\Builder;

class FinanceLogisticsCreditOrders
{
    public function query(): Builder
    {
        $db = app(FinanceWorkbookService::class)->database();
        if (! $db->getSchemaBuilder()->hasTable('logistics_orders')) {
            return FinanceRecord::query()->whereRaw('1 = 0');
        }
        $grammar = $db->getQueryGrammar();
        $json = fn ($key) => $grammar->wrap('o.shopify_payload->'.str_replace('.', '->', $key));
        $account = "COALESCE(o.collection_account, '')";
        $reference = "COALESCE(NULLIF(o.transaction_id, ''), NULLIF(o.payment_gateway_order_id, ''), NULLIF(o.bill_number, ''), o.shopify_order_number)";
        $hasStores = $db->getSchemaBuilder()->hasTable('assistant_domain_stores')
            && $db->getSchemaBuilder()->hasColumn('logistics_orders', 'assistant_domain_store_id');
        $domain = $hasStores ? "COALESCE(NULLIF(s.domain, ''), NULLIF(o.source_shop_domain, ''), '')" : "COALESCE(NULLIF(o.source_shop_domain, ''), '')";
        $indexed = $db->getSchemaBuilder()->hasColumn('logistics_orders', 'finance_is_credit_card');
        $source = $db->table('logistics_orders as o');
        if ($hasStores) {
            $source->leftJoin('assistant_domain_stores as s', 's.id', '=', 'o.assistant_domain_store_id');
        }
        if ($db->getSchemaBuilder()->hasColumn('logistics_orders', 'finance_is_non_paypal')) {
            $source->where('o.finance_is_non_paypal', 1);
        } else {
            $source->whereRaw("LOWER(TRIM(COALESCE(o.payment_gateway, ''))) <> ?", ['paypal']);
        }
        $occurredAt = $indexed ? 'o.finance_credit_occurred_at' : 'COALESCE(o.order_created_at, o.paid_at)';
        $values = [
            'o.store_name', $account, 'o.shopify_order_number', $reference,
            'COALESCE('.$json('currentTotalPriceSet.shopMoney.currencyCode').', '.$json('currency').')',
            'COALESCE('.$json('currentTotalPriceSet.shopMoney.amount').', '.$json('total_price').')', "'USD'", 'o.paid_amount_usd',
            'o.payment_status', 'o.payment_method', $domain, 'o.tongtu_order_number',
            'o.subscription_type', 'o.is_add_on', 'o.order_created_at', 'o.paid_at',
            $hasStores ? 's.user_name' : 'NULL', $hasStores ? 's.team' : 'NULL',
        ];
        $pairs = [];
        $bindings = [];
        foreach (FinanceCatalog::dataset('xuzong2-orders')['headers'] as $index => $header) {
            $pairs[] = '?, '.$values[$index];
            $bindings[] = $header;
        }
        $source->selectRaw("o.id, 'xuzong2-orders' as dataset, {$account} as account, {$reference} as reference,
            {$occurredAt} as occurred_at, 'USD' as currency,
            o.payment_status as status, {$domain} as domain, NULL as raw_type")
            ->selectRaw('JSON_OBJECT('.implode(', ', $pairs).') as payload', $bindings);

        return FinanceRecord::query()->fromSub($source, 'finance_records');
    }
}
