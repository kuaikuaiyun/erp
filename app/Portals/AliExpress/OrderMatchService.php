<?php

namespace App\Portals\AliExpress;

use App\Models\AliExpressProcurementItem;
use App\Models\AliExpressSelectionSku;
use App\Models\LogisticsOrder;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Collection;

class OrderMatchService
{
    public function match(): ?int
    {
        $db = (new AliExpressProcurementItem())->getConnection();
        if (!$db->getSchemaBuilder()->hasTable('aliexpress_order_match_state')) {
            return null;
        }

        return $db->transaction(function () use ($db): ?int {
            $stateQuery = $db->table('aliexpress_order_match_state')->where('id', 1);
            $state = $stateQuery->lockForUpdate()->first();
            if (!$state || !$state->is_enabled) {
                return null;
            }

            $enabledAt = CarbonImmutable::parse($state->enabled_at, 'UTC');
            $previous = CarbonImmutable::parse($state->matched_through_at, 'UTC');
            $through = CarbonImmutable::now('UTC')->startOfSecond()->min($previous->addMinutes(15));
            if ($through->lt($previous)) {
                return 0;
            }

            // Replay the last two minutes to cover same-second updates and partially committed syncs.
            $from = $previous->subMinutes(2)->max($enabledAt);
            $count = 0;
            LogisticsOrder::query()
                ->select(['id', 'source_shop_domain', 'store_name', 'shopify_order_id', 'shopify_order_number', 'shopify_payload'])
                ->with('items:id,logistics_order_id,shopify_line_item_id,shopify_variant_id')
                ->whereNotNull('shopify_payload')
                ->whereBetween('updated_at', [$from, $through])
                ->chunkById(150, function (Collection $orders) use ($enabledAt, $through, &$count): void {
                    foreach ($orders as $order) {
                        $count += $this->matchOrder($order, $enabledAt, $through);
                    }
                });

            $stateQuery->update(['matched_through_at' => $through]);

            return $count;
        });
    }

    private function matchOrder(LogisticsOrder $order, CarbonImmutable $enabledAt, CarbonImmutable $now): int
    {
        $payload = json_decode((string) $order->shopify_payload, true, flags: JSON_BIGINT_AS_STRING);
        $lines = $payload['lineItems']['nodes'] ?? null;
        $createdAt = $this->sourceCreatedAt($payload['createdAt'] ?? null);
        $domain = strtolower(trim((string) $order->source_shop_domain));
        $orderId = $this->shopifyId($order->shopify_order_id, 'Order');
        if (!is_array($lines) || !$createdAt || $createdAt->lt($enabledAt) || $domain === '' || !$orderId) {
            return 0;
        }

        $items = $order->items->keyBy(fn ($item) => $this->shopifyId($item->shopify_line_item_id, 'LineItem'));
        $rows = [];
        $issue = $this->sourceIssue($payload);

        foreach ($lines as $line) {
            $sku = trim((string) ($line['sku'] ?? ''));
            $lineId = $this->shopifyId($line['id'] ?? null, 'LineItem');
            if (!str_starts_with($sku, AliExpressSelectionSku::PREFIX) || !$lineId || mb_strlen($sku) > 255) {
                continue;
            }

            $item = $items->get($lineId);
            $key = hash('sha256', implode('|', [$domain, $orderId, $lineId]));
            $quantity = max(0, (int) ($line['currentQuantity'] ?? $line['quantity'] ?? 0));
            $rows[$key] = [
                'source_key' => $key,
                'logistics_order_id' => $order->id,
                'logistics_order_item_id' => $item?->id,
                'shop_domain' => $domain,
                'store_name' => $order->store_name,
                'shopify_order_id' => $orderId,
                'shopify_order_number' => $order->shopify_order_number,
                'shopify_line_item_id' => $lineId,
                'shopify_variant_id' => $this->shopifyId($line['variant']['id'] ?? $item?->shopify_variant_id, 'ProductVariant'),
                'sku' => $sku,
                'sku_key' => AliExpressSelectionSku::keyFor($sku),
                'product_title' => mb_substr((string) ($line['title'] ?? $line['variant']['product']['title'] ?? ''), 0, 255) ?: null,
                'quantity' => $quantity,
                'source_issue' => $issue ?? ($quantity === 0 ? 'line_changed' : null),
                'order_created_at' => $createdAt,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows !== []) {
            AliExpressProcurementItem::query()->upsert(array_values($rows), ['source_key'], [
                'logistics_order_id', 'logistics_order_item_id', 'store_name', 'shopify_order_number', 'shopify_variant_id',
                'sku', 'sku_key', 'product_title', 'quantity', 'source_issue', 'order_created_at', 'updated_at',
            ]);
        }

        // The existing Shopify query caps line items at 250. An incomplete snapshot cannot establish removal.
        if (count($lines) < 250) {
            AliExpressProcurementItem::query()
                ->where('logistics_order_id', $order->id)
                ->whereNotIn('source_key', array_keys($rows))
                ->update(['source_issue' => 'line_changed']);
        }

        return count($rows);
    }

    private function sourceCreatedAt(mixed $value): ?CarbonImmutable
    {
        if (!is_string($value) || !preg_match('/\A\d{4}-\d{2}-\d{2}[T ]/', $value)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, 'UTC')->utc();
        } catch (InvalidFormatException) {
            return null;
        }
    }

    private function sourceIssue(array $payload): ?string
    {
        if (!empty($payload['cancelledAt']) || !empty($payload['cancelled_at'])) {
            return 'order_cancelled';
        }

        $payment = strtoupper((string) ($payload['displayFinancialStatus'] ?? ''));
        if (in_array($payment, ['REFUNDED', 'PARTIALLY_REFUNDED'], true)) {
            return 'order_refunded';
        }
        if ($payment !== 'PAID') {
            return 'payment_unconfirmed';
        }

        return match (strtoupper((string) ($payload['displayFulfillmentStatus'] ?? ''))) {
            'FULFILLED' => 'order_fulfilled',
            'PARTIAL', 'PARTIALLY_FULFILLED' => 'order_partial_fulfilled',
            default => null,
        };
    }

    private function shopifyId(mixed $value, string $resource): ?string
    {
        $id = is_string($value) || is_int($value) ? (string) $value : '';
        $prefix = 'gid://shopify/'.$resource.'/';
        if (str_starts_with($id, $prefix)) {
            $id = substr($id, strlen($prefix));
        }

        return $id !== '' && ctype_digit($id) && strlen($id) <= 100 ? $id : null;
    }
}
