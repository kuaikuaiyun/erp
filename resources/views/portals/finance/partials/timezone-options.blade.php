@foreach([
    '常用时区' => \App\Portals\Finance\FinanceWorkbookService::NAMED_TIMEZONES,
    '固定 UTC 偏移' => \App\Portals\Finance\FinanceWorkbookService::FIXED_TIMEZONES,
] as $group => $zones)
    <optgroup label="{{ $group }}">
        @foreach($zones as $zone)
            <option value="{{ $zone }}" @selected($selectedTimezone === $zone)>{{ \App\Portals\Finance\FinanceCatalog::timezoneLabel($zone) }}</option>
        @endforeach
    </optgroup>
@endforeach
