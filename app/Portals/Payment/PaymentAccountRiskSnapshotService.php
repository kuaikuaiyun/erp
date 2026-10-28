<?php

namespace App\Portals\Payment;

use App\Models\LogisticsPayPalAccount;
use App\Models\PaymentPayPalAccountRiskSnapshot;
use App\Portals\Logistics\PayPalRiskAnalyticsService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class PaymentAccountRiskSnapshotService
{
    public const RISK_THRESHOLD = 3.0;

    private const TIMEZONE = 'Asia/Shanghai';

    public function __construct(private readonly PayPalRiskAnalyticsService $analytics)
    {
    }

    /** @return array{snapshot_date:CarbonImmutable,window_start:CarbonImmutable,window_end:CarbonImmutable} */
    public function currentWindow(): array
    {
        $snapshotDate = CarbonImmutable::now(self::TIMEZONE)->startOfWeek();
        $windowEnd = $snapshotDate->subSecond();

        return [
            'snapshot_date' => $snapshotDate,
            'window_start' => $windowEnd->startOfDay()->subDays(29),
            'window_end' => $windowEnd,
        ];
    }

    public function refresh(): int
    {
        $window = $this->currentWindow();
        $count = 0;

        LogisticsPayPalAccount::query()->orderBy('id')->chunkById(100, function (Collection $accounts) use ($window, &$count): void {
            foreach ($accounts as $account) {
                $summary = $this->analytics->currentSummary($account, [
                    'date_mode' => 'event',
                    'date_from' => '',
                    'date_to' => '',
                    'event_date_from' => $window['window_start']->format('Y-m-d'),
                    'event_date_to' => $window['window_end']->format('Y-m-d'),
                ]);
                PaymentPayPalAccountRiskSnapshot::query()->updateOrCreate([
                    'logistics_paypal_account_id' => $account->getKey(),
                    'snapshot_date' => $window['snapshot_date']->format('Y-m-d'),
                ], [
                    'window_start' => $window['window_start']->utc(),
                    'window_end' => $window['window_end']->utc(),
                    'order_count' => (int) ($summary['order_count'] ?? 0),
                    'dispute_order_count' => (int) ($summary['overall_dispute_order_count'] ?? 0),
                    'dispute_rate' => (float) ($summary['overall_dispute_rate'] ?? 0),
                ]);
                $count++;
            }
        });

        return $count;
    }

    /** @param list<int> $accountIds @return Collection<int,PaymentPayPalAccountRiskSnapshot> */
    public function currentForAccounts(array $accountIds): Collection
    {
        if ($accountIds === []) {
            return collect();
        }
        $snapshotDate = $this->currentWindow()['snapshot_date']->format('Y-m-d');

        return PaymentPayPalAccountRiskSnapshot::query()
            ->whereIn('logistics_paypal_account_id', $accountIds)
            ->whereDate('snapshot_date', $snapshotDate)
            ->get()->keyBy('logistics_paypal_account_id');
    }
}
