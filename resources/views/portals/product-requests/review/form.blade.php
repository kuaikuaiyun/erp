<form class="product-request-form" method="post" action="{{ route('product-requests.placement-reviews.update', $productRequest) }}" data-product-request-modal-form>
    @csrf @method('PUT')
    <input type="hidden" name="modal" value="1">
    <div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="{{ __('product_requests.common.close') }}"><span aria-hidden="true">&times;</span></button><h4 class="modal-title">{{ __('product_requests.review.review_form_title') }}</h4><p class="product-request-modal-subtitle">{{ __('product_requests.review.subtitle') }}</p></div>
    <div class="modal-body product-request-modal-body">
        <div class="row"><div class="form-group col-md-6"><label>{{ __('product_requests.fields.report_no') }}</label><div class="product-request-readonly">{{ $productRequest->report_no }}</div></div><div class="form-group col-md-6"><label>{{ __('product_requests.fields.operation') }}</label><div class="product-request-readonly">{{ $productRequest->operation_display_name }}</div></div></div>
        <div class="form-group"><label>{{ __('product_requests.fields.placement_link') }}</label><div class="product-request-readonly"><a href="{{ $pendingReview->placement_link }}" target="_blank" rel="noopener">{{ $pendingReview->placement_link }}</a></div></div>
        <div class="form-group @error('remark') has-error @enderror">
            <label for="review_remark">{{ __('product_requests.fields.review_remark') }}</label>
            <div class="product-request-rich-editor" data-product-request-editor data-upload-url="{{ route('product-requests.placement-reviews.images.store', $productRequest) }}">
                <div class="product-request-rich-toolbar">
                    <button type="button" class="product-request-rich-button" data-editor-command="bold" title="{{ __('product_requests.editor.bold') }}"><i class="fa fa-bold"></i></button>
                    <button type="button" class="product-request-rich-button" data-editor-command="italic" title="{{ __('product_requests.editor.italic') }}"><i class="fa fa-italic"></i></button>
                    <button type="button" class="product-request-rich-button" data-editor-command="underline" title="{{ __('product_requests.editor.underline') }}"><i class="fa fa-underline"></i></button>
                    <span class="product-request-rich-sep"></span>
                    <button type="button" class="product-request-rich-button" data-editor-command="insertUnorderedList" title="{{ __('product_requests.editor.bullet_list') }}"><i class="fa fa-list-ul"></i></button>
                    <button type="button" class="product-request-rich-button" data-editor-command="insertOrderedList" title="{{ __('product_requests.editor.number_list') }}"><i class="fa fa-list-ol"></i></button>
                    <span class="product-request-rich-sep"></span>
                    <button type="button" class="product-request-rich-button" data-editor-command="insertImage" title="{{ __('product_requests.editor.image') }}"><i class="fa fa-image"></i></button>
                    <button type="button" class="product-request-rich-button" data-editor-command="removeFormat" title="{{ __('product_requests.editor.clear_format') }}"><i class="fa fa-eraser"></i></button>
                </div>
                <div id="review_remark" class="product-request-rich-content" contenteditable="true" data-editor-content data-placeholder="{{ __('product_requests.placeholders.review_remark') }}"></div>
                <input type="file" class="product-request-rich-file" accept="image/jpeg,image/png,image/webp" data-editor-image-input hidden>
            </div>
            <input type="hidden" name="remark" data-editor-input value="{{ old('remark', $pendingReview->remark) }}">
            <span class="help-block">{{ __('product_requests.review.remark_image_hint') }}</span>
            @error('remark')<span class="help-block text-red">{{ $message }}</span>@enderror
        </div>
    </div>
    <div class="modal-footer"><button class="btn btn-default" type="button" data-dismiss="modal">{{ __('product_requests.common.cancel') }}</button><button class="btn btn-danger" type="submit" name="result" value="rejected">{{ __('product_requests.review.reject') }}</button><button class="btn btn-success" type="submit" name="result" value="approved">{{ __('product_requests.review.pass') }}</button></div>
</form>
