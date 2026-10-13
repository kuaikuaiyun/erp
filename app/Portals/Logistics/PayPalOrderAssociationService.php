<?php

namespace App\Portals\Logistics;

use App\Models\LogisticsOrder;
use App\Models\LogisticsPayPalAccount;

class PayPalOrderAssociationService
{
    public function associateOrder(LogisticsOrder $order): ?LogisticsPayPalAccount
    {
        $merchantId = $this->normaliseMerchantId($order->paypal_merchant_id);
        $email = $this->normaliseEmail($order->collection_account_email);
        $account = null;

        if ($merchantId !== '') {
            $account = LogisticsPayPalAccount::query()
                ->whereRaw('UPPER(merchant_id) = ?', [$merchantId])
                ->first();
        }

        if (!$account && $email !== '') {
            $account = LogisticsPayPalAccount::query()
                ->whereRaw('LOWER(account_email) = ?', [$email])
                ->first();
        }

        $accountId = $account?->getKey();
        if ((int) ($order->paypal_account_id ?? 0) !== (int) ($accountId ?? 0)) {
            $order->forceFill(['paypal_account_id' => $accountId])->save();
        }

        return $account;
    }

    public function associateExistingOrders(LogisticsPayPalAccount $account): int
    {
        $accountId = $account->getKey();
        $merchantId = $this->normaliseMerchantId($account->merchant_id);
        $email = $this->normaliseEmail($account->account_email);

        if ($merchantId !== '' || $email !== '') {
            LogisticsOrder::query()
                ->whereNull('paypal_account_id')
                ->where(function ($matches) use ($merchantId, $email): void {
                    if ($merchantId !== '') {
                        $matches->whereRaw('UPPER(paypal_merchant_id) = ?', [$merchantId]);
                    }
                    if ($email !== '') {
                        $method = $merchantId !== '' ? 'orWhereRaw' : 'whereRaw';
                        $matches->{$method}('LOWER(collection_account_email) = ?', [$email]);
                    }
                })
                ->update([
                    'paypal_account_id' => $accountId,
                    'updated_at' => now(),
                ]);
        }

        return LogisticsOrder::query()
            ->where('paypal_account_id', $accountId)
            ->count();
    }

    public function orderForTransaction(LogisticsPayPalAccount $account, ?string $transactionId): ?LogisticsOrder
    {
        $transactionId = trim((string) $transactionId);
        if ($transactionId === '') {
            return null;
        }

        $order = LogisticsOrder::query()
            ->where('bill_number', $transactionId)
            ->first();

        if (!$order) {
            return null;
        }

        if (!$order->paypal_account_id) {
            $order->forceFill(['paypal_account_id' => $account->getKey()])->save();
        }

        return (int) $order->paypal_account_id === (int) $account->getKey() ? $order : null;
    }

    private function normaliseMerchantId(mixed $value): string
    {
        return mb_strtoupper(trim((string) ($value ?? '')));
    }

    private function normaliseEmail(mixed $value): string
    {
        return mb_strtolower(trim((string) ($value ?? '')));
    }
}
