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
                <div class="form-group col-md-4 @error('skus') has-error @enderror"><label for="skus">{{ __('product_requests.fields.skus') }} <span class="text-red">*</span></label>
                    <textarea id="skus" name="skus" class="form-control" rows="6" placeholder="{{ __('product_requests.placeholders.skus') }}">{{ old('skus') }}</textarea>
                    <span class="help-block"><span id="sku-count" class="text-muted">0</span> / 50 · {{ __('product_requests.help.skus') }}</span>
                    <span class="help-block text-yellow" id="sku-warn" style="display:none;"></span>
                    @error('skus')<span class="help-block text-red">{{ $message }}</span>@enderror
                </div>
                <div class="form-group col-md-4 @error('operation_supplier_url') has-error @enderror"><label for="operation_supplier_url">{{ __('product_requests.fields.operation_supplier_url') }} <span class="text-red">*</span></label><input id="operation_supplier_url" type="url" class="form-control" name="operation_supplier_url" value="{{ old('operation_supplier_url') }}" maxlength="2048" required placeholder="{{ __('product_requests.placeholders.operation_supplier_url') }}">@error('operation_supplier_url')<span class="help-block text-red">{{ $message }}</span>@enderror</div>
                <div class="form-group col-md-4 @error('has_stock') has-error @enderror"><label for="has_stock">{{ __('product_requests.fields.has_stock') }} <span class="text-red">*</span></label><select id="has_stock" class="form-control" name="has_stock" required><option value="">{{ __('product_requests.common.please_select') }}</option><option value="yes" @selected(old('has_stock') === 'yes')>{{ __('product_requests.common.yes') }}</option><option value="no" @selected(old('has_stock') === 'no')>{{ __('product_requests.common.no') }}</option></select>@error('has_stock')<span class="help-block text-red">{{ $message }}</span>@enderror</div>
            </div>
            <div class="form-group @error('product_image') has-error @enderror"><label>{{ __('product_requests.fields.product_image') }} <span class="text-red">*</span></label>@include('portals.product-requests.partials.upload', ['inputName' => 'product_image', 'inputId' => 'product_image', 'maxFiles' => 1, 'required' => true, 'existingAttachments' => collect()])<span class="help-block">{{ __('product_requests.help.image_upload') }} {{ __('product_requests.help.one_image_only') }}</span></div>
        </section>
    </div>
    <div class="modal-footer"><button class="btn btn-default" type="button" data-dismiss="modal">{{ __('product_requests.common.cancel') }}</button><button class="btn btn-success" type="submit"><i class="fa fa-check"></i> {{ __('product_requests.common.submit') }}</button></div>
</form>
<script>
(function () {
    var textarea = document.getElementById('skus');
    var counter = document.getElementById('sku-count');
    var warn = document.getElementById('sku-warn');
    var MAX = 50;

    function parse() {
        return String(textarea.value).split(/[\n,，;；\t]+/).map(function (s) { return s.trim(); }).filter(function (s) { return s !== ''; });
    }

    function update() {
        var skus = parse();
        var unique = skus.filter(function (v, i) { return skus.indexOf(v) === i; });
        counter.textContent = String(unique.length);
        counter.className = unique.length > MAX ? 'text-red' : 'text-muted';
        var msgs = [];
        if (unique.length !== skus.length) { msgs.push('存在重复 SKU，提交时自动去重'); }
        warn.textContent = msgs.join('；');
        warn.style.display = msgs.length ? '' : 'none';
    }

    textarea.addEventListener('input', update);
    textarea.addEventListener('blur', function () {
        var unique = parse().filter(function (v, i, a) { return a.indexOf(v) === i; });
        textarea.value = unique.join('\n');
        update();
    });
    update();
})();
</script>
