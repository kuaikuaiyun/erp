@php
    $paginationText = fn ($key, $values = []) => __('fashion_logistics_order_list.'.$key, $values);
@endphp
<nav class="flt-pagination fashion-order-pagination" aria-label="{{ $paginationText('pagination') }}">
    <form method="get" action="{{ url()->current() }}" data-fashion-page-size data-loading="region" data-loading-target="#fashion-order-region" data-loading-text="{{ $paginationText('loading') }}">
        @foreach($filters as $name => $value)
            @if(!in_array($name, ['page', 'per_page'], true) && $value !== null && $value !== '')
                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
            @endif
        @endforeach
        <label>{{ $paginationText('per_page') }}
            <select name="per_page" data-fashion-page-size-select>
                @foreach([20, 50, 100] as $size)
                    <option value="{{ $size }}" @selected($orders->perPage() === $size)>{{ $size }}</option>
                @endforeach
            </select>
        </label>
        <noscript><button type="submit" class="flt-btn">{{ $paginationText('query') }}</button></noscript>
    </form>
    @if($orders->previousPageUrl())
        <a class="flt-btn" href="{{ $orders->previousPageUrl() }}">{{ $paginationText('previous') }}</a>
    @else
        <button type="button" class="flt-btn" disabled>{{ $paginationText('previous') }}</button>
    @endif
    <strong>{{ $paginationText('page_label', ['page' => $orders->currentPage(), 'pages' => $orders->lastPage()]) }}</strong>
    @if($orders->nextPageUrl())
        <a class="flt-btn" href="{{ $orders->nextPageUrl() }}">{{ $paginationText('next') }}</a>
    @else
        <button type="button" class="flt-btn" disabled>{{ $paginationText('next') }}</button>
    @endif
    <form method="get" action="{{ url()->current() }}">
        @foreach($filters as $name => $value)
            @if($name !== 'page' && $value !== null && $value !== '')
                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
            @endif
        @endforeach
        <label>{{ $paginationText('jump_to') }}
            <input type="number" name="page" min="1" max="{{ $orders->lastPage() }}" step="1" required>
        </label>
        <button type="submit" class="flt-btn flt-primary">{{ $paginationText('jump') }}</button>
    </form>
</nav>
