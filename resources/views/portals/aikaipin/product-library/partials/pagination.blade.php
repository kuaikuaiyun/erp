@php
    $currentPage = $products->currentPage();
    $lastPage = max(1, $products->lastPage());
    $query = request()->query();
@endphp

<nav class="flt-pagination aikaipin-pagination-control" aria-label="{{ __('aikaipin.product_library.pagination.navigation') }}">
    <span class="aikaipin-pagination-summary">
        {{ __('aikaipin.product_library.pagination.summary', ['from' => $products->firstItem() ?? 0, 'to' => $products->lastItem() ?? 0, 'total' => $products->total()]) }}
    </span>

    <form method="get" action="{{ request()->url() }}" data-loading="off">
        @foreach ($query as $key => $value)
            @if (! in_array($key, ['page', 'per_page'], true) && ! is_array($value))
                <input type="hidden" name="{{ $key }}" value="{{ $value }}">
            @endif
        @endforeach
        <label>每页显示
            <select name="per_page" aria-label="每页显示条数" onchange="this.form.submit()">
                @foreach ([20, 50, 100] as $size)
                    <option value="{{ $size }}" @selected((int) $perPage === $size)>{{ $size }}</option>
                @endforeach
            </select> 条
        </label>
    </form>

    <a class="flt-btn {{ $products->onFirstPage() ? 'is-disabled' : '' }}" href="{{ $products->previousPageUrl() ?: '#' }}" @if($products->onFirstPage()) aria-disabled="true" tabindex="-1" @endif>
        <i class="fa fa-angle-left" aria-hidden="true"></i> 上一页
    </a>
    <strong>第 {{ $currentPage }} / {{ $lastPage }} 页</strong>
    <a class="flt-btn {{ $products->hasMorePages() ? '' : 'is-disabled' }}" href="{{ $products->nextPageUrl() ?: '#' }}" @unless($products->hasMorePages()) aria-disabled="true" tabindex="-1" @endunless>
        下一页 <i class="fa fa-angle-right" aria-hidden="true"></i>
    </a>

    <form method="get" action="{{ request()->url() }}" data-loading="off">
        @foreach ($query as $key => $value)
            @if ($key !== 'page' && ! is_array($value))
                <input type="hidden" name="{{ $key }}" value="{{ $value }}">
            @endif
        @endforeach
        <label>跳至
            <input type="number" name="page" min="1" max="{{ $lastPage }}" step="1" required aria-label="跳转页码">
            页
        </label>
        <button class="flt-btn flt-primary" type="submit">跳转</button>
    </form>
</nav>
