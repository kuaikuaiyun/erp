<?php

namespace App\Portals\Finance;

class FinanceReportTimezones
{
    public static function groups(string $channel, array $paymentChannels = []): array
    {
        $groups = $channel === 'paypal'
            ? ['-04:00' => ['paypal']]
            : ['-04:00' => ['xuzong1', 'xuzong2'], 'Asia/Shanghai' => ['ye', 'worldpay']];
        foreach ($groups as $zone => $platforms) {
            $groups[$zone] = $paymentChannels === [] ? $platforms : array_values(array_intersect($platforms, $paymentChannels));
        }

        return array_filter($groups);
    }
}
