@php
    $title = $product->exists ? __('aliexpress.actions.edit') : __('aliexpress.actions.create');
    $action = $product->exists ? route('aliexpress.selection.update', $product) : route('aliexpress.selection.store');
    $existing = $product->skus->keyBy('id');
    $rows = $product->skus->map(fn ($sku) => $sku->only(['id', 'supplier_sku', 'is_enabled']))->all() ?: [['is_enabled' => '1']];
@endphp

<form class="ae-editor" method="post" action="{{ $action }}" data-loading="off"
    data-ae-editor data-ae-title="{{ $title }}" data-prefix="{{ $prefix }}" data-preview-empty="{{ __('aliexpress.hints.preview') }}"
    data-lookup-url="{{ route('aliexpress.selection.lookup') }}" data-product-id="{{ $product->id }}"
    data-lookup-checking="{{ __('aliexpress.messages.checking') }}" data-lookup-found="{{ __('aliexpress.messages.lookup_found') }}"
    data-lookup-clear="{{ __('aliexpress.messages.lookup_clear') }}" data-lookup-current="{{ __('aliexpress.messages.lookup_current') }}"
    data-lookup-failed="{{ __('aliexpress.messages.lookup_failed') }}" data-max-rows="{{ __('aliexpress.messages.max_rows') }}">
    @csrf
    @if ($product->exists) @method('PUT') @endif
    <div class="flt-dialog-content">
        <div class="alert alert-danger" role="alert" tabindex="-1" data-ae-form-errors hidden></div>
        <div class="flt-filter-grid">
            <label>{{ __('aliexpress.fields.name') }}<input type="text" name="name" value="{{ $product->name }}" required maxlength="191"></label>
            <div>
                <label>{{ __('aliexpress.fields.supplier_url') }}<input type="url" name="supplier_url" value="{{ $product->supplier_url }}" required maxlength="2048" placeholder="{{ __('aliexpress.hints.link') }}"></label>
                <div class="ae-link-actions">
                    <button type="button" class="flt-btn" data-ae-lookup><i class="fa fa-search" aria-hidden="true"></i> {{ __('aliexpress.actions.lookup') }}</button>
                    <span role="status" aria-live="polite" data-ae-lookup-status></span>
                    <button type="button" class="flt-btn" data-ae-existing data-ae-title="{{ __('aliexpress.actions.edit') }}" hidden>{{ __('aliexpress.actions.open_existing') }}</button>
                </div>
                @if ($product->exists)<small class="ae-field-note">{{ __('aliexpress.hints.change_source') }}</small>@endif
            </div>
            <label>{{ __('aliexpress.fields.remark') }}<textarea name="remark" maxlength="2000">{{ $product->remark }}</textarea></label>
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

    </div>
    <footer>
        <button type="button" class="flt-btn" data-ae-close>{{ __('frontend_list_template.cancel') }}</button>
        <button type="submit" class="flt-btn flt-primary"><i class="fa fa-check" aria-hidden="true"></i> {{ __('aliexpress.actions.save') }}</button>
    </footer>
</form>
