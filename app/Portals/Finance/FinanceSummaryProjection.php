<?php

namespace App\Portals\Finance;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class FinanceSummaryProjection
{
    public function __construct(private readonly FinanceWorkbookService $workbooks, private readonly FinanceSettlementService $settlements) {}

    public function read(string $channel, array $filters, bool $transactions, ?string $currency = null): ?Collection
    {
        $rows = $this->stream($channel, $filters, $transactions, $currency);

        return $rows === null ? null : collect(iterator_to_array($rows, false));
    }

    public function stream(string $channel, array $filters, bool $transactions, ?string $currency = null): ?iterable
    {
        $db = $this->workbooks->database();
        // Worldpay allocates costs within the account-filtered source batches.
        if (! in_array($db->getDriverName(), ['mysql', 'mariadb'], true)
            || ($channel === 'credit_card' && filled($filters['account'] ?? null))) {
            return null;
        }

        $month = substr($filters['from'], 0, 7);
        return $db->transaction(function () use ($db, $month, $channel, $filters, $transactions, $currency): ?iterable {
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

            return $rows->lazyById(500, 'position')->map(fn ($row) => json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR))
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
        if ((int) $period->published >= (int) $period->revision) {
            return;
        }
        $db = $this->workbooks->database();
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
        // A concurrent import changes the revision in the same transaction as its source rows.
        $published = $db->table('finance_summary_periods')->where($version)->update(['published' => $period->revision]);
        if ($published) {
            // Keep the immediately preceding publication available to in-flight readers.
            $db->table('finance_summary_rows')->where($scope)->where('revision', '<', $period->published)->delete();
        } else {
            $db->table('finance_summary_rows')->where($version)->delete();
        }
    }
}
