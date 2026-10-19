@if ($paginator->hasPages())
    <div class="box-footer clearfix product-request-pagination" data-loading="region" data-loading-target=".product-request-table-box">
        <ul class="pagination">
            @if ($paginator->onFirstPage())
                <li class="disabled"><span>{{ __('pagination.previous') }}</span></li>
            @else
                <li><a href="{{ $paginator->previousPageUrl() }}" rel="prev">{{ __('pagination.previous') }}</a></li>
            @endif

            @foreach ($elements as $element)
                @if (is_string($element))
                    <li class="disabled"><span>{{ $element }}</span></li>
                @endif

                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <li class="active"><span>{{ $page }}</span></li>
                        @else
                            <li><a href="{{ $url }}">{{ $page }}</a></li>
                        @endif
                    @endforeach
                @endif
            @endforeach

            @if ($paginator->hasMorePages())
                <li><a href="{{ $paginator->nextPageUrl() }}" rel="next">{{ __('pagination.next') }}</a></li>
            @else
                <li class="disabled"><span>{{ __('pagination.next') }}</span></li>
            @endif
        </ul>
        <span class="product-request-pagination-total">{{ __('product_requests.common.pagination_summary', ['total' => $paginator->total(), 'current' => $paginator->currentPage(), 'last' => $paginator->lastPage()]) }}</span>
    </div>
@endif
