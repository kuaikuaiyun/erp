<?php

namespace App\Portals\Logistics;

use App\Models\LogisticsOrder;
use App\Models\LogisticsOrderItem;
use Illuminate\Support\Facades\DB;

class LogisticsOrderItemService
{
    /**
     * Persist Shopify line items and split order-level amounts by each item's paid amount.
     * Tongtu fields are intentionally preserved here and are populated after matching.
     *
     * @param array<string, mixed> $payload
     */
    public function syncFromShopifyPayload(LogisticsOrder $order, array $payload): void
    {
        $nodes = $payload['lineItems']['nodes'] ?? [];
        if (!is_array($nodes) || $nodes === []) {
            return;
        }

        $rows = [];
        $weights = [];
        foreach ($nodes as $position => $node) {
            if (!is_array($node)) {
                continue;
            }

            $quantity = max(0.0, $this->number($node['quantity'] ?? null) ?? 0.0);
            $unitPrice = $this->money($node, 'discountedUnitPriceSet')
                ?? $this->money($node, 'originalUnitPriceSet')
                ?? $this->number($node['price'] ?? null);
            $lineAmount = $unitPrice === null ? null : $unitPrice * $quantity;
            $fallbackWeight = $quantity > 0 ? $quantity : 1.0;

            $row = [
                'source_key' => self::sourceKey($node, (int) $position),
                'shopify_line_item_id' => $this->id($node['id'] ?? null),
                'shopify_product_id' => $this->id($node['variant']['product']['id'] ?? $node['product']['id'] ?? null),
                'shopify_variant_id' => $this->id($node['variant']['id'] ?? null),
                'product_title' => trim((string) ($node['variant']['product']['title'] ?? $node['product']['title'] ?? $node['title'] ?? '未命名商品')),
                'variant_title' => $this->variantTitle($node),
                'product_image_url' => $this->imageUrl($node),
                'shopify_sku' => trim((string) ($node['sku'] ?? '')) ?: null,
                'quantity' => $quantity,
                'unit_price_usd' => $unitPrice,
                'line_amount' => $lineAmount,
                'weight' => $lineAmount !== null && $lineAmount > 0 ? $lineAmount : $fallbackWeight,
            ];
            $rows[] = $row;
            $weights[] = $row['weight'];
        }

        if ($rows === []) {
            return;
        }

        $revenues = $this->allocate($this->number($order->paid_amount_usd), $weights);
        $handlings = $this->allocate($this->number($order->handling_fee_usd), $weights);
        $shippings = $this->allocate($this->number($order->total_shipping_usd), $weights);
        $refunds = $this->allocate($this->number($order->proportional_refund_usd), $weights);
        $keys = array_column($rows, 'source_key');

        DB::connection($order->getConnectionName())->transaction(function () use ($order, $rows, $keys, $revenues, $handlings, $shippings, $refunds): void {
            foreach ($rows as $index => $row) {
                $item = LogisticsOrderItem::query()->firstOrNew([
                    'logistics_order_id' => $order->getKey(),
                    'source_key' => $row['source_key'],
                ]);
                $item->fill([
                    'shopify_line_item_id' => $row['shopify_line_item_id'],
                    'shopify_product_id' => $row['shopify_product_id'],
                    'shopify_variant_id' => $row['shopify_variant_id'],
                    'product_title' => $row['product_title'],
                    'variant_title' => $row['variant_title'],
                    'product_image_url' => $row['product_image_url'],
                    'shopify_sku' => $row['shopify_sku'],
                    'quantity' => $this->decimal($row['quantity']),
                    'unit_price_usd' => $this->decimal($row['unit_price_usd']),
                    'line_revenue_usd' => $this->decimal($revenues[$index]),
                    'handling_fee_usd' => $this->decimal($handlings[$index]),
                    'total_shipping_usd' => $this->decimal($shippings[$index]),
                    'proportional_refund_usd' => $this->decimal($refunds[$index]),
                ]);
                $this->recalculate($item);
                $item->save();
            }

            LogisticsOrderItem::query()
                ->where('logistics_order_id', $order->getKey())
                ->whereNotIn('source_key', $keys)
                ->delete();
        });
    }

    /**
     * @param list<array{source_key:string,tongtu_sku:?string,tongtu_product_code:?string,tongtu_product_name:?string,tongtu_product_type:?string,product_cost_usd:?float,sale_weight_gram:?float}> $rows
     */
    public function applyTongtuMatch(LogisticsOrder $order, array $rows): void
    {
        foreach ($rows as $row) {
            $item = LogisticsOrderItem::query()
                ->where('logistics_order_id', $order->getKey())
                ->where('source_key', $row['source_key'])
                ->first();
            if (!$item) {
                continue;
            }

            $item->fill([
                'tongtu_sku' => $row['tongtu_sku'],
                'tongtu_product_code' => $row['tongtu_product_code'],
                'tongtu_product_name' => $row['tongtu_product_name'],
                'tongtu_product_type' => $row['tongtu_product_type'],
                'product_cost_usd' => $this->decimal($row['product_cost_usd']),
                'sale_weight_gram' => $this->decimal($row['sale_weight_gram']),
            ]);
            $this->recalculate($item);
            $item->save();
        }
    }

    /** Build item rows from already persisted order payloads, without calling external APIs. */
    public function backfill(LogisticsOrder $order): int
    {
        $payload = json_decode((string) $order->shopify_payload, true);
        if (!is_array($payload)) {
            return 0;
        }

        $this->syncFromShopifyPayload($order, $payload);
        $items = $order->items()->orderBy('id')->get();
        if ($items->isEmpty()) {
            return 0;
        }

        $tongtuSkus = $this->lines($order->tongtu_sku);
        $tongtuProductCodes = $this->lines($order->tongtu_product_code);
        $productNames = $this->lines($order->tongtu_product_name);
        $weights = $this->lines($order->sale_product_weight);
        $costs = $this->allocate($this->number($order->product_cost_usd), $items->map(fn (LogisticsOrderItem $item): float => max(0.0, (float) $item->quantity))->all());

        foreach ($items->values() as $index => $item) {
            if ($item->tongtu_product_code !== null || $item->tongtu_sku !== null || $item->product_cost_usd !== null) {
                continue;
            }
            $item->fill([
                'tongtu_sku' => $this->meaningfulLine($tongtuSkus[$index] ?? null),
                'tongtu_product_code' => $this->meaningfulLine($tongtuProductCodes[$index] ?? null),
                'tongtu_product_name' => $this->meaningfulLine($productNames[$index] ?? null),
                'sale_weight_gram' => $this->number($weights[$index] ?? null),
                'product_cost_usd' => $this->decimal($costs[$index] ?? null),
            ]);
            $this->recalculate($item);
            $item->save();
        }

        return $items->count();
    }

    /** @param array<string, mixed> $item */
    public static function sourceKey(array $item, int $position): string
    {
        $id = trim((string) ($item['id'] ?? ''));
        if ($id !== '') {
            return 'shopify:'.$id;
        }

        return 'line:'.sha1(implode('|', [
            $position,
            trim((string) ($item['sku'] ?? '')),
            trim((string) ($item['title'] ?? '')),
            trim((string) ($item['quantity'] ?? '')),
        ]));
    }

    private function recalculate(LogisticsOrderItem $item): void
    {
        $revenue = $this->number($item->line_revenue_usd) ?? 0.0;
        $cost = $this->number($item->product_cost_usd);
        if ($cost === null) {
            $item->profit_usd = null;
            $item->profit_rate = null;

            return;
        }

        $profit = $revenue - $cost - ($this->number($item->handling_fee_usd) ?? 0.0)
            - ($this->number($item->total_shipping_usd) ?? 0.0)
            - ($this->number($item->proportional_refund_usd) ?? 0.0);
        $item->profit_usd = $this->decimal($profit);
        $item->profit_rate = $revenue > 0 ? $this->decimal($profit / $revenue * 100) : null;
    }

    /** @param list<float|int|null> $weights
     * @return list<float|null>
     */
    private function allocate(?float $total, array $weights): array
    {
        if ($total === null) {
            return array_fill(0, count($weights), null);
        }
        $normalized = array_map(static fn ($value): float => max(0.0, (float) $value), $weights);
        $weightTotal = array_sum($normalized);
        if ($weightTotal <= 0.0) {
            $normalized = array_fill(0, count($weights), 1.0);
            $weightTotal = (float) count($weights);
        }

        $allocated = [];
        $remainder = $total;
        $last = count($normalized) - 1;
        foreach ($normalized as $index => $weight) {
            $value = $index === $last ? $remainder : round($total * ($weight / $weightTotal), 4);
            $allocated[] = $value;
            $remainder -= $value;
        }

        return $allocated;
    }

    /** @param array<string, mixed> $payload */
    private function money(array $payload, string $key): ?float
    {
        return $this->number($payload[$key]['shopMoney']['amount'] ?? null);
    }

    /** @param array<string, mixed> $item */
    private function variantTitle(array $item): ?string
    {
        $title = trim((string) ($item['variant']['title'] ?? $item['variantTitle'] ?? ''));

        return $title === '' || mb_strtolower($title) === 'default title' ? null : $title;
    }

    /** @param array<string, mixed> $item */
    private function imageUrl(array $item): ?string
    {
        $url = trim((string) ($item['variant']['image']['url'] ?? $item['variant']['product']['featuredImage']['url'] ?? $item['product']['featuredImage']['url'] ?? ''));

        return $url !== '' ? $url : null;
    }

    private function id(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function decimal(?float $value): ?string
    {
        return $value === null ? null : number_format(round($value, 4), 4, '.', '');
    }

    /** @return list<string> */
    private function lines(?string $value): array
    {
        return $value === null || $value === '' ? [] : (preg_split('/\r\n|\r|\n/', $value) ?: []);
    }

    private function meaningfulLine(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' || $value === '—' ? null : $value;
    }
}
