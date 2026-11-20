<?php

namespace App\Portals\Finance;

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;

/** Durable input revisions, independent of report menus and worker acknowledgements. */
class FinanceSummarySourceChanges
{
    public const TABLE = 'finance_summary_source_revisions';
    private const SOURCES = [
        'finance_records' => 'record', 'finance_settlement_supplements' => 'supplement',
        'finance_payment_transactions' => 'payment', 'finance_credit_settlement_entries' => 'statement',
        'finance_payment_accounts' => 'account', 'finance_transaction_types' => 'type',
        'finance_credit_usd_rates' => 'rate', 'finance_monthly_exchange_rates' => 'monthly_rate',
    ];
    public const DATASETS = ['paypal-orders', 'xuzong2-orders', 'xuzong2-details', 'xuzong1-details',
        'xuzong-credit-source-1', 'xuzong-credit-source-2', 'xuzong-credit-imported-orders'];

    private static function sources(Connection $db): array
    {
        return self::SOURCES + ($db->getSchemaBuilder()->hasTable('logistics_credit_card_refunds')
            ? ['logistics_credit_card_refunds' => 'credit_refund'] : []);
    }

    public static function available(Connection $db): bool
    {
        $key = 'finance.scoped-tracking:'.$db->getName().':'.$db->getDatabaseName();
        $attributes = app('request')->attributes;
        if ($attributes->has($key)) return $attributes->get($key);
        if (! FinanceSummaryTriggers::available($db) || ! $db->getSchemaBuilder()->hasTable(self::TABLE)) {
            $attributes->set($key, false);
            return false;
        }
        $names = [];
        foreach ([...array_values(self::sources($db)), ...FinanceLiveSourceChanges::ATTRIBUTION_SOURCES] as $source) {
            foreach (['insert', 'update', 'delete'] as $event) $names[] = "finance_scope_{$source}_{$event}";
        }
        $names[] = 'finance_scope_change_delete_guard';
        $available = $db->table('information_schema.TRIGGERS')->whereRaw('TRIGGER_SCHEMA = DATABASE()')
            ->whereIn('TRIGGER_NAME', $names)->count() === count($names);
        $attributes->set($key, $available);
        return $available;
    }

    public static function install(Connection $db): void
    {
        if (! in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) return;
        FinanceSummaryTriggers::assertCanInstall($db);
        app('request')->attributes->remove('finance.scoped-tracking:'.$db->getName().':'.$db->getDatabaseName());
        $attribution = [
            (new \App\Models\AdminUser)->getTable() => 'attribution_user',
            config('admin.database.role_users_table') => 'attribution_membership',
            config('admin.database.roles_table') => 'attribution_department',
        ];
        foreach (self::sources($db) + $attribution as $table => $source) {
            $columns = $db->getSchemaBuilder()->getColumnListing($table);
            $watched = self::watched($source, $columns);
            $changed = self::changed($watched);
            // The original projection triggers also need semantic update guards.
            $old = $db->table('information_schema.TRIGGERS')->whereRaw('TRIGGER_SCHEMA = DATABASE()')
                ->where('EVENT_OBJECT_TABLE', $table)->where('EVENT_MANIPULATION', 'UPDATE')
                ->where('TRIGGER_NAME', 'like', 'finance_summary_%')->get(['TRIGGER_NAME', 'ACTION_STATEMENT']);
            foreach ($old as $trigger) {
                if (str_contains($trigger->ACTION_STATEMENT, 'finance_semantic_v1')) continue;
                $body = self::binaryComparisons($trigger->ACTION_STATEMENT);
                $db->unprepared("DROP TRIGGER `{$trigger->TRIGGER_NAME}`");
                $db->unprepared("CREATE TRIGGER `{$trigger->TRIGGER_NAME}` AFTER UPDATE ON `{$table}` FOR EACH ROW
                    BEGIN /* finance_semantic_v1 */ IF {$changed} THEN {$body}; END IF; END");
            }
            foreach (['insert' => ['NEW'], 'update' => ['OLD', 'NEW'], 'delete' => ['OLD']] as $event => $images) {
                $writes = [];
                foreach ($images as $image) {
                    if (in_array($source, ['record', 'supplement', 'payment'], true)) {
                        $record = $source === 'record' ? $image : 'f';
                        $from = $source === 'record' ? '' : " FROM finance_records f WHERE f.id = {$image}.finance_record_id";
                        $predicate = $record.'.dataset IN ('.implode(',', array_map(fn ($dataset) => $db->getPdo()->quote($dataset), self::DATASETS)).')';
                        $from .= $source === 'record' ? " WHERE {$predicate}" : " AND {$predicate}";
                        $pairs = ["'id', {$record}.id", "'occurred_at', {$record}.occurred_at", "'dataset', {$record}.dataset",
                            "'account', {$record}.account", "'reference', {$record}.reference", "'scope_channel', ".self::channelSql($record)];
                        foreach (['帳單號碼', '商户订单号', 'Shopify订单号', '偏好設定 Txn ID', '交易 ID'] as $key) {
                            $pairs[] = $db->getPdo()->quote($key).", JSON_EXTRACT({$record}.payload, ".$db->getPdo()->quote('$."'.$key.'"').')';
                        }
                        if ($source === 'payment') {
                            $pairs[] = "'transaction_id', {$image}.transaction_id";
                            $pairs[] = "'parent', JSON_EXTRACT({$image}.raw_payload, '$.transaction_info.paypal_reference_id')";
                            $pairs[] = "'invoice', JSON_EXTRACT({$image}.raw_payload, '$.transaction_info.invoice_id')";
                        }
                        $writes[] = "INSERT INTO finance_summary_changes (source, payload) SELECT 'finance', JSON_OBJECT(".implode(', ', $pairs)."){$from}";
                    } else {
                        $keys = match ($source) {
                            'statement' => ['payment_account_id', 'platform', 'reference', 'settlement_date', 'kind'],
                            'account' => ['id', 'activity_account', 'account_type'],
                            'type' => ['raw_type'], 'rate' => ['starts_on', 'ends_on'],
                            'monthly_rate' => ['month', 'channel'], default => [],
                            'credit_refund' => ['logistics_order_id'],
                        };
                        $pairs = array_map(fn ($column) => "'{$column}', {$image}.`{$column}`", array_intersect($keys, $columns));
                        $queueSource = 'scope_'.str_replace('attribution_', '', $source);
                        $writes[] = "INSERT INTO finance_summary_changes (source, payload) VALUES ('{$queueSource}', JSON_OBJECT(".implode(', ', $pairs).'))';
                        if ($source === 'account') {
                            // Account deletion can remove its records before a worker runs.
                            // Retain their dates while they still exist in this transaction.
                            $writes[] = "INSERT INTO finance_summary_changes (source, payload)
                                SELECT DISTINCT 'scope_account_day', JSON_OBJECT('channel', {$image}.account_type, 'source_date', DATE(f.occurred_at))
                                FROM finance_records f WHERE f.account = {$image}.activity_account";
                        }
                    }
                }
                $name = "finance_scope_{$source}_{$event}";
                $db->unprepared("DROP TRIGGER IF EXISTS `{$name}`");
                $guard = $event === 'update' ? $changed : '1';
                // Fail deployment visibly if tracking cannot be installed; never silently claim reusable data.
                $db->unprepared("CREATE TRIGGER `{$name}` AFTER {$event} ON `{$table}` FOR EACH ROW
                    BEGIN IF {$guard} THEN ".implode('; ', $writes).'; END IF; END');
            }
        }
        // Older owner/item triggers treated a timestamp-only write as a business edit.
        foreach ($db->table('information_schema.TRIGGERS')->whereRaw('TRIGGER_SCHEMA = DATABASE()')
            ->where('TRIGGER_NAME', 'like', 'finance_summary_dependency_%_update')
            ->get(['TRIGGER_NAME', 'EVENT_OBJECT_TABLE', 'ACTION_STATEMENT']) as $trigger) {
            $columns = self::watched('dependency', $db->getSchemaBuilder()->getColumnListing($trigger->EVENT_OBJECT_TABLE));
            $changed = self::changed($columns);
            if (str_contains($trigger->ACTION_STATEMENT, 'finance_semantic_v1')) continue;
            $body = self::binaryComparisons($trigger->ACTION_STATEMENT);
            $db->unprepared("DROP TRIGGER `{$trigger->TRIGGER_NAME}`");
            $db->unprepared("CREATE TRIGGER `{$trigger->TRIGGER_NAME}` AFTER UPDATE ON `{$trigger->EVENT_OBJECT_TABLE}` FOR EACH ROW
                BEGIN /* finance_semantic_v1 */ IF {$changed} THEN {$body}; END IF; END");
        }
        $db->unprepared('DROP TRIGGER IF EXISTS finance_scope_change_delete_guard');
        $db->unprepared("CREATE TRIGGER finance_scope_change_delete_guard BEFORE DELETE ON finance_summary_changes FOR EACH ROW
            BEGIN IF NOT OLD.scopes_resolved THEN SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Finance source changes must be resolved before acknowledgement'; END IF; END");
    }

    private static function watched(string $source, array $columns): array
    {
        $fields = match ($source) {
            'account' => ['id', 'activity_account', 'account_name', 'account_type', 'main_currency', 'status', 'deleted_at', 'card_platform', 'card_merchant_key', 'card_group_id'],
            'type' => ['raw_type', 'category'],
            'statement' => ['payment_account_id', 'platform', 'settlement_date', 'reference', 'kind', 'payload'],
            'rate' => ['currency', 'scope', 'starts_on', 'ends_on', 'usd_rate'],
            'monthly_rate' => ['month', 'channel', 'currency', 'cny_rate'],
            'attribution_user' => ['name', 'username'],
            'attribution_membership' => ['user_id', 'role_id', 'is_primary'],
            'attribution_department' => ['name', 'slug', 'parent_id'],
            default => array_diff($columns, ['created_at', 'updated_at', 'synced_at', 'updated_by', 'created_by', 'imported_by']),
        };
        return array_values(array_intersect($fields, $columns));
    }

    private static function changed(array $columns): string
    {
        return 'NOT ('.implode(' AND ', array_map(fn ($column) => "BINARY OLD.`{$column}` <=> BINARY NEW.`{$column}`", $columns)).')';
    }

    private static function binaryComparisons(string $sql): string
    {
        return preg_replace('/(?<!BINARY )(OLD\.`[a-z_]+`) <=> (NEW\.`[a-z_]+`)/', 'BINARY $1 <=> BINARY $2', $sql);
    }

    /** Resolve a bounded batch before certifying cached inputs. Queue deletion cannot change its revision. */
    public function publish(Connection $db, int $limit = 1000): bool
    {
        if (! $db->table('finance_summary_changes')->where('scopes_resolved', false)->exists()) return true;
        $ids = $db->table('finance_summary_changes')->where('scopes_resolved', false)->orderBy('id')->limit($limit)->pluck('id')->all();
        $db->transaction(function () use ($db, $ids): void {
            // Lock existing IDs only; a range lock would block new outbox inserts
            // while their source transaction holds a period row we need to update.
            $changes = $db->table('finance_summary_changes')->whereIntegerInRaw('id', $ids)->where('scopes_resolved', false)
                ->orderBy('id')->lockForUpdate()->get();
            foreach ($changes->groupBy(fn ($change) => $change->source.':'.$change->payload) as $duplicates) {
                $change = $duplicates->last();
                $values = json_decode($change->payload, true, 512, JSON_THROW_ON_ERROR);
                $scopes = $this->scopes($db, $change->source, $values);
                foreach ($scopes as [$channel, $day]) {
                    $db->statement('INSERT INTO '.self::TABLE.' (channel, source_date, revision) VALUES (?, ?, ?)
                        ON DUPLICATE KEY UPDATE revision = GREATEST(revision, VALUES(revision))', [$channel, $day, $change->id]);
                }
                $months = [];
                foreach ($scopes as [$channel, $day]) {
                    $channel = $channel === 'paypal' ? 'paypal' : 'credit_card';
                    if ($day === '*') {
                        $db->table('finance_summary_periods')->where('channel', $channel)->increment('revision');
                    } else {
                        // Settlement zones can put a UTC day in either adjacent month.
                        foreach (['Asia/Shanghai', '-04:00'] as $zone) foreach (['00:00:00', '23:59:59'] as $time) {
                            $month = CarbonImmutable::parse($day.' '.$time, 'UTC')->setTimezone($zone)->format('Y-m');
                            $months[$channel.'/'.$month] = compact('channel', 'month');
                        }
                    }
                }
                foreach ($months as $period) $db->table('finance_summary_periods')->where($period)->increment('revision');
            }
            $db->table('finance_summary_changes')->whereIntegerInRaw('id', $changes->pluck('id')->all())->update(['scopes_resolved' => true]);
        }, 3);
        return ! $db->table('finance_summary_changes')->where('scopes_resolved', false)->exists();
    }

    private function scopes(Connection $db, string $source, array $values): array
    {
        if (in_array($source, ['scope_user', 'scope_membership', 'scope_department'], true)) return [['paypal', '*'], ['credit_card', '*']];
        if ($source === 'scope_account_day') return [[$values['channel'], $values['source_date']]];
        $records = $db->table('finance_records')->whereIn('dataset', self::DATASETS);
        $extra = [];
        if ($source === 'finance') {
            $keys = array_values(array_unique(array_filter(array_map(fn ($key) => trim((string) ($values[$key] ?? '')),
                ['reference', 'transaction_id', 'parent', 'invoice', '帳單號碼', '商户订单号', 'Shopify订单号', '偏好設定 Txn ID', '交易 ID']))));
            $records->where(function ($query) use ($values, $keys) {
                $query->where('id', $values['id']);
                if ($keys !== []) $query->orWhere(function ($query) use ($values, $keys) {
                    $query->where('account', $values['account'])->where(function ($query) use ($keys) {
                        $query->whereIn('reference', $keys)->orWhereIn('payload->商户订单号', $keys)->orWhereIn('payload->Shopify订单号', $keys);
                    });
                });
            });
            $extra[] = [$values['scope_channel'] ?? ($values['dataset'] === 'paypal-orders' ? 'paypal' : 'credit_card'), substr($values['occurred_at'], 0, 10)];
        } elseif ($source === 'scope_account') {
            $records->where('account', $values['activity_account']);
        } elseif ($source === 'scope_type') {
            $records->where('dataset', 'paypal-orders')->where('raw_type', $values['raw_type']);
        } elseif ($source === 'scope_rate' || $source === 'scope_monthly_rate') {
            $from = $values['starts_on'] ?? $values['month'].'-01';
            $to = $values['ends_on'] ?? CarbonImmutable::parse($from)->endOfMonth()->toDateString();
            $records->where('occurred_at', '>=', CarbonImmutable::parse($from, 'Asia/Shanghai')->utc())
                ->where('occurred_at', '<', CarbonImmutable::parse($to, 'Asia/Shanghai')->addDay()->utc());
            if ($source === 'scope_rate') $records->where('dataset', '<>', 'paypal-orders');
            elseif (($values['channel'] ?? '') === 'paypal') $records->where('dataset', 'paypal-orders');
            // Other monthly FX channels are deliberately conservative within this month.
        } elseif ($source === 'scope_statement') {
            $account = $db->table('finance_payment_accounts')->where('id', $values['payment_account_id'])->value('activity_account');
            $records->where('account', $account)->where(function ($query) use ($values) {
                if (($values['kind'] ?? '') === 'summary') {
                    $query->whereRaw('1 = 1');
                    return;
                }
                $query->where('reference', $values['reference'] ?? '');
                if (($values['platform'] ?? '') === 'ye') {
                    $date = CarbonImmutable::parse($values['settlement_date'], 'Asia/Shanghai');
                    $query->orWhereBetween('occurred_at', [$date->startOfMonth()->utc(), $date->endOfMonth()->utc()]);
                }
            });
            if (filled($values['settlement_date'] ?? null)) {
                $date = CarbonImmutable::parse($values['settlement_date'], 'Asia/Shanghai');
                $platform = in_array($values['platform'] ?? '', ['xuzong1', 'xuzong2', 'ye', 'worldpay'], true) ? $values['platform'] : 'credit_card';
                $extra[] = [$platform, $date->utc()->toDateString()];
                $extra[] = [$platform, $date->addDay()->subSecond()->utc()->toDateString()];
            }
        } else {
            $match = app(FinanceSummaryDependencies::class)->match($source === 'scope_credit_refund' ? 'item' : $source, $values, $db);
            $records = $db->table('finance_records as f')->whereIn('f.dataset', self::DATASETS)->whereRaw($match);
            if ($source === 'scope_credit_refund') $records->where('f.dataset', '<>', 'paypal-orders');
        }
        $rows = $records->get(['id', 'dataset', 'occurred_at']);
        if ($source === 'finance') $rows = $rows->concat($this->relatedBusinessRecords($db, $rows->pluck('id')->all(), $values));
        $paypalIds = $rows->where('dataset', 'paypal-orders')->pluck('id')->all();
        if ($paypalIds !== [] || ($source === 'finance' && $values['dataset'] === 'paypal-orders')) {
            $rows = $rows->concat($this->relatedPayPal($db, $paypalIds, $source === 'finance' ? $values : []));
        }
        $scopes = collect($extra);
        foreach (array_chunk($rows->pluck('id')->unique()->all(), 500) as $ids) {
            foreach ($db->table('finance_records as f')->whereIntegerInRaw('f.id', $ids)->select('f.occurred_at')
                ->selectRaw(self::channelSql('f').' AS scope_channel')->get() as $record) {
                $scopes->push([$record->scope_channel, substr($record->occurred_at, 0, 10)]);
                // Account edits can move a legacy row between inferred platforms.
                if ($source === 'scope_account' && $record->scope_channel !== 'paypal') $scopes->push(['credit_card', substr($record->occurred_at, 0, 10)]);
            }
        }
        return $scopes->unique(fn ($scope) => implode('/', $scope))->values()->all();
    }

    /** Same Worldpay/Ye precedence and account fallback as FinanceCreditSettlementLayout. */
    private static function channelSql(string $record): string
    {
        $value = fn ($key) => "CASE WHEN JSON_TYPE(JSON_EXTRACT({$record}.payload, '$.\"{$key}\"')) = 'NULL'
            THEN NULL ELSE JSON_UNQUOTE(JSON_EXTRACT({$record}.payload, '$.\"{$key}\"')) END";
        $explicit = $value('_card_channel');
        $website = $value('网站名称');
        $gateway = $value('网关接入号');
        $account = function ($platform) use ($record): string {
            $name = "LOWER(CONCAT(COALESCE(a.account_name, ''), ' ', COALESCE(a.activity_account, '')))";
            $legacy = $platform === 'worldpay' ? "{$name} LIKE '%worldpay%'" : "({$name} LIKE '%confluxapi%' OR {$name} LIKE '%叶总%')";
            return "EXISTS (SELECT 1 FROM finance_payment_accounts a WHERE a.activity_account = {$record}.account
                AND a.account_type = 'credit_card' AND a.deleted_at IS NULL
                AND (a.card_platform = '{$platform}' OR (a.card_platform IS NULL AND {$legacy})))";
        };
        $worldpay = "({$explicit} = 'worldpay' OR ({$explicit} IS NULL AND ({$gateway} <> '' OR (".$account('worldpay')." AND ({$website} IS NULL OR {$website} = '')))))";
        $ye = "({$explicit} = 'ye' OR ({$explicit} IS NULL AND ({$website} <> '' OR (".$account('ye')." AND ({$gateway} IS NULL OR {$gateway} = '')))))";
        return "CASE WHEN {$record}.dataset = 'paypal-orders' THEN 'paypal' WHEN {$worldpay} THEN 'worldpay'
            WHEN {$ye} THEN 'ye' WHEN {$record}.dataset IN ('xuzong1-details', 'xuzong-credit-source-1') THEN 'xuzong1' ELSE 'xuzong2' END";
    }

    private function relatedBusinessRecords(Connection $db, array $ids, array $image): Collection
    {
        $keys = array_filter(array_map(fn ($key) => trim((string) ($image[$key] ?? '')),
            ['reference', '帳單號碼', '商户订单号', 'Shopify订单号']));
        foreach (array_chunk($ids, 500) as $batch) {
            foreach ($db->table('finance_records')->whereIntegerInRaw('id', $batch)->get([
                'reference', 'payload->帳單號碼 as bill', 'payload->商户订单号 as merchant', 'payload->Shopify订单号 as shopify',
            ]) as $record) $keys = [...$keys, ...array_filter((array) $record)];
        }
        $orderIds = [];
        foreach (array_chunk(array_values(array_unique($keys)), 500) as $batch) {
            $orders = $db->table('logistics_orders')->where(function ($query) use ($batch) {
                foreach (['bill_number', 'transaction_id', 'payment_gateway_order_id', 'shopify_order_number'] as $field) $query->orWhereIn($field, $batch);
                $query->orWhereIn('id', function ($query) use ($batch) {
                    $query->select('logistics_order_id')->from('logistics_order_payment_references')->whereIn('reference_number', $batch);
                });
            })->get(['id', 'bill_number', 'transaction_id', 'payment_gateway_order_id', 'shopify_order_number']);
            foreach ($orders as $order) {
                $orderIds[$order->id] = true;
                unset($order->id);
                $keys = [...$keys, ...array_filter((array) $order)];
            }
        }
        if ($orderIds === []) return collect();
        foreach (array_chunk(array_keys($orderIds), 500) as $batch) {
            $keys = [...$keys, ...$db->table('logistics_order_payment_references')->whereIntegerInRaw('logistics_order_id', $batch)->pluck('reference_number')->all()];
        }
        $result = collect();
        foreach (array_chunk(array_values(array_unique(array_filter($keys))), 500) as $batch) {
            $result = $result->concat($db->table('finance_records')->whereIn('dataset', self::DATASETS)->where(function ($query) use ($batch) {
                $query->whereIn('reference', $batch)->orWhereIn('payload->帳單號碼', $batch)
                    ->orWhereIn('payload->商户订单号', $batch)->orWhereIn('payload->Shopify订单号', $batch);
            })->get(['id', 'dataset', 'occurred_at']));
        }
        return $result;
    }

    /** Include ancestors, invoices and ambiguous duplicate references, including cross-month refunds. */
    private function relatedPayPal(Connection $db, array $ids, array $image): Collection
    {
        $references = array_fill_keys(array_filter([$image['reference'] ?? '', $image['transaction_id'] ?? '', $image['parent'] ?? '']), true);
        $invoices = array_fill_keys(array_filter([$image['invoice'] ?? '']), true);
        $seen = [];
        $recordIds = array_fill_keys($ids, true);
        do {
            $query = $db->table('finance_payment_transactions')->where(function ($query) use ($recordIds, $references, $invoices) {
                $query->whereIntegerInRaw('finance_record_id', array_keys($recordIds));
                foreach (array_chunk(array_keys($references), 500) as $keys) {
                    $query->orWhereIn('transaction_id', $keys)->orWhereIn('raw_payload->transaction_info->paypal_reference_id', $keys);
                }
                foreach (array_chunk(array_keys($invoices), 500) as $keys) $query->orWhereIn('raw_payload->transaction_info->invoice_id', $keys);
            });
            $added = false;
            foreach ($query->select('id', 'finance_record_id', 'transaction_id')
                ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.transaction_info.paypal_reference_id')) AS parent, JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.transaction_info.invoice_id')) AS invoice")
                ->lazyById(500) as $transaction) {
                if (isset($seen[$transaction->id])) continue;
                $seen[$transaction->id] = $added = true;
                $recordIds[$transaction->finance_record_id] = true;
                foreach ([$transaction->transaction_id, $transaction->parent] as $reference) {
                    if (filled($reference) && $reference !== 'null') $references[$reference] = true;
                }
                if (filled($transaction->invoice) && $transaction->invoice !== 'null') $invoices[$transaction->invoice] = true;
            }
        } while ($added);
        return $db->table('finance_records')->whereIntegerInRaw('id', array_keys($recordIds))->get(['id', 'dataset', 'occurred_at']);
    }
}
