<?php

namespace Tests\Unit;

use App\Portals\Payment\PaymentSubscriptionLifecycle;
use PHPUnit\Framework\TestCase;

class PaymentSubscriptionLifecycleTest extends TestCase
{
    public function test_it_classifies_one_time_and_first_charge_orders(): void
    {
        $seen = [];

        $this->assertSame('one_time', PaymentSubscriptionLifecycle::classify('无订阅', null, $seen)['key']);
        $this->assertSame('first_charge', PaymentSubscriptionLifecycle::classify('订阅首次购买', null, $seen)['key']);
    }

    public function test_it_builds_unique_non_empty_chain_references(): void
    {
        $this->assertSame(
            ['origin-1', 'shopify-1'],
            PaymentSubscriptionLifecycle::chainReferences('origin-1', '', null, 'shopify-1', 'origin-1'),
        );
    }

    public function test_it_uses_explicit_renewal_number(): void
    {
        $seen = [];
        $stage = PaymentSubscriptionLifecycle::classify('订阅复购4次', 'origin-1', $seen);

        $this->assertSame('renewal_4', $stage['key']);
        $this->assertSame(4, $stage['renewal_number']);
        $this->assertSame(5, $stage['sort']);
    }

    public function test_it_falls_back_to_sequence_within_each_origin_chain(): void
    {
        $seen = [];

        $this->assertSame('renewal_1', PaymentSubscriptionLifecycle::classify('订阅复购', 'origin-a', $seen)['key']);
        $this->assertSame('renewal_2', PaymentSubscriptionLifecycle::classify('订阅复购', 'origin-a', $seen)['key']);
        $this->assertSame('renewal_1', PaymentSubscriptionLifecycle::classify('订阅复购', 'origin-b', $seen)['key']);
    }
}
