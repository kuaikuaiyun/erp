@php
    $operationImages = $productRequest->attachmentsFor(\App\Models\ProductRequestAttachment::STAGE_OPERATION);
    $purchaseItemImages = $productRequest->attachmentsFor(\App\Models\ProductRequestAttachment::STAGE_PURCHASE_ITEM);
    $sellerImages = $productRequest->attachmentsFor(\App\Models\ProductRequestAttachment::STAGE_PROCUREMENT);
@endphp
<form class="product-request-form" method="post" action="{{ route('product-requests.procurement.update', $productRequest) }}" enctype="multipart/form-data" data-product-request-modal-form data-product-request-reuse-form data-current-id="{{ $productRequest->getKey() }}" data-reuse-check-url="{{ route('product-requests.procurement.reuse-check') }}">
    @csrf
    @method('PUT')
    <input type="hidden" name="modal" value="1">
    <input type="hidden" name="reuse_source_id" value="{{ old('reuse_source_id') }}">
    <input type="hidden" name="link_skus" value="{{ old('link_skus') }}" data-product-request-link-skus>

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
                        <label for="operation_skus_input">{{ __('product_requests.fields.operation_skus') }}</label>
                        <textarea id="operation_skus_input" class="form-control product-request-sku-edit" rows="2" maxlength="2000" data-sku-edit-source>{{ str_replace(',', "\n", (string) $productRequest->skus) }}</textarea>
                        <div class="product-request-sku-tags product-request-sku-preview" data-sku-edit-preview></div>
                        <span class="help-block">{{ __('product_requests.procurement.operation_skus_editable_hint') }}</span>
                    </div>
                    <div class="form-group">
                        <label>{{ __('product_requests.fields.operation_supplier_url') }}</label>
                        <div class="product-request-readonly">@if($productRequest->operation_supplier_url)<a href="{{ $productRequest->operation_supplier_url }}" target="_blank" rel="noopener">{{ $productRequest->operation_supplier_url }}</a>@else{{ __('product_requests.common.not_available') }}@endif</div>
                    </div>
                    <div class="form-group">
                        <label>{{ __('product_requests.fields.operation_remark') }}</label>
                        <div class="product-request-readonly">{{ $productRequest->operation_remark ?: __('product_requests.common.not_filled') }}</div>
                    </div>
                </div>
                <div class="form-group product-request-purchase-item-gallery">
                    <label>{{ __('product_requests.fields.purchase_item_images') }}</label>
                    <div class="product-request-image-grid">
                        @forelse($purchaseItemImages as $attachment)
                            <a href="{{ route('product-requests.attachments.show', [$productRequest, $attachment]) }}" data-product-request-image-preview data-product-request-image-alt="{{ $attachment->original_name }}">
                                <img src="{{ route('product-requests.attachments.show', [$productRequest, $attachment]) }}" alt="{{ $attachment->original_name }}">
                            </a>
                        @empty
                            <span class="text-muted">{{ __('product_requests.common.not_available') }}</span>
                        @endforelse
                    </div>
                </div>
            </div>
        </section>

        <section class="form-section product-request-procurement-section">
            <div class="product-request-section-head">
                <h2 class="form-section-title">{{ __('product_requests.show.procurement_information') }}</h2>
                <div class="product-request-dedupe-bar">
                    <button class="btn btn-default btn-sm" type="button" data-product-request-reuse-check><i class="fa fa-search"></i> {{ __('product_requests.procurement.dedupe_check') }}</button>
                    <span class="text-muted product-request-dedupe-hint">{{ __('product_requests.procurement.dedupe_check_hint') }}</span>
                </div>
            </div>

            <div class="row">
                <div class="form-group col-md-6 @error('standard_chinese_name') has-error @enderror">
                    <label for="standard_chinese_name">{{ __('product_requests.fields.standard_chinese_name') }} <span class="text-red">*</span></label>
                    <input id="standard_chinese_name" class="form-control" name="standard_chinese_name" value="{{ old('standard_chinese_name', $productRequest->standard_chinese_name) }}" maxlength="191" required placeholder="{{ __('product_requests.placeholders.standard_chinese_name') }}">
                    @error('standard_chinese_name')<span class="help-block text-red">{{ $message }}</span>@enderror
                    <span class="product-request-selected-reuse" data-product-request-reuse-selected hidden></span>
                </div>
                <div class="form-group col-md-6 @error('supplier_url') has-error @enderror">
                    <label for="supplier_url">{{ __('product_requests.fields.supplier_url') }} <span class="text-red">*</span></label>
                    @php($supplierUrl = old('supplier_url', $productRequest->supplier_url ?: $productRequest->operation_supplier_url))
                    <div class="product-request-readonly product-request-readonly-link">
                        @if($supplierUrl)<a href="{{ $supplierUrl }}" target="_blank" rel="noopener">{{ $supplierUrl }}</a>@else<span class="text-muted">{{ __('product_requests.common.not_available') }}</span>@endif
                    </div>
                    <input id="supplier_url" type="hidden" name="supplier_url" value="{{ $supplierUrl }}" data-sku-suggestions-url="{{ route('product-requests.procurement.sku-suggestions') }}" data-sku-suggestions-current-id="{{ $productRequest->getKey() }}">
                    <span class="help-block">{{ __('product_requests.procurement.supplier_url_readonly_hint') }}</span>
                    @error('supplier_url')<span class="help-block text-red">{{ $message }}</span>@enderror
                </div>
            </div>
            <div class="row">
                <div class="form-group col-md-12">
                    <div class="sku-suggestion-panel" id="sku-suggestion-panel" hidden>
                        <div class="sku-suggestion-columns">
                            <div class="sku-suggestion-box">
                                <div class="sku-suggestion-heading"><strong>{{ __('product_requests.procurement.sku_suggestions_operation') }}</strong></div>
                                <div class="sku-suggestion-list" id="sku-operation-list" data-operation-skus="{{ $productRequest->skus }}" data-empty-text="{{ __('product_requests.procurement.sku_suggestions_empty') }}"></div>
                            </div>
                            <div class="sku-suggestion-box">
                                <div class="sku-suggestion-heading"><strong>{{ __('product_requests.procurement.sku_suggestions_system') }}</strong><span class="sku-suggestion-count" id="sku-suggestion-count"></span></div>
                                <div class="sku-suggestion-list" id="sku-suggestion-list"></div>
                            </div>
                        </div>
                        <span class="help-block" id="sku-suggestion-summary-row" hidden><i class="fa fa-info-circle"></i> {{ __('product_requests.procurement.sku_suggestions_summary') }} <span class="text-muted" id="sku-selected-summary"></span></span>
                    </div>
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
                <div class="form-group col-md-6 @error('large_volume_weight_g') has-error @enderror">
                    <label for="large_volume_weight_g">{{ __('product_requests.fields.large_volume_weight_g') }}</label>
                    <input id="large_volume_weight_g" class="form-control" name="large_volume_weight_g" value="{{ old('large_volume_weight_g', $productRequest->large_volume_weight_g) }}" maxlength="100" placeholder="{{ __('product_requests.placeholders.large_volume_weight_g') }}">
                    @error('large_volume_weight_g')<span class="help-block text-red">{{ $message }}</span>@enderror
                </div>
            </div>

            <div class="row">
                <div class="form-group col-md-6 @error('pcl_product_no') has-error @enderror">
                    <label for="pcl_product_no">{{ __('product_requests.fields.pcl_product_no') }} <span class="text-red">*</span></label>
                    <input id="pcl_product_no" class="form-control" name="pcl_product_no" value="{{ old('pcl_product_no', $productRequest->pcl_product_no) }}" maxlength="100" required placeholder="{{ __('product_requests.placeholders.pcl_product_no') }}">
                    @error('pcl_product_no')<span class="help-block text-red">{{ $message }}</span>@enderror
                </div>
                <div class="form-group col-md-6">
                    <label>{{ __('product_requests.fields.erp_operator') }} <span class="text-red">*</span></label>
                    <div class="product-request-readonly">{{ $actor['display_name'] }}</div>
                    <span class="help-block">{{ __('product_requests.help.automatic_current_user') }}</span>
                </div>
            </div>

            <div class="row product-request-form-bottom-row">
                <div class="form-group col-md-12 @error('seller_images') has-error @enderror">
                    <label>{{ __('product_requests.fields.seller_images') }} <span class="text-red">*</span></label>
                    @include('portals.product-requests.partials.upload', ['inputName' => 'seller_images', 'inputId' => 'seller_images', 'maxFiles' => 3, 'required' => true, 'existingAttachments' => $sellerImages, 'removableName' => 'removed_seller_images'])
                    <span class="help-block">{{ __('product_requests.help.image_upload') }} {{ __('product_requests.procurement.seller_image_hint') }}</span>
                </div>
            </div>

            <div class="row">
                <div class="form-group col-md-12 @error('inspection_reject_reason') has-error @enderror">
                    <label for="inspection_reject_reason">{{ __('product_requests.procurement.inspection_reject_reason') }}</label>
                    <textarea id="inspection_reject_reason" class="form-control" name="inspection_reject_reason" rows="3" maxlength="2000" placeholder="{{ __('product_requests.procurement.inspection_reject_reason_placeholder') }}">{{ old('inspection_reject_reason', $productRequest->inspection_reject_reason) }}</textarea>
                    <span class="help-block">{{ __('product_requests.procurement.inspection_reject_reason_hint') }}</span>
                    @error('inspection_reject_reason')<span class="help-block text-red">{{ $message }}</span>@enderror
                </div>
            </div>
        </section>
    </div>

    <div class="product-request-reuse-overlay" data-product-request-reuse-modal hidden>
        <div class="product-request-reuse-dialog" role="dialog" aria-modal="true">
            <div class="product-request-reuse-dialog-heading">
                <h5>{{ __('product_requests.procurement.dedupe_title') }}</h5>
                <button type="button" class="close" data-product-request-reuse-close aria-label="{{ __('product_requests.common.close') }}"><span aria-hidden="true">&times;</span></button>
            </div>
            <div data-product-request-reuse-results></div>
        </div>
    </div>

    <div class="modal-footer">
        <button class="btn btn-default" type="button" data-dismiss="modal">{{ __('product_requests.common.cancel') }}</button>
        <button class="btn btn-danger" type="submit" name="result" value="rejected" formnovalidate><i class="fa fa-times"></i> {{ __('product_requests.procurement.inspection_reject') }}</button>
        <button class="btn btn-success" type="submit" name="result" value="passed"><i class="fa fa-check"></i> {{ __('product_requests.procurement.inspection_pass') }}</button>
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
    .sku-suggestion-list .sku-suggestion-item { display: inline-flex; align-items: center; gap: 4px; }
    .sku-suggestion-list .sku-suggestion-input { width: 150px; max-width: 100%; height: 24px; padding: 2px 6px; border: 1px solid #d2d6de; border-radius: 3px; font-size: 12px; color: #333; }
    .sku-suggestion-list .sku-suggestion-input:focus { border-color: #3c8dbc; outline: none; }
    /* 运营填写的 SKU：一行一个竖排，输入框撑满整行保证内容完整可见 */
    #sku-operation-list { flex-direction: column; flex-wrap: nowrap; align-items: stretch; gap: 6px; }
    #sku-operation-list .sku-suggestion-item { display: flex; width: 100%; }
    #sku-operation-list .sku-suggestion-input { flex: 1 1 auto; width: auto; }
</style>

