@php
    $query = request()->except(['page', 'per_page']);
    $pageRoute = $routeName ?? request()->route()->getName();
    $pageUrl = static fn (int $page) => route($pageRoute, array_merge($query, ['page' => $page, 'per_page' => $pagination['per_page']]));
@endphp
<div class="payment-unified-pagination">
    <span>{{ __('payment.common.pagination.summary', ['from' => $pagination['from'], 'to' => $pagination['to'], 'total' => $pagination['total']]) }}</span>
    <form method="get" action="{{ route($pageRoute) }}" class="payment-pagination-form">
        @foreach($query as $key => $value)
            @if(is_scalar($value))<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endif
        @endforeach
        <label><span>{{ __('payment.common.pagination.per_page') }}</span><select class="form-control input-sm" name="per_page" data-payment-per-page>@foreach([50,100] as $size)<option value="{{ $size }}" @selected((int)$pagination['per_page'] === $size)>{{ $size }}</option>@endforeach</select></label>
        <a class="btn btn-default btn-sm {{ $pagination['current_page'] <= 1 ? 'disabled' : '' }}" href="{{ $pageUrl(max(1, $pagination['current_page'] - 1)) }}">{{ __('payment.common.pagination.previous') }}</a>
        <strong>{{ __('payment.common.pagination.page_status', ['current' => $pagination['current_page'], 'last' => $pagination['last_page']]) }}</strong>
        <a class="btn btn-default btn-sm {{ $pagination['current_page'] >= $pagination['last_page'] ? 'disabled' : '' }}" href="{{ $pageUrl(min($pagination['last_page'], $pagination['current_page'] + 1)) }}">{{ __('payment.common.pagination.next') }}</a>
        <label><span>{{ __('payment.common.pagination.jump_to') }}</span><input class="form-control input-sm payment-page-jump-input" type="number" name="page" min="1" max="{{ $pagination['last_page'] }}" placeholder="{{ __('payment.common.pagination.page_placeholder') }}" autocomplete="off"></label>
        <button class="btn btn-primary btn-sm" type="submit">{{ __('payment.common.pagination.jump') }}</button>
    </form>
</div>
