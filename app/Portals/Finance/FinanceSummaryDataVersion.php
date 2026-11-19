<?php

namespace App\Portals\Finance;

class FinanceSummaryDataVersion
{
    public function __construct(private readonly FinanceWorkbookService $workbooks) {}

    public function current(string $channel): ?string
    {
        $db = $this->workbooks->database();
        if (! FinanceSummaryTriggers::available($db)) {
            return null;
        }
        // Related refunds, statements and FX can change an earlier month's payment.
        $periods = $db->table('finance_summary_periods')->where('channel', $channel)
            ->orderBy('month')->get(['month', 'revision']);
        // Pending dependency changes already represent changed source data, even before
        // the background worker has resolved them into individual period revisions.
        $changes = $db->table('finance_summary_changes')->selectRaw('COUNT(*) AS total, MAX(id) AS last_id')->first();
        $rates = $db->table('finance_monthly_exchange_rates')->orderBy('month')->orderBy('channel')->orderBy('currency')
            ->get(['month', 'channel', 'currency', 'cny_rate']);
        $creditRates = $channel === 'credit_card' ? $periods->mapWithKeys(fn ($period) => [
            $period->month => app(FinanceCreditUsdRates::class)->fingerprint($period->month),
        ]) : [];
        return hash('sha256', json_encode([$periods, $changes, $rates, $creditRates], JSON_THROW_ON_ERROR));
    }
}
