<?php

namespace App\Portals\Finance;

class FinanceSummaryDataVersion
{
    public function __construct(private readonly FinanceWorkbookService $workbooks) {}

    public function cacheRevision(array $channels): string
    {
        $version = $this->forCache($channels);
        if ($version !== null) return $version;
        // Untracked options may be shared within this request only.
        $attributes = app('request')->attributes;
        $run = $attributes->get('finance.source_cache_run') ?? bin2hex(random_bytes(16));
        $attributes->set('finance.source_cache_run', $run);
        return hash('sha256', json_encode([$channels, $run], JSON_THROW_ON_ERROR));
    }

    public function forCache(array $channels): ?string
    {
        $db = $this->workbooks->database();
        if (FinanceReadModelVersions::installed($db)) return FinanceReadModelVersions::version($db, $channels);
        if (FinanceSummarySourceChanges::available($db) && ! app(FinanceExternalChanges::class)->drain($db)) return null;
        if (FinanceSummarySourceChanges::available($db) && app(FinanceSummarySourceChanges::class)->publish($db)) {
            $cardChannels = ['xuzong1', 'xuzong2', 'ye', 'worldpay'];
            $scopes = in_array('credit_card', $channels, true) ? array_unique([...$channels, ...$cardChannels]) : $channels;
            if (array_intersect($channels, $cardChannels)) $scopes = array_unique([...$scopes, 'credit_card']);
            $revisions = $db->table(FinanceSummarySourceChanges::TABLE)->whereIn('channel', $scopes)
                ->orderBy('channel')->orderBy('source_date')->get(['channel', 'source_date', 'revision']);
            // Durable revisions survive worker acknowledgements and period publication.
            return hash('sha256', json_encode(['finance-input-cache-v1', $channels, $revisions], JSON_THROW_ON_ERROR));
        }
        $versions = array_map(fn ($channel) => $this->current(in_array($channel, ['xuzong1', 'xuzong2', 'ye', 'worldpay'], true)
            ? 'credit_card' : $channel), $channels);
        if (in_array(null, $versions, true)) return null;
        return hash('sha256', json_encode([$channels, $versions], JSON_THROW_ON_ERROR));
    }

    public function current(string $channel): ?string
    {
        $db = $this->workbooks->database();
        if (FinanceReadModelVersions::installed($db)) return FinanceReadModelVersions::version($db, [$channel]);
        if (! FinanceSummarySourceChanges::available($db)) {
            // Without application tracking, reuse source parts only within this one
            // authenticated range calculation, never across later queries.
            $run = app('request')->attributes->get('finance.source_run');
            return is_string($run) && $run !== '' ? hash('sha256', 'request-source:'.$channel.':'.$run) : null;
        }
        if (! app(FinanceExternalChanges::class)->drain($db)) return null;
        // Related refunds, statements and FX can change an earlier month's payment.
        $periods = $db->table('finance_summary_periods')->where('channel', $channel)
            ->orderBy('month')->get(['month', 'revision']);
        // Pending dependency changes already represent changed source data, even before
        // the background worker has resolved them into individual period revisions.
        $changes = $db->table('finance_summary_changes')
            ->when($channel === 'paypal', fn ($query) => $query->whereNotIn('source', ['fashion', 'fashion_item']))
            ->selectRaw('COUNT(*) AS total, MAX(id) AS last_id')->first();
        $rates = $db->table('finance_monthly_exchange_rates')
            ->when($channel === 'paypal', fn ($query) => $query->whereIn('channel', ['paypal', 'combined']))
            ->when($channel === 'credit_card', fn ($query) => $query->where('channel', '<>', 'paypal'))
            ->orderBy('month')->orderBy('channel')->orderBy('currency')
            ->get(['month', 'channel', 'currency', 'cny_rate']);
        $creditRates = $channel === 'credit_card' ? $periods->mapWithKeys(fn ($period) => [
            $period->month => app(FinanceCreditUsdRates::class)->fingerprint($period->month),
        ]) : [];
        return hash('sha256', json_encode([$periods, $changes, $rates, $creditRates], JSON_THROW_ON_ERROR));
    }

    public function forRange(string $channel, array $filters): ?string
    {
        $db = $this->workbooks->database();
        if (FinanceReadModelVersions::installed($db)) return FinanceReadModelVersions::version($db, [$channel]);
        if (empty($filters['from']) || empty($filters['to']) || ! FinanceSummarySourceChanges::available($db)) {
            return $this->current($channel);
        }
        if (! app(FinanceSummarySourceChanges::class)->publish($db)) {
            // A large, genuinely changed batch is not yet fully mapped. Keep the safe legacy fallback.
            return $this->current($channel);
        }
        $zone = $filters['timezone'] ?? 'Asia/Shanghai';
        $start = \Carbon\CarbonImmutable::parse($filters['from'], $zone)->utc()->toDateString();
        $end = \Carbon\CarbonImmutable::parse($filters['to'], $zone)->addDay()->subSecond()->utc()->toDateString();
        $channels = $channel === 'paypal' ? ['paypal'] : ['credit_card', ...($filters['summary_payment_channels'] ?? ['xuzong1', 'xuzong2', 'ye', 'worldpay'])];
        $days = $db->table(FinanceSummarySourceChanges::TABLE)->whereIn('channel', $channels)
            ->where(fn ($query) => $query->whereBetween('source_date', [$start, $end])->orWhere('source_date', '*'))
            ->orderBy('channel')->orderBy('source_date')->get(['channel', 'source_date', 'revision']);
        $rates = $channel === 'credit_card'
            ? app(FinanceCreditUsdRates::class)->rangeFingerprint($filters['from'], $filters['to']) : null;
        return hash('sha256', json_encode(['finance-scoped-source-v1', $channel, $days, $rates], JSON_THROW_ON_ERROR));
    }
}
