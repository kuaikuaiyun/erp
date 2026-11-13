@foreach(['team_search' => 'team', 'person_search' => 'person'] as $parameter => $label)
<div class="ot-search-field" data-search-combobox>
    <label for="ot-search-{{ $parameter }}">{{ __('operation_tasks.filters.'.$label) }}</label>
    <div class="ot-search-control">
        <input class="form-control" id="ot-search-{{ $parameter }}" name="{{ $parameter }}" value="{{ request($parameter) }}" autocomplete="off" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="ot-options-{{ $parameter }}">
        <button class="ot-search-clear" type="button" data-clear-search aria-label="{{ __('operation_tasks.filters.clear') }}" @if(!request($parameter)) hidden @endif>×</button>
        <button class="ot-search-toggle" type="button" data-search-toggle aria-label="{{ __('operation_tasks.filters.expand') }}" aria-expanded="false"><span class="ot-search-caret" aria-hidden="true"></span></button>
    </div>
    <div class="ot-search-options" id="ot-options-{{ $parameter }}" role="listbox" hidden>
        @foreach(($parameter === 'team_search' ? ($searchTeamOptions ?? []) : ($searchPersonOptions ?? [])) as $option)
            @if(filled($option))<button type="button" role="option" aria-selected="false" data-search-option data-value="{{ $option }}">{{ $option }}</button>@endif
        @endforeach
        <div class="ot-search-empty" data-search-empty hidden>{{ __('operation_tasks.filters.no_matches') }}</div>
    </div>
</div>
@endforeach
@once
@push('styles')<link rel="stylesheet" href="{{ asset('assets/erp/operation-task-filters.css') }}?v=20261113-4">@endpush
@push('scripts')<script src="{{ asset('assets/erp/operation-task-filters.js') }}?v=20261113-3"></script>@endpush
@endonce
