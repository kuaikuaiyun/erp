<?php

namespace App\Portals\Logistics;

use App\Models\LogisticsOrder;
use App\Models\LogisticsPayPalAccount;
use App\Models\LogisticsPayPalDispute;
use App\Models\LogisticsPayPalRefund;
use App\Models\LogisticsPayPalTransaction;

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
        if (!$this->associateOrderToAccount($order, $account)) {
            return null;
        }

        return $order;
    }

    /**
     * Reconcile a raw payment immediately after it is received. The raw row
     * remains in the ledger even when the Shopify order does not exist yet.
     */
    public function reconcileTransaction(LogisticsPayPalTransaction $transaction): ?LogisticsOrder
    {
        $account = $transaction->relationLoaded('account')
            ? $transaction->account
            : LogisticsPayPalAccount::query()->find($transaction->logistics_paypal_account_id);

        if (!$account instanceof LogisticsPayPalAccount) {
            return null;
        }

        $order = $this->orderForTransaction($account, $transaction->payment_transaction_id);
        if (!$order) {
            return null;
        }

        $transaction->forceFill([
            'logistics_order_id' => $order->getKey(),
            'match_source' => 'bill_number',
            'matched_at' => now(),
        ])->save();

        $this->attachRelatedRiskRows($account, $order, $transaction->payment_transaction_id);

        return $order;
    }

    /**
     * Reverse reconciliation for Shopify-later imports. Only an exact and
     * unique bill number is linked; ambiguous data stays unmatched.
     */
    public function reconcileOrder(LogisticsOrder $order): int
    {
        $billNumber = trim((string) $order->bill_number);
        if ($billNumber === '') {
            return 0;
        }

        // Keep the same safety rule as payment-first matching: a duplicated
        // ERP bill number must never be reverse-associated automatically.
        $orderMatches = LogisticsOrder::query()
            ->where('bill_number', $billNumber)
            ->limit(2)
            ->get(['id']);
        if ($orderMatches->count() !== 1 || (int) $orderMatches->first()->getKey() !== (int) $order->getKey()) {
            return 0;
        }

        $transactions = LogisticsPayPalTransaction::query()
            ->with('account')
            ->where('payment_transaction_id', $billNumber)
            ->get();
        $matched = 0;

        foreach ($transactions as $transaction) {
            $account = $transaction->account;
            if (!$account instanceof LogisticsPayPalAccount || !$this->associateOrderToAccount($order, $account)) {
                continue;
            }

            $transaction->forceFill([
                'logistics_order_id' => $order->getKey(),
                'match_source' => 'bill_number',
                'matched_at' => now(),
            ])->save();
            $this->attachRelatedRiskRows($account, $order, $billNumber);
            $matched++;
        }

        // Refunds and disputes can reach ERP before their normal payment event.
        $candidateAccounts = collect()
            ->merge($transactions->pluck('logistics_paypal_account_id'))
            ->merge(LogisticsPayPalRefund::query()->where('original_transaction_id', $billNumber)->pluck('logistics_paypal_account_id'))
            ->merge(LogisticsPayPalDispute::query()->where('disputed_transaction_id', $billNumber)->pluck('logistics_paypal_account_id'))
            ->filter()
            ->unique()
            ->values();

        foreach ($candidateAccounts as $accountId) {
            $account = LogisticsPayPalAccount::query()->find($accountId);
            if (!$account instanceof LogisticsPayPalAccount || !$this->associateOrderToAccount($order, $account)) {
                continue;
            }
            $this->attachRelatedRiskRows($account, $order, $billNumber);
        }

        return $matched;
    }

    private function associateOrderToAccount(LogisticsOrder $order, LogisticsPayPalAccount $account): bool
    {
        if ($order->paypal_account_id !== null
            && $order->paypal_association_source === 'actual_transaction'
            && (int) $order->paypal_account_id !== (int) $account->getKey()) {
            return false;
        }

        if ((int) $order->paypal_account_id !== (int) $account->getKey()
            || $order->paypal_association_source !== 'actual_transaction') {
            $order->forceFill([
                'paypal_account_id' => $account->getKey(),
                'paypal_association_source' => 'actual_transaction',
                'paypal_associated_at' => now(),
            ])->save();
        }

        return true;
    }

    private function attachRelatedRiskRows(
        LogisticsPayPalAccount $account,
        LogisticsOrder $order,
        string $paymentTransactionId,
    ): void {
        LogisticsPayPalRefund::query()
            ->where('logistics_paypal_account_id', $account->getKey())
            ->where('original_transaction_id', $paymentTransactionId)
            ->update(['logistics_order_id' => $order->getKey(), 'updated_at' => now()]);

        LogisticsPayPalDispute::query()
            ->where('logistics_paypal_account_id', $account->getKey())
            ->where('disputed_transaction_id', $paymentTransactionId)
            ->update(['logistics_order_id' => $order->getKey(), 'updated_at' => now()]);
    }
}
