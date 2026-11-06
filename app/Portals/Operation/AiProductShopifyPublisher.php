<?php

namespace App\Portals\Operation;

use App\Models\AdminUser;
use App\Models\AikaipinProduct;
use App\Models\AssistantDomainStore;
use App\Portals\Logistics\ShopifyOrderSyncService;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\URL;
use RuntimeException;

class AiProductShopifyPublisher
{
    public function __construct(private readonly ShopifyOrderSyncService $shopify) {}

    /** @return array<string, mixed> */
    public function publish(
        AikaipinProduct $product,
        AssistantDomainStore $store,
        AdminUser $actor,
        string $title,
        string $sku,
        float $price,
        string $status,
    ): array {
        $grantedScopes = $this->grantedScopes($store, $actor);
        if (! $grantedScopes->contains('write_products')) {
            // The scope is fixed in Shopify Dev Dashboard, not in Laravel. Expire the
            // current read-only token and immediately retry once. This lets a newly
            // saved Shopify permission take effect without waiting for token expiry.
            $this->expireAccessToken($store, $grantedScopes->all());
            $grantedScopes = $this->grantedScopes($store, $actor);
        }
        if (! $grantedScopes->contains('write_products')) {
            $this->expireAccessToken($store, $grantedScopes->all());

            throw new RuntimeException(
                'Shopify 应用版本已声明 write_products，但店铺“'.($store->shop_name ?: $store->domain).'”当前安装尚未批准该权限。请在该店铺的 Shopify 后台打开并更新应用授权，批准 Products 写入权限；若没有出现授权提示，请从 Shopify Dev Dashboard 重新安装该应用。仅重新点击上架不会生效。'
            );
        }

        if ($product->hasShopifyData() && collect($product->shopifyData()['variants'] ?? [])->isNotEmpty()) {
            return $this->publishEnrichedProduct($product, $store, $actor, $title, $status);
        }

        $media = collect(array_keys($product->imageFiles()))
            ->take(10)
            ->map(fn (int $index): array => [
                'mediaContentType' => 'IMAGE',
                'originalSource' => URL::temporarySignedRoute(
                    'operation.ai-product-library.shopify-image',
                    now()->addHours(2),
                    ['product' => $product, 'imageIndex' => $index],
                ),
                'alt' => $title,
            ])
            ->values()
            ->all();

        $mutation = <<<'GRAPHQL'
mutation CreateAiProduct($product: ProductCreateInput!, $media: [CreateMediaInput!]) {
  productCreate(product: $product, media: $media) {
    product {
      id
      title
      handle
      status
      variants(first: 1) { nodes { id sku price } }
    }
    userErrors { field message }
  }
}
GRAPHQL;
        $variables = [
            'product' => [
                'title' => $title,
                'status' => $status,
                'vendor' => 'AI产品库',
                'productType' => $product->categoryValues()[0] ?? '',
                'tags' => array_values(array_unique(array_filter([
                    'AI产品库',
                    (string) $product->product_code,
                    ...$product->categoryValues(),
                ]))),
            ],
            'media' => $media,
        ];
        // A previous attempt might have created the product and then failed while
        // updating its default variant. Reuse that product to keep retries idempotent.
        $shopifyProduct = $this->findExistingProduct($product, $store, $actor, $title);
        $created = null;
        if (! is_array($shopifyProduct)) {
            $created = $this->shopify->graphqlForStore($store, $actor, $mutation, $variables);
            $errors = Arr::get($created, 'data.productCreate.userErrors', []);
            if ($errors !== []) {
                throw new RuntimeException($this->errorMessage($errors));
            }
            $shopifyProduct = Arr::get($created, 'data.productCreate.product');
        }
        $productId = (string) data_get($shopifyProduct, 'id');
        $variantId = (string) data_get($shopifyProduct, 'variants.nodes.0.id');
        if ($productId === '' || $variantId === '') {
            throw new RuntimeException('Shopify 已响应，但没有返回商品或规格 ID。');
        }

        $variantMutation = <<<'GRAPHQL'
mutation UpdateAiProductVariant($productId: ID!, $variants: [ProductVariantsBulkInput!]!) {
  productVariantsBulkUpdate(productId: $productId, variants: $variants) {
    productVariants { id sku price }
    userErrors { field message }
  }
}
GRAPHQL;
        $updated = $this->shopify->graphqlForStore($store, $actor, $variantMutation, [
            'productId' => $productId,
            'variants' => [[
                'id' => $variantId,
                'inventoryItem' => ['sku' => $sku],
                'price' => number_format($price, 2, '.', ''),
            ]],
        ]);
        $variantErrors = Arr::get($updated, 'data.productVariantsBulkUpdate.userErrors', []);
        if ($variantErrors !== []) {
            throw new RuntimeException('商品已创建，但 SKU/价格写入失败：'.$this->errorMessage($variantErrors));
        }

        return [
            'product' => $shopifyProduct,
            'variant' => Arr::get($updated, 'data.productVariantsBulkUpdate.productVariants.0'),
            'request' => $variables,
            'responses' => ['create' => $created, 'variant' => $updated],
        ];
    }

    /** @return array<string, mixed> */
    private function publishEnrichedProduct(
        AikaipinProduct $product,
        AssistantDomainStore $store,
        AdminUser $actor,
        string $title,
        string $status,
    ): array {
        $data = $product->shopifyData();
        $variants = collect($data['variants'] ?? [])->filter(fn ($variant): bool => is_array($variant) && filled($variant['sku'] ?? null))->values();
        if ($variants->isEmpty()) {
            throw new RuntimeException('AI 产品库中的 Shopify 补充数据没有有效规格。');
        }

        $optionNames = collect($data['option_names'] ?? [])->map(fn ($name) => trim((string) $name))->filter()->values();
        $productOptions = $optionNames->map(function (string $optionName) use ($variants): array {
            $values = $variants->flatMap(fn (array $variant): array => collect($variant['options'] ?? [])
                ->filter(fn ($option): bool => is_array($option) && (string) ($option['name'] ?? '') === $optionName)
                ->pluck('value')
                ->all())
                ->map(fn ($value) => trim((string) $value))
                ->filter()
                ->unique(fn (string $value): string => mb_strtolower($value))
                ->map(fn (string $value): array => ['name' => $value])
                ->values()
                ->all();

            return ['name' => $optionName, 'values' => $values];
        })->filter(fn (array $option): bool => $option['values'] !== [])->values()->all();

        $allImages = collect($data['images'] ?? [])
            ->filter(fn ($image): bool => is_array($image) && filled($image['url'] ?? null))
            ->map(fn (array $image): array => [
                'originalSource' => trim((string) $image['url']),
                'alt' => Str::limit(trim((string) ($image['alt'] ?? $title)) ?: $title, 512, ''),
                'contentType' => 'IMAGE',
            ])
            ->concat($variants->map(fn (array $variant): ?array => filled($variant['image_url'] ?? null) ? [
                'originalSource' => trim((string) $variant['image_url']),
                'alt' => Str::limit($title.' - '.collect($variant['options'] ?? [])->pluck('value')->filter()->implode(' / '), 512, ''),
                'contentType' => 'IMAGE',
            ] : null)->filter())
            ->unique('originalSource')
            ->take(50)
            ->values();

        $locationId = $this->primaryLocationId($store, $actor);
        $variantInputs = $variants->map(function (array $variant, int $index) use ($locationId): array {
            $input = [
                'sku' => trim((string) $variant['sku']),
                'price' => number_format((float) ($variant['price'] ?? 0), 2, '.', ''),
                'position' => $index + 1,
                'inventoryPolicy' => strtolower((string) ($variant['inventory_policy'] ?? 'deny')) === 'continue' ? 'CONTINUE' : 'DENY',
                'inventoryItem' => [
                    'requiresShipping' => (bool) ($variant['requires_shipping'] ?? true),
                    'tracked' => filled($variant['inventory_tracker'] ?? null),
                ],
                'optionValues' => collect($variant['options'] ?? [])
                    ->filter(fn ($option): bool => is_array($option) && filled($option['name'] ?? null) && filled($option['value'] ?? null))
                    ->map(fn (array $option): array => [
                        'optionName' => trim((string) $option['name']),
                        'name' => trim((string) $option['value']),
                    ])->values()->all(),
            ];
            if (filled($variant['image_url'] ?? null)) {
                $input['file'] = [
                    'originalSource' => trim((string) $variant['image_url']),
                    'alt' => Str::limit(collect($variant['options'] ?? [])->pluck('value')->filter()->implode(' / '), 512, ''),
                    'contentType' => 'IMAGE',
                ];
            }
            if ($locationId !== null && filled($variant['inventory_tracker'] ?? null)) {
                $input['inventoryQuantities'] = [[
                    'locationId' => $locationId,
                    'name' => 'available',
                    'quantity' => max(0, (int) ($variant['inventory_quantity'] ?? 0)),
                ]];
            }

            return $input;
        })->all();

        $existing = $this->findExistingProduct($product, $store, $actor, $title, false);
        $handle = filled(data_get($existing, 'handle'))
            ? (string) data_get($existing, 'handle')
            : $this->uniqueProductHandle($product, (string) ($data['handle'] ?? ''), $title);

        $input = array_filter([
            'title' => $title,
            'handle' => $handle,
            'descriptionHtml' => (string) ($data['description_html'] ?? ''),
            'vendor' => trim((string) ($data['vendor'] ?? '')) ?: 'AI产品库',
            'productType' => trim((string) ($data['product_type'] ?? '')) ?: ($product->categoryValues()[0] ?? ''),
            'status' => $status,
            'tags' => array_values(array_unique(array_filter([
                'AI产品库',
                (string) $product->product_code,
                ...$product->categoryValues(),
            ]))),
            'productOptions' => $productOptions,
            'variants' => $variantInputs,
            'files' => $allImages->all(),
        ], static fn ($value): bool => $value !== null && $value !== '');

        $identifier = filled(data_get($existing, 'id')) ? ['id' => (string) data_get($existing, 'id')] : null;
        $mutation = <<<'GRAPHQL'
mutation SetAiProduct($input: ProductSetInput!, $identifier: ProductSetIdentifiers) {
  productSet(input: $input, identifier: $identifier, synchronous: true) {
    product {
      id
      title
      handle
      status
      variants(first: 100) { nodes { id sku price title } }
      media(first: 100) { nodes { id alt mediaContentType status } }
    }
    userErrors { field message }
  }
}
GRAPHQL;
        $variables = ['input' => $input, 'identifier' => $identifier];
        $response = $this->shopify->graphqlForStore($store, $actor, $mutation, $variables);
        $errors = Arr::get($response, 'data.productSet.userErrors', []);
        if ($errors !== []) {
            throw new RuntimeException($this->errorMessage($errors));
        }
        $shopifyProduct = Arr::get($response, 'data.productSet.product');
        if (! is_array($shopifyProduct) || blank($shopifyProduct['id'] ?? null)) {
            throw new RuntimeException('Shopify 已响应，但没有返回已上架的商品。');
        }

        return [
            'product' => $shopifyProduct,
            'variant' => Arr::get($shopifyProduct, 'variants.nodes.0'),
            'request' => $variables,
            'responses' => ['product_set' => $response],
        ];
    }

    private function primaryLocationId(AssistantDomainStore $store, AdminUser $actor): ?string
    {
        try {
            $response = $this->shopify->graphqlForStore(
                $store,
                $actor,
                'query AiProductPrimaryLocation { locations(first: 10) { nodes { id isActive } } }',
            );

            $location = collect(Arr::get($response, 'data.locations.nodes', []))
                ->first(fn ($candidate): bool => is_array($candidate) && (bool) ($candidate['isActive'] ?? false));

            return is_array($location) && filled($location['id'] ?? null) ? (string) $location['id'] : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function uniqueProductHandle(AikaipinProduct $product, string $importedHandle, string $title): string
    {
        $base = Str::slug(trim($importedHandle)) ?: Str::slug($title) ?: 'ai-product';
        $suffix = '-ai-'.$product->getKey();

        return Str::limit($base, 255 - strlen($suffix), '').$suffix;
    }

    /** @return array<string, mixed>|null */
    private function findExistingProduct(
        AikaipinProduct $product,
        AssistantDomainStore $store,
        AdminUser $actor,
        string $title,
        bool $requireSameTitle = true,
    ): ?array {
        $productCode = trim((string) $product->product_code);
        if ($productCode === '') {
            return null;
        }

        $query = <<<'GRAPHQL'
query FindAiProductForRetry($search: String!) {
  products(first: 10, query: $search, sortKey: CREATED_AT, reverse: true) {
    nodes {
      id
      title
      handle
      tags
      variants(first: 100) { nodes { id sku price } }
    }
  }
}
GRAPHQL;
        $escapedCode = str_replace(['\\', '"'], ['\\\\', '\\"'], $productCode);
        $response = $this->shopify->graphqlForStore($store, $actor, $query, [
            'search' => 'tag:"'.$escapedCode.'"',
        ]);

        return collect(Arr::get($response, 'data.products.nodes', []))
            ->first(static fn (mixed $candidate): bool => is_array($candidate)
                && (! $requireSameTitle || (string) ($candidate['title'] ?? '') === $title)
                && in_array($productCode, (array) ($candidate['tags'] ?? []), true));
    }

    private function errorMessage(array $errors): string
    {
        return collect($errors)->pluck('message')->filter()->implode('；') ?: 'Shopify 返回未知错误。';
    }

    /** @return Collection<int, string> */
    private function grantedScopes(AssistantDomainStore $store, AdminUser $actor): Collection
    {
        $response = $this->shopify->graphqlForStore(
            $store,
            $actor,
            'query AiProductPublishScopes { currentAppInstallation { accessScopes { handle } } }',
        );

        return collect(Arr::get($response, 'data.currentAppInstallation.accessScopes', []))
            ->pluck('handle')
            ->filter()
            ->values();
    }

    /** @param list<string> $scopes */
    private function expireAccessToken(AssistantDomainStore $store, array $scopes): void
    {
        $store->shopifyConnection()->update([
            'access_token' => null,
            'granted_scopes' => implode(',', $scopes),
            'status' => 'failed',
            'token_expires_at' => now(),
        ]);
    }
}
