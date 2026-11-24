@php
    $query = request()->query();
    $currentPage = $paginator->currentPage();
    $lastPage = max(1, $paginator->lastPage());
@endphp
<nav class="flt-pagination operation-ai-template-pagination" aria-label="分页">
    <span class="operation-ai-pagination-total">显示 {{ $paginator->firstItem() ?? 0 }}-{{ $paginator->lastItem() ?? 0 }} 条，共 {{ $paginator->total() }} 条</span>
    <form class="operation-ai-page-size" method="get" action="{{ request()->url() }}">
        @foreach($query as $key=>$value)@if(!in_array($key,['page','per_page'],true) && !is_array($value))<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endif @endforeach
        <label>每页显示 <select name="per_page" onchange="this.form.submit()">@foreach([15,30,50,100] as $size)<option value="{{ $size }}" @selected((int)request('per_page',15)===$size)>{{ $size }}</option>@endforeach</select> 条</label>
    </form>
    <a class="flt-btn {{ $paginator->onFirstPage() ? 'is-disabled' : '' }}" href="{{ $paginator->previousPageUrl() ?: '#' }}"><i class="fa fa-angle-left"></i> 上一页</a>
    <strong>第 {{ $currentPage }} / {{ $lastPage }} 页</strong>
    <a class="flt-btn {{ $paginator->hasMorePages() ? '' : 'is-disabled' }}" href="{{ $paginator->nextPageUrl() ?: '#' }}">下一页 <i class="fa fa-angle-right"></i></a>
    <form class="operation-ai-page-jump" method="get" action="{{ request()->url() }}">
        @foreach($query as $key=>$value)@if($key!=='page' && !is_array($value))<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endif @endforeach
        <label>跳至 <input type="number" name="page" min="1" max="{{ $lastPage }}" value="{{ $currentPage }}"> 页</label><button class="flt-btn flt-primary" type="submit">跳转</button>
    </form>
</nav>
