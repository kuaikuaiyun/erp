<form class="product-request-form" method="post" action="{{ route('product-requests.placement.update', $productRequest) }}" data-product-request-modal-form>
    @csrf @method('PUT')
    <input type="hidden" name="modal" value="1">
    <div class="modal-header">
        <button type="button" class="close" data-dismiss="modal" aria-label="{{ __('product_requests.common.close') }}"><span aria-hidden="true">&times;</span></button>
        <h4 class="modal-title">{{ __('product_requests.operation.placement_title') }}</h4>
        <p class="product-request-modal-subtitle">{{ __('product_requests.operation.placement_subtitle') }}</p>
    </div>
    <div class="modal-body product-request-modal-body">
        <div class="row"><div class="form-group col-md-6"><label>{{ __('product_requests.fields.report_no') }}</label><div class="product-request-readonly">{{ $productRequest->report_no }}</div></div><div class="form-group col-md-6"><label>{{ __('product_requests.fields.main_status') }}</label><div class="product-request-readonly">{{ __('product_requests.status.'.$productRequest->status) }}</div></div></div>
        <div class="form-group @error('placement_link') has-error @enderror"><label for="placement_link">{{ __('product_requests.fields.placement_link') }} <span class="text-red">*</span></label><input id="placement_link" class="form-control" type="url" name="placement_link" value="{{ old('placement_link', $productRequest->placement_link) }}" maxlength="2048" required placeholder="{{ __('product_requests.placeholders.placement_link') }}">@error('placement_link')<span class="help-block text-red">{{ $message }}</span>@enderror</div>
    </div>
    <div class="modal-footer"><button class="btn btn-default" type="button" data-dismiss="modal">{{ __('product_requests.common.cancel') }}</button><button class="btn btn-success" type="submit">{{ __('product_requests.common.submit') }}</button></div>
</form>
