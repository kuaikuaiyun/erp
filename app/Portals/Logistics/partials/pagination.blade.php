@php
    $currentPage = $paginator->currentPage();
    $lastPage = $paginator->lastPage();
    $visiblePages = collect([1, 2, $currentPage - 1, $currentPage, $currentPage + 1, $lastPage - 1, $lastPage])
        ->filter(fn (int $page): bool => $page >= 1 && $page <= $lastPage)
        ->unique()
        ->sort()
        ->values();
    $previousVisiblePage = null;
    $loadingTarget = $loadingTarget ?? '.logistics-table-box';
@endphp

<div class="logistics-pagination-control" data-loading="region" data-loading-target="{{ $loadingTarget }}">
    <span class="logistics-pagination-summary">显示 {{ $paginator->firstItem() ?? 0 }}-{{ $paginator->lastItem() ?? 0 }} 条，共 {{ $paginator->total() }} 条</span>
    @if ($lastPage > 1)
        <nav aria-label="分页导航">
            <ul class="pagination pagination-sm no-margin">
                <li class="{{ $paginator->onFirstPage() ? 'disabled' : '' }}"><a href="{{ $paginator->previousPageUrl() ?: '#' }}" aria-label="上一页">&laquo;</a></li>
                @foreach ($visiblePages as $page)
                    @if ($previousVisiblePage !== null && $page - $previousVisiblePage > 1)
                        <li class="disabled"><span>…</span></li>
                    @endif
                    <li class="{{ $currentPage === $page ? 'active' : '' }}"><a href="{{ $paginator->url($page) }}">{{ $page }}</a></li>
                    @php
                        $previousVisiblePage = $page;
                    @endphp
                @endforeach
                <li class="{{ $paginator->hasMorePages() ? '' : 'disabled' }}"><a href="{{ $paginator->nextPageUrl() ?: '#' }}" aria-label="下一页">&raquo;</a></li>
            </ul>
        </nav>
    @endif
    <form method="get" action="{{ route($routeName) }}" class="logistics-per-page-form" data-loading="region" data-loading-target="{{ $loadingTarget }}">
        @foreach ($filters as $filterName => $filterValue)
            @if ($filterName !== 'per_page')
                <input type="hidden" name="{{ $filterName }}" value="{{ $filterValue }}">
            @endif
        @endforeach
        <label>每页显示
            <select name="per_page" class="form-control input-sm" onchange="this.form.submit()">
                @foreach ([20, 50, 100] as $option)
                    <option value="{{ $option }}" @selected($filters['per_page'] === $option)>{{ $option }}</option>
                @endforeach
            </select>
            条
        </label>
    </form>
</div>
