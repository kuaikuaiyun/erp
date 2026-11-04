@if($paginator->total() > 0)
<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:12px 16px;background:#f8fbfd;border-top:1px solid #dce7ee">
    <span style="color:#687f91">显示 {{ $paginator->firstItem() }}-{{ $paginator->lastItem() }} 条，共 {{ $paginator->total() }} 条</span>
    @if($paginator->hasPages())
    <ul class="pagination pagination-sm" style="margin:0">
        <li class="{{ $paginator->onFirstPage() ? 'disabled' : '' }}"><a href="{{ $paginator->previousPageUrl() ?: '#' }}">&laquo;</a></li>
        @foreach($paginator->getUrlRange(max(1, $paginator->currentPage() - 2), min($paginator->lastPage(), $paginator->currentPage() + 2)) as $page => $url)
            <li class="{{ $page === $paginator->currentPage() ? 'active' : '' }}"><a href="{{ $url }}">{{ $page }}</a></li>
        @endforeach
        <li class="{{ $paginator->hasMorePages() ? '' : 'disabled' }}"><a href="{{ $paginator->nextPageUrl() ?: '#' }}">&raquo;</a></li>
    </ul>
    @endif
</div>
@endif
