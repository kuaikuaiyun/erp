<?php

namespace App\Services\FinanceSync;

use App\Portals\Logistics\ShopifyOrderSyncService;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;

final class FinanceSyncOrderFields
{
    public const ORDER_FIELDS = ['shop_order_currency' => null, 'shop_order_amount' => null, 'merchant_order_number' => null];

    public function __construct(private FinanceSyncCatalog $catalog) {}

    /**
     * Compute the order and transaction views together so that a successful
     * transaction is associated with at most one payment reference.
     */
    public function forOrders(Connection $db, Collection $orders, Collection $references): array
    {
        $accountIds = $orders->pluck('paypal_account_id')->filter()->unique()->values();
        $paypal = $accountIds->isEmpty() ? collect() : $db->table(FinanceSyncCatalog::PAYPAL_LEDGER)
            ->whereIn('logistics_paypal_account_id', $accountIds)
            ->whereIn('logistics_order_id', $orders->pluck('id'))
            ->get(['logistics_order_id', 'logistics_paypal_account_id', 'payment_transaction_id', 'status',
                'original_amount', 'currency_code'])
            ->groupBy('logistics_order_id');
        $references = $references->groupBy('logistics_order_id');

        $orderFields = [];
        $referenceFields = [];
        foreach ($orders as $order) {
            $payload = json_decode((string) $order->shopify_payload, true);
            $payload = is_array($payload) ? $payload : [];
            $attributes = $this->attributes($payload);
            $orderReferences = $references->get($order->id, collect());
            $transactions = data_get($payload, 'transactions.nodes') ?? ($payload['transactions'] ?? []);
            $transactions = is_array($transactions) ? array_filter($transactions, 'is_array') : [];
            $payments = $this->payments($order, $transactions, $paypal->get($order->id, collect()));
            $expectedReferences = $this->expectedReferences($order, $orderReferences, $attributes);

            foreach ($orderReferences as $reference) {
                $money = $payments[(string) $reference->reference_number] ?? FinanceSyncCatalog::AMOUNT;
                if ($money === FinanceSyncCatalog::AMOUNT && $reference->reference_type === 'primary'
                    && $expectedReferences->count() === 1 && count($payments) === 1
                    && array_key_first($payments) !== (string) $reference->reference_number
                    && str_starts_with((string) array_key_first($payments), 'gid://shopify/OrderTransaction/')) {
                    $money = reset($payments);
                }
                $referenceFields[$reference->id] = array_intersect_key($money, FinanceSyncCatalog::AMOUNT);
            }

            // Preserve the order-level pair for a single payment. For multi-payment
            // orders the values belong to their individual reference rows.
            $orderMoney = FinanceSyncCatalog::AMOUNT;
            if ($expectedReferences->count() <= 1 && count($payments) === 1) {
                $paymentId = array_key_first($payments);
                if ($expectedReferences->isEmpty() || $expectedReferences->containsStrict((string) $paymentId)
                    || str_starts_with((string) $paymentId, 'gid://shopify/OrderTransaction/')) {
                    $orderMoney = reset($payments);
                }
            }

            $shopMoney = $this->shopMoney($payload);
            $firstPayment = ShopifyOrderSyncService::successfulPayment($transactions);
            $orderFields[$order->id] = [
                'shop_order_currency' => $shopMoney['payment_original_currency'],
                'shop_order_amount' => $shopMoney['payment_original_amount'],
                'merchant_order_number' => $attributes['yx_order_id'] ?? null,
                ...array_intersect_key($orderMoney, FinanceSyncCatalog::AMOUNT),
                'paid_amount_usd' => $this->paidUsd($payments, $expectedReferences, $orderMoney),
                'order_created_at' => $this->shopifyDate($order->order_created_at, $payload['createdAt'] ?? null),
                'paid_at' => $this->shopifyDate($order->paid_at, $firstPayment['processedAt'] ?? $payload['processedAt'] ?? null),
            ];
        }

        return [$orderFields, $referenceFields];
    }

    private function attributes(array $payload): array
    {
        $result = [];
        foreach (data_get($payload, 'customAttributes', []) ?? [] as $attribute) {
            $key = $attribute['key'] ?? null;
            $value = $attribute['value'] ?? null;
            if (is_string($key) && is_scalar($value) && trim((string) $value) !== '') {
                $result[$key] = trim((string) $value);
            }
        }

        return $result;
    }

    private function expectedReferences(object $order, Collection $references, array $attributes): Collection
    {
        $upsells = json_decode($attributes['yx_payment_upsell'] ?? '', true);
        $upsells = ! is_array($upsells) ? [] : (isset($upsells['gateway_txn_id']) ? [$upsells] : $upsells);

        return $references->pluck('reference_number')
            ->merge([$order->bill_number, $attributes['yx_payment_gateway_txn_id'] ?? null])
            ->merge(array_column($upsells, 'gateway_txn_id'))
            ->filter(fn ($value) => is_scalar($value) && trim((string) $value) !== '')
            ->map(fn ($value) => trim((string) $value))
            ->uniqueStrict()
            ->values();
    }

    private function payments(object $order, array $transactions, Collection $ledger): array
    {
        $payments = [];
        foreach ($ledger as $row) {
            if ((string) $row->logistics_paypal_account_id === (string) $order->paypal_account_id
                && in_array(strtoupper((string) $row->status), ['S', 'SUCCESS', 'COMPLETED', 'PAID'], true)) {
                $money = $this->money($row->currency_code, $row->original_amount);
                $payments[(string) $row->payment_transaction_id] = $money + [
                    'usd' => $money['payment_original_currency'] === 'USD' ? $money['payment_original_amount'] : null,
                ];
            }
        }

        // A matching PayPal ledger is the payment source on PayPal orders.
        // Shopify may represent the same transaction with a different ID.
        if ($payments !== []) {
            return $payments;
        }

        foreach ($transactions as $transaction) {
            $id = $transaction['id'] ?? null;
            if (! is_scalar($id)
                || ! in_array(strtoupper((string) ($transaction['kind'] ?? '')), ['SALE', 'CAPTURE'], true)
                || strtoupper((string) ($transaction['status'] ?? '')) !== 'SUCCESS') {
                continue;
            }

            $presentment = data_get($transaction, 'amountSet.presentmentMoney');
            $shopMoney = data_get($transaction, 'amountSet.shopMoney');
            $payments[(string) $id] = $this->money(
                $presentment['currencyCode'] ?? $transaction['currency'] ?? null,
                $presentment['amount'] ?? $transaction['amount'] ?? null,
            ) + ['usd' => $this->catalog->currency($shopMoney['currencyCode'] ?? null) === 'USD'
                ? $this->catalog->decimal($shopMoney['amount'] ?? null) : null];
        }

        return $payments;
    }

    private function shopMoney(array $payload): array
    {
        // The webhook fallback currently synthesizes a USD shopMoney value
        // while representing fulfillments as nodes. Its currency is not evidence.
        if (is_array(data_get($payload, 'fulfillments.nodes'))) {
            return FinanceSyncCatalog::AMOUNT;
        }

        $shopMoney = data_get($payload, 'currentTotalPriceSet.shopMoney');

        return $this->money($shopMoney['currencyCode'] ?? null, $shopMoney['amount'] ?? null);
    }

    private function paidUsd(array $payments, Collection $expectedReferences, array $singlePayment): ?string
    {
        if (count($payments) === 1 && array_key_exists('usd', $singlePayment)) {
            return $singlePayment['usd'];
        }
        if ($payments === []
            || ($expectedReferences->isNotEmpty() && $expectedReferences->diff(array_keys($payments))->isNotEmpty())) {
            return null;
        }

        $total = '0';
        $scale = 0;
        foreach ($payments as $payment) {
            if ($payment['usd'] === null) {
                return null;
            }
            $decimalPoint = strpos($payment['usd'], '.');
            if ($decimalPoint !== false) {
                $scale = max($scale, strlen($payment['usd']) - $decimalPoint - 1);
            }
            $total = bcadd($total, $payment['usd'], $scale);
        }

        return $this->catalog->decimal($total);
    }

    private function money(mixed $currency, mixed $amount): array
    {
        $currency = $this->catalog->currency($currency);
        $amount = $this->catalog->decimal($amount);

        return $currency !== null && $amount !== null
            ? ['payment_original_currency' => $currency, 'payment_original_amount' => $amount]
            : FinanceSyncCatalog::AMOUNT;
    }

    private function shopifyDate(?string $stored, mixed $fromPayload): ?string
    {
        $fallback = $this->catalog->date($stored);
        if (! $stored || ! is_string($fromPayload) || ! preg_match('/(?:Z|[+-]\d{2}:\d{2})$/D', $fromPayload)) {
            return $fallback;
        }

        $utc = $this->catalog->date($fromPayload);
        $wallTime = str_replace('T', ' ', substr($fromPayload, 0, 19));
        $utcWallTime = str_replace('T', ' ', substr($utc ?? '', 0, 19));

        // Use the source offset when the stored time still corresponds to it.
        // A later ERP correction remains authoritative.
        return $utc !== null && in_array(substr($stored, 0, 19), [$wallTime, $utcWallTime], true) ? $utc : $fallback;
    }
}
