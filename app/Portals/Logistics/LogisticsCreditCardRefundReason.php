<?php

namespace App\Portals\Logistics;

final class LogisticsCreditCardRefundReason
{
    /** @var list<string> */
    public const ALERT = ['Ethoca', 'CDRN', 'RDR'];

    /** @var list<string> */
    public const CUSTOMER_SERVICE = [
        '取消复购订单',
        '取消加购',
        '取消订单',
        '质量问题',
        '地址无法发货',
        '描述不符',
        '商品无法发货',
    ];

    /** @var list<string> */
    public const DISPUTE = [
        '争议（欺诈）',
        '争议（重复交易）',
        '争议（未收到）',
        '争议（商品或服务质量）',
        '争议（其他）',
    ];

    /** @return list<string> */
    public static function allowed(): array
    {
        return [...self::ALERT, ...self::CUSTOMER_SERVICE, ...self::DISPUTE];
    }

    public static function canonical(mixed $reason): ?string
    {
        $value = self::normalize($reason);
        if ($value === '') {
            return null;
        }

        foreach (self::allowed() as $allowed) {
            if ($value === self::normalize($allowed)) {
                return $allowed;
            }
        }

        return null;
    }

    public static function normalize(mixed $reason): string
    {
        $value = mb_strtolower(trim((string) $reason));
        $value = str_replace(['（', '）', '(', ')'], '', $value);

        return preg_replace('/\s+/u', '', $value) ?? $value;
    }
}
