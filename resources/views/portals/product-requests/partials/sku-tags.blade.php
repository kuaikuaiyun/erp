@php
    $skuValues = collect(explode(',', (string) ($skus ?? '')))->map(fn ($sku) => trim($sku))->filter()->values();
    $compareValues = isset($compareSkus) && $compareSkus !== null
        ? collect(explode(',', (string) $compareSkus))->map(fn ($sku) => trim($sku))->filter()->values()
        : collect();
    $collapse = !empty($collapse) && $skuValues->count() > 1;
    $visibleCount = (int) ($collapseLimit ?? 1);
@endphp
@if($skuValues->isEmpty())
    {{ $empty ?? '' }}
@else
    <span class="product-request-sku-tags">
        @foreach($skuValues as $index => $sku)
            <span class="product-request-sku-tag{{ $compareValues->isNotEmpty() && !$compareValues->contains($sku) ? ' product-request-sku-tag-missing' : '' }}{{ $collapse && $index >= $visibleCount ? ' product-request-sku-tag-hidden' : '' }}">{{ $sku }}</span>
        @endforeach
        @if($collapse)
            <button type="button" class="product-request-sku-more-toggle" data-toggle="sku-tags">{{ $skuValues->count() - $visibleCount > 0 ? '+'.($skuValues->count() - $visibleCount).' 更多' : '' }}</button>
        @endif
    </span>
@endif
