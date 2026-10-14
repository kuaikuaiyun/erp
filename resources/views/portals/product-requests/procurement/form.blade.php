@php
    $operationImages = $productRequest->attachmentsFor(\App\Models\ProductRequestAttachment::STAGE_OPERATION);
    $sellerImages = $productRequest->attachmentsFor(\App\Models\ProductRequestAttachment::STAGE_PROCUREMENT);
@endphp
<form class="product-request-form" method="post" action="{{ route('product-requests.procurement.update', $productRequest) }}" enctype="multipart/form-data" data-product-request-modal-form>
    @csrf
    @method('PUT')
    <input type="hidden" name="modal" value="1">

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
                        <label>{{ __('product_requests.fields.operation_skus') }}</label>
                        <div class="product-request-readonly">@include('portals.product-requests.partials.sku-tags', ['skus' => $productRequest->skus, 'empty' => __('product_requests.common.not_available')])</div>
                    </div>
                    <div class="form-group">
                        <label>{{ __('product_requests.fields.operation_supplier_url') }}</label>
                        <div class="product-request-readonly">@if($productRequest->operation_supplier_url)<a href="{{ $productRequest->operation_supplier_url }}" target="_blank" rel="noopener">{{ $productRequest->operation_supplier_url }}</a>@else{{ __('product_requests.common.not_available') }}@endif</div>
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
                    <label for="supplier_url">{{ __('product_requests.fields.supplier_url') }} <span class="text-red">*</span> <button class="btn btn-default btn-xs" type="button" data-sku-check>{{ __('product_requests.procurement.sku_check') }}</button></label>
                    <input id="supplier_url" class="form-control" type="text" name="supplier_url" value="{{ old('supplier_url', $productRequest->supplier_url) }}" maxlength="2048" required placeholder="{{ __('product_requests.placeholders.supplier_url') }}" data-sku-suggestions-url="{{ route('product-requests.procurement.sku-suggestions') }}" data-sku-suggestions-current-id="{{ $productRequest->getKey() }}">
                    <span class="help-block">{{ __('product_requests.procurement.sku_check_hint') }}</span>
                    <div class="sku-suggestion-panel" id="sku-suggestion-panel" hidden>
                        <div class="sku-suggestion-columns">
                            <div class="sku-suggestion-box">
                                <div class="sku-suggestion-heading"><strong>{{ __('product_requests.procurement.sku_suggestions_operation') }}</strong></div>
                                <div class="sku-suggestion-list">
                                    @forelse(collect(explode(',', (string) $productRequest->skus))->map(fn ($s) => trim($s))->filter()->all() as $sku)
                                    <label><input type="checkbox" name="selected_skus[]" value="{{ $sku }}" checked> {{ $sku }}</label>
                                    @empty
                                    <span class="sku-suggestion-empty">{{ __('product_requests.procurement.sku_suggestions_empty') }}</span>
                                    @endforelse
                                </div>
                            </div>
                            <div class="sku-suggestion-box">
                                <div class="sku-suggestion-heading"><strong>{{ __('product_requests.procurement.sku_suggestions_system') }}</strong><span class="sku-suggestion-count" id="sku-suggestion-count"></span></div>
                                <div class="sku-suggestion-list" id="sku-suggestion-list"></div>
                            </div>
                        </div>
                    </div>
                    @error('supplier_url')<span class="help-block text-red">{{ $message }}</span>@enderror
                </div>
            </div>
            <div class="row" id="sku-suggestion-summary-row" hidden>
                <div class="form-group col-md-12">
                    <span class="help-block"><i class="fa fa-info-circle"></i> {{ __('product_requests.procurement.sku_suggestions_summary') }} <span class="text-muted" id="sku-selected-summary"></span></span>
                </div>
            </div>

            <div class="row product-request-form-row-divider">
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

            <div class="row">
                <div class="form-group col-md-6 @error('outer_package_size') has-error @enderror">
                    <label for="outer_package_size">{{ __('product_requests.fields.outer_package_size') }}</label>
                    <input id="outer_package_size" class="form-control" name="outer_package_size" value="{{ old('outer_package_size', $productRequest->outer_package_size) }}" maxlength="191" placeholder="{{ __('product_requests.placeholders.outer_package_size') }}">
                    @error('outer_package_size')<span class="help-block text-red">{{ $message }}</span>@enderror
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
</form>
<style>
    .sku-suggestion-panel { margin-top: 6px; border: 1px solid #d2d6de; border-radius: 4px; background: #fbfdff; padding: 8px 10px; }
    .sku-suggestion-columns { display: flex; gap: 12px; }
    .sku-suggestion-box { flex: 1; min-width: 0; border: 1px solid #e2e8f0; border-radius: 4px; padding: 6px 8px; background: #fff; }
    .sku-suggestion-heading { display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px; font-size: 13px; color: #3c8dbc; }
    .sku-suggestion-count { font-size: 12px; color: #8899a6; }
    .sku-suggestion-list { display: flex; flex-wrap: wrap; gap: 6px 14px; max-height: 180px; overflow-y: auto; }
    .sku-suggestion-list label { display: inline-flex; align-items: center; margin: 0; font-weight: 400; font-size: 12px; color: #444; cursor: pointer; word-break: break-all; }
    .sku-suggestion-list label .sku-source { color: #8899a6; margin-left: 4px; font-size: 11px; }
    .sku-suggestion-list label input[type="checkbox"] { margin-right: 4px; }
    .sku-suggestion-empty { font-size: 12px; color: #8899a6; }
</style>

