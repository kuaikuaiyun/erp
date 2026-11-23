<?php

namespace Tests\Unit;

use App\Portals\Assistant\AppLovinHistoricalSpendService;
use Tests\TestCase;

class AppLovinHistoricalSpendServiceTest extends TestCase
{
    public function test_it_sums_each_normalized_account_number_only_once(): void
    {
        $service = new AppLovinHistoricalSpendService;
        $totals = collect([
            '1343777812' => 9807.43,
            'abc123' => 120.50,
        ]);

        self::assertSame(9927.93, $service->sumForAccountNumbers($totals, [
            '1343777812',
            ' 134-377-7812 ',
            'ABC 123',
        ]));
    }

    public function test_it_allocates_only_the_gift_crossed_by_the_current_segment(): void
    {
        $service = new AppLovinHistoricalSpendService;

        self::assertSame(2000.0, $service->allocatedGift(0, 7000, true));
        self::assertSame(5000.0, $service->netSegmentConsumption(0, 7000, true));
        self::assertSame(3000.0, $service->allocatedGift(7000, 5000, true));
        self::assertSame(2000.0, $service->netSegmentConsumption(7000, 5000, true));
    }

    public function test_a_segment_can_be_fully_deducted_by_the_remaining_gift(): void
    {
        $service = new AppLovinHistoricalSpendService;

        self::assertSame(5000.0, $service->allocatedGift(5000, 5000, true));
        self::assertSame(0.0, $service->netSegmentConsumption(5000, 5000, true));
        self::assertSame(5000.0, $service->netSegmentConsumption(5000, 5000, false));
    }

    public function test_it_finds_the_operator_project_segment_and_its_previous_consumption(): void
    {
        $service = new AppLovinHistoricalSpendService;
        $ledger = collect([
            '1343777812' => [
                'total_consumption' => 9807.43,
                'segments' => [
                    ['operator_user_id' => 1, 'project_code' => 'subscription', 'start_date' => '2026-10-23', 'end_date' => '2026-11-03', 'before_consumption' => 0.0, 'segment_consumption' => 1340.13],
                    ['operator_user_id' => 2, 'project_code' => 'subscription', 'start_date' => '2026-11-04', 'end_date' => '2026-11-23', 'before_consumption' => 1340.13, 'segment_consumption' => 8467.30],
                ],
            ],
        ]);

        self::assertSame([
            'before_consumption' => 1340.13,
            'segment_consumption' => 8467.30,
            'start_date' => '2026-11-04',
            'end_date' => '2026-11-23',
        ], $service->segmentFor($ledger, '1343-777-812', 2, 'subscription', '2026-11-20'));
    }
}
