<?php

namespace App\Portals\Finance;

use App\Models\FinancePaymentAccount;
use App\Models\FinanceRecord;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** Indexed transaction headers and channel-specific references, kept in the source write transaction. */
class FinanceTransactions
{
    public const PAYPAL = 1;
    public const XUZONG1 = 2;
    public const XUZONG2 = 4;
    public const YE = 8;
    public const WORLDPAY = 16;

    public function __construct(private readonly FinanceWorkbookService $workbooks) {}

    public function installed(): bool
    {
        $db = $this->workbooks->database();
        $key = 'finance.transactions-schema:'.$db->getName().':'.$db->getDatabaseName();
        $attributes = app('request')->attributes;
        if (! $attributes->has($key)) $attributes->set($key, $db->getSchemaBuilder()->hasTable('finance_transaction_storage'));
        return $attributes->get($key);
    }

    public function ready(): bool
    {
        $db = $this->workbooks->database();
        return $this->installed() && $db->table('finance_transaction_storage')->where('id', 1)->value('state') === 'ready';
    }

    public static function flags(string $dataset, array $payload, array|string|null $accountPlatform): int
    {
        if (str_starts_with($dataset, 'paypal-')) return self::PAYPAL;
        $accountPlatforms = is_array($accountPlatform) ? $accountPlatform : [$accountPlatform];
        $explicit = $payload['_card_channel'] ?? null;
        $worldpay = $explicit === 'worldpay' || ($explicit === null && (filled($payload['网关接入号'] ?? null)
            || (in_array('worldpay', $accountPlatforms, true) && blank($payload['网站名称'] ?? null))));
        $ye = $explicit === 'ye' || ($explicit === null && (filled($payload['网站名称'] ?? null)
            || (in_array('ye', $accountPlatforms, true) && blank($payload['网关接入号'] ?? null))));
        return ($worldpay ? self::WORLDPAY : 0) | ($ye ? self::YE : 0)
            | (! $worldpay && ! $ye ? (in_array($dataset, ['xuzong1-details', 'xuzong-credit-source-1'], true) ? self::XUZONG1 : self::XUZONG2) : 0);
    }

    public function sync(Collection $records): void
    {
        if ($records->isEmpty() || ! $this->installed()) return;
        $db = $this->workbooks->database();
        $accounts = FinancePaymentAccount::query()->where('account_type', 'credit_card')->sharedLock()->get()->keyBy('id');
        $platforms = [];
        // Use the database's existing account collation, including case-insensitive aliases.
        foreach ($db->table('finance_records as r')->join('finance_payment_accounts as a', 'a.activity_account', '=', 'r.account')
            ->whereIntegerInRaw('r.id', $records->pluck('id')->all())->whereIn('a.id', $accounts->keys()->all())
            ->get(['r.id', 'a.id as account_id']) as $match) {
            $platforms[$match->id][] = FinanceCreditSettlementLayout::accountPlatform($accounts->get($match->account_id));
        }
        $headers = $details = [];
        foreach ($records as $record) {
            $payload = $record->payload;
            $headers[] = ['id' => $record->id, 'dataset' => $record->dataset,
                'channel_flags' => self::flags($record->dataset, $payload, $platforms[$record->id] ?? []),
                'account' => $record->account, 'reference' => $record->reference,
                'occurred_at' => $record->getAttributes()['occurred_at'] ?? null, 'currency' => $record->currency, 'status' => $record->status,
                'status_code' => strtoupper(trim((string) $record->status)),
                'raw_type' => $record->raw_type, 'source_timezone' => $record->source_timezone, 'domain' => $record->domain];
            $details[] = ['record_id' => $record->id, 'explicit_channel' => $payload['_card_channel'] ?? null,
                'merchant_order' => $payload['商户订单号'] ?? null, 'shopify_order' => $payload['Shopify订单号'] ?? null,
                'gateway' => $payload['网关接入号'] ?? null, 'website' => $payload['网站名称'] ?? null,
                'legacy_order' => $payload['_legacy_order_id'] ?? null, 'platform_order' => $payload['_edited_platform_order_id'] ?? null,
                'linked_platform_order' => $payload['_linked_platform_order_id'] ?? null,
                'transaction_number' => $payload['交易流水号'] ?? null, 'erp_order' => $payload['通途订单'] ?? null,
                'has_worldpay_events' => isset($payload['_worldpay_events']), 'is_refunded' => ($payload['退款'] ?? null) === '是'];
        }
        $db->table('finance_transactions')->upsert($headers, ['id'], array_diff(array_keys($headers[0]), ['id']));
        $db->table('finance_card_transaction_details')->upsert($details, ['record_id'], array_diff(array_keys($details[0]), ['record_id']));
    }

    public function syncIds(array $ids): void
    {
        if (! $this->installed()) return;
        foreach (array_chunk($ids, 300) as $batch) $this->sync(FinanceRecord::query()->whereKey($batch)->get());
    }

    public function reclassifyAccount(string $account): void
    {
        app(FinanceSourceStorage::class)->forgetAccounts();
        if (! $this->installed()) return;
        $db = $this->workbooks->database();
        $platform = FinancePaymentAccount::query()->where('activity_account', $account)->where('account_type', 'credit_card')->get()
            ->map(FinanceCreditSettlementLayout::accountPlatform(...))->all();
        $groups = [];
        foreach ($db->table('finance_transactions as t')->join('finance_card_transaction_details as d', 'd.record_id', '=', 't.id')
            ->where('t.account', $account)->select(['t.id', 't.dataset', 'd.explicit_channel', 'd.gateway', 'd.website'])->lazyById(500, 't.id', 'id') as $row) {
            $flags = self::flags($row->dataset, ['_card_channel' => $row->explicit_channel, '网关接入号' => $row->gateway, '网站名称' => $row->website], $platform);
            $groups[$flags][] = $row->id;
        }
        foreach ($groups as $flags => $ids) foreach (array_chunk($ids, 500) as $batch) {
            $db->table('finance_transactions')->whereIntegerInRaw('id', $batch)->update(['channel_flags' => $flags]);
            if (app(FinanceSourceStorage::class)->installed()) {
                $channel = match (true) { (bool) ($flags & self::PAYPAL) => 'paypal', (bool) ($flags & self::WORLDPAY) => 'worldpay',
                    (bool) ($flags & self::YE) => 'ye', (bool) ($flags & self::XUZONG1) => 'xuzong1', default => 'xuzong2' };
                $db->table('finance_records')->whereIntegerInRaw('id', $batch)->update(['source_channel' => $channel]);
            }
        }
    }

    public function filter(Builder $query, array $channels): void
    {
        $flags = $this->channelFlags($channels);
        if ($query->getQuery()->from === 'finance_transactions as finance_records') {
            $query->whereIn('finance_records.channel_flags', $flags);
        } else $query->whereIn('finance_records.id', $this->workbooks->database()->table('finance_transactions')
            ->select('id')->whereIn('channel_flags', $flags));
    }

    public function page(Builder $query, array $channels, int $offset, int $limit): Collection
    {
        $union = null;
        foreach ($this->channelFlags($channels) as $flags) {
            // Each fixed channel value can read the date index in order. Merge
            // only page keys instead of sorting every transaction in the channel.
            $branch = (clone $query)->where('finance_records.channel_flags', $flags)->reorder()
                ->orderByDesc('finance_records.occurred_at')->orderByDesc('finance_records.id')->limit($offset + $limit)
                ->toBase()->select(['finance_records.id', 'finance_records.occurred_at']);
            $union = $union === null ? $branch : $union->unionAll($branch);
        }
        if ($union === null) return collect();
        $ids = $this->workbooks->database()->query()->fromSub($union, 'finance_page_keys')
            ->orderByDesc('occurred_at')->orderByDesc('id')->offset($offset)->limit($limit)->pluck('id');
        $details = FinanceRecord::query()->whereKey($ids->all())->get()->keyBy('id');
        return new \Illuminate\Database\Eloquent\Collection($ids->map(fn ($id) => $details->get($id))->filter()->all());
    }

    private function channelFlags(array $channels): array
    {
        $flags = [];
        foreach ($channels as $channel) $flags = [...$flags, ...match ($channel) {
            'paypal' => [self::PAYPAL], 'ye' => [self::YE, self::YE | self::WORLDPAY],
            'worldpay' => [self::WORLDPAY, self::YE | self::WORLDPAY],
            'xuzong1' => [self::XUZONG1], 'xuzong2' => [self::XUZONG2], default => [],
        }];
        return array_values(array_unique($flags));
    }
}
