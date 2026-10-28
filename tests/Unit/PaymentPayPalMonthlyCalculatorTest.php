<?php

namespace Tests\Unit;

use App\Portals\Payment\PaymentPayPalMonthlyCalculator;
use PHPUnit\Framework\TestCase;

class PaymentPayPalMonthlyCalculatorTest extends TestCase
{
    public function test_it_calculates_monthly_rates_and_actual_payout_without_processing_fees(): void
    {
        $result = (new PaymentPayPalMonthlyCalculator())->calculate([
            'payment_order_count' => 100,
            'received_amount' => 10000,
            'refund_order_count' => 4,
            'refund_amount' => 400,
            'dispute_order_count' => 3,
            'dispute_amount' => 300,
            'dispute_fee_amount' => 24,
            'chargeback_fee_amount' => 10,
            'realised_dispute_loss' => 250,
        ], true);

        self::assertSame(4.0, $result['refund_amount_rate']);
        self::assertSame(3.0, $result['dispute_order_rate']);
        self::assertSame(3.0, $result['dispute_amount_rate']);
        self::assertSame(0.24, $result['dispute_fee_rate']);
        self::assertSame(9316.0, $result['actual_payout_amount']);
        self::assertSame(93.16, $result['actual_payout_rate']);
    }

    public function test_payment_time_dimension_does_not_claim_an_actual_payout(): void
    {
        $result = (new PaymentPayPalMonthlyCalculator())->calculate([
            'payment_order_count' => 1,
            'received_amount' => 100,
            'refund_amount' => 10,
            'dispute_fee_amount' => 8,
        ], false);

        self::assertNull($result['actual_payout_amount']);
        self::assertNull($result['actual_payout_rate']);
    }

    public function test_signed_credits_reduce_net_loss_instead_of_being_charged_again(): void
    {
        $result = (new PaymentPayPalMonthlyCalculator())->calculate([
            'received_amount' => 100,
            'refund_amount' => 0,
            'dispute_fee_amount' => -8,
            'realised_dispute_loss' => -20,
        ], true);

        self::assertSame(128.0, $result['actual_payout_amount']);
        self::assertSame(128.0, $result['actual_payout_rate']);
    }
}
