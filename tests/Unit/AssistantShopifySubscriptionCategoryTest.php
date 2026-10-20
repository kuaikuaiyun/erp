<?php

namespace Tests\Unit;

use App\Models\AssistantShopifyStoreOrder;
use PHPUnit\Framework\TestCase;

class AssistantShopifySubscriptionCategoryTest extends TestCase
{
    public function test_recurring_tag_has_highest_priority(): void
    {
        $category = AssistantShopifyStoreOrder::subscriptionCategoryForTags([
            'Subscription',
            'Subscription First Order',
            'OuyiCart Subscription Recurring Order',
        ]);

        $this->assertSame(AssistantShopifyStoreOrder::SUBSCRIPTION_CATEGORY_RECURRING, $category);
    }

    public function test_subscription_tags_are_matched_exactly_and_case_insensitively(): void
    {
        $this->assertSame(
            AssistantShopifyStoreOrder::SUBSCRIPTION_CATEGORY_SUBSCRIBED,
            AssistantShopifyStoreOrder::subscriptionCategoryForTags([' subscription first order ']),
        );
        $this->assertSame(
            AssistantShopifyStoreOrder::SUBSCRIPTION_CATEGORY_SUBSCRIBED,
            AssistantShopifyStoreOrder::subscriptionCategoryForTags(['SUBSCRIPTION']),
        );
        $this->assertSame(
            AssistantShopifyStoreOrder::SUBSCRIPTION_CATEGORY_REGULAR,
            AssistantShopifyStoreOrder::subscriptionCategoryForTags(['Subscription First Order Extra']),
        );
    }

    public function test_orders_without_subscription_tags_are_regular(): void
    {
        $this->assertSame(
            AssistantShopifyStoreOrder::SUBSCRIPTION_CATEGORY_REGULAR,
            AssistantShopifyStoreOrder::subscriptionCategoryForTags(['One Page Checkout', 'PayPal']),
        );
    }
}
