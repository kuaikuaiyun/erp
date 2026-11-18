<?php

namespace Tests\Unit;

use App\Support\AppLovinGiftConsumption;
use PHPUnit\Framework\TestCase;

class AppLovinGiftConsumptionTest extends TestCase
{
    public function test_account_without_gift_keeps_historical_consumption(): void
    {
        self::assertSame(8654.34, AppLovinGiftConsumption::net(8654.34, false));
    }

    public function test_gift_account_between_five_and_ten_thousand_is_counted_as_five_thousand(): void
    {
        self::assertSame(5000.0, AppLovinGiftConsumption::net(5000.0, true));
        self::assertSame(5000.0, AppLovinGiftConsumption::net(8654.34, true));
        self::assertSame(5000.0, AppLovinGiftConsumption::net(10000.0, true));
    }

    public function test_gift_account_over_ten_thousand_deducts_five_thousand(): void
    {
        self::assertSame(5000.01, AppLovinGiftConsumption::net(10000.01, true));
        self::assertSame(7500.0, AppLovinGiftConsumption::net(12500.0, true));
    }

    public function test_consumption_below_threshold_is_not_changed(): void
    {
        self::assertSame(4999.99, AppLovinGiftConsumption::net(4999.99, true));
        self::assertSame(0.0, AppLovinGiftConsumption::net(-1.0, true));
    }

    public function test_account_identity_ignores_historical_formatting_differences(): void
    {
        self::assertSame(
            AppLovinGiftConsumption::accountIdentity(65, '2078896419', 'first-key'),
            AppLovinGiftConsumption::accountIdentity(65, ' 207-889-6419 ', 'second-key'),
        );
    }

    public function test_account_identity_uses_exact_key_when_number_is_missing(): void
    {
        self::assertNotSame(
            AppLovinGiftConsumption::accountIdentity(65, '', 'first-key'),
            AppLovinGiftConsumption::accountIdentity(65, '', 'second-key'),
        );
    }
}
