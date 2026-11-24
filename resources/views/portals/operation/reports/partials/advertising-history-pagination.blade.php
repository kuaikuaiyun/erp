@php
    $query = request()->query();
    $currentPage = $history->currentPage();
    $lastPage = max(1, $history->lastPage());
@endphp
@if($history->total() > 0)
<nav class="flt-pagination advertising-history-pagination" aria-label="广告费提报记录分页">
    <span class="advertising-history-pagination-summary">显示 {{ $history->firstItem() ?? 0 }}-{{ $history->lastItem() ?? 0 }} 条，共 {{ $history->total() }} 条</span>
    <form method="get" action="{{ request()->url() }}">
        @foreach($query as $key => $value)@if(!in_array($key, ['history_page', 'history_per_page'], true) && !is_array($value))<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endif @endforeach
        <label>每页显示 <select name="history_per_page" onchange="this.form.submit()">@foreach([10,20,50,100] as $size)<option value="{{ $size }}" @selected($history->perPage() === $size)>{{ $size }}</option>@endforeach</select> 条</label>
    </form>
    <a class="flt-btn {{ $history->onFirstPage() ? 'is-disabled' : '' }}" href="{{ $history->previousPageUrl() ?: '#' }}"><i class="fa fa-angle-left"></i> 上一页</a>
    <strong>第 {{ $currentPage }} / {{ $lastPage }} 页</strong>
    <a class="flt-btn {{ $history->hasMorePages() ? '' : 'is-disabled' }}" href="{{ $history->nextPageUrl() ?: '#' }}">下一页 <i class="fa fa-angle-right"></i></a>
    <form method="get" action="{{ request()->url() }}">
        @foreach($query as $key => $value)@if($key !== 'history_page' && !is_array($value))<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endif @endforeach
        <label>跳至 <input type="number" name="history_page" min="1" max="{{ $lastPage }}" value="{{ $currentPage }}"> 页</label><button class="flt-btn flt-primary" type="submit">跳转</button>
    </form>
</nav>
@endif
