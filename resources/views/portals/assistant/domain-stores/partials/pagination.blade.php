@php
    $t = static fn (string $key, array $replace = []): string => __('assistant.domain_stores.'.$key, $replace);
    $currentPage = $stores->currentPage();
    $lastPage = $stores->lastPage();
    $visiblePages = collect([1, 2, $currentPage - 1, $currentPage, $currentPage + 1, $lastPage - 1, $lastPage])
        ->filter(fn (int $page): bool => $page >= 1 && $page <= $lastPage)
        ->unique()
        ->sort()
        ->values();
    $previousVisiblePage = null;
@endphp

<div class="assistant-pagination-control" data-loading="region" data-loading-target=".assistant-domain-stores-list">
    <span class="assistant-pagination-summary">{{ $t('pagination.summary', ['from' => $stores->firstItem() ?? 0, 'to' => $stores->lastItem() ?? 0, 'total' => $stores->total()]) }}</span>
    @if ($lastPage > 1)
        <nav aria-label="{{ $t('pagination.navigation') }}">
            <ul class="pagination pagination-sm no-margin">
                <li class="{{ $stores->onFirstPage() ? 'disabled' : '' }}"><a href="{{ $stores->previousPageUrl() ?: '#' }}" aria-label="{{ $t('pagination.previous') }}">&laquo;</a></li>
                @foreach ($visiblePages as $page)
                    @if ($previousVisiblePage !== null && $page - $previousVisiblePage > 1)
                        <li class="disabled"><span>…</span></li>
                    @endif
                    <li class="{{ $currentPage === $page ? 'active' : '' }}"><a href="{{ $stores->url($page) }}">{{ $page }}</a></li>
                    @php
                        $previousVisiblePage = $page;
                    @endphp
                @endforeach
                <li class="{{ $stores->hasMorePages() ? '' : 'disabled' }}"><a href="{{ $stores->nextPageUrl() ?: '#' }}" aria-label="{{ $t('pagination.next') }}">&raquo;</a></li>
            </ul>
        </nav>
    @endif
    <form method="get" action="{{ route('assistant.domain-stores.index') }}" class="assistant-per-page-form" data-loading="region" data-loading-target=".assistant-domain-stores-list">
        @foreach ($filters as $filterName => $filterValue)
            @if ($filterName !== 'per_page')
                @if (is_array($filterValue))
                    @foreach ($filterValue as $item)
                        <input type="hidden" name="{{ $filterName }}[]" value="{{ $item }}">
                    @endforeach
                @else
                    <input type="hidden" name="{{ $filterName }}" value="{{ $filterValue }}">
                @endif
            @endif
        @endforeach
        <label>{{ $t('pagination.per_page') }}
            <select name="per_page" class="form-control input-sm" onchange="this.form.submit()">
                @foreach ([20, 50, 100] as $option)
                    <option value="{{ $option }}" @selected($filters['per_page'] === $option)>{{ $option }}</option>
                @endforeach
            </select>
            {{ $t('pagination.records_suffix') }}
        </label>
    </form>
</div>
