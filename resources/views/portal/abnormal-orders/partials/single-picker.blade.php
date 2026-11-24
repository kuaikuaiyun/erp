@php
    $pickerId = 'abnormal-order-picker-'.preg_replace('/[^a-zA-Z0-9_-]/', '-', $name);
    $selectedValue = (string) $selected;
    $selectedLabel = $options[$selectedValue] ?? reset($options);
@endphp
<div class="abnormal-order-picker-field">
    <span class="flt-field-label" id="{{ $pickerId }}-label">{{ $label }}</span>
    <details class="flt-picker abnormal-order-picker" data-abnormal-order-picker>
        <summary aria-labelledby="{{ $pickerId }}-label" data-picker-summary>{{ $selectedLabel }}</summary>
        <div class="flt-picker-panel">
            <input type="search" class="abnormal-order-picker-search" placeholder="搜索选项" autocomplete="off" data-picker-search>
            <div class="flt-picker-options">
                @foreach($options as $optionValue => $optionLabel)
                    <label data-picker-option><input type="radio" name="{{ $name }}" value="{{ $optionValue }}" @checked($selectedValue === (string) $optionValue)><span>{{ $optionLabel }}</span></label>
                @endforeach
                <p data-picker-empty hidden>没有匹配选项</p>
            </div>
        </div>
    </details>
</div>
