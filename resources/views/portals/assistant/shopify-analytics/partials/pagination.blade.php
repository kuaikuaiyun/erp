@php
    $t = static fn (string $key, array $replace = []): string => __('assistant.shopify_analytics.'.$key, $replace);
    $currentPage = $stores->currentPage();
    $lastPage = $stores->lastPage();
    $visiblePages = collect([1, 2, $currentPage - 1, $currentPage, $currentPage + 1, $lastPage - 1, $lastPage])
        ->filter(fn (int $page): bool => $page >= 1 && $page <= $lastPage)
        ->unique()
        ->sort()
        ->values();
    $previousVisiblePage = null;
@endphp

<div class="assistant-shopify-pagination-control" data-loading="region" data-loading-target=".assistant-shopify-analytics-page">
    <span>{{ $t('pagination.summary', ['from' => $stores->firstItem() ?? 0, 'to' => $stores->lastItem() ?? 0, 'total' => $stores->total()]) }}</span>
    @if ($lastPage > 1)
        <nav aria-label="{{ $t('pagination.navigation') }}">
            <ul class="pagination pagination-sm no-margin">
                <li class="{{ $stores->onFirstPage() ? 'disabled' : '' }}"><a href="{{ $stores->previousPageUrl() ?: '#' }}" aria-label="{{ $t('pagination.previous') }}">&laquo;</a></li>
                @foreach ($visiblePages as $page)
                    @if ($previousVisiblePage !== null && $page - $previousVisiblePage > 1)
                        <li class="disabled"><span>&hellip;</span></li>
                    @endif
                    <li class="{{ $currentPage === $page ? 'active' : '' }}"><a href="{{ $stores->url($page) }}">{{ $page }}</a></li>
                    @php($previousVisiblePage = $page)
                @endforeach
                <li class="{{ $stores->hasMorePages() ? '' : 'disabled' }}"><a href="{{ $stores->nextPageUrl() ?: '#' }}" aria-label="{{ $t('pagination.next') }}">&raquo;</a></li>
            </ul>
        </nav>
    @endif
    <form method="get" action="{{ route('assistant.shopify-analytics.index') }}" class="assistant-shopify-per-page-form" data-loading="region" data-loading-target=".assistant-shopify-analytics-page">
        <input type="hidden" name="shop_name" value="{{ $filters['shop_name'] }}">
        <input type="hidden" name="domains" value="{{ implode(',', $filters['domains']) }}">
        @foreach ($filters['team'] as $team)
            <input type="hidden" name="team[]" value="{{ $team }}">
        @endforeach
        <input type="hidden" name="owner_assignment" value="{{ $filters['owner_assignment'] }}">
        <input type="hidden" name="email_purchased" value="{{ $filters['email_purchased'] }}">
        <input type="hidden" name="timezone" value="{{ $filters['timezone'] }}">
        <input type="hidden" name="from_date" value="{{ $filters['from_date'] }}">
        <input type="hidden" name="to_date" value="{{ $filters['to_date'] }}">
        <input type="hidden" name="sort" value="{{ $filters['sort'] }}">
        <input type="hidden" name="direction" value="{{ $filters['direction'] }}">
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
