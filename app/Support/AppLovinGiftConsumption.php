<?php

namespace App\Support;

final class AppLovinGiftConsumption
{
    public const GIFT_THRESHOLD = 5000.0;

    public const SECOND_THRESHOLD = 10000.0;

    public static function accountIdentity(int $operatorId, string $accountNumber, string $fallbackKey = ''): string
    {
        $normalizedNumber = preg_replace('/[^0-9a-z]+/i', '', mb_strtolower(trim($accountNumber))) ?? '';

        return $normalizedNumber !== ''
            ? $operatorId.'|'.$normalizedNumber
            : $operatorId.'|key:'.$fallbackKey;
    }

    public static function net(float $historicalConsumption, bool $hasGift): float
    {
        $historicalConsumption = max(0.0, $historicalConsumption);

        if (! $hasGift || $historicalConsumption < self::GIFT_THRESHOLD) {
            return $historicalConsumption;
        }

        if ($historicalConsumption <= self::SECOND_THRESHOLD) {
            return self::GIFT_THRESHOLD;
        }

        return $historicalConsumption - self::GIFT_THRESHOLD;
    }
}
