<?php

namespace App\Portals\Finance;

class FinanceSummaryDataVersion
{
    public function __construct(private readonly FinanceWorkbookService $workbooks) {}

    public function cacheRevision(array $channels): string
    {
        $versions = array_map(fn ($channel) => $this->current($channel), $channels);
        if (in_array(null, $versions, true)) {
            // Untracked sources may be shared within a request, never across requests.
            $request = app('request');
            $run = $request->attributes->get('finance.source_cache_run') ?? bin2hex(random_bytes(16));
            $request->attributes->set('finance.source_cache_run', $run);
            $versions[] = $run;
        }
        return hash('sha256', json_encode([$channels, $versions], JSON_THROW_ON_ERROR));
    }

    public function current(string $channel): ?string
    {
        $db = $this->workbooks->database();
        if (! FinanceSummaryTriggers::available($db)) {
            // Without DB triggers, reuse source parts only within this one
            // authenticated range calculation, never across later queries.
            $run = app('request')->attributes->get('finance.source_run');
            return is_string($run) && $run !== '' ? hash('sha256', 'request-source:'.$channel.':'.$run) : null;
        }
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
}
