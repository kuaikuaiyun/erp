<?php

namespace App\Portals\Finance;

use Illuminate\Support\Arr;

class FinancePayPalSourceEdits extends FinanceSourceEdits
{
    public static function transactionInfo(array $info, array $payloadEdits): array
    {
        $headers = FinanceCatalog::dataset('paypal-orders')['headers'];
        foreach ([5 => 'transaction_status', 6 => 'transaction_amount.currency_code', 7 => 'transaction_amount.value',
            8 => 'fee_amount.value', 12 => 'paypal_reference_id', 13 => 'invoice_id'] as $index => $path) {
            if (array_key_exists($headers[$index], $payloadEdits)) Arr::set($info, $path, $payloadEdits[$headers[$index]]);
        }
        return $info;
    }
}
