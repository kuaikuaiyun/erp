@php
    $paginationUnit = $unit ?? '条';
    $paginationPerPageOptions = $perPageOptions ?? [20, 50, 100];
    $paginationPageName = $paginator->getPageName();
    $paginationLastPage = max(1, $paginator->lastPage());
@endphp

@once
@push('styles')
<link rel="stylesheet" href="{{ asset('css/assistant-advertising-template-pagination.css') }}?v=20261124-1">
@endpush
@endonce

@if($paginator->total() > 0)
<nav class="assistant-ad-pagination" aria-label="广告费数据分页">
    <span class="assistant-ad-pagination-summary">显示 {{ $paginator->firstItem() ?? 0 }}-{{ $paginator->lastItem() ?? 0 }} {{ $paginationUnit }}，共 {{ $paginator->total() }} {{ $paginationUnit }}</span>
    <label class="assistant-ad-pagination-size">每页显示
        <select aria-label="每页显示数量" data-assistant-page-size data-page-name="{{ $paginationPageName }}">
            @foreach($paginationPerPageOptions as $size)
                <option value="{{ $size }}" @selected($paginator->perPage() === $size)>{{ $size }}</option>
            @endforeach
        </select>
        {{ $paginationUnit }}
    </label>
    @if($paginator->onFirstPage())
        <span class="assistant-ad-pagination-button is-disabled" aria-disabled="true">上一页</span>
    @else
        <a class="assistant-ad-pagination-button" href="{{ $paginator->previousPageUrl() }}" rel="prev">上一页</a>
    @endif
    <strong class="assistant-ad-pagination-status">第 {{ $paginator->currentPage() }} / {{ $paginationLastPage }} 页</strong>
    @if($paginator->hasMorePages())
        <a class="assistant-ad-pagination-button" href="{{ $paginator->nextPageUrl() }}" rel="next">下一页</a>
    @else
        <span class="assistant-ad-pagination-button is-disabled" aria-disabled="true">下一页</span>
    @endif
    @if($paginationLastPage > 1)
        <div class="assistant-ad-pagination-jump">
            <label>跳至 <input type="number" min="1" max="{{ $paginationLastPage }}" step="1" placeholder="页码" aria-label="页码"></label>
            <button type="button" data-page-name="{{ $paginationPageName }}" onclick="var input=this.parentNode.querySelector('input');if(!input.reportValidity())return;var url=new URL(window.location.href);url.searchParams.set(this.dataset.pageName,input.value);window.location.href=url.toString()">跳转</button>
        </div>
    @endif
</nav>
@endif

@once
@push('scripts')
<script>
document.addEventListener('change', function (event) {
    var selector = event.target.closest && event.target.closest('[data-assistant-page-size]');
    if (!selector) return;

    var url = new URL(window.location.href);
    url.searchParams.set('per_page', selector.value);
    url.searchParams.delete(selector.dataset.pageName || 'page');
    window.location.assign(url.toString());
});
</script>
@endpush
@endonce
