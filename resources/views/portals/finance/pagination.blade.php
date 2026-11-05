@php
    $currentPage = $paginator->currentPage();
    $lastPage = $paginator->lastPage();
    $pageNumbers = collect([1, 2, $currentPage - 1, $currentPage, $currentPage + 1, $lastPage - 1, $lastPage])->filter(fn ($page) => $page >= 1 && $page <= $lastPage)->unique()->sort()->values();
    $previousNumber = 0;
@endphp
<nav class="finance-pages finance-numbered-pages" aria-label="分页">
    <span>显示 {{ $paginator->firstItem() ?? 0 }}-{{ $paginator->lastItem() ?? 0 }} 条，共 {{ $paginator->total() }} 条</span>
    <div class="finance-page-controls"><ul class="pagination pagination-sm">
        <li @class(['disabled' => $paginator->onFirstPage()])>@if($paginator->onFirstPage())<span aria-disabled="true">«</span>@else<a href="{{ $paginator->previousPageUrl() }}" aria-label="上一页">«</a>@endif</li>
        @foreach($pageNumbers as $pageNumber)
            @if($previousNumber && $pageNumber - $previousNumber > 1)<li class="disabled"><span>…</span></li>@endif
            <li @class(['active' => $pageNumber === $currentPage])>@if($pageNumber === $currentPage)<span aria-current="page">{{ $pageNumber }}</span>@else<a href="{{ $paginator->url($pageNumber) }}">{{ $pageNumber }}</a>@endif</li>
            @php
                $previousNumber = $pageNumber;
            @endphp
        @endforeach
        <li @class(['disabled' => !$paginator->hasMorePages()])>@if($paginator->hasMorePages())<a href="{{ $paginator->nextPageUrl() }}" aria-label="下一页">»</a>@else<span aria-disabled="true">»</span>@endif</li>
    </ul><label class="finance-page-size">每页显示 <select aria-label="每页显示条数" data-finance-page-size>@foreach([10, 20, 50, 100] as $pageSize)<option value="{{ $pageSize }}" @selected($paginator->perPage() === $pageSize)>{{ $pageSize }}</option>@endforeach</select> 条</label></div>
</nav>
@once
@push('styles')
<style>
.finance-system .finance-numbered-pages{display:flex;justify-content:flex-end;align-items:center;gap:14px;flex-wrap:wrap;padding-top:14px;font-size:13px;color:#5a7690}.finance-page-controls{display:flex;align-items:center;gap:14px;flex-wrap:wrap}.finance-page-controls .pagination{margin:0}.finance-page-controls .pagination>li>a,.finance-page-controls .pagination>li>span{min-width:32px;height:32px;padding:6px 10px;text-align:center;border-color:#d8e3ed}.finance-page-size{display:flex;align-items:center;gap:6px;margin:0;font-weight:400;white-space:nowrap}.finance-page-size select{height:32px;min-width:64px;padding:4px 8px;border:1px solid #d8e3ed;border-radius:3px;background:#fff;color:#3b5870}@media(max-width:650px){.finance-system .finance-numbered-pages{justify-content:flex-start}}
</style>
@endpush
@endonce
