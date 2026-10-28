<?php

namespace Tests\Unit;

use App\Portals\Payment\PaymentProductAlertService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PaymentProductAlertServiceTest extends TestCase
{
    /** @return iterable<string,array{int,int,bool}> */
    public static function qualificationCases(): iterable
    {
        yield '没有有效订单' => [0, 0, false];
        yield '一笔订单无纠纷' => [1, 0, false];
        yield '一笔订单一笔纠纷' => [1, 1, true];
        yield '刚好百分之零点五' => [200, 1, true];
        yield '低于百分之零点五' => [201, 1, false];
    }

    #[DataProvider('qualificationCases')]
    public function test_fixed_product_alert_rule(int $eligibleOrders, int $disputeOrders, bool $expected): void
    {
        self::assertSame($expected, PaymentProductAlertService::qualifies($eligibleOrders, $disputeOrders));
    }

    public function test_dispute_rate_is_rounded_to_two_decimals(): void
    {
        self::assertSame(33.33, PaymentProductAlertService::disputeRate(3, 1));
    }
}
