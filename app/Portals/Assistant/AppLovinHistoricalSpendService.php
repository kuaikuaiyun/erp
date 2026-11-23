<?php

namespace App\Portals\Assistant;

use App\Models\FashionAdSpendEntry;
use App\Models\FashionAdvertisingReport;
use App\Models\OperationReport;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class AppLovinHistoricalSpendService
{
    public const SUBSCRIPTION_PROJECT = 'subscription';

    /** @return Collection<string,float> */
    public function totalsByAccountNumber(): Collection
    {
        return $this->ledgerByAccountNumber()->map(
            fn (array $account): float => (float) $account['total_consumption']
        );
    }

    /**
     * @return Collection<string,array{total_consumption:float,segments:list<array{operator_user_id:int,project_code:string,start_date:string,end_date:string,before_consumption:float,segment_consumption:float}>}>
     */
    public function ledgerByAccountNumber(): Collection
    {
        return Cache::remember('applovin-global-spend-ledger:v2:'.$this->cacheVersion(), now()->addMinutes(30), function (): Collection {
            /** @var array<string,list<array<string,mixed>>> $records */
            $records = [];
            OperationReport::query()->where('status', OperationReport::STATUS_APPROVED)
                ->select(['id', 'report_date', 'operator_user_id', 'advertising_rows', 'created_at'])
                ->orderBy('id')->chunkById(200, function (Collection $reports) use (&$records): void {
                    foreach ($reports as $report) {
                        foreach ($report->advertising_rows ?: [] as $index => $row) {
                            if (mb_strtolower(trim((string) ($row['media_source'] ?? ''))) !== 'applovin') {
                                continue;
                            }
                            $number = self::normalizeAccountNumber((string) ($row['account_number'] ?? ''));
                            if ($number === '') {
                                continue;
                            }
                            $records[$number][] = [
                                'operator_user_id' => (int) $report->operator_user_id,
                                'project_code' => self::SUBSCRIPTION_PROJECT,
                                'date' => $report->report_date->format('Y-m-d'),
                                'occurred_at' => (string) ($report->created_at ?? ''),
                                'sequence' => sprintf('operation|%020d|%06d', $report->getKey(), $index),
                                'spend' => (float) ($row['actual_spend'] ?? 0),
                            ];
                        }
                    }
                });

            FashionAdvertisingReport::query()->where('status', FashionAdvertisingReport::STATUS_APPROVED)
                ->with(['adSpendEntries' => fn ($query) => $query->whereRaw("LOWER(TRIM(media)) = 'applovin'")])
                ->select(['id', 'project_code', 'report_date', 'submitter_id', 'created_at'])
                ->orderBy('id')->chunkById(200, function (Collection $reports) use (&$records): void {
                    foreach ($reports as $report) {
                        foreach ($report->adSpendEntries as $entry) {
                            $number = self::normalizeAccountNumber((string) $entry->account_number);
                            if ($number === '') {
                                continue;
                            }
                            $records[$number][] = [
                                'operator_user_id' => (int) $report->submitter_id,
                                'project_code' => (string) $report->project_code,
                                'date' => $report->report_date->format('Y-m-d'),
                                'occurred_at' => (string) ($report->created_at ?? ''),
                                'sequence' => sprintf('fashion|%020d|%020d', $report->getKey(), $entry->getKey()),
                                'spend' => (float) $entry->actual_spend_usd,
                            ];
                        }
                    }
                });

            return collect($records)->map(function (array $accountRecords): array {
                usort($accountRecords, static fn (array $left, array $right): int => [
                    $left['date'], $left['occurred_at'], $left['sequence'],
                ] <=> [
                    $right['date'], $right['occurred_at'], $right['sequence'],
                ]);
                $segments = [];
                $cumulative = 0.0;
                foreach ($accountRecords as $record) {
                    $lastIndex = count($segments) - 1;
                    $isSameSegment = $lastIndex >= 0
                        && $segments[$lastIndex]['operator_user_id'] === (int) $record['operator_user_id']
                        && $segments[$lastIndex]['project_code'] === (string) $record['project_code'];
                    if (! $isSameSegment) {
                        $segments[] = [
                            'operator_user_id' => (int) $record['operator_user_id'],
                            'project_code' => (string) $record['project_code'],
                            'start_date' => (string) $record['date'],
                            'end_date' => (string) $record['date'],
                            'before_consumption' => round($cumulative, 2),
                            'segment_consumption' => 0.0,
                        ];
                        $lastIndex++;
                    }
                    $segments[$lastIndex]['end_date'] = (string) $record['date'];
                    $segments[$lastIndex]['segment_consumption'] += (float) $record['spend'];
                    $cumulative += (float) $record['spend'];
                }
                foreach ($segments as &$segment) {
                    $segment['segment_consumption'] = round((float) $segment['segment_consumption'], 2);
                }
                unset($segment);

                return ['total_consumption' => round($cumulative, 2), 'segments' => $segments];
            });
        });
    }

    /** @return array{before_consumption:float,segment_consumption:float,start_date:?string,end_date:?string} */
    public function segmentFor(Collection $ledger, string $accountNumber, int $operatorUserId, string $projectCode, ?string $atDate = null): array
    {
        $account = $ledger->get(self::normalizeAccountNumber($accountNumber));
        $segments = collect($account['segments'] ?? [])->filter(fn (array $segment): bool => $segment['operator_user_id'] === $operatorUserId && $segment['project_code'] === $projectCode
        );
        $segment = $atDate
            ? ($segments->first(fn (array $item): bool => $item['start_date'] <= $atDate && $item['end_date'] >= $atDate)
                ?: $segments->filter(fn (array $item): bool => $item['start_date'] <= $atDate)->last())
            : $segments->last();
        $segment ??= $segments->last();

        return [
            'before_consumption' => (float) ($segment['before_consumption'] ?? 0),
            'segment_consumption' => (float) ($segment['segment_consumption'] ?? 0),
            'start_date' => $segment['start_date'] ?? null,
            'end_date' => $segment['end_date'] ?? null,
        ];
    }

    public function allocatedGift(float $beforeConsumption, float $segmentConsumption, bool $hasGift): float
    {
        if (! $hasGift) {
            return 0.0;
        }
        $giftAtEnd = min(max($beforeConsumption + $segmentConsumption - 5000, 0), 5000);
        $giftAtStart = min(max($beforeConsumption - 5000, 0), 5000);

        return round(max(0, $giftAtEnd - $giftAtStart), 2);
    }

    public function netSegmentConsumption(float $beforeConsumption, float $segmentConsumption, bool $hasGift): float
    {
        return round(max(0, $segmentConsumption - $this->allocatedGift($beforeConsumption, $segmentConsumption, $hasGift)), 2);
    }

    /**
     * @param  iterable<string>  $accountNumbers
     * @param  iterable<string>  $giftAccountNumbers
     * @return array{before_consumption:float,segment_consumption:float,allocated_gift:float,deducted_consumption:float,all_gift_deducted:bool,segment_keys:list<string>}
     */
    public function segmentMetricsForAccounts(
        Collection $ledger,
        iterable $accountNumbers,
        int $operatorUserId,
        string $projectCode,
        ?string $atDate,
        iterable $giftAccountNumbers,
    ): array {
        $giftNumbers = collect($giftAccountNumbers)
            ->map(fn ($number): string => self::normalizeAccountNumber((string) $number))
            ->filter()->unique()->flip();
        $metrics = [
            'before_consumption' => 0.0,
            'segment_consumption' => 0.0,
            'allocated_gift' => 0.0,
            'deducted_consumption' => 0.0,
            'segment_keys' => [],
        ];

        foreach (collect($accountNumbers)->map(fn ($number): string => self::normalizeAccountNumber((string) $number))->filter()->unique() as $number) {
            $segment = $this->segmentFor($ledger, $number, $operatorUserId, $projectCode, $atDate);
            $before = (float) $segment['before_consumption'];
            $consumption = (float) $segment['segment_consumption'];
            $hasGift = $giftNumbers->has($number);
            $allocatedGift = $this->allocatedGift($before, $consumption, $hasGift);
            $metrics['before_consumption'] += $before;
            $metrics['segment_consumption'] += $consumption;
            $metrics['allocated_gift'] += $allocatedGift;
            $metrics['deducted_consumption'] += max(0, $consumption - $allocatedGift);
            $metrics['segment_keys'][] = implode('|', [$number, $operatorUserId, $projectCode, $segment['start_date'] ?? '']);
        }

        foreach (['before_consumption', 'segment_consumption', 'allocated_gift', 'deducted_consumption'] as $field) {
            $metrics[$field] = round((float) $metrics[$field], 2);
        }
        $metrics['segment_keys'] = array_values(array_unique($metrics['segment_keys']));
        $metrics['all_gift_deducted'] = $metrics['segment_consumption'] > 0 && $metrics['deducted_consumption'] <= 0;

        return $metrics;
    }

    public function sumForAccountNumbers(Collection $totals, iterable $accountNumbers): float
    {
        return round((float) collect($accountNumbers)
            ->map(fn ($number): string => self::normalizeAccountNumber((string) $number))
            ->filter()->unique()
            ->sum(fn (string $number): float => (float) $totals->get($number, 0)), 2);
    }

    public static function normalizeAccountNumber(string $accountNumber): string
    {
        return preg_replace('/[^0-9a-z]+/i', '', mb_strtolower(trim($accountNumber))) ?? '';
    }

    private function cacheVersion(): string
    {
        $operation = OperationReport::query()->where('status', OperationReport::STATUS_APPROVED)
            ->selectRaw('COUNT(*) as total_rows, MAX(updated_at) as last_updated_at')->first();
        $fashion = FashionAdvertisingReport::query()->where('status', FashionAdvertisingReport::STATUS_APPROVED)
            ->selectRaw('COUNT(*) as total_rows, MAX(updated_at) as last_updated_at')->first();
        $entries = FashionAdSpendEntry::query()
            ->selectRaw('COUNT(*) as total_rows, MAX(updated_at) as last_updated_at')->first();

        return sha1(implode('|', [
            (string) ($operation?->total_rows ?? 0), (string) ($operation?->last_updated_at ?? ''),
            (string) ($fashion?->total_rows ?? 0), (string) ($fashion?->last_updated_at ?? ''),
            (string) ($entries?->total_rows ?? 0), (string) ($entries?->last_updated_at ?? ''),
        ]));
    }
}
