<?php

namespace App\Portals\Finance;

class FinanceSummaryDependencies
{
    public function resolve(\Illuminate\Database\Connection $db): void
    {
        $db->table('finance_summary_changes')->orderBy('id')->chunkById(200, function ($changes) use ($db): void {
            $db->transaction(function () use ($db, $changes): void {
                $affected = [];
                foreach ($changes->unique(fn ($change) => $change->source.':'.$change->payload) as $change) {
                    $values = json_decode($change->payload, true, 512, JSON_THROW_ON_ERROR);
                    $matches = $this->match($change->source, $values, $db);
                    $periods = $db->select("SELECT DISTINCT DATE_FORMAT(DATE_ADD(f.occurred_at, INTERVAL 8 HOUR), '%Y-%m') AS month,
                        IF(f.dataset = 'paypal-orders', 'paypal', 'credit_card') AS channel
                        FROM finance_records f
                        WHERE f.dataset IN ('paypal-orders','xuzong2-orders','xuzong2-details','xuzong1-details','xuzong-credit-source-1','xuzong-credit-source-2','xuzong-credit-imported-orders') AND ({$matches})");
                    foreach ($periods as $period) {
                        $affected[$period->month.'/'.$period->channel] = (array) $period;
                    }
                }
                foreach ($affected as $period) {
                    $db->table('finance_summary_periods')->where($period)->increment('revision');
                }
                $db->table('finance_summary_changes')->whereIntegerInRaw('id', $changes->pluck('id')->all())->delete();
            });
        });
    }

    private function match(string $source, array $values, \Illuminate\Database\Connection $db): string
    {
        $bill = "TRIM(JSON_UNQUOTE(JSON_EXTRACT(f.payload, '$.\"帳單號碼\"')))";
        $merchant = "JSON_UNQUOTE(JSON_EXTRACT(f.payload, '$.\"商户订单号\"'))";
        $shopify = "JSON_UNQUOTE(JSON_EXTRACT(f.payload, '$.\"Shopify订单号\"'))";
        $references = "f.reference, IF(f.dataset = 'paypal-orders', {$bill}, {$merchant}), IF(f.dataset = 'paypal-orders', NULL, {$shopify})";
        $reference = fn (string $column) => "NULLIF({$column}, '') IN ({$references})";
        $order = fn (string $alias) => implode(' OR ', [
            $reference($alias.'.bill_number'),
            $reference($alias.'.transaction_id'),
            $reference($alias.'.payment_gateway_order_id'),
            "(f.dataset <> 'paypal-orders' AND ".$reference($alias.'.shopify_order_number').')',
        ]);
        $linked = fn (string $condition) => "EXISTS (SELECT 1 FROM (
            SELECT o.id, o.bill_number, o.transaction_id, o.payment_gateway_order_id, o.shopify_order_number
            FROM logistics_orders o WHERE ({$condition}) LIMIT 18446744073709551615
        ) o WHERE ".$order('o')." OR EXISTS (SELECT 1 FROM logistics_order_payment_references r
            WHERE r.logistics_order_id = o.id AND ".$reference('r.reference_number').'))';
        $fashion = fn (string $condition) => "f.dataset <> 'paypal-orders' AND f.reference IN (
            SELECT o.transaction_id FROM fashion_logistics_orders o WHERE ({$condition})
        )";
        $store = function (string $id, string $domain, string $name) use ($linked, $fashion): string {
            $url = "JSON_UNQUOTE(JSON_EXTRACT(f.payload, '$.\"交易网址\"'))";
            $host = "LOWER(SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(TRIM({$url}), '://', -1), '/', 1), '?', 1), ':', 1))";
            $custom = "TRIM(JSON_UNQUOTE(JSON_EXTRACT(f.payload, '$.\"自定义编号\"')))";
            $savedDomain = "LOWER(TRIM(COALESCE(
                JSON_UNQUOTE(JSON_EXTRACT(s.payload, '$.\"_edited_fields\".\"domain\"')),
                JSON_UNQUOTE(JSON_EXTRACT(s.payload, '$.\"domain\"')), '')))";
            $direct = "NULLIF(LOWER(TRIM(f.domain)), '') IN (LOWER(TRIM({$domain})), CONCAT('www.', LOWER(TRIM({$domain}))))
                OR NULLIF({$host}, '') = LOWER(TRIM({$domain}))
                OR (f.dataset = 'paypal-orders' AND NULLIF({$custom}, '') = TRIM({$name}))
                OR EXISTS (SELECT 1 FROM finance_settlement_supplements s WHERE s.finance_record_id = f.id
                    AND NULLIF({$savedDomain}, '') IN (LOWER(TRIM({$domain})), CONCAT('www.', LOWER(TRIM({$domain})))))";
            return "({$direct}) OR ".$linked("o.assistant_domain_store_id = {$id} OR NULLIF(o.source_shop_domain, '') = {$domain}")
                .' OR ('.$fashion("o.assistant_domain_store_id = {$id} OR NULLIF(o.source_domain, '') = {$domain}").')';
        };

        $image = 'changed';
        $sql = match ($source) {
            'order' => $order($image),
            'reference' => $reference($image.'.reference_number'),
            'item' => $linked("o.id = {$image}.logistics_order_id"),
            'sku' => $linked("o.id IN (SELECT i.logistics_order_id FROM logistics_order_items i
                WHERE i.tongtu_sku_mapping_id = {$image}.id)"),
            'fashion' => "f.dataset <> 'paypal-orders' AND NULLIF({$image}.transaction_id, '') = f.reference",
            'fashion_item' => $fashion("o.id = {$image}.fashion_logistics_order_id"),
            'store' => $store($image.'.id', $image.'.domain', $image.'.shop_name'),
            'owner' => $linked("o.assistant_domain_store_id = {$image}.assistant_domain_store_id")
                .' OR ('.$fashion("o.assistant_domain_store_id = {$image}.assistant_domain_store_id").')',
        };
        return preg_replace_callback('/\bchanged\.([a-z_]+)/', fn ($match) =>
            isset($values[$match[1]]) ? $db->getPdo()->quote((string) $values[$match[1]]) : 'NULL', $sql);
    }

}
