@extends('portal.layout')

@php
    $title = $product->exists ? __('aliexpress.actions.edit') : __('aliexpress.actions.create');
    $action = $product->exists ? route('aliexpress.selection.update', $product) : route('aliexpress.selection.store');
    $existing = $product->skus->keyBy('id');
    $rows = old('rows', $product->skus->map(fn ($sku) => $sku->only(['id', 'supplier_sku', 'is_enabled']))->all());
    $rows = is_array($rows) && $rows !== [] ? $rows : [['is_enabled' => '1']];
@endphp
@section('page_title', $title)

@push('styles')
    <link rel="stylesheet" href="{{ request()->getSchemeAndHttpHost() }}/css/frontend-list-template.css?v=20261125-1">
    <style>
        .ae-editor { max-width: 1150px; }
        .ae-editor .ae-link-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin-top: 8px; }
        .ae-editor .ae-specification { margin: 12px 0; padding: 16px; border: 1px solid #e1eaf1; border-radius: 8px; }
        .ae-editor .ae-row-heading { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 12px; }
        .ae-editor .ae-preview { overflow-wrap: anywhere; color: #2188be; font-weight: 600; }
        .ae-editor .ae-field-error { display: block; margin-top: 6px; color: #b84137; }
        .ae-editor textarea { width: 100%; min-height: 74px; padding: 8px 11px; border: 1px solid #ccdae6; border-radius: 5px; margin-top: 8px; resize: vertical; }
        .ae-editor .ae-field-note { display: block; color: #71899d; margin: 8px 0; }
    </style>
@endpush

@section('content')
<section class="content flt ae-editor" data-aliexpress>
    <h2 class="page-header">{{ $title }}</h2>
    <form class="flt-card flt-filters" method="post" action="{{ $action }}" data-loading="region" data-loading-target=".ae-editor"
        data-ae-editor data-prefix="{{ $prefix }}" data-preview-empty="{{ __('aliexpress.hints.preview') }}"
        data-lookup-url="{{ route('aliexpress.selection.lookup') }}" data-product-id="{{ $product->id }}"
        data-lookup-checking="{{ __('aliexpress.messages.checking') }}" data-lookup-found="{{ __('aliexpress.messages.lookup_found') }}"
        data-lookup-clear="{{ __('aliexpress.messages.lookup_clear') }}" data-lookup-current="{{ __('aliexpress.messages.lookup_current') }}"
        data-lookup-failed="{{ __('aliexpress.messages.lookup_failed') }}" data-max-rows="{{ __('aliexpress.messages.max_rows') }}">
        @csrf
        @if ($product->exists) @method('PUT') @endif
        @if ($errors->any())
            <div class="alert alert-danger" role="alert">
                <ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </div>
        @endif
        <div class="flt-filter-grid">
            <label>{{ __('aliexpress.fields.name') }}<input type="text" name="name" value="{{ old('name', $product->name) }}" required maxlength="191"></label>
            <div>
                <label>{{ __('aliexpress.fields.supplier_url') }}<input type="url" name="supplier_url" value="{{ old('supplier_url', $product->supplier_url) }}" required maxlength="2048" placeholder="{{ __('aliexpress.hints.link') }}"></label>
                <div class="ae-link-actions">
                    <button type="button" class="flt-btn" data-ae-lookup><i class="fa fa-search" aria-hidden="true"></i> {{ __('aliexpress.actions.lookup') }}</button>
                    <span role="status" aria-live="polite" data-ae-lookup-status></span>
                    <a class="flt-btn" data-ae-existing hidden>{{ __('aliexpress.actions.open_existing') }}</a>
                </div>
                @if ($product->exists)<small class="ae-field-note">{{ __('aliexpress.hints.change_source') }}</small>@endif
            </div>
            <label>{{ __('aliexpress.fields.remark') }}<textarea name="remark" maxlength="2000">{{ old('remark', $product->remark) }}</textarea></label>
        </div>
        <p class="ae-field-note">{{ __('aliexpress.hints.specification') }}</p>

        <div data-ae-rows>
            @foreach (array_values($rows) + ['__index__' => []] as $index => $row)
                @php
                    $prototype = $index === '__index__';
                    $row = is_array($row) ? $row : [];
                    $stored = $existing->get($row['id'] ?? null);
                    $field = 'rows['.$index.']';
                @endphp
                @if ($prototype)<template data-ae-template>@endif
                <fieldset class="ae-specification" data-ae-row>
                    <div class="ae-row-heading">
                        <span class="ae-preview" @unless($stored) data-ae-preview @endunless>{{ $stored?->sku ?? __('aliexpress.hints.preview') }}</span>
                        @if ($stored)
                            <input type="hidden" name="{{ $field }}[id]" value="{{ $stored->id }}">
                        @else
                            <button type="button" class="flt-btn flt-danger-outline" data-ae-remove>{{ __('aliexpress.actions.remove_row') }}</button>
                        @endif
                    </div>
                    <div class="flt-filter-grid">
                        @unless ($stored)
                            @foreach (['pinyin' => 48, 'attribute' => 48, 'package_label' => 32] as $segment => $length)
                                <label>{{ __('aliexpress.fields.'.$segment) }}
                                    <input type="text" data-ae-part="{{ $segment }}" name="{{ $field }}[{{ $segment }}]" maxlength="{{ $length }}" required
                                        value="{{ $row[$segment] ?? ($segment === 'package_label' ? __('aliexpress.hints.default_package') : '') }}">
                                </label>
                            @endforeach
                        @endunless
                        <label>{{ __('aliexpress.fields.supplier_sku') }}
                            <input type="text" name="{{ $field }}[supplier_sku]" maxlength="191" value="{{ $row['supplier_sku'] ?? '' }}" required>
                        </label>
                        <label>{{ __('aliexpress.fields.status') }}
                            <select name="{{ $field }}[is_enabled]">
                                <option value="1" @selected((bool) ($row['is_enabled'] ?? true))>{{ __('aliexpress.status.enabled') }}</option>
                                <option value="0" @selected(!(bool) ($row['is_enabled'] ?? true))>{{ __('aliexpress.status.disabled') }}</option>
                            </select>
                        </label>
                    </div>
                </fieldset>
                @if ($prototype)</template>@endif
            @endforeach
        </div>
        <button type="button" class="flt-btn" data-ae-add><i class="fa fa-plus" aria-hidden="true"></i> {{ __('aliexpress.actions.add_row') }}</button>
        <span class="ae-field-error" role="status" data-ae-row-status></span>

        <div class="flt-filter-actions">
            <a class="flt-btn" href="{{ route('aliexpress.selection.index') }}">{{ __('aliexpress.actions.back') }}</a>
            <button type="submit" class="flt-btn flt-primary"><i class="fa fa-check" aria-hidden="true"></i> {{ __('aliexpress.actions.save') }}</button>
        </div>
    </form>
</section>
@endsection

@push('scripts')
    <script src="{{ request()->getSchemeAndHttpHost() }}/js/aliexpress-selection.js?v=1"></script>
@endpush
