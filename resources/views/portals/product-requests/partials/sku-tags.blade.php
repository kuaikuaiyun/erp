@php
    $skuValues = collect(explode(',', (string) ($skus ?? '')))->map(fn ($sku) => trim($sku))->filter()->values();
    $compareValues = isset($compareSkus) && $compareSkus !== null
        ? collect(explode(',', (string) $compareSkus))->map(fn ($sku) => trim($sku))->filter()->values()
        : collect();
@endphp
@if($skuValues->isEmpty())
    {{ $empty ?? '' }}
@else
    <span class="product-request-sku-tags">@foreach($skuValues as $sku)<span class="product-request-sku-tag{{ $compareValues->isNotEmpty() && !$compareValues->contains($sku) ? ' product-request-sku-tag-missing' : '' }}">{{ $sku }}</span>@endforeach</span>
@endif
