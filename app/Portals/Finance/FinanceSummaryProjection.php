<?php

namespace App\Portals\Finance;

use Illuminate\Support\Collection;

class FinanceSummaryProjection
{
    public function __construct(private readonly FinanceWorkbookService $workbooks, private readonly FinanceSettlementService $settlements) {}

    public function usesSourceFiles(): bool
    {
        return ! app(FinanceSummaryFacts::class)->installed();
    }

    public function read(string $channel, array $filters, bool $transactions, ?string $currency = null): ?Collection
    {
        $rows = $this->stream($channel, $filters, $transactions, $currency);

        return $rows === null ? null : collect(iterator_to_array($rows, false));
    }

    /** Losses preserve the channel-specific month boundaries and monetary basis. */
    public function lossStream(string $channel, array $filters, array $paymentChannels = []): ?iterable
    {
        $variants = ! empty($filters['summary_shared_source']) ? [[false, $channel === 'paypal' ? ['paypal'] : ['xuzong1', 'xuzong2', 'ye', 'worldpay']]]
            : ($channel === 'paypal' ? [[false, ['paypal']]]
            : [[true, ['xuzong1', 'xuzong2']], [false, ['ye', 'worldpay']]]);
        $streams = [];
        foreach (FinanceReportTimezones::groups($channel, $paymentChannels) as $zone => $zonePlatforms) {
            foreach ($variants as [$transactions, $platforms]) {
                $selected = array_values(array_intersect($platforms, $zonePlatforms));
                if ($selected === []) continue;
                $rows = $this->stream($channel, [...$filters, 'timezone' => $zone], $transactions, paymentChannels: $selected);
                if ($rows === null) return null;
                $streams[] = $rows;
            }
        }

        return \Illuminate\Support\LazyCollection::make(function () use ($streams) {
            foreach ($streams as $rows) yield from $rows;
        });
    }

    public function stream(string $channel, array $filters, bool $transactions, ?string $currency = null, array $paymentChannels = [], bool $aggregateTransactions = false): ?iterable
    {
        $facts = app(FinanceSummaryFacts::class);
        // Missing or stale facts fall back to the versioned source calculation
        // at the caller; the retired JSON summary tables are never queried.
        return $facts->installed() ? $facts->stream($channel, $filters, $transactions, $currency, $paymentChannels, $aggregateTransactions) : null;
    }

    public function refresh(object $period): void
    {
        $facts = app(FinanceSummaryFacts::class);
        if ($facts->installed()) $facts->refresh($period);
    }
}
