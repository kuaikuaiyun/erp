@php
    $pickerId = 'baokuan-picker-'.$name;
    $pickerValue = (string) $selected;
    $pickerLabel = $options[$pickerValue] ?? $options[''] ?? reset($options);
@endphp
<div class="baokuan-list-picker-field">
    <span class="flt-field-label" id="{{ $pickerId }}-label">{{ $label }}</span>
    <details class="flt-picker" data-baokuan-list-picker>
        <summary id="{{ $pickerId }}-summary" aria-labelledby="{{ $pickerId }}-label {{ $pickerId }}-summary" title="{{ $pickerLabel }}">{{ $pickerLabel }}</summary>
        <div class="flt-picker-panel">
            @if($searchable ?? false)
                <input type="search" autocomplete="off" data-picker-search aria-label="{{ __('frontend_list_template.search_options') }}" placeholder="{{ __('frontend_list_template.search_options') }}">
            @endif
            <div class="flt-picker-options">
                @foreach($options as $value => $option)
                    <label data-picker-option data-picker-keywords="{{ ($keywords ?? [])[$value] ?? '' }}">
                        <input type="radio" name="{{ $name }}" value="{{ $value }}" @checked((string) $value === $pickerValue)>
                        <span>{{ $option }}</span>
                    </label>
                @endforeach
                <p data-picker-empty hidden>{{ __('frontend_list_template.no_options') }}</p>
            </div>
        </div>
    </details>
</div>
