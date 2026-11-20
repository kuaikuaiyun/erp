@extends('portal.layout')

@section('page_title', __('logistics.orders.manual.title'))

@php
    $prefix = 'logistics.orders.manual';
    $fields = __($prefix.'.fields');
    $groups = [
        'basic' => ['assistant_domain_store_id','shopify_order_id','shopify_order_number','order_created_at','operator_name'],
        'customer' => ['buyer_name','buyer_email','buyer_phone','destination'],
        'payment' => ['payment_method','payment_gateway','paid_amount_usd','paid_at','total_shipping_usd','collection_account','collection_account_email','transaction_id','bill_number','payment_gateway_order_id','is_add_on','subscription_kind','renewal_number','subscription_origin_order_id'],
        'shipping' => ['shipping_company','tracking_number'],
    ];
    $required = ['assistant_domain_store_id','shopify_order_id','shopify_order_number','order_created_at','payment_method','payment_gateway','paid_amount_usd','paid_at','is_add_on','subscription_kind'];
    $money = ['paid_amount_usd','total_shipping_usd'];
    $dates = ['order_created_at','paid_at'];
    $rows = old('items', [['shopify_sku'=>'','product_title'=>'','quantity'=>1,'unit_price_usd'=>'']]);
    $rows = is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    if ($rows === []) $rows = [['shopify_sku'=>'','product_title'=>'','quantity'=>1,'unit_price_usd'=>'']];
@endphp

@push('styles')
<link rel="stylesheet" href="{{ rtrim(request()->getSchemeAndHttpHost(), '/') }}/assets/erp/logistics.css?v=20261009-10">
<link rel="stylesheet" href="{{ rtrim(request()->getSchemeAndHttpHost(), '/') }}/vendor/laravel-admin/AdminLTE/plugins/select2/select2.min.css">
@endpush

@section('content')
<section class="content-header"><h1>{{ __($prefix.'.title') }}</h1></section>
<section class="content logistics-page">
    <div class="alert alert-info"><p>{{ __($prefix.'.intro') }}</p><p>{{ __($prefix.'.time_hint') }}</p><p>{{ __($prefix.'.id_hint') }}</p></div>
    @if($errors->any())<div class="alert alert-danger"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <form id="logistics-manual-order" method="post" action="{{ route('logistics.orders.store') }}">
        @csrf
        @foreach($groups as $group=>$names)
            <div class="box box-solid logistics-form-box">
                <div class="box-header with-border"><h3 class="box-title">{{ __($prefix.'.groups.'.$group) }}</h3></div>
                <div class="box-body"><div class="row">
                    @foreach($names as $field)
                        <div class="col-sm-6 col-lg-4 form-group" @if(in_array($field,['renewal_number','subscription_origin_order_id'],true)) data-manual-renewal-field @endif>
                            <label for="manual-{{ $field }}">{{ $fields[$field] }} @if(in_array($field,$required,true)) * @endif</label>
                            @if($field === 'assistant_domain_store_id')
                                <select id="manual-{{ $field }}" class="form-control" name="{{ $field }}" required><option value="">{{ __($prefix.'.choose_store') }}</option>@foreach($stores as $store)<option value="{{ $store->id }}" @selected((string)old($field)===(string)$store->id)>{{ $store->shop_name }}</option>@endforeach</select>
                            @elseif($field === 'subscription_kind')
                                <select id="manual-{{ $field }}" class="form-control" name="{{ $field }}" required>@foreach(__($prefix.'.subscription_options') as $key=>$label)<option value="{{ $key }}" @selected(old($field,'none')===$key)>{{ $label }}</option>@endforeach</select>
                            @elseif($field === 'is_add_on')
                                <select id="manual-{{ $field }}" class="form-control" name="{{ $field }}" required>@foreach(['no','yes'] as $key)<option value="{{ $key }}" @selected(old($field,'no')===$key)>{{ __($prefix.'.'.$key) }}</option>@endforeach</select>
                            @else
                                <input id="manual-{{ $field }}" class="form-control" name="{{ $field }}" value="{{ old($field) }}"
                                    type="{{ in_array($field,$dates,true) ? 'datetime-local' : (in_array($field,$money,true) || $field === 'renewal_number' ? 'number' : (in_array($field,['buyer_email','collection_account_email'],true) ? 'email' : 'text')) }}"
                                    @if(in_array($field,$dates,true)) step="1" @endif
                                    @if(in_array($field,$money,true)) min="0" step="0.0001" data-manual-money @endif
                                    @if($field === 'renewal_number') min="1" max="9999" step="1" data-manual-integer @endif
                                    @if(in_array($field,['shopify_order_id','subscription_origin_order_id'],true)) pattern="[1-9][0-9]{0,99}" maxlength="100" inputmode="numeric"
                                    @else maxlength="{{ $field === 'buyer_phone' || $field === 'operator_name' || $field === 'shopify_order_number' ? 100 : (in_array($field,['collection_account','transaction_id','bill_number','payment_gateway_order_id','tracking_number','destination'],true) ? 255 : 191) }}" @endif
                                    @required(in_array($field,$required,true))>
                            @endif
                            @error($field)<span class="text-danger">{{ $message }}</span>@enderror
                        </div>
                    @endforeach
                </div>
                @if($group === 'payment')<p class="help-block">{{ __($prefix.'.paid_only') }}</p><p class="help-block">{{ __($prefix.'.origin_hint') }}</p>@endif
                </div>
            </div>
        @endforeach
        <div class="box box-solid logistics-form-box">
            <div class="box-header with-border"><h3 class="box-title">{{ __($prefix.'.groups.items') }}</h3></div>
            <div class="box-body">
                <p class="help-block">{{ __($prefix.'.item_hint') }}</p>
                <div class="table-responsive"><table class="table table-bordered"><thead><tr>
                    @foreach(['shopify_sku','product_title','quantity','unit_price_usd'] as $field)<th>{{ $fields['items.*.'.$field] }} *</th>@endforeach<th></th>
                </tr></thead><tbody id="manual-order-items">
                    @foreach($rows as $index=>$row)
                        <tr data-manual-item>
                            @foreach(['shopify_sku','product_title','quantity','unit_price_usd'] as $field)
                                <td><input class="form-control" name="items[{{ $index }}][{{ $field }}]" value="{{ $row[$field] ?? '' }}" required
                                    @if(in_array($field,['quantity','unit_price_usd'],true)) type="number" min="{{ $field === 'quantity' ? 1 : 0 }}" step="{{ $field === 'quantity' ? 1 : '0.0001' }}" @if($field === 'quantity') max="1000000" data-manual-integer @else data-manual-money @endif
                                    @else type="text" maxlength="{{ $field === 'shopify_sku' ? 191 : 255 }}" @endif></td>
                            @endforeach
                            <td><button type="button" class="btn btn-default" data-remove-manual-item>{{ __($prefix.'.remove_item') }}</button></td>
                        </tr>
                    @endforeach
                </tbody></table></div>
                <button type="button" class="btn btn-default" id="add-manual-item">{{ __($prefix.'.add_item') }}</button>
                <p class="text-danger" id="manual-item-error" role="alert" hidden>{{ __($prefix.'.item_count_error') }}</p>
            </div>
        </div>
        <div class="logistics-form-actions"><a class="btn btn-default" href="{{ route('logistics.orders.index') }}">{{ __($prefix.'.cancel') }}</a><button class="btn btn-success" id="save-manual-order" type="submit">{{ __($prefix.'.save') }}</button></div>
    </form>
</section>
@endsection

@push('scripts')
<script src="{{ rtrim(request()->getSchemeAndHttpHost(), '/') }}/vendor/laravel-admin/AdminLTE/plugins/select2/select2.full.min.js"></script>
<script>
(function () {
    var form = document.getElementById('logistics-manual-order');
    var list = document.getElementById('manual-order-items');
    var add = document.getElementById('add-manual-item');
    var error = document.getElementById('manual-item-error');
    var kind = document.getElementById('manual-subscription_kind');
    var copy = @json(__('logistics.orders.manual'));
    var store = form.elements.assistant_domain_store_id;
    if (window.jQuery && window.jQuery.fn.select2) {
        var picker = window.jQuery(store);
        picker.select2({
            width: '100%', placeholder: copy.choose_store, minimumResultsForSearch: 0,
            language: { noResults: function () { return copy.store_no_results; }, searching: function () { return copy.store_searching; } }
        });
        var storeError = document.createElement('span');
        storeError.className = 'text-danger'; storeError.textContent = copy.required_error; storeError.hidden = true;
        store.parentElement.appendChild(storeError);
        picker.on('select2:open', function () {
            window.jQuery('.select2-container--open .select2-search__field').attr('placeholder', copy.store_search_placeholder);
        });
        picker.on('change', function () { storeError.hidden = !!store.value; });
        store.addEventListener('invalid', function (event) {
            event.preventDefault(); storeError.hidden = false; picker.select2('open');
        });
    }
    var next = Math.max.apply(null, Array.from(list.querySelectorAll('input')).map(function (input) { return Number((input.name.match(/^items\[(\d+)\]/) || [0,0])[1]); })) + 1;
    var template = list.querySelector('[data-manual-item]').cloneNode(true);
    var saving = false;
    function counts() {
        var rows = list.querySelectorAll('[data-manual-item]');
        add.disabled = rows.length >= 100;
        rows.forEach(function (row) { row.querySelector('[data-remove-manual-item]').disabled = rows.length <= 1; });
    }
    function subscription() {
        form.querySelectorAll('[data-manual-renewal-field]').forEach(function (group) {
            group.hidden = kind.value !== 'renewal';
            var input = group.querySelector('input'); input.required = !group.hidden; input.disabled = group.hidden;
        });
    }
    function validate(input) {
        if (input.disabled) return;
        input.setCustomValidity('');
        if (input.required && !input.value.trim()) input.setCustomValidity(copy.required_error);
        else if (input.value && input.hasAttribute('data-manual-money') && !/^\d{1,14}(?:\.\d{1,4})?$/.test(input.value)) input.setCustomValidity(copy.money_error);
        else if (input.value && input.hasAttribute('data-manual-integer') && !/^[1-9]\d*$/.test(input.value)) input.setCustomValidity(copy.integer_error);
    }
    add.addEventListener('click', function () {
        if (list.children.length >= 100) return;
        var row = template.cloneNode(true);
        row.querySelectorAll('input').forEach(function (input) { input.name = input.name.replace(/^items\[\d+\]/, 'items['+next+']'); input.value = input.name.endsWith('[quantity]') ? '1' : ''; input.setCustomValidity(''); });
        next++; list.appendChild(row); counts(); error.hidden = true;
    });
    list.addEventListener('click', function (event) {
        var button = event.target.closest('[data-remove-manual-item]');
        if (button && list.children.length > 1) button.closest('[data-manual-item]').remove();
        counts();
    });
    kind.addEventListener('change', subscription);
    form.addEventListener('input', function (event) { if (event.target.tagName === 'INPUT') validate(event.target); });
    form.addEventListener('submit', function (event) {
        if (saving) { event.preventDefault(); return; }
        if (list.children.length < 1 || list.children.length > 100) { event.preventDefault(); error.hidden = false; return; }
        form.querySelectorAll('input').forEach(validate);
        if (!form.reportValidity()) { event.preventDefault(); return; }
        saving = true; var button = document.getElementById('save-manual-order'); button.disabled = true; button.textContent = copy.saving;
    });
    subscription(); counts();
}());
</script>
@endpush
