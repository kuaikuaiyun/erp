@extends('portal.layout')

@php
    $title = __('aliexpress.menus.aliexpress_procurement');
    $action = route('aliexpress.procurement.index');
    $lastPage = max(1, $items->lastPage());
    $blank = __('aliexpress.hints.blank');
@endphp
@section('page_title', $title)

@push('styles')
    <link rel="stylesheet" href="{{ request()->getSchemeAndHttpHost() }}/css/frontend-list-template.css?v=20261125-1">
    <style>
        .ae-procurement .flt-table-scroll table { min-width: 1150px; table-layout: auto; }
        .ae-procurement .flt-table-scroll th { width: auto; }
        .ae-procurement .flt-name { min-width: 165px; }
        .ae-procurement .ae-order, .ae-procurement .ae-description { min-width: 150px; }
        .ae-procurement .ae-order small { display: block; color: #71899d; }
        .ae-procurement code { overflow-wrap: anywhere; }
        .ae-procurement .flt-stat-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        @media (max-width: 540px) { .ae-procurement .flt-stat-grid { grid-template-columns: 1fr; } }
    </style>
@endpush

@section('content')
<section class="content-header"><h1>{{ $title }} <small>{{ __('aliexpress.page_descriptions.aliexpress_procurement') }}</small></h1></section>
<section class="content flt ae-procurement">
    <form class="flt-card flt-filters" method="get" action="{{ $action }}" data-loading="region" data-loading-target="#ae-procurement-list"
        data-logistics-filters data-search-options="{{ __('frontend_list_template.search_options') }}"
        data-no-options="{{ __('frontend_list_template.no_options') }}" data-clear-choice="{{ __('frontend_list_template.clear_choice') }}">
        <input type="hidden" name="per_page" value="{{ $perPage }}">
        <div class="flt-filter-grid">
            <label>{{ __('aliexpress.fields.keyword') }}
                <input type="search" name="keyword" value="{{ $keyword }}" maxlength="191" placeholder="{{ __('aliexpress.hints.procurement_search') }}">
            </label>
            <div><label class="flt-field-label" for="ae-procurement-status">{{ __('aliexpress.fields.status') }}</label>
                <select id="ae-procurement-status" name="status">
                    <option value="">{{ __('frontend_list_template.all') }}</option>
                    @foreach (['ready', 'exception'] as $option)
                        <option value="{{ $option }}" @selected($status === $option)>{{ __('aliexpress.status.'.$option) }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <div class="flt-filter-actions">
            <button type="submit" class="flt-btn flt-primary"><i class="fa fa-search" aria-hidden="true"></i> {{ __('frontend_list_template.query') }}</button>
            <a class="flt-btn" href="{{ $action }}" data-loading="region" data-loading-target="#ae-procurement-list">{{ __('frontend_list_template.reset') }}</a>
        </div>
    </form>

    <div class="flt-stat-grid" aria-label="{{ __('frontend_list_template.stats_title') }}">
        @foreach (['ready' => ['blue', 'fa-shopping-cart'], 'exception' => ['orange', 'fa-warning']] as $key => [$tone, $icon])
            <article class="flt-stat" data-stat-tone="{{ $tone }}">
                <div><h3>{{ __('aliexpress.status.'.$key) }}</h3><p><strong>{{ number_format($summary[$key]) }}</strong><span>{{ __('frontend_list_template.stats_unit') }}</span></p></div>
                <span class="flt-stat-icon"><i class="fa {{ $icon }}" aria-hidden="true"></i></span>
            </article>
        @endforeach
    </div>

    <section id="ae-procurement-list" class="flt-card flt-records">
        <div class="flt-toolbar">
            <div class="flt-selection">{{ __('frontend_list_template.records', ['count' => $items->total()]) }}<span class="flt-toolbar-divider" aria-hidden="true"></span>{{ __('aliexpress.hints.partial') }}</div>
        </div>
        <div class="flt-table-region">
            <div class="flt-table-scroll {{ $items->isEmpty() ? 'is-empty' : '' }}" tabindex="0" aria-label="{{ $title }}">
                <table>
                    <caption class="flt-sr-only">{{ $title }}</caption>
                    <thead><tr>
                        @foreach (['store', 'order', 'line_id', 'name', 'sku', 'quantity', 'supplier_sku', 'order_created_at', 'status', 'exception_reason', 'supplier_url'] as $column)
                            <th scope="col">{{ __('aliexpress.fields.'.$column) }}</th>
                        @endforeach
                    </tr></thead>
                    <tbody>
                    @foreach ($items as $item)
                        @php
                            $issue = $item->source_issue ?? ($item->supplier_sku === null ? 'missing_mapping' : (!$item->is_enabled ? 'disabled_mapping' : null));
                        @endphp
                        <tr>
                            <td class="flt-name"><strong>{{ $item->store_name ?: $item->shop_domain }}</strong>@if ($item->store_name)<small>{{ $item->shop_domain }}</small>@endif</td>
                            <td class="ae-order">{{ $item->shopify_order_number ?: $item->shopify_order_id }}@if ($item->shopify_order_number)<small>{{ $item->shopify_order_id }}</small>@endif</td>
                            <td>{{ $item->shopify_line_item_id }}</td>
                            <td class="ae-description">{{ $item->selection_name ?: ($item->product_title ?: $blank) }}</td>
                            <td><code>{{ $item->sku }}</code></td>
                            <td>{{ $item->quantity }}</td>
                            <td>{{ $item->supplier_sku ?? $blank }}</td>
                            <td class="flt-time">{{ $item->order_created_at->timezone('Asia/Shanghai')->format('Y-m-d H:i') }}</td>
                            <td><span class="flt-status" data-status="{{ $issue ? 'pending' : 'active' }}">{{ __('aliexpress.status.'.($issue ? 'exception' : 'ready')) }}</span></td>
                            <td>{{ $issue ? __('aliexpress.issues.'.$issue) : $blank }}</td>
                            <td>@if ($item->supplier_url)<a href="{{ $item->supplier_url }}" target="_blank" rel="noopener noreferrer">{{ __('aliexpress.actions.open_link') }}</a>@else{{ $blank }}@endif</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @if ($items->isEmpty())
                <div class="flt-empty-host"><div class="flt-empty"><i class="fa fa-search" aria-hidden="true"></i><h4>{{ __('frontend_list_template.empty_title') }}</h4><p>{{ __('frontend_list_template.empty_hint') }}</p></div></div>
            @endif
        </div>
        <nav class="flt-pagination" aria-label="{{ __('frontend_list_template.pagination') }}" data-loading="region" data-loading-target="#ae-procurement-list">
            <form method="get" action="{{ $action }}">
                <input type="hidden" name="keyword" value="{{ $keyword }}"><input type="hidden" name="status" value="{{ $status }}">
                <label>{{ __('frontend_list_template.per_page') }}
                    <select name="per_page" aria-label="{{ __('frontend_list_template.page_size') }}" onchange="this.form.requestSubmit()">
                        @foreach ([20, 50, 100] as $size)<option @selected($perPage === $size)>{{ $size }}</option>@endforeach
                    </select>
                </label>
            </form>
            @if ($items->onFirstPage())<button type="button" class="flt-btn" disabled>{{ __('frontend_list_template.previous') }}</button>@else<a class="flt-btn" href="{{ $items->previousPageUrl() }}">{{ __('frontend_list_template.previous') }}</a>@endif
            <strong>{{ __('frontend_list_template.page_label', ['page' => $items->currentPage(), 'pages' => $lastPage]) }}</strong>
            @if ($items->hasMorePages())<a class="flt-btn" href="{{ $items->nextPageUrl() }}">{{ __('frontend_list_template.next') }}</a>@else<button type="button" class="flt-btn" disabled>{{ __('frontend_list_template.next') }}</button>@endif
            <form method="get" action="{{ $action }}">
                <input type="hidden" name="keyword" value="{{ $keyword }}"><input type="hidden" name="status" value="{{ $status }}"><input type="hidden" name="per_page" value="{{ $perPage }}">
                <label>{{ __('frontend_list_template.jump_to') }} <input type="number" name="page" min="1" max="{{ $lastPage }}" step="1" required aria-label="{{ __('frontend_list_template.page_placeholder') }}"></label>
                <button type="submit" class="flt-btn flt-primary">{{ __('frontend_list_template.jump') }}</button>
            </form>
        </nav>
    </section>
</section>
@endsection

@push('scripts')
    <script src="{{ request()->getSchemeAndHttpHost() }}/assets/erp/shopify-orders-filters.js?v=20261123-19"></script>
@endpush
