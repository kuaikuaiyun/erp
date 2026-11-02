<?php

namespace App\Portals\Payment;

final class PaymentSubscriptionLifecycle
{
    /** @return list<string> */
    public static function chainReferences(mixed ...$values): array
    {
        $references = [];
        foreach ($values as $value) {
            $reference = trim((string) $value);
            if ($reference !== '') {
                $references[$reference] = true;
            }
        }

        return array_keys($references);
    }

    /**
     * @param array<string,int> $seenRenewals
     * @return array{key:string,renewal_number:?int,sort:int}
     */
    public static function classify(?string $type, ?string $originOrderId, array &$seenRenewals): array
    {
        $type = trim((string) $type);
        $originOrderId = trim((string) $originOrderId);

        if ($originOrderId === '') {
            if ($type === '' || str_contains($type, '无订阅')) {
                return ['key' => 'one_time', 'renewal_number' => null, 'sort' => 0];
            }

            return ['key' => 'first_charge', 'renewal_number' => null, 'sort' => 1];
        }

        $seenRenewals[$originOrderId] = ($seenRenewals[$originOrderId] ?? 0) + 1;
        preg_match('/订阅复购\s*(\d+)\s*次/u', $type, $matches);
        if (! isset($matches[1])) {
            preg_match('/(\d+)/u', $type, $matches);
        }

        $renewalNumber = isset($matches[1]) && (int) $matches[1] > 0
            ? (int) $matches[1]
            : $seenRenewals[$originOrderId];

        return [
            'key' => 'renewal_'.$renewalNumber,
            'renewal_number' => $renewalNumber,
            'sort' => $renewalNumber + 1,
        ];
    }
}
