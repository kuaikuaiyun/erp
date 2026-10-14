@php($skuValues = collect(explode(',', (string) ($skus ?? '')))->map(fn ($sku) => trim($sku))->filter()->values())
@if($skuValues->isEmpty())
    {{ $empty ?? '' }}
@else
    <span class="product-request-sku-tags">@foreach($skuValues as $sku)<span class="product-request-sku-tag">{{ $sku }}</span>@endforeach</span>
@endif
