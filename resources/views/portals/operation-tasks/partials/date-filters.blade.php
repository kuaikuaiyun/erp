@php
    $dateFromName = $dateFromName ?? 'from';
    $dateToName = $dateToName ?? 'to';
    $dateFrom = $dateFrom ?? request($dateFromName, '');
    $dateTo = $dateTo ?? request($dateToName, '');
@endphp
<div class="ot-date-field"><label>{{ __('operation_tasks.dates.from') }}</label><input class="form-control" type="date" name="{{ $dateFromName }}" value="{{ $dateFrom }}"></div>
<div class="ot-date-field"><label>{{ __('operation_tasks.dates.to') }}</label><input class="form-control" type="date" name="{{ $dateToName }}" value="{{ $dateTo }}"></div>
<div class="ot-date-shortcuts" data-date-shortcuts data-from-name="{{ $dateFromName }}" data-to-name="{{ $dateToName }}">
    <span>{{ __('operation_tasks.dates.quick') }}</span>
    @foreach(['today', 'yesterday', 'week', 'all'] as $range)
        <button type="button" class="btn btn-default btn-sm" data-date-range="{{ $range }}">{{ __('operation_tasks.dates.'.$range) }}</button>
    @endforeach
</div>
