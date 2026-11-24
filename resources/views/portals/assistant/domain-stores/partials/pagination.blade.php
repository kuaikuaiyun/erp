@php
    $t = static fn (string $key, array $replace = []): string => __('assistant.domain_stores.'.$key, $replace);
    $currentPage = $stores->currentPage();
    $lastPage = max(1, $stores->lastPage());
@endphp

<nav class="assistant-pagination-control" aria-label="{{ $t('pagination.navigation') }}" data-loading="region" data-loading-target=".assistant-domain-stores-list">
    <span class="assistant-pagination-summary">{{ $t('pagination.summary', ['from' => $stores->firstItem() ?? 0, 'to' => $stores->lastItem() ?? 0, 'total' => $stores->total()]) }}</span>

    <form method="get" action="{{ route('assistant.domain-stores.index') }}" class="assistant-per-page-form" data-loading="region" data-loading-target=".assistant-domain-stores-list">
        @foreach ($filters as $filterName => $filterValue)
            @if ($filterName !== 'per_page')
                @if (is_array($filterValue))
                    @foreach ($filterValue as $item)
                        <input type="hidden" name="{{ $filterName }}[]" value="{{ $item }}">
                    @endforeach
                @else
                    <input type="hidden" name="{{ $filterName }}" value="{{ $filterValue }}">
                @endif
            @endif
        @endforeach
        <label>{{ $t('pagination.per_page') }}
            <select name="per_page" class="form-control input-sm" onchange="this.form.submit()">
                @foreach ([20, 50, 100] as $option)
                    <option value="{{ $option }}" @selected($filters['per_page'] === $option)>{{ $option }}</option>
                @endforeach
            </select>
            {{ $t('pagination.records_suffix') }}
        </label>
    </form>

    @if ($stores->onFirstPage())
        <span class="assistant-page-button is-disabled" aria-disabled="true">{{ $t('pagination.previous') }}</span>
    @else
        <a class="assistant-page-button" href="{{ $stores->previousPageUrl() }}" rel="prev">{{ $t('pagination.previous') }}</a>
    @endif

    <strong class="assistant-pagination-status">第 {{ $currentPage }} / {{ $lastPage }} 页</strong>

    @if ($stores->hasMorePages())
        <a class="assistant-page-button" href="{{ $stores->nextPageUrl() }}" rel="next">{{ $t('pagination.next') }}</a>
    @else
        <span class="assistant-page-button is-disabled" aria-disabled="true">{{ $t('pagination.next') }}</span>
    @endif

    @if ($lastPage > 1)
        <form method="get" action="{{ route('assistant.domain-stores.index') }}" class="assistant-page-jump-form" data-loading="region" data-loading-target=".assistant-domain-stores-list">
            @foreach ($filters as $filterName => $filterValue)
                @if (is_array($filterValue))
                    @foreach ($filterValue as $item)
                        <input type="hidden" name="{{ $filterName }}[]" value="{{ $item }}">
                    @endforeach
                @else
                    <input type="hidden" name="{{ $filterName }}" value="{{ $filterValue }}">
                @endif
            @endforeach
            <label>跳至 <input type="number" name="page" min="1" max="{{ $lastPage }}" step="1" required placeholder="页码" aria-label="页码"></label>
            <button type="submit">跳转</button>
        </form>
    @endif
</nav>
