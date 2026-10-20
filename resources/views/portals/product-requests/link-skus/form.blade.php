<form class="product-request-form" method="post" action="{{ route('product-requests.link-skus.update', $linkSku) }}" enctype="multipart/form-data" data-product-request-modal-form>
    @csrf @method('PUT')
    <input type="hidden" name="modal" value="1">
    <div class="modal-header">
        <button type="button" class="close" data-dismiss="modal" aria-label="{{ __('product_requests.common.close') }}"><span aria-hidden="true">&times;</span></button>
        <h4 class="modal-title">{{ __('product_requests.link_skus.edit_title') }}</h4>
        <p class="product-request-modal-subtitle">{{ __('product_requests.link_skus.edit_subtitle') }}</p>
    </div>
    <div class="modal-body product-request-modal-body">
        <div class="form-group"><label>{{ __('product_requests.table.supplier_url') }}</label><div class="product-request-readonly">@if($linkSku->supplier_url)<a href="{{ $linkSku->supplier_url }}" target="_blank" rel="noopener">{{ $linkSku->supplier_url }}</a>@else{{ __('product_requests.common.not_filled') }}@endif</div></div>
        <div class="form-group"><label>{{ __('product_requests.link_skus.product_name') }}</label><div class="product-request-readonly">{{ $productName ?: __('product_requests.common.not_filled') }}</div></div>
        <div class="form-group @error('pcl_product_no') has-error @enderror"><label for="pcl_product_no">{{ __('product_requests.fields.pcl_product_no') }}</label><input type="text" id="pcl_product_no" class="form-control" name="pcl_product_no" value="{{ old('pcl_product_no', $pcl) }}" maxlength="100">@error('pcl_product_no')<span class="help-block text-red">{{ $message }}</span>@enderror</div>
        <div class="form-group @error('skus') has-error @enderror"><label for="skus">{{ __('product_requests.table.skus') }}</label><textarea id="skus" class="form-control" name="skus" rows="8" maxlength="10000" placeholder="{{ __('product_requests.link_skus.skus_placeholder') }}">{{ old('skus', implode("\n", $skus)) }}</textarea><span class="help-block">{{ __('product_requests.link_skus.skus_help') }}</span>@error('skus')<span class="help-block text-red">{{ $message }}</span>@enderror</div>
        @if($skus !== [])
        <div class="form-group"><label>{{ __('product_requests.link_skus.sku_values_label') }}</label>
            <div class="link-sku-value-list">
                <div class="link-sku-value-item link-sku-value-head">
                    <span class="link-sku-value-head-spacer"></span>
                    <span class="link-sku-value-head-label">{{ __('product_requests.procurement.sku_value_price_placeholder') }}</span>
                    <span class="link-sku-value-head-label">{{ __('product_requests.procurement.sku_value_weight_placeholder') }}</span>
                </div>
                @foreach($skus as $sku)
                    @php($sv = old('sku_values')[$sku] ?? ($skuValues[$sku] ?? []))
                    <div class="link-sku-value-item">
                        <span class="product-request-sku-tag">{{ $sku }}</span>
                        <input class="form-control link-sku-value-input" type="text" name="sku_values[{{ $sku }}][price]" value="{{ is_array($sv) ? ($sv['price'] ?? '') : '' }}" maxlength="100" placeholder="{{ __('product_requests.procurement.sku_value_price_placeholder') }}">
                        <input class="form-control link-sku-value-input" type="text" name="sku_values[{{ $sku }}][weight]" value="{{ is_array($sv) ? ($sv['weight'] ?? '') : '' }}" maxlength="100" placeholder="{{ __('product_requests.procurement.sku_value_weight_placeholder') }}">
                    </div>
                @endforeach
            </div>
            <span class="help-block">{{ __('product_requests.link_skus.sku_values_help') }}</span>
        </div>
        @endif
        @if($linkSku->product_image)
        <div class="form-group"><label>{{ __('product_requests.link_skus.product_image_label') }}</label>
            <div class="link-sku-image-list">
                <div class="link-sku-image-item">
                    <a href="{{ route('product-requests.link-skus.product-image', $linkSku) }}" data-product-request-image-preview data-product-request-image-alt="{{ __('product_requests.link_skus.product_image_label') }}"><img src="{{ route('product-requests.link-skus.product-image', $linkSku) }}" alt="{{ __('product_requests.link_skus.product_image_label') }}"></a>
                </div>
            </div>
            <span class="help-block">{{ __('product_requests.link_skus.product_image_help') }}</span>
        </div>
        @endif
        @php($existingImages = array_values((array) ($linkSku->images ?? [])))
        <div class="form-group"><label>{{ __('product_requests.link_skus.images_label') }}</label>
            @if($existingImages !== [])
            <div class="link-sku-image-list">
                @foreach($existingImages as $imageIndex => $imagePath)
                    <div class="link-sku-image-item">
                        <a href="{{ route('product-requests.link-skus.image', [$linkSku, $imageIndex]) }}" data-product-request-image-preview data-product-request-image-alt="{{ __('product_requests.link_skus.images_label') }}"><img src="{{ route('product-requests.link-skus.image', [$linkSku, $imageIndex]) }}" alt="{{ __('product_requests.link_skus.images_label') }}"></a>
                        <label class="link-sku-image-remove"><input type="checkbox" name="remove_images[]" value="{{ $imageIndex }}"> {{ __('product_requests.uploads.remove_image') }}</label>
                    </div>
                @endforeach
            </div>
            @endif
            @include('portals.product-requests.partials.upload', ['inputName' => 'images', 'inputId' => 'link_sku_images', 'maxFiles' => 3, 'required' => false, 'existingAttachments' => collect()])
            <span class="help-block">{{ __('product_requests.link_skus.images_help') }}</span>
        </div>
    </div>
    <div class="modal-footer"><button class="btn btn-default" type="button" data-dismiss="modal">{{ __('product_requests.common.cancel') }}</button><button class="btn btn-success" type="submit">{{ __('product_requests.common.submit') }}</button></div>
</form>
<style>
    .link-sku-value-list { display: flex; flex-direction: column; gap: 6px; max-height: 260px; overflow-y: auto; }
    .link-sku-value-item { display: flex; align-items: center; gap: 8px; }
    .link-sku-image-list { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 8px; }
    .link-sku-image-item { display: flex; flex-direction: column; align-items: center; gap: 4px; cursor: pointer; }
    .link-sku-image-item img { width: 72px; height: 72px; object-fit: cover; border: 1px solid #ddd; border-radius: 4px; }
    .link-sku-image-remove { font-size: 12px; color: #999; }
    .link-sku-value-item .product-request-sku-tag { flex: 1 1 auto; word-break: break-all; }
    .link-sku-value-input { width: 120px; flex: 0 0 120px; height: 28px; }
    .link-sku-value-head { margin-bottom: -2px; }
    .link-sku-value-head-spacer { flex: 1 1 auto; }
    .link-sku-value-head-label { width: 120px; flex: 0 0 120px; font-size: 12px; color: #7c96ab; text-align: center; line-height: 18px; }
</style>
