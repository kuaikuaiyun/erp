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

    public function read(string $channel, array $filters, bool $transactions, ?string $currency = null): ?Collection
    {
        $rows = $this->stream($channel, $filters, $transactions, $currency);

        return $rows === null ? null : collect(iterator_to_array($rows, false));
    }

    public function stream(string $channel, array $filters, bool $transactions, ?string $currency = null, array $paymentChannels = []): ?iterable
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
        return $db->transaction(function () use ($db, $month, $channel, $filters, $transactions, $currency, $paymentChannels): ?iterable {
            if ($db->table('finance_summary_changes')->exists()) {
                return null;
            }
            $period = $db->table('finance_summary_periods')->where(compact('month', 'channel'))->first();
            if (! $period || ! $period->published || $period->published !== $period->revision) {
                return null;
            }

            $rows = $db->table('finance_summary_rows')
                ->where(compact('month', 'channel', 'transactions'))->where('revision', $period->published)
                ->when(filled($filters['account'] ?? null), fn ($query) => $query->where('account', $filters['account']))
                ->when(filled($currency), fn ($query) => $query->where('currency', $currency))
                ->orderBy('position');

            $first = (clone $rows)->value('payload');
            if ($first !== null && ! isset(json_decode($first, true, 512, JSON_THROW_ON_ERROR)['payment_channel'])) {
                return null;
            }
            if ($channel === 'paypal' && $first !== null
                && (json_decode($first, true, 512, JSON_THROW_ON_ERROR)['_summary_calculation_version'] ?? null) !== 2) {
                return null;
            }
            if ($channel === 'credit_card' && $first !== null
                && (json_decode($first, true, 512, JSON_THROW_ON_ERROR)['_summary_fx_version'] ?? null)
                    !== app(FinanceCreditUsdRates::class)->fingerprint($month)) {
                // Old rows could have been published while reference quotes were unavailable.
                // Never let a source revision alone certify their converted amounts.
                return null;
            }

            if ($paymentChannels !== []) {
                $rows->whereIn('payload->payment_channel', $paymentChannels);
            }
            if (filled($filters['team'] ?? null)) {
                $rows->where('payload->_summary_team', $filters['team']);
            }
            if (filled($filters['subscription'] ?? null)) {
                $rows->where('payload->subscription', FinanceSettlementService::subscriptionLabel($filters['subscription']));
            }

            return $rows->select('position', 'payload')->lazyById(2000, 'position')
                ->map(fn ($row) => json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR))
                ->filter(fn ($row) => (! filled($filters['team'] ?? null) || $row['_summary_team'] === $filters['team'])
                && (! filled($filters['subscription'] ?? null) || $row['subscription'] === FinanceSettlementService::subscriptionLabel($filters['subscription'])))
                ->map(function (array $row): array {
                    unset($row['_summary_team']);

                    return $row;
                });
        });
    }

    public function refresh(object $period): void
    {
        if ($this->usesTableLocks()) {
            $this->warmSourceFiles($period);
            return;
        }
        $db = $this->workbooks->database();
        $fxVersion = $period->channel === 'credit_card' ? app(FinanceCreditUsdRates::class)->fingerprint($period->month) : null;
        if ((int) $period->published >= (int) $period->revision) {
            $first = $db->table('finance_summary_rows')->where(['month' => $period->month, 'channel' => $period->channel,
                'revision' => $period->published])->orderBy('position')->value('payload');
            $payload = $first === null ? null : json_decode($first, true, 512, JSON_THROW_ON_ERROR);
            if ($payload === null || ($period->channel === 'paypal'
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
        $start = CarbonImmutable::parse($period->month.'-01', 'Asia/Shanghai');
        $db->table('finance_summary_rows')->where($version)->delete();
        foreach ([false, true] as $transactions) {
            $batch = [];
            $position = 0;
            $filters = ['from' => $start->toDateString(), 'to' => $start->endOfMonth()->toDateString(),
                'timezone' => 'Asia/Shanghai', 'logistics_costs' => $transactions, 'summary_projection' => true, 'include_unmatched_fees' => true];
            foreach ($this->settlements->summaryRowStream($period->channel, $filters, $transactions) as $row) {
                $row['_summary_calculation_version'] = 2;
                if ($fxVersion !== null) {
                    $row['_summary_fx_version'] = $fxVersion;
                }
                $batch[] = [...$version, 'transactions' => $transactions, 'position' => ++$position,
                    'account' => $row['_summary_source_account'], 'currency' => $row['currency'] ?? null,
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
        if ($published) {
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
                'timezone' => $zone, 'logistics_costs' => $costs, 'include_unmatched_fees' => true];
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
