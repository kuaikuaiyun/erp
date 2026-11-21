<?php

namespace App\Portals\Finance;

use Carbon\CarbonImmutable;

/** Canonical summary dimensions and numeric amounts. No original payload is published here. */
class FinanceSummaryFacts
{
    public const DIMENSIONS = ['payment_channel', 'account', 'account_key', 'currency', 'team', 'owner', 'subscription', 'payment_method'];

    public function __construct(private readonly FinanceWorkbookService $workbooks, private readonly FinanceSettlementService $settlements) {}

    public static function amounts(): array
    {
        return array_values(array_unique(['gross', 'settlement', 'converted_usd', 'usd_rate', ...array_keys(FinanceSettlementService::MONEY), 'summary_amount_usd', 'summary_payable']));
    }

    public function installed(): bool
    {
        return $this->workbooks->database()->getSchemaBuilder()->hasColumn('finance_summary_publications', 'verified');
    }

    private function filters(string $channel, string $month, string $zone): array
    {
        $date = CarbonImmutable::parse($month.'-01');
        $groups = FinanceReportTimezones::groups($channel);
        return ['from' => $date->toDateString(), 'to' => $date->endOfMonth()->toDateString(), 'timezone' => $zone,
            'summary_payment_channels' => $groups[$zone] ?? [], 'logistics_costs' => true,
            'summary_shared_source' => true, 'include_unmatched_fees' => true];
    }

    private function version(string $channel, array $filters): ?string
    {
        $source = app(FinanceSummaryDataVersion::class)->forRange($channel, $filters);
        return $source === null ? null : hash('sha256', json_encode([$source, FinanceSettlementService::summarySourceSchemaVersion($channel)], JSON_THROW_ON_ERROR));
    }

    public function refresh(object $period): void
    {
        $db = $this->workbooks->database();
        $key = 'finance-facts:'.substr(hash('sha256', $db->getDatabaseName().':'.$period->channel.':'.$period->month), 0, 48);
        $mysql = in_array($db->getDriverName(), ['mysql', 'mariadb'], true);
        $lock = $mysql ? null : \Illuminate\Support\Facades\Cache::store('file')->lock($key, 3600);
        $acquired = $mysql ? (int) $db->selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$key])->acquired === 1 : $lock->get();
        if (! $acquired) return;
        try {
            $this->build($period);
        } finally {
            if ($mysql) $db->selectOne('SELECT RELEASE_LOCK(?) AS released', [$key]);
            else $lock->release();
        }
    }

    private function build(object $period): void
    {
        $db = $this->workbooks->database();
        foreach (FinanceReportTimezones::groups($period->channel) as $zone => $platforms) {
            $filters = $this->filters($period->channel, $period->month, $zone);
            $version = $this->version($period->channel, $filters);
            if ($version === null) return;
            $existing = $db->table('finance_summary_publications')->where(['month' => $period->month, 'channel' => $period->channel, 'timezone' => $zone,
                'source_version' => $version, 'complete' => true])->orderByDesc('id')->first();
            if ($existing) {
                if (! $existing->verified && ! $this->verify($period->channel, $period->month, $zone)) throw new \RuntimeException('结构化汇总对账失败，未启用新批次。');
                $this->prune($period->channel, $period->month, $zone);
                continue;
            }
            $publication = $db->table('finance_summary_publications')->insertGetId(['month' => $period->month, 'channel' => $period->channel,
                'timezone' => $zone, 'revision' => $period->revision, 'source_version' => $version, 'complete' => false]);
            $position = 0;
            try {
                $rows = app(FinanceSummarySourceCache::class)->stream($period->channel, $filters, false,
                    fn (array $sourceFilters) => $this->settlements->summaryRowStream($period->channel, $sourceFilters));
                $batch = [];
                foreach ($rows as $row) {
                    $batch[] = $row;
                    if (count($batch) === 300) { $this->insert($publication, $position, $batch); $batch = []; }
                }
                if ($batch !== []) $this->insert($publication, $position, $batch);
                if ($version !== $this->version($period->channel, $filters)) {
                    $this->delete($publication);
                    return;
                }
                $db->table('finance_summary_publications')->where('id', $publication)->update(['complete' => true, 'row_count' => $position]);
                if (! $this->verify($period->channel, $period->month, $zone)) throw new \RuntimeException('结构化汇总对账失败，未启用新批次。');
                $this->prune($period->channel, $period->month, $zone);
            } catch (\Throwable $error) {
                $this->delete($publication);
                throw $error;
            }
        }
        $db->table('finance_summary_periods')->where(['month' => $period->month, 'channel' => $period->channel, 'revision' => $period->revision])
            ->update(['published' => $period->revision]);
    }

    public function insert(int $publication, int &$position, array $rows): void
    {
        $db = $this->workbooks->database();
        $start = $position;
        $position = $db->transaction(function () use ($db, $publication, $start, $rows): int {
            // One publication writes in order; other publications may build concurrently.
            $db->table('finance_summary_publications')->where('id', $publication)->lockForUpdate()->firstOrFail();
            $lastId = (int) $db->table('finance_summary_facts')->max('id');
            $next = $start;
            $facts = [];
            foreach ($rows as $row) {
                $values = ['publication_id' => $publication, 'position' => ++$next,
                    'record_id' => is_numeric($row['id'] ?? null) ? $row['id'] : null,
                    'summary_team' => $row['_summary_team'] ?? $row['team'] ?? null,
                    'settlement_currency' => $row['_summary_settlement_currency'] ?? 'USD',
                    'native_currency' => $row['_summary_native_currency'] ?? null,
                    'success' => ! empty($row['success']), 'failed' => ! empty($row['failed']),
                    'cost_adjustment' => ! empty($row['_cost_adjustment']), 'xuzong_detail' => ! empty($row['_summary_xuzong_detail'])];
                foreach (self::DIMENSIONS as $field) $values[$field] = $row[$field] ?? null;
                $facts[] = $values;
            }
            if ($facts === []) return $start;
            $db->table('finance_summary_facts')->insert($facts);
            $ids = $db->table('finance_summary_facts')->where('publication_id', $publication)->where('id', '>', $lastId)
                ->orderBy('position')->pluck('id')->all();
            if (count($ids) !== count($rows)) throw new \RuntimeException('汇总批次行数不一致，已回滚写入。');
            $amounts = [];
            foreach (array_values($rows) as $index => $row) {
                foreach ([0 => [], 1 => $row['_summary_native_values'] ?? []] as $basis => $overrides) {
                    if ($basis === 1 && $overrides === []) continue;
                    $source = array_replace($row, $overrides);
                    $amount = ['fact_id' => $ids[$index], 'basis' => $basis];
                    foreach (self::amounts() as $field) {
                        $value = $source[str_starts_with($field, 'summary_') ? '_'.$field : $field] ?? null;
                        $amount[$field] = is_numeric($value) ? (string) $value : null;
                    }
                    $amounts[] = $amount;
                }
            }
            $db->table('finance_summary_amounts')->insert($amounts);
            return $next;
        }, 3);
    }

    public function stream(string $channel, array $filters, bool $native, ?string $currency, array $paymentChannels, bool $aggregate = false, bool $audit = false, ?int $publicationId = null): ?iterable
    {
        $month = substr($filters['from'], 0, 7);
        $zone = $filters['timezone'] ?? 'Asia/Shanghai';
        $expected = $this->filters($channel, $month, $zone);
        if ($filters['from'] !== $expected['from'] || $filters['to'] !== $expected['to']
            || ! empty($filters['conditions'])
            || ($channel === 'credit_card' && filled($filters['account'] ?? null))) return null;
        $version = $this->version($channel, $expected);
        if ($version === null) return null;
        $db = $this->workbooks->database();
        $publication = $db->table('finance_summary_publications')->where(['month' => $month, 'channel' => $channel,
            'timezone' => $zone, 'source_version' => $version, 'complete' => true])
            ->when(! $audit, fn ($query) => $query->where('verified', true))
            ->when($publicationId !== null, fn ($query) => $query->where('id', $publicationId))->orderByDesc('id')->first();
        if (! $publication) return null;
        $query = $db->table('finance_summary_facts')->where('publication_id', $publication->id)
            ->when($paymentChannels !== [], fn ($query) => $query->whereIn('payment_channel', $paymentChannels))
            ->when(filled($currency), fn ($query) => $query->where('currency', $currency))
            ->when(filled($filters['account'] ?? null), fn ($query) => $query->where('account', $filters['account']))
            ->when(filled($filters['team'] ?? null), fn ($query) => $query->where('summary_team', $filters['team']))
            ->when(filled($filters['subscription'] ?? null), fn ($query) => $query->where('subscription', FinanceSettlementService::subscriptionLabel($filters['subscription'])));
        foreach (['account_key' => 'account_key', 'owner' => 'owner', 'team' => 'team', 'subscription' => 'subscription', 'payment_method' => 'payment_method'] as $filter => $column) {
            if (filled($filters['summary_row_filters'][$filter] ?? null)) $query->where($column, $filters['summary_row_filters'][$filter]);
        }
        if ($aggregate) return $this->aggregate($query, $channel, $month, $native);
        return \Illuminate\Support\LazyCollection::make(function () use ($query, $db, $channel, $month, $native): iterable {
            foreach ($query->orderBy('id')->lazyById(500)->chunk(500) as $batch) {
                $amounts = $db->table('finance_summary_amounts')->whereIntegerInRaw('fact_id', $batch->pluck('id')->all())->get()->groupBy('fact_id');
                foreach ($batch as $fact) {
                    $row = ['id' => $fact->record_id, 'channel' => $channel, 'month' => $month,
                        'success' => (bool) $fact->success, 'failed' => (bool) $fact->failed,
                        '_cost_adjustment' => (bool) $fact->cost_adjustment, '_summary_xuzong_detail' => (bool) $fact->xuzong_detail,
                        '_summary_settlement_currency' => $native ? ($fact->native_currency ?? $fact->settlement_currency) : $fact->settlement_currency, '_summary_shared_version' => 1,
                        '_summary_credit_version' => FinanceSettlementService::CREDIT_SUMMARY_VERSION,
                        '_summary_usd_version' => 1, '_summary_calculation_version' => FinanceSettlementService::PAYPAL_SUMMARY_VERSION,
                        '_summary_settlement_currency_version' => $native ? 1 : 0];
                    foreach (self::DIMENSIONS as $field) $row[$field] = $fact->$field;
                    $variants = $amounts->get($fact->id, collect())->keyBy('basis');
                    $usd = $variants->get(0);
                    if (! $usd) throw new \RuntimeException('汇总金额明细缺失，禁止显示不完整结果。');
                    foreach (self::amounts() as $field) $row[str_starts_with($field, 'summary_') ? '_'.$field : $field] = FinanceSourceStorage::money($usd->$field);
                    $row['_summary_native_currency'] = $fact->native_currency;
                    $row['_summary_native_values'] = [];
                    if ($original = $variants->get(1)) {
                        foreach (self::amounts() as $field) {
                            $row['_summary_native_values'][str_starts_with($field, 'summary_') ? '_'.$field : $field] = FinanceSourceStorage::money($original->$field);
                        }
                    }
                    if ($native) $row = FinanceSummarySourceCache::nativeRow($row);
                    yield $row;
                }
            }
        });
    }

    public function verify(string $channel, string $month, string $zone): bool
    {
        $filters = $this->filters($channel, $month, $zone);
        $version = $this->version($channel, $filters);
        if ($version === null) return false;
        $db = $this->workbooks->database();
        $publication = $db->table('finance_summary_publications')->where(['month' => $month, 'channel' => $channel,
            'timezone' => $zone, 'source_version' => $version, 'complete' => true])->orderByDesc('id')->first();
        if (! $publication) return false;
        $facts = $db->table('finance_summary_facts')->where('publication_id', $publication->id);
        if ((int) $publication->row_count !== (clone $facts)->count()
            || (clone $facts)->whereNotExists(fn ($query) => $query->selectRaw('1')->from('finance_summary_amounts')
                ->whereColumn('fact_id', 'finance_summary_facts.id')->where('basis', 0))->exists()) return false;
        foreach ([false, true] as $native) {
            $source = app(FinanceSummarySourceCache::class)->stream($channel, $filters, false,
                fn (array $sourceFilters) => $this->settlements->summaryRowStream($channel, $sourceFilters));
            if ($native) $source = \Illuminate\Support\LazyCollection::make(fn () => $source)->map(FinanceSummarySourceCache::nativeRow(...));
            $typed = $this->stream($channel, $filters, $native, null, $filters['summary_payment_channels'], true, audit: true, publicationId: $publication->id);
            if ($typed === null) return false;
            $summary = new FinanceTransactionSummary;
            $tab = $channel === 'paypal' ? 'paypal' : 'combined';
            $expected = $summary->summarize($source, $tab, usd: ! $native);
            $actual = $summary->summarize($typed, $tab, usd: ! $native);
            if (self::canonical($expected) !== self::canonical($actual)) {
                file_put_contents(storage_path('logs/finance-facts-reconciliation.json'), json_encode(compact('month', 'channel', 'zone', 'native', 'expected', 'actual'), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                return false;
            }
        }
        if ($version !== $this->version($channel, $filters)) return false;
        $db->table('finance_summary_publications')->where('id', $publication->id)->update(['verified' => true]);
        return true;
    }

    private static function canonical(array $value): array
    {
        foreach ($value as &$item) if (is_array($item)) $item = self::canonical($item);
        unset($item);
        if (! array_is_list($value)) ksort($value);
        return $value;
    }

    private function aggregate(\Illuminate\Database\Query\Builder $query, string $channel, string $month, bool $native): iterable
    {
        $query = (clone $query)->join('finance_summary_amounts as usd', function ($join): void {
            $join->on('usd.fact_id', '=', 'finance_summary_facts.id')->where('usd.basis', 0);
        });
        if ($native) $query->leftJoin('finance_summary_amounts as original', function ($join): void {
            $join->on('original.fact_id', '=', 'finance_summary_facts.id')->where('original.basis', 1);
        });
        $number = static fn ($field) => $native ? '(CASE WHEN original.fact_id IS NULL THEN usd.'.$field.' ELSE original.'.$field.' END)' : 'usd.'.$field;
        $dimensions = [...array_combine(self::DIMENSIONS, self::DIMENSIONS), '_cost_adjustment' => 'cost_adjustment',
            '_summary_xuzong_detail' => 'xuzong_detail', 'success' => 'success', 'failed' => 'failed'];
        $query->select([]);
        foreach ($dimensions as $field => $column) $query->addSelect('finance_summary_facts.'.$column.' as '.$field)->groupBy('finance_summary_facts.'.$column);
        $currency = $native ? 'COALESCE(native_currency, settlement_currency)' : 'settlement_currency';
        $query->selectRaw($currency.' as _summary_settlement_currency')->groupByRaw($currency);
        foreach (['settlement', 'summary_amount_usd', 'summary_payable'] as $index => $field) {
            $query->selectRaw('('.$number($field).' IS NULL) as n'.$index)->groupBy('n'.$index);
        }
        foreach (self::amounts() as $field) $query->selectRaw('SUM('.$number($field).') as '.$query->getGrammar()->wrap($field));
        $refund = $number('refund');
        if ($channel === 'paypal') foreach (['frozen_refund' => 'refund', 'refund_top_up' => 'refund_fee'] as $field => $legacy) {
            $refund = '(CASE WHEN COALESCE('.$number($field).', 0) <> 0 AND COALESCE('.$number($legacy).', 0) = 0 THEN COALESCE('.$refund.', 0) + '.$number($field).' ELSE '.$refund.' END)';
        }
        $query->selectRaw('SUM('.$refund.') as _summary_refund')->selectRaw('COUNT(*) as _row_count')
            ->selectRaw('MIN(position) as first_position')->orderBy('first_position');
        return \Illuminate\Support\LazyCollection::make(function () use ($query, $channel, $month): iterable {
            foreach ($query->cursor() as $group) {
                $row = (array) $group;
                $row['channel'] = $channel; $row['month'] = $month;
                $row['_summary_amount_usd'] = $row['summary_amount_usd']; $row['_summary_payable'] = $row['summary_payable'];
                foreach (['success', 'failed', '_cost_adjustment', '_summary_xuzong_detail'] as $field) $row[$field] = (bool) $row[$field];
                yield $row;
            }
        });
    }

    private function delete(int $publication): void
    {
        $db = $this->workbooks->database();
        do {
            $ids = $db->table('finance_summary_facts')->where('publication_id', $publication)->limit(500)->pluck('id')->all();
            if ($ids !== []) $db->transaction(function () use ($db, $ids): void {
                $db->table('finance_summary_amounts')->whereIntegerInRaw('fact_id', $ids)->delete();
                $db->table('finance_summary_facts')->whereIntegerInRaw('id', $ids)->delete();
            });
        } while ($ids !== []);
        $db->table('finance_summary_publications')->where('id', $publication)->delete();
    }

    private function prune(string $channel, string $month, string $zone): void
    {
        $query = $this->workbooks->database()->table('finance_summary_publications')->where(['channel' => $channel, 'month' => $month, 'timezone' => $zone]);
        $keep = (clone $query)->where('complete', true)->where('verified', true)->orderByDesc('id')->limit(2)->pluck('id')->all();
        if ($keep === []) return;
        foreach ((clone $query)->whereNotIn('id', $keep)->pluck('id') as $id) $this->delete($id);
    }
}
