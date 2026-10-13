@php
    $operationImages = $productRequest->attachmentsFor(\App\Models\ProductRequestAttachment::STAGE_OPERATION);
    $sellerImages = $productRequest->attachmentsFor(\App\Models\ProductRequestAttachment::STAGE_PROCUREMENT);
@endphp
<form class="product-request-form" method="post" action="{{ route('product-requests.procurement.update', $productRequest) }}" enctype="multipart/form-data" data-product-request-modal-form data-product-request-reuse-form data-current-id="{{ $productRequest->getKey() }}" data-reuse-check-url="{{ route('product-requests.procurement.reuse-check') }}">
    @csrf
    @method('PUT')
    <input type="hidden" name="modal" value="1">
    <input type="hidden" name="reuse_source_id" value="{{ old('reuse_source_id') }}">

    <div class="modal-header">
        <button type="button" class="close" data-dismiss="modal" aria-label="{{ __('product_requests.common.close') }}"><span aria-hidden="true">&times;</span></button>
        <h4 class="modal-title">{{ __('product_requests.procurement.form_title') }}</h4>
        <p class="product-request-modal-subtitle">{{ __('product_requests.procurement.form_subtitle') }}</p>
    </div>

    <div class="modal-body product-request-modal-body">
        <section class="form-section product-request-source-summary">
            <div class="product-request-source-summary-image">
                <span class="product-request-detail-label">{{ __('product_requests.fields.product_image') }}</span>
                <div class="product-request-image-grid">
                    @forelse($operationImages as $attachment)
                        <a href="{{ route('product-requests.attachments.show', [$productRequest, $attachment]) }}" data-product-request-image-preview data-product-request-image-alt="{{ $attachment->original_name }}">
                            <img src="{{ route('product-requests.attachments.show', [$productRequest, $attachment]) }}" alt="{{ $attachment->original_name }}">
                        </a>
                    @empty
                        <span class="text-muted">{{ __('product_requests.common.not_available') }}</span>
                    @endforelse
                </div>
            </div>
            <div class="product-request-source-summary-fields">
                <h2 class="form-section-title">{{ __('product_requests.show.overview') }}</h2>
                <div class="product-request-source-summary-grid">
                    <div class="form-group">
                        <label>{{ __('product_requests.fields.report_no') }}</label>
                        <div class="product-request-readonly">{{ $productRequest->report_no }}</div>
                    </div>
                    <div class="form-group">
                        <label>{{ __('product_requests.fields.operation') }}</label>
                        <div class="product-request-readonly">{{ $productRequest->operation_display_name }}</div>
                    </div>
                    <div class="form-group">
                        <label>{{ __('product_requests.fields.city') }}</label>
                        <div class="product-request-readonly">{{ $productRequest->city }}</div>
                    </div>
                </div>
            </div>
        </section>

        <section class="form-section product-request-procurement-section">
            <h2 class="form-section-title">{{ __('product_requests.show.procurement_information') }}</h2>

            <div class="row">
                <div class="form-group col-md-6 @error('standard_chinese_name') has-error @enderror">
                    <label for="standard_chinese_name">{{ __('product_requests.fields.standard_chinese_name') }} <span class="text-red">*</span></label>
                    <input id="standard_chinese_name" class="form-control" name="standard_chinese_name" value="{{ old('standard_chinese_name', $productRequest->standard_chinese_name) }}" maxlength="191" required placeholder="{{ __('product_requests.placeholders.standard_chinese_name') }}">
                    @error('standard_chinese_name')<span class="help-block text-red">{{ $message }}</span>@enderror
                </div>
                <div class="form-group col-md-6 @error('supplier_url') has-error @enderror">
                    <label for="supplier_url">{{ __('product_requests.fields.supplier_url') }} <span class="text-red">*</span> <button class="btn btn-default btn-xs" type="button" data-product-request-reuse-check>{{ __('product_requests.procurement.reuse_check') }}</button></label>
                    <input id="supplier_url" class="form-control" type="url" name="supplier_url" value="{{ old('supplier_url', $productRequest->supplier_url) }}" maxlength="2048" required placeholder="{{ __('product_requests.placeholders.supplier_url') }}">
                    <span class="help-block">{{ __('product_requests.procurement.reuse_check_hint') }}</span>
                    <span class="product-request-selected-reuse" data-product-request-reuse-selected hidden></span>
                    @error('supplier_url')<span class="help-block text-red">{{ $message }}</span>@enderror
                </div>
            </div>

            <div class="row product-request-form-row-divider">
                <div class="form-group col-md-4 @error('company_spu') has-error @enderror">
                    <label for="company_spu">{{ __('product_requests.fields.company_spu') }} <span class="text-red">*</span></label>
                    <input id="company_spu" class="form-control" name="company_spu" value="{{ old('company_spu', $productRequest->company_spu) }}" required maxlength="191" placeholder="{{ __('product_requests.placeholders.company_spu') }}">
                    @error('company_spu')<span class="help-block text-red">{{ $message }}</span>@enderror
                </div>
                <div class="form-group col-md-4 @error('sku_sequence') has-error @enderror">
                    <label for="sku_sequence">{{ __('product_requests.fields.sku_sequence') }} <span class="text-red">*</span></label>
                    <input id="sku_sequence" class="form-control" name="sku_sequence" value="{{ old('sku_sequence', $productRequest->sku_sequence) }}" required maxlength="191" placeholder="{{ __('product_requests.placeholders.sku_sequence') }}">
                    @error('sku_sequence')<span class="help-block text-red">{{ $message }}</span>@enderror
                </div>
                <div class="form-group col-md-4 @error('standard_sku') has-error @enderror">
                    <label for="standard_sku">{{ __('product_requests.fields.standard_sku') }}</label>
                    <input id="standard_sku" class="form-control" name="standard_sku" value="{{ old('standard_sku', $productRequest->standard_sku) }}" maxlength="191" placeholder="{{ __('product_requests.placeholders.standard_sku') }}">
                    @error('standard_sku')<span class="help-block text-red">{{ $message }}</span>@enderror
                </div>
            </div>

            <div class="row">
                <div class="form-group col-md-4 @error('stock_status') has-error @enderror">
                    <label>{{ __('product_requests.fields.stock_status') }} <span class="text-red">*</span></label>
                    <div class="product-request-radio-list">
                        @foreach(['yes' => __('product_requests.common.yes'), 'no' => __('product_requests.common.no')] as $value => $label)
                            <label><input type="radio" name="stock_status" value="{{ $value }}" @checked(old('stock_status', $productRequest->stock_status) === $value) required> {{ $label }}</label>
                        @endforeach
                    </div>
                    @error('stock_status')<span class="help-block text-red">{{ $message }}</span>@enderror
                </div>
                <div class="form-group col-md-4 @error('minimum_order_quantity') has-error @enderror">
                    <label for="minimum_order_quantity">{{ __('product_requests.fields.minimum_order_quantity') }}</label>
                    <input id="minimum_order_quantity" class="form-control" name="minimum_order_quantity" value="{{ old('minimum_order_quantity', $productRequest->minimum_order_quantity) }}" maxlength="255" placeholder="{{ __('product_requests.placeholders.minimum_order_quantity') }}">
                    @error('minimum_order_quantity')<span class="help-block text-red">{{ $message }}</span>@enderror
                </div>
                <div class="form-group col-md-4 @error('erp_binding_status') has-error @enderror">
                    <label>{{ __('product_requests.fields.erp_binding_status') }} <span class="text-red">*</span></label>
                    <div class="product-request-radio-list">
                        @foreach(['unbound', 'bound'] as $value)
                            <label><input type="radio" name="erp_binding_status" value="{{ $value }}" @checked(old('erp_binding_status', $productRequest->erp_binding_status) === $value) required> {{ __('product_requests.erp_binding.'.$value) }}</label>
                        @endforeach
                    </div>
                    @error('erp_binding_status')<span class="help-block text-red">{{ $message }}</span>@enderror
                </div>
            </div>

            <div class="row">
                <div class="form-group col-md-6 @error('minimum_purchase_price_rmb') has-error @enderror">
                    <label for="minimum_purchase_price_rmb">{{ __('product_requests.fields.minimum_purchase_price_rmb') }} <span class="text-red">*</span></label>
                    <input id="minimum_purchase_price_rmb" class="form-control" name="minimum_purchase_price_rmb" value="{{ old('minimum_purchase_price_rmb', $productRequest->minimum_purchase_price_rmb) }}" maxlength="100" required placeholder="{{ __('product_requests.placeholders.minimum_purchase_price_rmb') }}">
                    @error('minimum_purchase_price_rmb')<span class="help-block text-red">{{ $message }}</span>@enderror
                </div>
                <div class="form-group col-md-6 @error('estimated_shipping_weight_g') has-error @enderror">
                    <label for="estimated_shipping_weight_g">{{ __('product_requests.fields.estimated_shipping_weight_g') }} <span class="text-red">*</span></label>
                    <input id="estimated_shipping_weight_g" class="form-control" name="estimated_shipping_weight_g" value="{{ old('estimated_shipping_weight_g', $productRequest->estimated_shipping_weight_g) }}" maxlength="100" required placeholder="{{ __('product_requests.placeholders.estimated_shipping_weight_g') }}">
                    @error('estimated_shipping_weight_g')<span class="help-block text-red">{{ $message }}</span>@enderror
                </div>
            </div>

            <div class="row product-request-form-bottom-row">
                <div class="form-group col-md-6">
                    <label>{{ __('product_requests.fields.erp_operator') }} <span class="text-red">*</span></label>
                    <div class="product-request-readonly">{{ $actor['display_name'] }}</div>
                    <span class="help-block">{{ __('product_requests.help.automatic_current_user') }}</span>
                </div>
                <div class="form-group col-md-6 @error('seller_images') has-error @enderror">
                    <label>{{ __('product_requests.fields.seller_images') }}</label>
                    @include('portals.product-requests.partials.upload', ['inputName' => 'seller_images', 'inputId' => 'seller_images', 'maxFiles' => 3, 'required' => false, 'existingAttachments' => $sellerImages])
                    <span class="help-block">{{ __('product_requests.help.image_upload') }} {{ __('product_requests.procurement.seller_image_hint') }}</span>
                </div>
            </div>
        </section>
    </div>

    <div class="modal-footer">
        <button class="btn btn-default" type="button" data-dismiss="modal">{{ __('product_requests.common.cancel') }}</button>
        <button class="btn btn-success" type="submit"><i class="fa fa-check"></i> {{ __('product_requests.common.submit') }}</button>
    </div>

    <div class="product-request-reuse-overlay" data-product-request-reuse-modal hidden>
        <div class="product-request-reuse-dialog">
            <div class="product-request-reuse-dialog-heading">
                <h5>{{ __('product_requests.procurement.reuse_title') }}</h5>
                <button class="close" type="button" data-product-request-reuse-close aria-label="{{ __('product_requests.common.close') }}"><span aria-hidden="true">&times;</span></button>
            </div>
            <div data-product-request-reuse-results></div>
        </div>
    </div>
</form>
