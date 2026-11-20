@php
    $emptyValue = \App\Portals\Finance\FinanceFilterSelection::EMPTY;
    $choices = collect($choices)->keyBy('value');
    foreach ($selected as $value) {
        if ($value !== $emptyValue && ! $choices->has($value)) $choices->put($value, ['value' => $value, 'label' => $value]);
    }
    $choices->prepend(['value' => $emptyValue, 'label' => '空值'], $emptyValue);
    $selectedLabels = $choices->filter(fn ($choice) => in_array($choice['value'], $selected, true))->pluck('label')->all();
@endphp
<div class="{{ $wrapperClass ?? '' }}" @isset($wrapperStyle) style="{{ $wrapperStyle }}" @endisset>
    <label id="{{ $id }}-label">{{ $label }}</label>
    <details class="finance-filter-picker" data-finance-filter-picker data-placeholder="{{ $placeholder }}" @isset($optionsUrl) data-options-url="{{ $optionsUrl }}" @endisset>
        <summary id="{{ $id }}" class="form-control" aria-labelledby="{{ $id }}-label" data-filter-caption>{{ $selectedLabels ? implode('、', $selectedLabels) : $placeholder }}</summary>
        <div class="finance-filter-picker-popup">
            <button type="button" class="btn btn-default btn-xs" data-filter-clear>清除选择（{{ $placeholder }}）</button>
            <div data-filter-choices data-name="{{ $name }}">
                @foreach($choices as $choice)
                <label><input type="checkbox" name="{{ $name }}[]" value="{{ $choice['value'] }}" data-filter-label="{{ $choice['label'] }}" @checked(in_array($choice['value'], $selected, true))> <span>{{ $choice['label'] }}</span></label>
                @endforeach
            </div>
        </div>
    </details>
</div>
@once
@push('styles')
<style>
.finance-filter-picker{position:relative;min-width:0}.finance-filter-picker>summary{cursor:pointer;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;list-style:none}.finance-filter-picker>summary:after{content:'▾';float:right;margin-left:8px}.finance-filter-picker-popup{position:absolute;left:0;right:0;top:calc(100% + 4px);min-width:210px;max-height:310px;overflow:auto;z-index:50;background:#fff;padding:10px;border:1px solid #cadbe8;border-radius:5px;box-shadow:0 4px 14px #183e5d25}.finance-filter-picker-popup [data-filter-choices]>label{display:flex;align-items:center;gap:8px;padding:7px 3px;margin:0;text-align:left;font-weight:400;cursor:pointer}.finance-filter-picker-popup input[type=checkbox]{width:auto;height:auto;margin:0}.finance-filter-picker-popup .btn{margin-bottom:7px}
</style>
@endpush
@push('scripts')<script src="{{ asset('assets/erp/finance-filter-multiselect.js') }}?v=1"></script>@endpush
@endonce
