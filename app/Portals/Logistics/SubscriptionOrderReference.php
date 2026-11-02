<?php

namespace App\Portals\Logistics;

final class SubscriptionOrderReference
{
    /** @param array<string,string> $attributes */
    public static function originOrderId(array $attributes): ?string
    {
        foreach (['yx_origin_order_id', 'subscription_origin_order_id', 'yx_subscription_origin_order_id'] as $key) {
            $value = trim((string) ($attributes[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /** @param array<string,string> $attributes */
    public static function recurringNumber(array $attributes): ?int
    {
        $orderId = trim((string) ($attributes['yx_order_id'] ?? ''));
        if ($orderId === '' || preg_match('/^SUB-.+-(\d+)-\d{14}$/i', $orderId, $matches) !== 1) {
            return null;
        }

        $number = (int) $matches[1];

        return $number > 0 ? $number : null;
    }
}
