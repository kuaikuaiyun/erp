@php
    $currentPage = $products->currentPage();
    $lastPage = $products->lastPage();
    $visiblePages = collect([1, 2, $currentPage - 1, $currentPage, $currentPage + 1, $lastPage - 1, $lastPage])
        ->filter(fn (int $page): bool => $page >= 1 && $page <= $lastPage)
        ->unique()
        ->sort()
        ->values();
    $previousVisiblePage = null;
@endphp

<div class="aikaipin-pagination-control">
    <span class="aikaipin-pagination-summary">{{ __('aikaipin.product_library.pagination.summary', ['from' => $products->firstItem() ?? 0, 'to' => $products->lastItem() ?? 0, 'total' => $products->total()]) }}</span>
    @if ($lastPage > 1)
        <nav aria-label="{{ __('aikaipin.product_library.pagination.navigation') }}">
            <ul class="pagination pagination-sm no-margin">
                <li class="{{ $products->onFirstPage() ? 'disabled' : '' }}"><a href="{{ $products->previousPageUrl() ?: '#' }}" aria-label="{{ __('aikaipin.product_library.pagination.previous') }}">&laquo;</a></li>
                @foreach ($visiblePages as $page)
                    @if ($previousVisiblePage !== null && $page - $previousVisiblePage > 1)
                        <li class="disabled"><span>&hellip;</span></li>
                    @endif
                    <li class="{{ $currentPage === $page ? 'active' : '' }}"><a href="{{ $products->url($page) }}">{{ $page }}</a></li>
                    @php($previousVisiblePage = $page)
                @endforeach
                <li class="{{ $products->hasMorePages() ? '' : 'disabled' }}"><a href="{{ $products->nextPageUrl() ?: '#' }}" aria-label="{{ __('aikaipin.product_library.pagination.next') }}">&raquo;</a></li>
            </ul>
        </nav>
    @endif
</div>
