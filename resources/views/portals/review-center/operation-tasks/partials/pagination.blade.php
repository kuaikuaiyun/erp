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
    <span>{{ __('review_center.copy.text_164') }} {{ $paginator->firstItem() ?: 0 }}-{{ $paginator->lastItem() ?: 0 }} {{ __('review_center.copy.text_165') }} {{ $paginator->total() }} {{ __('review_center.copy.text_140') }}</span>
    <form method="get" action="{{ request()->url() }}" class="ot-pagination-form">
        @foreach($query as $key => $value)
            @if(is_scalar($value) && $key !== 'review_tab')
                <input type="hidden" name="{{ $key }}" value="{{ $value }}">
            @endif
        @endforeach
        <input type="hidden" name="review_tab" value="{{ $activeTab }}">
        <label><span>{{ __('review_center.copy.text_166') }}</span><select class="form-control input-sm" name="{{ $perPageName }}" onchange="this.form.submit()">@foreach([20,50,100] as $size)<option value="{{ $size }}" @selected($paginator->perPage() === $size)>{{ $size }}</option>@endforeach</select></label>
        <a class="btn btn-default btn-sm {{ $paginator->onFirstPage() ? 'disabled' : '' }}" href="{{ $pageUrl(max(1, $paginator->currentPage() - 1)) }}">{{ __('review_center.copy.text_167') }}</a>
        <strong>{{ __('review_center.copy.text_168') }} {{ $paginator->currentPage() }} / {{ $paginator->lastPage() }} {{ __('review_center.copy.text_169') }}</strong>
        <a class="btn btn-default btn-sm {{ $paginator->hasMorePages() ? '' : 'disabled' }}" href="{{ $pageUrl(min($paginator->lastPage(), $paginator->currentPage() + 1)) }}">{{ __('review_center.copy.text_170') }}</a>
        <label><span>{{ __('review_center.copy.text_171') }}</span><input class="form-control input-sm" type="number" name="{{ $pageName }}" min="1" max="{{ $paginator->lastPage() }}" placeholder="{{ __('review_center.copy.text_163') }}" autocomplete="off"></label>
        <button class="btn btn-primary btn-sm" type="submit">{{ __('review_center.copy.text_172') }}</button>
    </form>
</div>
@endif
