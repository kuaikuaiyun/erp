@php
    $selectedValue = (string) $selected;
    $selectedLabel = $options[$selectedValue] ?? reset($options);
@endphp
<div class="advertising-operator-field">
    <span class="flt-field-label" id="advertising-operator-label">{{ $label }}</span>
    <details class="flt-picker advertising-operator-picker" data-advertising-operator-picker>
        <summary aria-labelledby="advertising-operator-label" data-picker-summary>{{ $selectedLabel }}</summary>
        <div class="flt-picker-panel">
            <input type="search" class="advertising-operator-search" placeholder="搜索运营人员" autocomplete="off" data-picker-search>
            <div class="flt-picker-options">
                @foreach($options as $optionValue => $optionLabel)
                    <label data-picker-option><input type="radio" name="operator_user_id" value="{{ $optionValue }}" @checked($selectedValue === (string) $optionValue)><span>{{ $optionLabel }}</span></label>
                @endforeach
                <p data-picker-empty hidden>没有匹配人员</p>
            </div>
        </div>
    </details>
</div>
