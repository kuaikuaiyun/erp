<?php

namespace Tests\Unit;

use App\Portals\Assistant\AppLovinHistoricalSpendService;
use Tests\TestCase;

class AppLovinHistoricalSpendServiceTest extends TestCase
{
    public function test_it_sums_each_normalized_account_number_only_once(): void
    {
        $service = new AppLovinHistoricalSpendService();
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
}
