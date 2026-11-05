<?php

namespace App\Portals\Operation;

use App\Models\AikaipinProduct;
use RuntimeException;

class ShopifyProductCsvImporter
{
    /** @return array<string, mixed> */
    public function parse(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('无法读取 CSV 文件。');
        }

        try {
            $bom = fread($handle, 3);
            if ($bom !== "\xEF\xBB\xBF") {
                rewind($handle);
            }
            $headers = fgetcsv($handle);
            if (! is_array($headers)) {
                throw new RuntimeException('CSV 文件没有表头。');
            }

            $headers = array_map(static function (mixed $header): string {
                return trim(preg_replace('/^\x{FEFF}/u', '', (string) $header) ?? '');
            }, $headers);
            foreach (['Handle', 'Title', 'Variant SKU', 'Variant Price', 'Image Src'] as $required) {
                if (! in_array($required, $headers, true)) {
                    throw new RuntimeException('CSV 缺少必要字段：'.$required.'。');
                }
            }

            $rows = [];
            while (($values = fgetcsv($handle)) !== false) {
                if ($values === [null] || $values === []) {
                    continue;
                }
                $values = array_pad($values, count($headers), '');
                $rows[] = array_combine($headers, array_slice($values, 0, count($headers)));
            }
        } finally {
            fclose($handle);
        }

        if ($rows === []) {
            throw new RuntimeException('CSV 文件没有商品数据。');
        }

        $handles = collect($rows)->pluck('Handle')->map(fn ($value) => trim((string) $value))->filter()->unique()->values();
        if ($handles->count() !== 1) {
            throw new RuntimeException('一次只能导入一个 Shopify 商品，当前文件包含 '.$handles->count().' 个 Handle。');
        }

        $primary = collect($rows)->first(fn (array $row): bool => trim((string) ($row['Title'] ?? '')) !== '') ?? $rows[0];
        $optionNames = [];
        for ($index = 1; $index <= 3; $index++) {
            $name = collect($rows)->pluck('Option'.$index.' Name')->map(fn ($value) => trim((string) $value))->first() ?: '';
            if ($name !== '') {
                $optionNames[$index] = $name;
            }
        }

        $variants = collect($rows)
            ->filter(fn (array $row): bool => trim((string) ($row['Variant SKU'] ?? '')) !== '')
            ->map(function (array $row) use ($optionNames): array {
                $options = [];
                foreach ($optionNames as $index => $name) {
                    $value = trim((string) ($row['Option'.$index.' Value'] ?? ''));
                    if ($value !== '') {
                        $options[] = ['name' => $name, 'value' => $value];
                    }
                }

                return [
                    'sku' => trim((string) $row['Variant SKU']),
                    'price' => round((float) ($row['Variant Price'] ?? 0), 2),
                    'inventory_quantity' => (int) ($row['Variant Inventory Qty'] ?? 0),
                    'inventory_tracker' => trim((string) ($row['Variant Inventory Tracker'] ?? '')),
                    'inventory_policy' => strtolower(trim((string) ($row['Variant Inventory Policy'] ?? 'deny'))),
                    'fulfillment_service' => trim((string) ($row['Variant Fulfillment Service'] ?? 'manual')),
                    'requires_shipping' => $this->toBoolean($row['Variant Requires Shipping'] ?? true),
                    'options' => $options,
                    'image_url' => trim((string) ($row['Variant Image'] ?? '')) ?: null,
                ];
            })
            ->unique(fn (array $variant): string => mb_strtolower($variant['sku']))
            ->values()
            ->all();

        if ($variants === []) {
            throw new RuntimeException('CSV 中没有填写 Variant SKU 的规格。');
        }

        $images = collect($rows)
            ->filter(fn (array $row): bool => trim((string) ($row['Image Src'] ?? '')) !== '')
            ->map(fn (array $row): array => [
                'url' => trim((string) $row['Image Src']),
                'position' => max(1, (int) ($row['Image Position'] ?? 0)),
                'alt' => trim((string) ($row['Image Alt Text'] ?? '')) ?: trim((string) ($primary['Title'] ?? '')),
            ])
            ->unique('url')
            ->sortBy('position')
            ->values()
            ->all();

        return [
            'schema_version' => 1,
            'source' => 'shopify_csv',
            'handle' => $handles->first(),
            'title' => trim((string) ($primary['Title'] ?? '')),
            'description_html' => (string) ($primary['Body (HTML)'] ?? ''),
            'vendor' => trim((string) ($primary['Vendor'] ?? '')),
            'product_type' => trim((string) ($primary['Type'] ?? '')),
            'status' => strtoupper(trim((string) ($primary['Status'] ?? 'DRAFT'))) ?: 'DRAFT',
            'published' => $this->toBoolean($primary['Published'] ?? false),
            'option_names' => array_values($optionNames),
            'variants' => $variants,
            'images' => $images,
        ];
    }

    /** @param array<string, mixed> $payload */
    public function supplement(AikaipinProduct $product, array $payload, string $sourceFile, ?string $sourceName = null): AikaipinProduct
    {
        $existingSkus = $product->skuValues();
        $csvSkus = collect($payload['variants'] ?? [])->pluck('sku')->filter()->map(fn ($sku) => trim((string) $sku))->all();
        $skus = collect([...$existingSkus, ...$csvSkus])
            ->filter()
            ->unique(fn (string $sku): string => mb_strtolower($sku))
            ->values()
            ->all();

        $payload['source_file'] = basename($sourceName ?: $sourceFile);
        $payload['imported_at'] = now()->toIso8601String();

        $product->forceFill([
            'sku' => implode(',', $skus),
            'shopify_data' => $payload,
        ])->save();

        return $product->refresh();
    }

    /** @param array<string, mixed> $payload */
    public function findProduct(array $payload, ?int $productId = null, ?string $productCode = null): AikaipinProduct
    {
        if ($productId !== null && $productId > 0) {
            return AikaipinProduct::query()->findOrFail($productId);
        }
        if (filled($productCode)) {
            return AikaipinProduct::query()->where('product_code', trim((string) $productCode))->firstOrFail();
        }

        $csvSkus = collect($payload['variants'] ?? [])->pluck('sku')->map(fn ($sku) => mb_strtolower(trim((string) $sku)))->filter()->flip();
        $matches = AikaipinProduct::query()->get()->map(function (AikaipinProduct $product) use ($csvSkus): array {
            $score = collect($product->skuValues())
                ->map(fn (string $sku): string => mb_strtolower($sku))
                ->filter(fn (string $sku): bool => $csvSkus->has($sku))
                ->count();

            return ['product' => $product, 'score' => $score];
        })->filter(fn (array $match): bool => $match['score'] > 0)->sortByDesc('score')->values();

        if ($matches->isEmpty()) {
            throw new RuntimeException('未找到与 CSV 规格 SKU 匹配的 AI 产品，请使用 --product-id 或 --product-code 指定。');
        }
        if ($matches->count() > 1 && $matches[0]['score'] === $matches[1]['score']) {
            throw new RuntimeException('存在多个同分匹配产品，请使用 --product-id 或 --product-code 指定。');
        }

        return $matches[0]['product'];
    }

    private function toBoolean(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
    }
}
