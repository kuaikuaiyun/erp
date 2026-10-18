<form class="product-request-form" method="post" action="{{ route('product-requests.link-skus.update', $linkSku) }}" data-product-request-modal-form>
    @csrf @method('PUT')
    <input type="hidden" name="modal" value="1">
    <div class="modal-header">
        <button type="button" class="close" data-dismiss="modal" aria-label="{{ __('product_requests.common.close') }}"><span aria-hidden="true">&times;</span></button>
        <h4 class="modal-title">{{ __('product_requests.link_skus.edit_title') }}</h4>
        <p class="product-request-modal-subtitle">{{ __('product_requests.link_skus.edit_subtitle') }}</p>
    </div>
    <div class="modal-body product-request-modal-body">
        <div class="form-group"><label>{{ __('product_requests.table.supplier_url') }}</label><div class="product-request-readonly">@if($linkSku->supplier_url)<a href="{{ $linkSku->supplier_url }}" target="_blank" rel="noopener">{{ $linkSku->supplier_url }}</a>@else{{ __('product_requests.common.not_filled') }}@endif</div></div>
        <div class="form-group"><label>{{ __('product_requests.fields.pcl_product_no') }}</label><div class="product-request-readonly">{{ $pcl ?: __('product_requests.common.not_filled') }}</div></div>
        <div class="form-group @error('skus') has-error @enderror"><label for="skus">{{ __('product_requests.table.skus') }}</label><textarea id="skus" class="form-control" name="skus" rows="8" maxlength="10000" placeholder="{{ __('product_requests.link_skus.skus_placeholder') }}">{{ old('skus', implode("\n", $skus)) }}</textarea><span class="help-block">{{ __('product_requests.link_skus.skus_help') }}</span>@error('skus')<span class="help-block text-red">{{ $message }}</span>@enderror</div>
    </div>
    <div class="modal-footer"><button class="btn btn-default" type="button" data-dismiss="modal">{{ __('product_requests.common.cancel') }}</button><button class="btn btn-success" type="submit">{{ __('product_requests.common.submit') }}</button></div>
</form>
