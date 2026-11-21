<?php

namespace App\Portals\Finance;

class FinanceSummaryDependencies
{
    public function resolve(\Illuminate\Database\Connection $db): bool
    {
        if ($db->getSchemaBuilder()->hasTable(FinanceSummarySourceChanges::TABLE)
            && $db->getSchemaBuilder()->hasColumn('finance_summary_changes', 'scopes_resolved')) {
            $limit = max(1, min(1000, (int) config('finance.summaries.dependency_changes_per_run', 100)));
            $ready = app(FinanceSummarySourceChanges::class)->publish($db, $limit);
            // Acknowledging a change does not invalidate an already updated source range.
            $ids = $db->table('finance_summary_changes')->where('scopes_resolved', true)->orderBy('id')->limit($limit)->pluck('id');
            $db->table('finance_summary_changes')->whereIntegerInRaw('id', $ids->all())->delete();
            return $ready;
        }
        $pending = $db->table('finance_summary_changes');
        $sample = (clone $pending)->orderBy('id')->limit(1001)->pluck('id');
        if ($sample->isEmpty()) return true;
        if ($sample->count() > 1000) {
            $lastId = $pending->max('id');
            // A large import can produce thousands of expensive dependency lookups.
            // Invalidate every existing period once and leave newer changes queued.
            $sources = (clone $pending)->where('id', '<=', $lastId)->distinct()->pluck('source');
            $creditOnly = $sources->every(fn ($source) => in_array($source, ['fashion', 'fashion_item'], true));
            $db->transaction(function () use ($db, $lastId, $creditOnly): void {
                $db->table('finance_summary_periods')->when($creditOnly, fn ($query) => $query->where('channel', 'credit_card'))->increment('revision');
                $db->table('finance_summary_changes')->where('id', '<=', $lastId)->delete();
            });
            return ! $pending->exists();
        }

        // Bound the expensive matching work and leave concurrently arriving
        // changes for a later run, instead of chasing a moving queue forever.
        $batchLimit = max(1, min(1000, (int) config('finance.summaries.dependency_changes_per_run', 100)));
        $lastId = $sample->take($batchLimit)->last();
        $db->table('finance_summary_changes')->where('id', '<=', $lastId)->orderBy('id')->chunkById(100, function ($changes) use ($db): void {
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
        return ! $pending->exists();
    }

    /** Match individual source changes through indexed reference columns, preserving old/new order images. */
    public function sourceRecords(string $source, array $values, \Illuminate\Database\Connection $db): ?\Illuminate\Support\Collection
    {
        if (! in_array($source, ['order', 'reference', 'item', 'scope_credit_refund', 'fashion', 'fashion_item'], true)
            || ! app(FinanceSourceStorage::class)->active('record')) return null;
        $attributes = app('request')->attributes;
        $cacheKey = 'finance.dependency-reference-indexes:'.$db->getName().':'.$db->getDatabaseName();
        if (! $attributes->has($cacheKey)) {
            $schema = $db->getSchemaBuilder();
            $attributes->set($cacheKey, $schema->hasColumn('finance_records', 'source_bill_trimmed')
                && $schema->hasIndex('finance_records', 'finance_dependency_bill_idx')
                && $schema->hasIndex('finance_records', 'finance_dependency_merchant_idx')
                && $schema->hasIndex('finance_records', 'finance_dependency_shopify_idx'));
        }
        // Deployment can defer optional indexes while the database is busy. Retain the complete legacy match until ready.
        if (! $attributes->get($cacheKey)) return null;
        $general = []; $card = [];
        if ($source === 'fashion' || $source === 'fashion_item') {
            $key = $source === 'fashion' ? ($values['transaction_id'] ?? null)
                : $db->table('fashion_logistics_orders')->where('id', $values['fashion_logistics_order_id'] ?? null)->value('transaction_id');
            return $db->table('finance_records')->whereIn('dataset', FinanceSummarySourceChanges::DATASETS)
                ->where('dataset', '<>', 'paypal-orders')->where('reference', $key)->whereRaw("NULLIF(?, '') IS NOT NULL", [$key])
                ->get(['id', 'dataset', 'occurred_at']);
        }
        if ($source === 'reference') {
            $general[] = $values['reference_number'] ?? null;
        } else {
            if ($source === 'order') {
                foreach (['bill_number', 'transaction_id', 'payment_gateway_order_id'] as $field) $general[] = $values[$field] ?? null;
                $card[] = $values['shopify_order_number'] ?? null;
            }
            $orderId = $source === 'order' ? ($values['id'] ?? null) : ($values['logistics_order_id'] ?? null);
            $order = $db->table('logistics_orders')->where('id', $orderId)
                ->first(['id', 'bill_number', 'transaction_id', 'payment_gateway_order_id', 'shopify_order_number']);
            if ($order !== null) {
                foreach (['bill_number', 'transaction_id', 'payment_gateway_order_id'] as $field) $general[] = $order->$field;
                $card[] = $order->shopify_order_number;
                $general = [...$general, ...$db->table('logistics_order_payment_references')->where('logistics_order_id', $order->id)->pluck('reference_number')->all()];
            }
        }
        // Do not trim keys: only the PayPal bill column was trimmed by the original matcher.
        $keys = fn (array $values) => array_values(array_unique(array_filter($values, fn ($key) => $key !== null && (string) $key !== '')));
        $general = $keys($general);
        $card = $keys([...$general, ...$card]);
        $cardOnly = $source === 'scope_credit_refund';
        $union = null;
        foreach ([['reference', $general, 'all'], ['source_bill_trimmed', $general, 'paypal'],
            ['reference', $card, 'card'], ['source_merchant', $card, 'card'], ['source_shopify', $card, 'card']] as [$column, $references, $channel]) {
            if ($references === [] || ($cardOnly && $channel === 'paypal')) continue;
            // Separate branches permit a reference index lookup instead of a scan over IF/OR expressions.
            $query = $db->table('finance_records')->whereIn('dataset', FinanceSummarySourceChanges::DATASETS)->whereIn($column, $references)
                ->when($channel === 'paypal', fn ($query) => $query->where('dataset', 'paypal-orders'))
                ->when($channel === 'card' || $cardOnly, fn ($query) => $query->where('dataset', '<>', 'paypal-orders'))
                ->select(['id', 'dataset', 'occurred_at']);
            if ($union === null) $union = $query;
            else $union->union($query);
        }
        return $union?->get() ?? collect();
    }

    public function match(string $source, array $values, \Illuminate\Database\Connection $db): string
    {
        $bill = "TRIM(JSON_UNQUOTE(JSON_EXTRACT(f.payload, '$.\"帳單號碼\"')))";
        $merchant = "JSON_UNQUOTE(JSON_EXTRACT(f.payload, '$.\"商户订单号\"'))";
        $shopify = "JSON_UNQUOTE(JSON_EXTRACT(f.payload, '$.\"Shopify订单号\"'))";
        $storage = app(FinanceSourceStorage::class);
        $field = fn (string $name) => $storage->active('record')
            ? $storage->expression('record', 'f', [$name])->getValue($db->getQueryGrammar())
            : "JSON_UNQUOTE(JSON_EXTRACT(f.payload, '$.\"{$name}\"'))";
        if ($storage->active('record')) {
            $bill = 'TRIM(f.source_bill)'; $merchant = 'f.source_merchant'; $shopify = 'f.source_shopify';
        }
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
        $store = function (string $id, string $domain, string $name) use ($linked, $fashion, $field): string {
            $url = $field('交易网址');
            $host = "LOWER(SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(TRIM({$url}), '://', -1), '/', 1), '?', 1), ':', 1))";
            $custom = 'TRIM('.$field('自定义编号').')';
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
            'order' => $order($image).' OR '.$linked("o.id = {$image}.id"),
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
