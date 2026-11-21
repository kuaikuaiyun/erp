<?php

namespace App\Portals\Finance;

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;

/** Durable input revisions written by the application, independent of report menus. */
class FinanceSummarySourceChanges
{
    public const TABLE = 'finance_summary_source_revisions';
    public const DATASETS = ['paypal-orders', 'xuzong2-orders', 'xuzong2-details', 'xuzong1-details',
        'xuzong-credit-source-1', 'xuzong-credit-source-2', 'xuzong-credit-imported-orders'];

    public static function available(Connection $db): bool
    {
        $attributes = app('request')->attributes;
        $key = 'finance.scoped-tracking:'.$db->getName().':'.$db->getDatabaseName();
        if (! $attributes->has($key)) $attributes->set($key, $db->getSchemaBuilder()->hasTable(self::TABLE)
            && $db->getSchemaBuilder()->hasTable('finance_summary_changes')
            && FinanceApplicationChanges::available($db));
        return $attributes->get($key);
    }

    /** Report requests inspect readiness; the scheduled worker imports and resolves changes. */
    public function reportReady(Connection $db): bool
    {
        return ! self::available($db) || (! app(FinanceExternalChanges::class)->hasPending($db)
            && ! $db->table('finance_summary_changes')->where('scopes_resolved', false)->exists());
    }

    /** Resolve a bounded batch before certifying cached inputs. Queue deletion cannot change its revision. */
    public function publish(Connection $db, int $limit = 1000): bool
    {
        if (! app(FinanceExternalChanges::class)->drain($db, $limit)) return false;
        if (app('request')->attributes->get('finance.report_read_only')) {
            return ! $db->table('finance_summary_changes')->where('scopes_resolved', false)->exists();
        }
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
                    if (in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) {
                        $db->statement('INSERT INTO '.self::TABLE.' (channel, source_date, revision) VALUES (?, ?, ?)
                            ON DUPLICATE KEY UPDATE revision = GREATEST(revision, VALUES(revision))', [$channel, $day, $change->id]);
                    } else {
                        $key = ['channel' => $channel, 'source_date' => $day];
                        $revision = max((int) $db->table(self::TABLE)->where($key)->value('revision'), $change->id);
                        $db->table(self::TABLE)->updateOrInsert($key, ['revision' => $revision]);
                    }
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
                foreach ($months as $period) {
                    if (in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) {
                        $db->statement('INSERT INTO finance_summary_periods (month, channel, revision, published) VALUES (?, ?, 1, 0)
                            ON DUPLICATE KEY UPDATE revision = revision + 1', [$period['month'], $period['channel']]);
                    } else {
                        $current = $db->table('finance_summary_periods')->where($period)->first();
                        if ($current) $db->table('finance_summary_periods')->where($period)->increment('revision');
                        else $db->table('finance_summary_periods')->insert([...$period, 'revision' => 1, 'published' => 0]);
                    }
                }
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
    public static function channelSql(string $record): string
    {
        if (app(FinanceSourceStorage::class)->active('record')) return $record.'.source_channel';
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
        $ordinary = app(FinanceSourceStorage::class)->active('record');
        foreach (array_chunk($ids, 500) as $batch) {
            foreach ($db->table('finance_records')->whereIntegerInRaw('id', $batch)->get([
                'reference', ($ordinary ? 'source_bill' : 'payload->帳單號碼').' as bill',
                ($ordinary ? 'source_merchant' : 'payload->商户订单号').' as merchant', ($ordinary ? 'source_shopify' : 'payload->Shopify订单号').' as shopify',
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
        $ordinary = app(FinanceSourceStorage::class)->active('record');
        foreach (array_chunk(array_values(array_unique(array_filter($keys))), 500) as $batch) {
            $result = $result->concat($db->table('finance_records')->whereIn('dataset', self::DATASETS)->where(function ($query) use ($batch, $ordinary) {
                $query->whereIn('reference', $batch)->orWhereIn($ordinary ? 'source_bill' : 'payload->帳單號碼', $batch)
                    ->orWhereIn($ordinary ? 'source_merchant' : 'payload->商户订单号', $batch)->orWhereIn($ordinary ? 'source_shopify' : 'payload->Shopify订单号', $batch);
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
        $ordinary = app(FinanceSourceStorage::class)->active('payment');
        do {
            $query = $db->table('finance_payment_transactions')->where(function ($query) use ($recordIds, $references, $invoices, $ordinary) {
                $query->whereIntegerInRaw('finance_record_id', array_keys($recordIds));
                foreach (array_chunk(array_keys($references), 500) as $keys) {
                    $query->orWhereIn('transaction_id', $keys)->orWhereIn($ordinary ? 'related_id' : 'raw_payload->transaction_info->paypal_reference_id', $keys);
                }
                foreach (array_chunk(array_keys($invoices), 500) as $keys) $query->orWhereIn($ordinary ? 'invoice_id' : 'raw_payload->transaction_info->invoice_id', $keys);
            });
            $added = false;
            $query->select('id', 'finance_record_id', 'transaction_id');
            if ($ordinary) $query->addSelect('related_id as parent', 'invoice_id as invoice');
            else $query->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.transaction_info.paypal_reference_id')) AS parent, JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.transaction_info.invoice_id')) AS invoice");
            foreach ($query->lazyById(500) as $transaction) {
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
