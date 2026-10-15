<?php

namespace App\Portals\Logistics;

use App\Models\LogisticsOrder;
use App\Models\LogisticsPayPalAccount;

/**
 * Associates an ERP order only after a real PayPal transaction references its bill number.
 *
 * Merchant IDs and collection emails are account-level metadata; they must never be used to
 * infer that an order belongs to a PayPal account.
 */
class PayPalOrderAssociationService
{
    public function orderForTransaction(LogisticsPayPalAccount $account, ?string $transactionId): ?LogisticsOrder
    {
        $transactionId = trim((string) $transactionId);
        if ($transactionId === '') {
            return null;
        }

        $matches = LogisticsOrder::query()
            ->where('bill_number', $transactionId)
            ->limit(2)
            ->get();

        // A duplicate bill number is not safe to associate automatically.
        if ($matches->count() !== 1) {
            return null;
        }

        /** @var LogisticsOrder $order */
        $order = $matches->first();
        // A payment transaction cannot safely belong to two PayPal accounts.
        // Preserve an already confirmed association rather than silently
        // reassigning financial history when an incoming file is malformed.
        if ($order->paypal_account_id !== null
            && $order->paypal_association_source === 'actual_transaction'
            && (int) $order->paypal_account_id !== (int) $account->getKey()) {
            return null;
        }

        $order->forceFill([
            'paypal_account_id' => $account->getKey(),
            'paypal_association_source' => 'actual_transaction',
            'paypal_associated_at' => now(),
        ])->save();

        return $order;
    }
}
