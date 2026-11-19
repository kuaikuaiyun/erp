<?php

namespace App\Portals\Finance;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class FinanceSummaryProjection
{
    public function __construct(private readonly FinanceWorkbookService $workbooks, private readonly FinanceSettlementService $settlements) {}

    private function usesTableLocks(): bool
    {
        $db = $this->workbooks->database();
        if (! in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) {
            return false;
        }
        $table = $db->selectOne('SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [$db->getDatabaseName(), $db->getTablePrefix().'finance_summary_rows']);
        return strtoupper($table->engine ?? '') !== 'INNODB';
    }

    public function usesSourceFiles(): bool
    {
        return $this->usesTableLocks();
    }

    public function read(string $channel, array $filters, bool $transactions, ?string $currency = null): ?Collection
    {
        $rows = $this->stream($channel, $filters, $transactions, $currency);

        return $rows === null ? null : collect(iterator_to_array($rows, false));
    }

    public function stream(string $channel, array $filters, bool $transactions, ?string $currency = null, array $paymentChannels = [], bool $aggregateTransactions = false): ?iterable
    {
        $db = $this->workbooks->database();
        // MyISAM publication/cleanup blocks every reader of this large table.
        // Let callers use immutable source files instead of waiting behind a rebuild.
        if ($this->usesTableLocks()) {
            return null;
        }
        // Worldpay allocates costs within the account-filtered source batches.
        if (! FinanceSummaryTriggers::available($db)
            || ($channel === 'credit_card' && filled($filters['account'] ?? null))) {
            return null;
        }

        $month = substr($filters['from'], 0, 7);
        $timezone = $filters['timezone'] ?? 'Asia/Shanghai';
        return $db->transaction(function () use ($db, $month, $channel, $timezone, $filters, $transactions, $currency, $paymentChannels, $aggregateTransactions): ?iterable {
            if ($db->table('finance_summary_changes')
                ->when($channel === 'paypal', fn ($query) => $query->whereNotIn('source', ['fashion', 'fashion_item']))->exists()) {
                return null;
            }
            $period = $db->table('finance_summary_periods')->where(compact('month', 'channel'))->first();
            if (! $period || ! $period->published || $period->published !== $period->revision) {
                return null;
            }

            $rows = $db->table('finance_summary_rows')
                ->where(compact('month', 'channel', 'timezone', 'transactions'))->where('revision', $period->published)
                ->when(filled($filters['account'] ?? null), fn ($query) => $query->where('account', $filters['account']))
                ->when(filled($currency), fn ($query) => $query->where('currency', $currency))
                ->orderBy('position');

            $hasFilterColumns = $db->getSchemaBuilder()->hasColumn('finance_summary_rows', 'payment_channel');
            if ($hasFilterColumns) {
                if ($paymentChannels !== []) {
                    $rows->whereIn('payment_channel', $paymentChannels);
                }
                if (filled($filters['team'] ?? null)) {
                    $rows->where('summary_team', $filters['team']);
                }
                if (filled($filters['subscription'] ?? null)) {
                    $rows->where('subscription', FinanceSettlementService::subscriptionLabel($filters['subscription']));
                }
                foreach (['account_key' => 'account', 'owner' => 'owner', 'team' => 'team',
                    'subscription' => 'subscription', 'payment_method' => 'payment_method'] as $filter => $column) {
                    if (filled($filters['summary_row_filters'][$filter] ?? null)) {
                        $rows->where($column, $filters['summary_row_filters'][$filter]);
                    }
                }
            }

            $first = (clone $rows)->value('payload');
            if ($first !== null && ! isset(json_decode($first, true, 512, JSON_THROW_ON_ERROR)['payment_channel'])) {
                return null;
            }
            if ($channel === 'paypal' && $first !== null
                && (json_decode($first, true, 512, JSON_THROW_ON_ERROR)['_summary_calculation_version'] ?? null) !== 2) {
                return null;
            }
            if ($channel === 'credit_card' && $first !== null
                && (json_decode($first, true, 512, JSON_THROW_ON_ERROR)['_summary_usd_version'] ?? null) !== 1) {
                return null;
            }
            if ($transactions && $first !== null
                && (json_decode($first, true, 512, JSON_THROW_ON_ERROR)['_summary_settlement_currency_version'] ?? null) !== 1) {
                return null;
            }
            if ($channel === 'credit_card' && $first !== null
                && (json_decode($first, true, 512, JSON_THROW_ON_ERROR)['_summary_fx_version'] ?? null)
                    !== app(FinanceCreditUsdRates::class)->fingerprint($month)) {
                // Old rows could have been published while reference quotes were unavailable.
                // Never let a source revision alone certify their converted amounts.
                return null;
            }

            if ($paymentChannels !== [] && ! $hasFilterColumns) {
                $rows->whereIn('payload->payment_channel', $paymentChannels);
            }
            if (filled($filters['team'] ?? null) && ! $hasFilterColumns) {
                $rows->where('payload->_summary_team', $filters['team']);
            }
            if (filled($filters['subscription'] ?? null) && ! $hasFilterColumns) {
                $rows->where('payload->subscription', FinanceSettlementService::subscriptionLabel($filters['subscription']));
            }

            if ($transactions && $aggregateTransactions && in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) {
                return $this->aggregateTransactions($rows);
            }

            return $rows->select('position', 'payload')->lazyById(10000, 'position')
                ->map(fn ($row) => json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR))
                ->filter(fn ($row) => (! filled($filters['team'] ?? null) || $row['_summary_team'] === $filters['team'])
                && (! filled($filters['subscription'] ?? null) || $row['subscription'] === FinanceSettlementService::subscriptionLabel($filters['subscription'])))
                ->map(function (array $row): array {
                    unset($row['_summary_team']);

                    return $row;
                });
        });
    }

    /** SQL reduces current canonical order calculations; no report result is saved. */
    private function aggregateTransactions(\Illuminate\Database\Query\Builder $rows): iterable
    {
        $value = static fn (string $field) => "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.{$field}')), 'null')";
        $number = static fn (string $field) => 'CAST('.$value($field).' AS DECIMAL(38, 8))';
        $dimensions = ['channel', 'payment_channel', 'account', 'account_key', 'currency', 'team', 'subscription',
            'month', 'success', 'failed', '_cost_adjustment', '_summary_settlement_currency'];
        $query = (clone $rows)->reorder()->select([]);
        foreach ($dimensions as $index => $field) {
            $query->selectRaw("JSON_EXTRACT(payload, '$.{$field}') AS d{$index}")->groupBy('d'.$index);
        }
        // Null settlement must remain a separate group so missing USD values
        // cannot disappear into SUM. Amount falls back to settlement per row.
        foreach (['settlement', '_summary_amount_usd'] as $index => $field) {
            $query->selectRaw('('.$value($field)." IS NULL) AS n{$index}")->groupBy('n'.$index);
        }
        $fields = array_values(array_unique(['gross', 'settlement', '_summary_amount_usd', ...array_keys(FinanceSettlementService::MONEY)]));
        foreach ($fields as $index => $field) {
            $query->selectRaw('SUM('.$number($field).") AS m{$index}");
        }
        $refund = $number('refund');
        foreach (['frozen_refund' => 'refund', 'refund_top_up' => 'refund_fee'] as $field => $legacy) {
            $refund = '(CASE WHEN '.$value('channel')." = 'paypal' AND COALESCE(".$number($field).', 0) <> 0 AND COALESCE('
                .$number($legacy).', 0) = 0 THEN COALESCE('.$refund.', 0) + '.$number($field).' ELSE '.$refund.' END)';
        }
        $query->selectRaw('SUM('.$refund.') AS refund_total')->selectRaw('COUNT(*) AS row_count')
            ->selectRaw('MIN(position) AS first_position')->orderBy('first_position');
        foreach ($query->cursor() as $group) {
            $row = ['_row_count' => (int) $group->row_count, '_summary_refund' => $group->refund_total];
            foreach ($dimensions as $index => $field) {
                $raw = $group->{'d'.$index};
                $row[$field] = $raw === null ? null : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            }
            foreach ($fields as $index => $field) $row[$field] = $group->{'m'.$index};
            yield $row;
        }
    }

    public function refresh(object $period): void
    {
        if ($this->usesTableLocks()) {
            $this->warmSourceFiles($period);
            return;
        }
        $db = $this->workbooks->database();
        $start = CarbonImmutable::parse($period->month.'-01', 'Asia/Shanghai');
        $nextRevision = (int) $db->table('finance_summary_periods')->where([
            'month' => $start->addMonth()->format('Y-m'), 'channel' => $period->channel,
        ])->value('revision');
        $projectionVersion = hash('sha256', json_encode([3, $nextRevision], JSON_THROW_ON_ERROR));
        $fxVersion = $period->channel === 'credit_card' ? app(FinanceCreditUsdRates::class)->fingerprint($period->month) : null;
        if ((int) $period->published >= (int) $period->revision) {
            $publishedRows = $db->table('finance_summary_rows')->where(['month' => $period->month, 'channel' => $period->channel,
                'revision' => $period->published]);
            $first = (clone $publishedRows)->orderBy('position')->value('payload');
            $payload = $first === null ? null : json_decode($first, true, 512, JSON_THROW_ON_ERROR);
            $variants = (clone $publishedRows)->select('timezone', 'transactions')
                ->groupBy('timezone', 'transactions')->get()->count();
            if ($payload !== null && $variants === 4 && ($payload['_summary_projection_version'] ?? null) === $projectionVersion
                && ($period->channel === 'paypal'
                ? ($payload['_summary_calculation_version'] ?? null) === 2
                : ($payload['_summary_fx_version'] ?? null) === $fxVersion)) {
                return;
            }
            if (! $db->table('finance_summary_periods')->where(['month' => $period->month, 'channel' => $period->channel,
                'revision' => $period->revision, 'published' => $period->published])->increment('revision')) {
                return;
            }
            $period = (object) [...(array) $period, 'revision' => (int) $period->revision + 1];
        }
        $scope = ['month' => $period->month, 'channel' => $period->channel];
        $version = [...$scope, 'revision' => $period->revision];
        $db->table('finance_summary_rows')->where($version)->delete();
        $hasFilterColumns = $db->getSchemaBuilder()->hasColumn('finance_summary_rows', 'payment_channel');
        foreach ([['Asia/Shanghai', false], ['Asia/Shanghai', true], ['-04:00', false], ['-04:00', true]] as [$timezone, $transactions]) {
            $batch = [];
            $position = 0;
            $filters = ['from' => $start->toDateString(), 'to' => $start->endOfMonth()->toDateString(),
                'timezone' => $timezone, 'logistics_costs' => $transactions, 'summary_projection' => true,
                'summary_native_settlement' => $transactions, 'include_unmatched_fees' => true];
            if ($period->channel === 'credit_card' && ($timezone === '-04:00' || ! $transactions)) {
                $filters['summary_payment_channels'] = $timezone === '-04:00' ? ['xuzong1', 'xuzong2'] : ['ye', 'worldpay'];
            }
            foreach ($this->settlements->summaryRowStream($period->channel, $filters, $transactions) as $row) {
                $row['_summary_projection_version'] = $projectionVersion;
                $row['_summary_calculation_version'] = 2;
                if ($fxVersion !== null) {
                    $row['_summary_fx_version'] = $fxVersion;
                }
                $batch[] = [...$version, 'timezone' => $timezone, 'transactions' => $transactions, 'position' => ++$position,
                    'account' => $row['_summary_source_account'], 'currency' => $row['currency'] ?? null,
                    ...($hasFilterColumns ? [
                        'payment_channel' => $row['payment_channel'] ?? null,
                        'owner' => $row['owner'] ?? null,
                        'team' => $row['team'] ?? null,
                        'summary_team' => $row['_summary_team'] ?? null,
                        'subscription' => $row['subscription'] ?? null,
                        'payment_method' => $row['payment_method'] ?? null,
                    ] : []),
                    'payload' => json_encode(array_diff_key($row, ['_summary_source_account' => true]), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)];
                if (count($batch) === 250) {
                    $db->table('finance_summary_rows')->insert($batch);
                    $batch = [];
                }
            }
            if ($batch !== []) {
                $db->table('finance_summary_rows')->insert($batch);
            }
        }
        if ($fxVersion !== null && $fxVersion !== app(FinanceCreditUsdRates::class)->fingerprint($period->month)) {
            $db->table('finance_summary_rows')->where($version)->delete();
            return;
        }
        // A concurrent import changes the revision in the same transaction as its source rows.
        $published = $db->table('finance_summary_periods')->where($version)->update(['published' => $period->revision]);
        $alreadyPublished = ! $published && $db->table('finance_summary_periods')->where($version)
            ->where('published', $period->revision)->exists();
        if ($published || $alreadyPublished) {
            // Keep the immediately preceding publication available to in-flight readers.
            $db->table('finance_summary_rows')->where($scope)->where('revision', '<', $period->published)->delete();
        } else {
            $db->table('finance_summary_rows')->where($version)->delete();
        }
    }

    private function warmSourceFiles(object $period): void
    {
        $start = CarbonImmutable::parse($period->month.'-01', 'Asia/Shanghai');
        $scopes = [['Asia/Shanghai', true], ['Asia/Shanghai', false], ['-04:00', false]];
        foreach ($scopes as [$zone, $costs]) {
            $filters = ['from' => $start->toDateString(), 'to' => $start->endOfMonth()->toDateString(),
                'timezone' => $zone, 'logistics_costs' => $costs,
                'summary_native_settlement' => $costs, 'include_unmatched_fees' => true];
            if ($period->channel === 'credit_card' && ! $costs) {
                $filters['summary_payment_channels'] = $zone === '-04:00' ? ['xuzong1', 'xuzong2'] : ['ye', 'worldpay'];
            }
            foreach (app(FinanceSummarySourceCache::class)->stream($period->channel, $filters, $costs,
                fn (array $sourceFilters) => $this->settlements->summaryRowStream($period->channel, $sourceFilters, $costs),
                static function (array $options): void {}) as $row) {
                // Consume a streaming build; source files publish atomically at the same revision.
            }
        }
    }
}
