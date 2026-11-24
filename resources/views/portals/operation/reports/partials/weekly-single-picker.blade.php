@php
    $pickerId = 'weekly-picker-'.preg_replace('/[^a-zA-Z0-9_-]/', '-', $name);
    $selectedValue = (string) $selected;
    $selectedLabel = $options[$selectedValue] ?? reset($options);
@endphp
<div class="weekly-single-field">
    <span class="flt-field-label" id="{{ $pickerId }}-label">{{ $label }}</span>
    <details class="flt-picker weekly-single-picker" data-weekly-single-picker @if($autoSubmit ?? false)data-auto-submit="1"@endif @if($departmentPicker ?? false)data-department-picker="1"@endif>
        <summary aria-labelledby="{{ $pickerId }}-label" data-picker-summary>{{ $selectedLabel }}</summary>
        <div class="flt-picker-panel"><div class="flt-picker-options">
            @foreach($options as $optionValue => $optionLabel)
                <label><input type="radio" name="{{ $name }}" value="{{ $optionValue }}" @checked($selectedValue === (string)$optionValue)><span>{{ $optionLabel }}</span></label>
            @endforeach
        </div></div>
    </details>
</div>
