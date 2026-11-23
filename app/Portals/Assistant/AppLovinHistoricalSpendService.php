<?php

namespace App\Portals\Assistant;

use App\Models\FashionAdSpendEntry;
use App\Models\FashionAdvertisingReport;
use App\Models\OperationReport;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class AppLovinHistoricalSpendService
{
    /** @return Collection<string,float> */
    public function totalsByAccountNumber(): Collection
    {
        $operationVersion = OperationReport::query()
            ->where('status', OperationReport::STATUS_APPROVED)
            ->selectRaw('COUNT(*) as total_rows, MAX(updated_at) as last_updated_at')->first();
        $fashionVersion = FashionAdvertisingReport::query()
            ->where('status', FashionAdvertisingReport::STATUS_APPROVED)
            ->selectRaw('COUNT(*) as total_rows, MAX(updated_at) as last_updated_at')->first();
        $entryVersion = FashionAdSpendEntry::query()
            ->selectRaw('COUNT(*) as total_rows, MAX(updated_at) as last_updated_at')->first();
        $version = sha1(implode('|', [
            (string) ($operationVersion?->total_rows ?? 0),
            (string) ($operationVersion?->last_updated_at ?? ''),
            (string) ($fashionVersion?->total_rows ?? 0),
            (string) ($fashionVersion?->last_updated_at ?? ''),
            (string) ($entryVersion?->total_rows ?? 0),
            (string) ($entryVersion?->last_updated_at ?? ''),
        ]));

        return Cache::remember('applovin-global-historical-spend:v1:'.$version, now()->addMinutes(30), function (): Collection {
            $totals = collect();
            OperationReport::query()->where('status', OperationReport::STATUS_APPROVED)
                ->select(['id', 'advertising_rows'])->orderBy('id')->chunkById(200, function (Collection $reports) use ($totals): void {
                    foreach ($reports as $report) {
                        foreach ($report->advertising_rows ?: [] as $row) {
                            if (mb_strtolower(trim((string) ($row['media_source'] ?? ''))) !== 'applovin') continue;
                            $number = self::normalizeAccountNumber((string) ($row['account_number'] ?? ''));
                            if ($number === '') continue;
                            $totals->put($number, (float) $totals->get($number, 0) + (float) ($row['actual_spend'] ?? 0));
                        }
                    }
                });

            FashionAdvertisingReport::query()->where('status', FashionAdvertisingReport::STATUS_APPROVED)
                ->with(['adSpendEntries' => fn ($query) => $query->whereRaw("LOWER(TRIM(media)) = 'applovin'")])
                ->select(['id'])->orderBy('id')->chunkById(200, function (Collection $reports) use ($totals): void {
                    foreach ($reports as $report) {
                        foreach ($report->adSpendEntries as $entry) {
                            $number = self::normalizeAccountNumber((string) $entry->account_number);
                            if ($number === '') continue;
                            $totals->put($number, (float) $totals->get($number, 0) + (float) $entry->actual_spend_usd);
                        }
                    }
                });

            return $totals->map(fn ($total): float => round((float) $total, 2));
        });
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
}
