@if ($paginator->hasPages())
    @php
        $currentPage = $paginator->currentPage();
        $lastPage = $paginator->lastPage();
        // 只展示首页、末页与当前页附近的页码，中间用省略号折叠，避免页码过多撑满一行
        $visiblePages = collect([1, 2, $currentPage - 1, $currentPage, $currentPage + 1, $lastPage - 1, $lastPage])
            ->filter(fn (int $page): bool => $page >= 1 && $page <= $lastPage)
            ->unique()
            ->sort()
            ->values();
        $previousVisiblePage = null;
    @endphp
    <div class="product-request-pagination" data-loading="region" data-loading-target=".product-request-table-box">
        <span class="product-request-pagination-total">{{ __('product_requests.common.pagination_summary', ['total' => $paginator->total(), 'current' => $paginator->currentPage(), 'last' => $paginator->lastPage()]) }}</span>
        <nav aria-label="{{ __('pagination.next') }}">
            <ul class="pagination pagination-sm no-margin">
                <li class="{{ $paginator->onFirstPage() ? 'disabled' : '' }}">
                    <a href="{{ $paginator->previousPageUrl() ?: '#' }}" rel="prev" aria-label="{{ __('pagination.previous') }}">&laquo;</a>
                </li>
                @foreach ($visiblePages as $page)
                    @if ($previousVisiblePage !== null && $page - $previousVisiblePage > 1)
                        <li class="disabled"><span>&hellip;</span></li>
                    @endif
                    <li class="{{ $currentPage === $page ? 'active' : '' }}">
                        <a href="{{ $paginator->url($page) }}">{{ $page }}</a>
                    </li>
                    @php
                        $previousVisiblePage = $page;
                    @endphp
                @endforeach
                <li class="{{ $paginator->hasMorePages() ? '' : 'disabled' }}">
                    <a href="{{ $paginator->nextPageUrl() ?: '#' }}" rel="next" aria-label="{{ __('pagination.next') }}">&raquo;</a>
                </li>
            </ul>
        </nav>
    </div>
@endif
