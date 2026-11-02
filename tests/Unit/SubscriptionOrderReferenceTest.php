<?php

namespace Tests\Unit;

use App\Portals\Logistics\SubscriptionOrderReference;
use PHPUnit\Framework\TestCase;

class SubscriptionOrderReferenceTest extends TestCase
{
    public function test_real_collector_attributes_return_explicit_origin_and_recurring_number(): void
    {
        $attributes = [
            'yx_order_id' => 'SUB-126304-1-20261022131056',
            'yx_origin_order_id' => '3149304296037665587219',
        ];

        $this->assertSame('3149304296037665587219', SubscriptionOrderReference::originOrderId($attributes));
        $this->assertSame(1, SubscriptionOrderReference::recurringNumber($attributes));
    }

    public function test_explicit_origin_has_priority_over_legacy_aliases(): void
    {
        $attributes = [
            'yx_origin_order_id' => 'new-origin',
            'subscription_origin_order_id' => 'legacy-origin',
            'yx_subscription_origin_order_id' => 'older-origin',
        ];

        $this->assertSame('new-origin', SubscriptionOrderReference::originOrderId($attributes));
    }

    public function test_unrecognised_order_id_does_not_guess_a_recurring_number(): void
    {
        $this->assertNull(SubscriptionOrderReference::recurringNumber([
            'yx_order_id' => 'SUB-126304-unknown',
        ]));
    }
}
