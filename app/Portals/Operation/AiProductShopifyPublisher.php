<?php

namespace App\Portals\Operation;

use App\Models\AdminUser;
use App\Models\AikaipinProduct;
use App\Models\AssistantDomainStore;
use App\Portals\Logistics\ShopifyOrderSyncService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\URL;
use RuntimeException;

class AiProductShopifyPublisher
{
    public function __construct(private readonly ShopifyOrderSyncService $shopify)
    {
    }

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
        if (!$grantedScopes->contains('write_products')) {
            // The scope is fixed in Shopify Dev Dashboard, not in Laravel. Expire the
            // current read-only token and immediately retry once. This lets a newly
            // saved Shopify permission take effect without waiting for token expiry.
            $this->expireAccessToken($store, $grantedScopes->all());
            $grantedScopes = $this->grantedScopes($store, $actor);
        }
        if (!$grantedScopes->contains('write_products')) {
            $this->expireAccessToken($store, $grantedScopes->all());

            throw new RuntimeException(
                '当前 Shopify 应用只有读取权限，缺少 write_products。请在 Shopify Dev Dashboard 为该应用开启“Products”的写入权限，保存后重新点击上架。'
            );
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
        $created = $this->shopify->graphqlForStore($store, $actor, $mutation, $variables);
        $errors = Arr::get($created, 'data.productCreate.userErrors', []);
        if ($errors !== []) {
            throw new RuntimeException($this->errorMessage($errors));
        }

        $shopifyProduct = Arr::get($created, 'data.productCreate.product');
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
                'sku' => $sku,
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

    private function errorMessage(array $errors): string
    {
        return collect($errors)->pluck('message')->filter()->implode('；') ?: 'Shopify 返回未知错误。';
    }

    /** @return \Illuminate\Support\Collection<int, string> */
    private function grantedScopes(AssistantDomainStore $store, AdminUser $actor): \Illuminate\Support\Collection
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
