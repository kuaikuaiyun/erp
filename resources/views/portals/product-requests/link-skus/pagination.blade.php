@php
    $query = request()->query();
    $currentPage = $paginator->currentPage();
    $lastPage = max(1, $paginator->lastPage());
@endphp
<nav class="flt-pagination product-request-link-sku-pagination" aria-label="PCL SKU 管理分页" data-loading="region" data-loading-target=".product-request-table-box">
    <span class="pagination-total">显示 {{ $paginator->firstItem() ?? 0 }}-{{ $paginator->lastItem() ?? 0 }} 条，共 {{ $paginator->total() }} 条</span>
    <form class="page-size" method="get" action="{{ request()->url() }}">
        @foreach($query as $key => $value)@if(!in_array($key, ['page', 'per_page'], true) && !is_array($value))<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endif @endforeach
        <label>每页显示 <select name="per_page" onchange="this.form.submit()">@foreach([20, 50] as $size)<option value="{{ $size }}" @selected($paginator->perPage() === $size)>{{ $size }}</option>@endforeach</select> 条</label>
    </form>
    <a class="flt-btn {{ $paginator->onFirstPage() ? 'is-disabled' : '' }}" href="{{ $paginator->previousPageUrl() ?: '#' }}"><i class="fa fa-angle-left"></i> 上一页</a>
    <strong>第 {{ $currentPage }} / {{ $lastPage }} 页</strong>
    <a class="flt-btn {{ $paginator->hasMorePages() ? '' : 'is-disabled' }}" href="{{ $paginator->nextPageUrl() ?: '#' }}">下一页 <i class="fa fa-angle-right"></i></a>
    <form class="page-jump" method="get" action="{{ request()->url() }}">
        @foreach($query as $key => $value)@if($key !== 'page' && !is_array($value))<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endif @endforeach
        <label>跳至 <input type="number" name="page" min="1" max="{{ $lastPage }}" value="{{ $currentPage }}"> 页</label>
        <button class="flt-btn flt-primary" type="submit">跳转</button>
    </form>
</nav>
