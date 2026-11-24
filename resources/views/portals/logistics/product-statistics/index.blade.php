@extends('portal.layout')

@section('page_title', __('logistics.product_statistics.title'))

@php
    $portalOrigin = rtrim(request()->getSchemeAndHttpHost(), '/');
    $productHeaderTooltips = [
        'shop_count' => __('logistics.product_statistics.tooltips.shop_count'),
        'revenue' => __('logistics.product_statistics.tooltips.revenue'),
        'paypal_refund_amount' => __('logistics.product_statistics.tooltips.paypal_refund_amount'),
        'paypal_refund_rate' => __('logistics.product_statistics.tooltips.paypal_refund_rate'),
        'paypal_dispute_count' => __('logistics.product_statistics.tooltips.paypal_dispute_count'),
        'paypal_dispute_rate' => __('logistics.product_statistics.tooltips.paypal_dispute_rate'),
        'credit_card_refund_amount' => __('logistics.product_statistics.tooltips.credit_card_refund_amount'),
        'credit_card_refund_rate' => __('logistics.product_statistics.tooltips.credit_card_refund_rate'),
        'combined_refund_rate' => __('logistics.product_statistics.tooltips.combined_refund_rate'),
        'shipping_rate' => __('logistics.product_statistics.tooltips.shipping_rate'),
        'delivery_rate' => __('logistics.product_statistics.tooltips.delivery_rate'),
    ];
    $productStatisticsClientConfig = [
        'listUrl' => route('logistics.product-statistics.list'),
        'storesUrl' => route('logistics.product-statistics.stores'),
        'refundReasonsUrl' => route('logistics.product-statistics.refund-reasons'),
        'detailUrl' => route('logistics.product-statistics.detail'),
        'retryTongtuSkusUrl' => route('logistics.product-statistics.tongtu-skus.retry'),
        'sort' => $filters['sort'],
        'direction' => $filters['direction'],
        'labels' => $labels,
        'exportValidationError' => $errors->first('scope'),
    ];
@endphp

@push('styles')
    <link rel="stylesheet" href="{{ $portalOrigin }}/css/frontend-list-template.css?v=20261123-19">
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/product-statistics.css?v=20261027-13">
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/logistics-order-detail.css?v=20261015-2">
@endpush

@section('content')
    <section class="content-header product-statistics-header">
        <h1>{{ __('logistics.product_statistics.title') }} <small>{{ __('logistics.product_statistics.subtitle') }}</small></h1>
    </section>

    <section class="content logistics-page product-statistics-page">
        @if($errors->has('scope'))
            <div class="alert alert-danger"><i class="fa fa-exclamation-circle"></i> {{ $errors->first('scope') }}</div>
        @endif
        @if(session('success'))
            <div class="alert alert-success"><i class="fa fa-check-circle"></i> {{ session('success') }}</div>
        @endif
        @if(session('info'))
            <div class="alert alert-info"><i class="fa fa-info-circle"></i> {{ session('info') }}</div>
        @endif

        <div class="flt product-statistics-filter-section">
                <form id="product-statistics-filter-form" class="flt-card flt-filters" autocomplete="off" method="get" action="{{ route('logistics.product-statistics.index') }}" data-loading="off"
                    data-logistics-filters data-search-options="{{ __('frontend_list_template.search_options') }}"
                    data-no-options="{{ __('frontend_list_template.no_options') }}" data-clear-choice="{{ __('frontend_list_template.clear_choice') }}">
                    <input id="product-statistics-sort-field" type="hidden" name="sort" value="{{ $filters['sort'] }}">
                    <input id="product-statistics-direction-field" type="hidden" name="direction" value="{{ $filters['direction'] }}">
                    <input id="product-statistics-per-page-field" type="hidden" name="per_page" value="{{ $filters['per_page'] }}">
                    <div class="flt-filter-grid product-statistics-filters">
                        <div class="product-statistics-filter-field product-statistics-date-field">
                            <label for="product-statistics-date-from">{{ __('logistics.product_statistics.filters.paid_date_range') }}</label>
                            <div class="flt-time-range">
                                <input id="product-statistics-date-from" name="date_from" type="date" class="form-control" aria-label="{{ __('logistics.orders.list.date_from') }}" value="{{ $filters['date_from'] }}">
                                <input id="product-statistics-date-to" name="date_to" type="date" class="form-control" aria-label="{{ __('logistics.orders.list.date_to') }}" value="{{ $filters['date_to'] }}">
                            </div>
                        </div>
                        <div class="product-statistics-filter-field">
                            <label for="product-statistics-store">{{ __('logistics.product_statistics.filters.store') }}</label>
                            <div class="product-statistics-store-control">
                            <select id="product-statistics-store" name="store" data-filter-searchable class="form-control">
                                <option value="">{{ __('logistics.product_statistics.filters.all_stores') }}</option>
                                @if($filters['store'] !== '')
                                    <option value="{{ $filters['store'] }}" selected>{{ $filters['store'] }}</option>
                                @endif
                            </select>
                            <div id="product-statistics-stores-status" class="product-statistics-stores-status">
                                <span id="product-statistics-stores-loading" aria-hidden="true"><i class="fa fa-circle-o-notch fa-spin"></i> {{ __('logistics.product_statistics.ui.stores_loading_short') }}</span>
                                <span id="product-statistics-stores-message" class="flt-sr-only" role="status">{{ __('logistics.product_statistics.ui.stores_loading') }}</span>
                                <button id="product-statistics-stores-retry" type="button" class="flt-row-action" hidden>{{ __('logistics.product_statistics.ui.retry_load') }}</button>
                            </div>
                            </div>
                        </div>
                        <div class="product-statistics-filter-field">
                            <label for="product-statistics-name">{{ __('logistics.product_statistics.filters.product_name') }}</label>
                            <input id="product-statistics-name" name="name" type="search" class="form-control" value="{{ $filters['name'] }}" placeholder="{{ __('logistics.product_statistics.filters.product_name_placeholder') }}">
                        </div>
                        <div class="product-statistics-filter-field product-statistics-search-field">
                            <label for="product-statistics-keyword">{{ __('logistics.product_statistics.filters.keyword') }}</label>
                            <input id="product-statistics-keyword" name="keyword" type="search" class="form-control" value="{{ $filters['keyword'] }}" placeholder="{{ __('logistics.product_statistics.filters.keyword_placeholder') }}">
                        </div>
                        <div class="product-statistics-filter-field">
                            <label for="product-statistics-match-status">{{ __('logistics.product_statistics.filters.match_status') }}</label>
                            <select id="product-statistics-match-status" name="match_status" class="form-control">
                                <option value="">{{ __('logistics.common.all') }}</option>
                                @foreach($matchStatusOptions as $key => $label)
                                    <option value="{{ $key }}" @selected($filters['match_status'] === $key)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="flt-filter-actions">
                        <button class="flt-btn flt-primary" type="submit"><i class="fa fa-search"></i> {{ __('logistics.common.search') }}</button>
                        <a id="product-statistics-reset" class="flt-btn" href="{{ route('logistics.product-statistics.index') }}">{{ __('logistics.common.reset') }}</a>
                    </div>
                </form>
        </div>

        <section class="flt-stat-grid product-statistics-stat-grid" aria-live="polite">
            @foreach ([
                ['products', 'products', 'blue', 'fa-cube'],
                ['sales', 'sales', 'cyan', 'fa-shopping-cart'],
                ['revenue', 'revenue', 'green', 'fa-usd'],
                ['actual_refunds', 'refunds', 'orange', 'fa-reply'],
            ] as [$metric, $id, $tone, $icon])
                <article class="flt-stat" data-stat-tone="{{ $tone }}">
                    <div>
                        <h3>{{ __('logistics.product_statistics.metrics.'.$metric) }}</h3>
                        <p><strong id="product-statistics-summary-{{ $id }}">—</strong></p>
                        <small>{{ __('logistics.product_statistics.metrics.current_filter') }}</small>
                    </div>
                    <span class="flt-stat-icon"><i class="fa {{ $icon }}" aria-hidden="true"></i></span>
                </article>
            @endforeach
        </section>

        <section id="product-statistics-reason-report" class="product-statistics-reason-report" aria-live="polite"></section>

        <div class="flt flt-card flt-records product-statistics-table-box">
            <div class="flt-toolbar">
                <div class="flt-selection" aria-live="polite">
                    <span id="product-statistics-total-label" data-template="{{ __('logistics.product_statistics.ui.list_total', ['count' => '__count__']) }}">—</span>
                    <span class="flt-toolbar-divider" aria-hidden="true"></span>
                    <span id="product-statistics-selected-count" data-template="{{ __('logistics.product_statistics.ui.list_selected', ['count' => '__count__']) }}"></span>
                    <button id="product-statistics-clear-selection" type="button" disabled>{{ __('frontend_list_template.clear_selected') }}</button>
                </div>
                <div class="flt-toolbar-actions">
                    <span class="product-statistics-toolbar-hint"><i class="fa fa-info-circle"></i> {{ __('logistics.product_statistics.ui.cross_product_note') }}</span>
                    <button id="product-statistics-retry-tongtu" class="flt-btn" type="button" disabled><i class="fa fa-refresh"></i> {{ __('logistics.product_statistics.ui.retry_tongtu_skus') }}</button>
                    <button id="product-statistics-export-button" class="flt-btn flt-primary" type="button" data-toggle="modal" data-target="#product-statistics-export-modal" disabled><i class="fa fa-download"></i> {{ __('logistics.common.export') }}</button>
                </div>
            </div>
            <div class="product-statistics-list-region" id="product-statistics-list-region" aria-busy="true">
            <div class="flt-table-scroll product-statistics-table-scroll" tabindex="0" aria-label="{{ __('logistics.product_statistics.headers.list') }}">
                <table class="table table-hover product-statistics-table" id="product-statistics-table">
                    <thead><tr>
                        <th class="product-statistics-check-column"><input id="product-statistics-check-all" type="checkbox" aria-label="{{ __('logistics.product_statistics.ui.select_current_page') }}"></th>
                        @foreach([
                            'name' => 'product', 'product_code' => 'product_code', 'match_status' => 'match_status', 'shop_count' => 'stores', 'sales' => 'sales', 'revenue' => 'revenue',
                            'paypal_refund_amount' => 'paypal_refund_amount', 'paypal_refund_rate' => 'paypal_refund_rate', 'paypal_dispute_count' => 'paypal_dispute_count', 'paypal_dispute_rate' => 'paypal_dispute_rate',
                            'credit_card_refund_amount' => 'credit_card_refund_amount', 'credit_card_refund_rate' => 'credit_card_refund_rate', 'combined_refund_rate' => 'combined_refund_rate',
                        ] as $sort => $label)
                            <th>
                                @if($sort === 'match_status')
                                    {{ __('logistics.product_statistics.headers.'.$label) }}
                                @else
                                    <button type="button" class="product-statistics-sort" data-sort="{{ $sort }}">{{ __('logistics.product_statistics.headers.'.$label) }} @if(isset($productHeaderTooltips[$sort]))<i class="fa fa-question-circle product-statistics-header-help" title="{{ $productHeaderTooltips[$sort] }}" aria-label="{{ $productHeaderTooltips[$sort] }}"></i>@endif <i class="fa fa-sort product-statistics-sort-indicator"></i></button>
                                @endif
                            </th>
                        @endforeach
                        <th>{{ __('logistics.common.operation') }}</th>
                    </tr></thead>
                    <tbody id="product-statistics-body"></tbody>
                    <tbody class="product-statistics-list-feedback">
                        <tr id="product-statistics-list-loading">
                            <td colspan="15">
                                <div class="product-statistics-loading product-statistics-list-loading" role="status">
                                    <i class="fa fa-circle-o-notch fa-spin" aria-hidden="true"></i>
                                    <span>{{ __('logistics.product_statistics.ui.list_loading') }}</span>
                                </div>
                            </td>
                        </tr>
                        <tr id="product-statistics-list-error" hidden>
                            <td colspan="15">
                                <div class="product-statistics-load-error" role="alert">
                                    <span id="product-statistics-list-error-message"></span>
                                    <button id="product-statistics-list-retry" type="button" class="flt-btn">{{ __('logistics.product_statistics.ui.retry_load') }}</button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <div id="product-statistics-empty" class="product-statistics-empty" hidden>
                    <i class="fa fa-search"></i><strong>{{ __('logistics.product_statistics.ui.empty_title') }}</strong><span>{{ __('logistics.product_statistics.ui.empty_hint') }}</span>
                </div>
            </div>
            </div>
            <div class="flt-pagination product-statistics-pagination">
                <span id="product-statistics-page-summary" class="product-statistics-page-summary">—</span>
                <div class="product-statistics-pagination-actions">
                    <label>{{ __('logistics.product_statistics.ui.per_page') }}
                        <select id="product-statistics-page-size">
                            @foreach ([5, 10, 20] as $size)
                                <option value="{{ $size }}" @selected($filters['per_page'] === $size)>{{ $size }}</option>
                            @endforeach
                        </select>
                    </label>
                    <nav class="product-statistics-page-links" aria-label="{{ __('logistics.orders.list.pagination') }}">
                        <button id="product-statistics-prev-page" type="button" class="flt-btn" disabled>{{ __('logistics.product_statistics.ui.previous_page') }}</button>
                        <span id="product-statistics-page-position"></span>
                        <button id="product-statistics-next-page" type="button" class="flt-btn" disabled>{{ __('logistics.product_statistics.ui.next_page') }}</button>
                    </nav>
                    <form id="product-statistics-page-jump" data-loading="off">
                        <label><span class="flt-sr-only">{{ __('logistics.orders.list.page') }}</span><input type="number" id="product-statistics-page-input" min="1" max="1" required disabled placeholder="{{ __('logistics.orders.list.page') }}"></label>
                        <button class="flt-btn flt-primary" type="submit" disabled>{{ __('logistics.orders.list.jump') }}</button>
                    </form>
                </div>
            </div>
        </div>

        <form id="product-statistics-retry-tongtu-form" method="post" action="{{ route('logistics.product-statistics.tongtu-skus.retry') }}" data-loading="submit" hidden>
            @csrf
            <div id="product-statistics-retry-tongtu-payload"></div>
        </form>

        <div class="modal fade" id="product-statistics-export-modal" tabindex="-1" role="dialog" aria-labelledby="product-statistics-export-title">
            <div class="modal-dialog" role="document"><form method="post" action="{{ route('logistics.product-statistics.export') }}" class="modal-content" id="product-statistics-export-form" data-loading="download">@csrf
                <div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="{{ __('logistics.common.close') }}"><span aria-hidden="true">&times;</span></button><h4 class="modal-title" id="product-statistics-export-title"><i class="fa fa-download"></i> {{ __('logistics.product_statistics.ui.export_title') }}</h4></div>
                <div class="modal-body">
                    <div class="alert alert-info product-statistics-export-notice"><i class="fa fa-info-circle"></i> {{ __('logistics.product_statistics.ui.export_notice') }}</div>
                    <p class="product-statistics-modal-section-title">{{ __('logistics.product_statistics.ui.export_scope') }}</p>
                    <label class="product-statistics-export-scope"><input type="radio" name="scope" value="selected" checked><span><strong>{{ __('logistics.product_statistics.ui.export_selected') }}</strong><small id="product-statistics-export-selected-count"></small></span></label>
                    <label class="product-statistics-export-scope"><input type="radio" name="scope" value="filtered"><span><strong>{{ __('logistics.product_statistics.ui.export_filtered') }}</strong><small id="product-statistics-export-filtered-count"></small></span></label>
                    <div id="product-statistics-export-payload"></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">{{ __('logistics.common.cancel') }}</button><button type="submit" class="btn btn-primary"><i class="fa fa-download"></i> {{ __('logistics.common.confirm_export') }}</button></div>
            </form></div>
        </div>
    </section>

    <div class="modal" id="product-statistics-detail-modal" tabindex="-1" role="dialog" aria-labelledby="product-statistics-detail-title">
        <div class="modal-dialog modal-lg product-statistics-modal-dialog" role="document"><div class="modal-content product-statistics-modal-content">
            <div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="{{ __('logistics.common.close') }}"><span aria-hidden="true">&times;</span></button><h4 class="modal-title" id="product-statistics-detail-title">{{ __('logistics.product_statistics.ui.detail_title') }}</h4></div>
            <div class="modal-body">
                <div id="product-statistics-detail-identity" class="product-statistics-detail-identity"></div>
                <ul class="nav nav-tabs product-statistics-tabs" role="tablist">
                    <li class="active"><a href="#product-statistics-overview" role="tab" data-toggle="tab" data-product-detail-tab="overview">{{ __('logistics.product_statistics.ui.overview') }}</a></li>
                    <li><a href="#product-statistics-paypal-orders" role="tab" data-toggle="tab" data-product-detail-tab="paypal_orders">{{ __('logistics.product_statistics.ui.paypal_orders') }}</a></li>
                    <li><a href="#product-statistics-credit-card-orders" role="tab" data-toggle="tab" data-product-detail-tab="credit_card_orders">{{ __('logistics.product_statistics.ui.credit_card_orders') }}</a></li>
                    <li><a href="#product-statistics-refunds" role="tab" data-toggle="tab" data-product-detail-tab="refunds">{{ __('logistics.product_statistics.ui.refund_records') }}</a></li>
                    <li><a href="#product-statistics-disputes" role="tab" data-toggle="tab" data-product-detail-tab="disputes">{{ __('logistics.product_statistics.ui.paypal_disputes') }}</a></li>
                    <li><a href="#product-statistics-orders" role="tab" data-toggle="tab" data-product-detail-tab="orders">{{ __('logistics.product_statistics.ui.related_orders') }}</a></li>
                </ul>
                <div class="flt product-statistics-detail-scroll" tabindex="0" role="region" aria-labelledby="product-statistics-detail-title">
                    <div id="product-statistics-detail-filters" class="product-statistics-detail-filters" hidden></div>
                    <div class="tab-content product-statistics-tab-content">
                        <div role="tabpanel" class="tab-pane active" id="product-statistics-overview"></div>
                        <div role="tabpanel" class="tab-pane" id="product-statistics-paypal-orders"></div>
                        <div role="tabpanel" class="tab-pane" id="product-statistics-credit-card-orders"></div>
                        <div role="tabpanel" class="tab-pane" id="product-statistics-refunds"></div>
                        <div role="tabpanel" class="tab-pane" id="product-statistics-disputes"></div>
                        <div role="tabpanel" class="tab-pane" id="product-statistics-orders"></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">{{ __('logistics.common.close') }}</button></div>
        </div></div>
    </div>

    <div class="modal fade" id="product-statistics-image-modal" tabindex="-1" role="dialog" aria-labelledby="product-statistics-image-title">
        <div class="modal-dialog product-statistics-image-modal-dialog" role="document"><div class="modal-content product-statistics-image-modal-content">
            <div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="{{ __('logistics.common.close') }}"><span aria-hidden="true">&times;</span></button><h4 class="modal-title" id="product-statistics-image-title"><i class="fa fa-image"></i> {{ __('logistics.product_statistics.ui.image_preview') }}</h4></div>
            <div class="modal-body"><img id="product-statistics-image-preview" class="product-statistics-image-preview-content" src="" alt=""><p id="product-statistics-image-name" class="product-statistics-image-name"></p></div>
            <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">{{ __('logistics.common.close') }}</button></div>
        </div></div>
    </div>
    @include('portals.logistics.partials.order-detail-modal', ['showDetailLoading' => true])
@endsection

@push('scripts')
    <script>
        window.ErpLogisticsProductStatistics = @json($productStatisticsClientConfig);
    </script>
    <script src="{{ $portalOrigin }}/assets/erp/shopify-orders-filters.js?v=5" defer></script>
    <script src="{{ $portalOrigin }}/assets/erp/product-statistics.js?v=20261027-10"></script>
    <script src="{{ $portalOrigin }}/assets/erp/logistics-order-detail.js?v=20261014-2"></script>
@endpush
