@php
    $pageName = $paginator->getPageName();
    $perPageName = $perPageName ?? 'per_page';
    $activeTab = $activeTab ?? 'recaps';
    $query = request()->except([$pageName, $perPageName]);
    $pageUrl = static fn (int $page) => request()->url().'?'.http_build_query(array_merge($query, [
        'review_tab' => $activeTab,
        $pageName => $page,
        $perPageName => $paginator->perPage(),
    ]));
@endphp
@if($paginator->total() > 0)
<div class="ot-unified-pagination" data-review-panel="{{ $activeTab }}">
    <span>显示 {{ $paginator->firstItem() ?: 0 }}-{{ $paginator->lastItem() ?: 0 }} 条，共 {{ $paginator->total() }} 条</span>
    <form method="get" action="{{ request()->url() }}" class="ot-pagination-form">
        @foreach($query as $key => $value)
            @if(is_scalar($value) && $key !== 'review_tab')
                <input type="hidden" name="{{ $key }}" value="{{ $value }}">
            @endif
        @endforeach
        <input type="hidden" name="review_tab" value="{{ $activeTab }}">
        <label><span>每页</span><select class="form-control input-sm" name="{{ $perPageName }}" onchange="this.form.submit()">@foreach([20,50,100] as $size)<option value="{{ $size }}" @selected($paginator->perPage() === $size)>{{ $size }}</option>@endforeach</select></label>
        <a class="btn btn-default btn-sm {{ $paginator->onFirstPage() ? 'disabled' : '' }}" href="{{ $pageUrl(max(1, $paginator->currentPage() - 1)) }}">上一页</a>
        <strong>第 {{ $paginator->currentPage() }} / {{ $paginator->lastPage() }} 页</strong>
        <a class="btn btn-default btn-sm {{ $paginator->hasMorePages() ? '' : 'disabled' }}" href="{{ $pageUrl(min($paginator->lastPage(), $paginator->currentPage() + 1)) }}">下一页</a>
        <label><span>跳至</span><input class="form-control input-sm" type="number" name="{{ $pageName }}" min="1" max="{{ $paginator->lastPage() }}" placeholder="页码" autocomplete="off"></label>
        <button class="btn btn-primary btn-sm" type="submit">跳转</button>
    </form>
</div>
@endif
