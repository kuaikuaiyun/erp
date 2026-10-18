<?php

namespace App\Portals\Logistics;

use App\Models\LogisticsOrder;
use App\Models\LogisticsOrderPaymentReference;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class LogisticsOrderPaymentReferenceService
{
    /**
     * Store every payment transaction reference carried by a Shopify order.
     * Existing references are intentionally retained: a later Shopify payload
     * must not make a previously observed payment reference unsearchable.
     *
     * @param array<string, string> $attributes
     */
    public function syncFromAttributes(LogisticsOrder $order, array $attributes): int
    {
        $references = [];

        $this->addReference(
            $references,
            $attributes['yx_payment_gateway_txn_id'] ?? $order->bill_number,
            LogisticsOrderPaymentReference::TYPE_PRIMARY,
            $attributes['yx_payment_gateway_order_id'] ?? $order->payment_gateway_order_id,
        );

        // The primary bill number may be present in historical ERP data even
        // if old Shopify payloads no longer expose the custom attribute.
        $this->addReference(
            $references,
            $order->bill_number,
            LogisticsOrderPaymentReference::TYPE_PRIMARY,
            $order->payment_gateway_order_id,
        );

        foreach ($this->upsellReferences($attributes['yx_payment_upsell'] ?? null) as $upsell) {
            $this->addReference(
                $references,
                $upsell['reference_number'],
                LogisticsOrderPaymentReference::TYPE_UPSELL,
                $upsell['gateway_order_id'],
            );
        }

        foreach ($references as $reference) {
            /** @var LogisticsOrderPaymentReference $record */
            $record = LogisticsOrderPaymentReference::query()->firstOrNew([
                'logistics_order_id' => $order->getKey(),
                'reference_number' => $reference['reference_number'],
            ]);

            // A primary reference keeps its type if a duplicate occurs in an
            // upsell payload. This produces a deterministic display order.
            if (!$record->exists || $reference['reference_type'] === LogisticsOrderPaymentReference::TYPE_PRIMARY) {
                $record->reference_type = $reference['reference_type'];
            }
            if (blank($record->gateway_order_id) && filled($reference['gateway_order_id'])) {
                $record->gateway_order_id = $reference['gateway_order_id'];
            }
            $record->save();
        }

        return count($references);
    }

    /** @param array<string, mixed> $payload */
    public function syncFromShopifyPayload(LogisticsOrder $order, array $payload): int
    {
        $attributes = [];
        foreach ((array) ($payload['customAttributes'] ?? []) as $attribute) {
            if (!is_array($attribute)) {
                continue;
            }

            $key = trim((string) ($attribute['key'] ?? ''));
            if ($key !== '') {
                $attributes[$key] = (string) ($attribute['value'] ?? '');
            }
        }

        return $this->syncFromAttributes($order, $attributes);
    }

    /**
     * Return an order only when the reference maps to exactly one ERP order.
     * Ambiguous values are never auto-linked.
     */
    public function uniqueOrderForReference(?string $referenceNumber): ?LogisticsOrder
    {
        $matches = $this->ordersForReference($referenceNumber);

        return $matches->count() === 1 ? $matches->first() : null;
    }

    /**
     * Whether a reference is present on more than one ERP order.
     * Such a value must be corrected manually before it can be used to
     * associate a financial record.
     */
    public function referenceIsAmbiguous(?string $referenceNumber): bool
    {
        return $this->ordersForReference($referenceNumber)->count() > 1;
    }

    /** @return Collection<int, LogisticsOrderPaymentReference> */
    public function referencesForOrder(LogisticsOrder $order): Collection
    {
        $references = $order->relationLoaded('paymentReferences')
            ? $order->paymentReferences
            : $order->paymentReferences()->get();

        $legacyPrimary = trim((string) $order->bill_number);
        if ($legacyPrimary !== '' && !$references->contains('reference_number', $legacyPrimary)) {
            $references->push(new LogisticsOrderPaymentReference([
                'logistics_order_id' => $order->getKey(),
                'reference_number' => $legacyPrimary,
                'reference_type' => LogisticsOrderPaymentReference::TYPE_PRIMARY,
                'gateway_order_id' => $order->payment_gateway_order_id,
            ]));
        }

        return $references
            ->sortBy(static fn (LogisticsOrderPaymentReference $reference): string => sprintf(
                '%d-%020d',
                $reference->reference_type === LogisticsOrderPaymentReference::TYPE_PRIMARY ? 0 : 1,
                (int) ($reference->id ?? 0),
            ))
            ->values();
    }

    /** @return Collection<int, LogisticsOrder> */
    private function ordersForReference(?string $referenceNumber): Collection
    {
        $referenceNumber = trim((string) $referenceNumber);
        if ($referenceNumber === '') {
            return collect();
        }

        return LogisticsOrder::query()
            ->where(function (Builder $query) use ($referenceNumber): void {
                $query->where('bill_number', $referenceNumber)
                    ->orWhereHas('paymentReferences', function (Builder $references) use ($referenceNumber): void {
                        $references->where('reference_number', $referenceNumber);
                    });
            })
            ->limit(2)
            ->get();
    }

    /** @return list<array{reference_number:string,gateway_order_id:?string}> */
    private function upsellReferences(?string $value): array
    {
        $value = trim((string) $value);
        if ($value === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            return [];
        }

        $items = array_key_exists('gateway_txn_id', $decoded) ? [$decoded] : $decoded;
        $references = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $reference = trim((string) ($item['gateway_txn_id'] ?? ''));
            if ($reference === '' || mb_strlen($reference) > 191) {
                continue;
            }

            $gatewayOrderId = trim((string) ($item['gateway_order_id'] ?? ''));
            $references[] = [
                'reference_number' => $reference,
                'gateway_order_id' => $gatewayOrderId !== '' && mb_strlen($gatewayOrderId) <= 191 ? $gatewayOrderId : null,
            ];
        }

        return $references;
    }

    /**
     * @param array<string, array{reference_number:string,reference_type:string,gateway_order_id:?string}> $references
     */
    private function addReference(array &$references, mixed $referenceNumber, string $type, mixed $gatewayOrderId): void
    {
        $referenceNumber = trim((string) $referenceNumber);
        if ($referenceNumber === '' || mb_strlen($referenceNumber) > 191) {
            return;
        }

        $gatewayOrderId = trim((string) $gatewayOrderId);
        $references[$referenceNumber] = [
            'reference_number' => $referenceNumber,
            'reference_type' => $type,
            'gateway_order_id' => $gatewayOrderId !== '' && mb_strlen($gatewayOrderId) <= 191 ? $gatewayOrderId : null,
        ];
    }
}
