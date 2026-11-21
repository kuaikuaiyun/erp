<?php

namespace App\Portals\Finance;

use Illuminate\Database\Connection;

/** Financial writes use an atomic outbox; external sources emit notifications after their own commit. */
class FinanceApplicationChanges
{
    public const TABLE = 'finance_application_tracking';
    private array $columns = [];

    public function forgetColumns(): void { $this->columns = []; }

    public static function available(Connection $db): bool
    {
        $attributes = app('request')->attributes;
        $key = 'finance.application-tracking:'.$db->getName().':'.$db->getDatabaseName();
        if (! $attributes->has($key)) $attributes->set($key, $db->getSchemaBuilder()->hasTable(self::TABLE)
            && $db->table(self::TABLE)->where('id', 1)->value('mode') === 'application');
        return $attributes->get($key);
    }

    public function source(string $table): ?string
    {
        return [
            'finance_records' => 'record', 'finance_settlement_supplements' => 'supplement',
            'finance_payment_transactions' => 'payment', 'finance_credit_settlement_entries' => 'statement',
            'finance_payment_accounts' => 'account', 'finance_transaction_types' => 'type',
            'finance_credit_usd_rates' => 'rate', 'finance_monthly_exchange_rates' => 'monthly_rate',
            'logistics_orders' => 'order', 'logistics_order_payment_references' => 'reference',
            'logistics_order_items' => 'item', 'logistics_tongtu_sku_mappings' => 'sku',
            'fashion_logistics_orders' => 'fashion', 'fashion_logistics_order_items' => 'fashion_item',
            'assistant_domain_stores' => 'store', 'assistant_domain_store_owner_histories' => 'owner',
            'logistics_credit_card_refunds' => 'credit_refund',
            (new \App\Models\AdminUser)->getTable() => 'user',
            config('admin.database.role_users_table') => 'membership', config('admin.database.roles_table') => 'department',
        ][$table] ?? null;
    }

    public function mutation(string $sql): ?array
    {
        if (! preg_match('/^\s*(insert(?:\s+(?:ignore|or\s+ignore))?\s+into|replace\s+into|update|delete\s+from)\s+[`"]?([a-z_][a-z0-9_]*)[`"]?/i', $sql, $match)) return null;
        $source = $this->source($match[2]);
        if ($source === null) return null;
        return ['operation' => str_starts_with(strtolower($match[1]), 'delete') ? 'delete'
            : (strtolower($match[1]) === 'update' ? 'update' : 'insert'), 'table' => $match[2], 'source' => $source];
    }

    private function columns(Connection $db, string $table): array
    {
        $key = $db->getName().':'.$db->getDatabaseName().':'.$table;
        return $this->columns[$key] ??= $db->getSchemaBuilder()->getColumnListing($table);
    }

    private function watched(string $source, array $columns): array
    {
        $fields = match ($source) {
            'record' => ['id', 'dataset', 'account', 'reference', 'occurred_at', 'source_timezone', 'domain', 'raw_type', 'currency', 'status', 'fingerprint', in_array('payload', $columns, true) ? 'payload' : 'source_hash'],
            'supplement' => ['finance_record_id', 'payload'],
            'statement' => ['payment_account_id', 'platform', 'settlement_date', 'reference', 'kind', 'report_hash', in_array('payload', $columns, true) ? 'payload' : 'source_hash'],
            'payment' => ['payment_account_id', 'finance_record_id', 'transaction_id', 'event_code', 'accounting_month', 'category', 'direction', 'loss_subject', 'monthly_rate', 'cny_amount', in_array('raw_payload', $columns, true) ? 'raw_payload' : 'source_hash'],
            'account' => ['id', 'activity_account', 'account_name', 'account_type', 'main_currency', 'status', 'deleted_at', 'card_platform', 'card_merchant_key', 'card_group_id'],
            'type' => ['raw_type', 'category'], 'rate' => ['currency', 'scope', 'starts_on', 'ends_on', 'usd_rate'],
            'monthly_rate' => ['month', 'channel', 'currency', 'cny_rate'], 'user' => ['name', 'username'],
            'membership' => ['user_id', 'role_id', 'is_primary'], 'department' => ['name', 'slug', 'parent_id'],
            default => array_diff($columns, ['created_at', 'updated_at', 'synced_at', 'last_synced_at', 'last_sync_at', 'updated_by', 'created_by', 'imported_by']),
        };
        return array_values(array_intersect($fields, $columns));
    }

    /** Find a SQL keyword outside quotes and nested expressions. */
    private function keyword(string $sql, string $word): ?int
    {
        $quote = null; $depth = 0; $length = strlen($sql); $wordLength = strlen($word);
        for ($i = 0; $i < $length; $i++) {
            $character = $sql[$i];
            if ($quote !== null) {
                if ($character === '\\') { $i++; continue; }
                if ($character === $quote) { if (($sql[$i + 1] ?? '') === $quote) $i++; else $quote = null; }
                continue;
            }
            if (in_array($character, ["'", '"', '`'], true)) { $quote = $character; continue; }
            if ($character === '(') $depth++;
            elseif ($character === ')') $depth--;
            elseif ($depth === 0 && strcasecmp(substr($sql, $i, $wordLength), $word) === 0
                && ($i === 0 || ! ctype_alnum($sql[$i - 1])) && ! ctype_alnum($sql[$i + $wordLength] ?? '')) return $i;
        }
        return null;
    }

    public function before(Connection $db, string $sql, array $bindings, array $mutation, bool $lock = true): ?array
    {
        $columns = $this->columns($db, $mutation['table']);
        $watched = $this->watched($mutation['source'], $columns);
        if ($mutation['operation'] === 'update') {
            $set = $this->keyword($sql, 'set'); $where = $this->keyword($sql, 'where');
            if ($set === null) return [...$mutation, 'global' => true];
            preg_match_all('/[`"]?([a-z_][a-z0-9_]*)[`"]?\s*=/i', substr($sql, $set + 3, ($where ?? strlen($sql)) - $set - 3), $assigned);
            if (array_intersect($watched, $assigned[1]) === []) return null;
        }
        $key = in_array('id', $columns, true) ? 'id' : (in_array('finance_record_id', $columns, true) ? 'finance_record_id' : null);
        if ($key === null) return [...$mutation, 'global' => true];
        $mutation['id_key'] = $key;
        if ($mutation['operation'] === 'insert') {
            $selector = $this->insertSelector($db, $sql, $bindings, $mutation);
            if ($selector !== null) {
                $old = $this->capture($db, clone $selector, $mutation, $watched, $lock);
                return $old === null ? [...$mutation, 'global' => true] : [...$mutation, 'selector' => $selector, 'old' => $old, 'watched' => $watched];
            }
            if (stripos($sql, 'duplicate key') !== false || stripos($sql, 'on conflict') !== false || stripos($sql, 'replace ') !== false) return [...$mutation, 'global' => true];
            return [...$mutation, 'last_id' => (int) $db->table($mutation['table'])->max($key), 'old' => [], 'watched' => $watched];
        }
        if (preg_match('/\b(join|limit|order\s+by)\b/i', $sql)) return [...$mutation, 'global' => true];
        $where = $this->keyword($sql, 'where');
        $selector = $db->table($mutation['table']);
        if ($where !== null) {
            $skip = substr_count(substr($sql, 0, $where), '?');
            $selector->whereRaw(substr($sql, $where + 5), array_slice($bindings, $skip));
        }
        $old = $this->capture($db, $selector, $mutation, $watched, $lock);
        if ($old === null) return [...$mutation, 'global' => true];
        return [...$mutation, 'old' => $old, 'watched' => $watched];
    }

    private function insertSelector(Connection $db, string $sql, array $bindings, array $mutation): ?\Illuminate\Database\Query\Builder
    {
        if (! preg_match('/\(([^()]+)\)\s*values\s*(.+?)(?:\s+on\s+(?:duplicate|conflict)|$)/is', $sql, $match)) return null;
        $columns = array_map(fn ($column) => trim($column, " `\"\t\n\r"), explode(',', $match[1]));
        $slots = substr_count($match[2], '?'); $width = count($columns);
        if ($width === 0 || $slots === 0 || $slots % $width !== 0 || $slots / $width > 500) return null;
        $identities = [];
        foreach ($db->getSchemaBuilder()->getIndexes($mutation['table']) as $index) {
            if (($index['unique'] ?? false) && array_diff($index['columns'], $columns) === []) { $identities = $index['columns']; break; }
        }
        if ($identities === [] && in_array($mutation['id_key'], $columns, true)) $identities = [$mutation['id_key']];
        if ($identities === []) return null;
        $rows = array_chunk(array_slice($bindings, 0, $slots), $width);
        return $db->table($mutation['table'])->where(function ($query) use ($rows, $identities, $columns): void {
            foreach ($rows as $row) $query->orWhere(function ($query) use ($row, $identities, $columns): void {
                foreach ($identities as $identity) $query->where($identity, $row[array_search($identity, $columns, true)]);
            });
        });
    }

    private function capture(Connection $db, \Illuminate\Database\Query\Builder $query, array $mutation, array $watched, bool $lock = false): ?array
    {
        $query->select($mutation['id_key'].' as id');
        if ($mutation['id_key'] !== 'id') $query->addSelect($mutation['id_key']);
        foreach ($watched as $column) {
            if ($column === $mutation['id_key']) continue;
            if (in_array($column, ['payload', 'raw_payload', 'shopify_payload'], true) && $db->getDriverName() !== 'sqlite') {
                $query->selectRaw('MD5(CAST('.$db->getQueryGrammar()->wrap($column).' AS CHAR)) AS '.$db->getQueryGrammar()->wrap($column));
            } else $query->addSelect($column);
        }
        $keys = match ($mutation['source']) {
            'record' => ['dataset', 'occurred_at', 'account', 'reference'],
            'supplement', 'payment' => ['finance_record_id'],
            'statement' => ['payment_account_id', 'platform', 'reference', 'settlement_date', 'kind'],
            'account' => ['id', 'activity_account', 'account_type'], 'type' => ['raw_type'],
            'rate' => ['starts_on', 'ends_on'], 'monthly_rate' => ['month', 'channel'],
            'order' => ['id', 'bill_number', 'transaction_id', 'payment_gateway_order_id', 'shopify_order_number'],
            'reference' => ['logistics_order_id', 'reference_number', 'reference_type'],
            'item', 'credit_refund' => ['logistics_order_id'], 'sku' => ['id'], 'fashion' => ['transaction_id'],
            'fashion_item' => ['fashion_logistics_order_id'], 'store' => ['id', 'domain', 'shop_name'], 'owner' => ['assistant_domain_store_id'], default => [],
        };
        $keys = array_values(array_intersect($keys, $this->columns($db, $mutation['table'])));
        foreach ($keys as $key) if (! in_array($key, $watched, true) && $key !== 'id') $query->addSelect($key);
        if ($mutation['source'] === 'record') {
            $query->selectRaw(FinanceSummarySourceChanges::channelSql($mutation['table']).' as scope_channel');
            $keys[] = 'scope_channel';
            $structured = app(FinanceSourceStorage::class)->active('record');
            foreach (['帳單號碼', '商户订单号', 'Shopify订单号', '偏好設定 Txn ID', '交易 ID'] as $index => $field) {
                $column = $structured ? ['source_bill', 'source_merchant', 'source_shopify', 'source_parent', 'source_reference'][$index] : 'payload->'.$field;
                $query->addSelect($column.' as __reference_'.$index);
            }
        } elseif ($mutation['source'] === 'payment') {
            $structured = app(FinanceSourceStorage::class)->active('payment');
            $query->addSelect('transaction_id', ($structured ? 'related_id' : 'raw_payload->transaction_info->paypal_reference_id').' as parent',
                ($structured ? 'invoice_id' : 'raw_payload->transaction_info->invoice_id').' as invoice');
            $keys = [...$keys, 'transaction_id', 'parent', 'invoice'];
        }
        if ($lock) $query->lockForUpdate();
        $rows = $query->limit(501)->get();
        if ($rows->count() > 500) return null;
        $records = [];
        foreach ($rows as $row) {
            $values = (array) $row;
            $records[$row->id] = ['digest' => hash('sha256', json_encode($values, JSON_THROW_ON_ERROR)),
                'values' => array_intersect_key($values, array_flip($keys))];
            if ($mutation['source'] === 'record') foreach (['帳單號碼', '商户订单号', 'Shopify订单号', '偏好設定 Txn ID', '交易 ID'] as $index => $field) {
                $records[$row->id]['values'][$field] = $values['__reference_'.$index] ?? null;
            }
            if ($mutation['source'] === 'account' && isset($values['activity_account'])) {
                $records[$row->id]['values']['__source_days'] = $db->table('finance_records as f')->where('f.account', $values['activity_account'])
                    ->whereNotNull('f.occurred_at')->selectRaw('DATE(f.occurred_at) as source_date, '.FinanceSummarySourceChanges::channelSql('f').' as channel')
                    ->distinct()->get()->map(fn ($day) => (array) $day)->all();
            }
        }
        return $records;
    }

    public function after(Connection $db, array $before, ?callable $emit = null): void
    {
        $emit ??= fn (string $source, array $values) => $this->enqueue($db, $source, $values);
        if ($before['source'] === 'account') app(FinanceSourceStorage::class)->forgetAccounts();
        if (! empty($before['global'])) {
            $emit('scope_user', []);
            if ($before['source'] === 'account' && app(FinanceTransactions::class)->installed()) {
                foreach ($db->table('finance_transactions')->distinct()->pluck('account') as $account) app(FinanceTransactions::class)->reclassifyAccount($account);
            }
            if ($before['operation'] === 'delete' && in_array($before['source'], ['record', 'payment', 'statement'], true)
                && app(FinanceSourceStorage::class)->installed()) app(FinanceSourceStorage::class)->sweepOrphans($before['source']);
            return;
        }
        $new = [];
        if ($before['operation'] !== 'delete') {
            $query = isset($before['selector']) ? clone $before['selector'] : $db->table($before['table']);
            if ($before['operation'] === 'insert' && ! isset($before['selector'])) $query->where($before['id_key'], '>', $before['last_id']);
            elseif ($before['operation'] !== 'insert') $query->whereIntegerInRaw($before['id_key'], array_keys($before['old']));
            $new = $this->capture($db, $query, $before, $before['watched']);
            if ($new === null) { $emit('scope_user', []); return; }
        }
        foreach (array_unique([...array_keys($before['old']), ...array_keys($new)]) as $id) {
            if (($before['old'][$id]['digest'] ?? null) === ($new[$id]['digest'] ?? null)) continue;
            foreach ([$before['old'][$id]['values'] ?? null, $new[$id]['values'] ?? null] as $values) {
                if ($values === null) continue;
                if (in_array($before['source'], ['record', 'payment', 'supplement'], true)) {
                    $recordId = $before['source'] === 'record' ? $id : ($values['finance_record_id'] ?? null);
                    $record = $before['source'] === 'record' ? ['id' => $id, ...$values] : $db->table('finance_records')->where('id', $recordId)->first(['id', 'dataset', 'occurred_at', 'account', 'reference']);
                    if ($record !== null) {
                        $record = (array) $record;
                        if (empty($record['occurred_at'])) { $emit('scope_user', []); continue; }
                        $record['scope_channel'] ??= $record['dataset'] === 'paypal-orders' ? 'paypal' : 'credit_card';
                        foreach (['transaction_id', 'parent', 'invoice'] as $key) if (isset($values[$key])) $record[$key] = $values[$key];
                        $emit('finance', $record);
                    }
                } else {
                    foreach ($values['__source_days'] ?? [] as $day) $emit('scope_account_day', $day);
                    unset($values['__source_days']);
                    $emit(in_array($before['source'], ['account', 'type', 'rate', 'monthly_rate', 'statement', 'credit_refund', 'user', 'membership', 'department'], true)
                        ? 'scope_'.$before['source'] : $before['source'], $values);
                }
            }
        }
        if ($before['operation'] === 'delete' && in_array($before['source'], ['record', 'payment', 'statement'], true)
            && app(FinanceSourceStorage::class)->installed()) app(FinanceSourceStorage::class)->sweepOrphans($before['source']);
        if ($before['source'] === 'account') {
            $accounts = [];
            foreach ([...array_values($before['old']), ...array_values($new)] as $image) {
                if (filled($image['values']['activity_account'] ?? null)) $accounts[] = $image['values']['activity_account'];
            }
            foreach (array_unique($accounts) as $account) app(FinanceTransactions::class)->reclassifyAccount($account);
        }
    }

    private function enqueue(Connection $db, string $source, array $values): void
    {
        $db->table('finance_summary_changes')->insert(['source' => $source, 'payload' => json_encode($values, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'scopes_resolved' => false]);
    }
}
