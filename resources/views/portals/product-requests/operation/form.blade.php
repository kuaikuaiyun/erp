<form class="product-request-form" method="post" action="{{ route('product-requests.operation.store') }}" enctype="multipart/form-data" data-product-request-modal-form>
    @csrf
    <input type="hidden" name="modal" value="1">
    <div class="modal-header">
        <button type="button" class="close" data-dismiss="modal" aria-label="{{ __('product_requests.common.close') }}"><span aria-hidden="true">&times;</span></button>
        <h4 class="modal-title">{{ __('product_requests.operation.form_title') }}</h4>
        <p class="product-request-modal-subtitle">{{ __('product_requests.operation.form_subtitle') }}</p>
    </div>
    <div class="modal-body product-request-modal-body">
        <section class="form-section">
            <h2 class="form-section-title">{{ __('product_requests.show.overview') }}</h2>
            <div class="row">
                <div class="form-group col-md-4"><label>{{ __('product_requests.fields.report_no') }}</label><div class="product-request-readonly">{{ __('product_requests.help.automatic_on_submit') }}</div></div>
                <div class="form-group col-md-4"><label>{{ __('product_requests.fields.reported_at') }}</label><div class="product-request-readonly">{{ __('product_requests.help.automatic_current_date') }}</div></div>
                <div class="form-group col-md-4"><label>{{ __('product_requests.fields.operation') }} <span class="text-red">*</span></label><div class="product-request-readonly">{{ $actor['display_name'] }}</div><span class="help-block">{{ __('product_requests.help.automatic_current_user') }}</span></div>
            </div>
            <div class="row">
                <div class="form-group col-md-6 @error('city') has-error @enderror"><label for="city">{{ __('product_requests.fields.city') }} <span class="text-red">*</span></label><input id="city" class="form-control" name="city" value="{{ old('city') }}" maxlength="100" required placeholder="{{ __('product_requests.placeholders.city') }}">@error('city')<span class="help-block text-red">{{ $message }}</span>@enderror</div>
                <div class="form-group col-md-6 @error('reference_page_url') has-error @enderror"><label for="reference_page_url">{{ __('product_requests.fields.reference_page_url') }}</label><input id="reference_page_url" class="form-control" name="reference_page_url" value="{{ old('reference_page_url') }}" maxlength="2048" placeholder="{{ __('product_requests.placeholders.reference_page_url') }}">@error('reference_page_url')<span class="help-block text-red">{{ $message }}</span>@enderror</div>
            </div>
            <div class="row">
                <div class="form-group col-md-6 @error('minimum_sale_unit') has-error @enderror"><label for="minimum_sale_unit">{{ __('product_requests.fields.minimum_sale_unit') }} <span class="text-red">*</span></label><input id="minimum_sale_unit" class="form-control" name="minimum_sale_unit" value="{{ old('minimum_sale_unit') }}" maxlength="255" required placeholder="{{ __('product_requests.placeholders.minimum_sale_unit') }}">@error('minimum_sale_unit')<span class="help-block text-red">{{ $message }}</span>@enderror</div>
                <div class="form-group col-md-6 @error('has_multiple_attributes') has-error @enderror"><label for="has_multiple_attributes">{{ __('product_requests.fields.has_multiple_attributes') }} <span class="text-red">*</span></label><input id="has_multiple_attributes" class="form-control" name="has_multiple_attributes" value="{{ old('has_multiple_attributes') }}" maxlength="255" required placeholder="{{ __('product_requests.placeholders.has_multiple_attributes') }}">@error('has_multiple_attributes')<span class="help-block text-red">{{ $message }}</span>@enderror</div>
            </div>
            <div class="form-group @error('product_image') has-error @enderror"><label>{{ __('product_requests.fields.product_image') }} <span class="text-red">*</span></label>@include('portals.product-requests.partials.upload', ['inputName' => 'product_image', 'inputId' => 'product_image', 'maxFiles' => 1, 'required' => true, 'existingAttachments' => collect()])<span class="help-block">{{ __('product_requests.help.image_upload') }} {{ __('product_requests.help.one_image_only') }}</span></div>
        </section>
    </div>
    <div class="modal-footer"><button class="btn btn-default" type="button" data-dismiss="modal">{{ __('product_requests.common.cancel') }}</button><button class="btn btn-success" type="submit"><i class="fa fa-check"></i> {{ __('product_requests.common.submit') }}</button></div>
</form>
