@extends('portal.layout')

@section('page_title', __('logistics.product_statistics.title'))

@php
    $portalOrigin = rtrim(request()->getSchemeAndHttpHost(), '/');
    $productHeaderTooltips = [
        'shop_count' => __('logistics.product_statistics.tooltips.shop_count'),
        'revenue' => __('logistics.product_statistics.tooltips.revenue'),
        'fees' => __('logistics.product_statistics.tooltips.fees'),
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
        'products' => $products,
        'summary' => $productSummary,
        'pagination' => $productPagination,
        'detailUrl' => route('logistics.product-statistics.detail'),
        'sort' => $filters['sort'],
        'direction' => $filters['direction'],
        'decisions' => $decisionOptions,
        'labels' => $labels,
        'exportValidationError' => $errors->first('scope'),
    ];
@endphp

@push('styles')
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/product-statistics.css?v=20261021-2">
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

        <div class="alert alert-info product-statistics-demo-notice">
            <i class="fa fa-database"></i> {{ __('logistics.product_statistics.real_data_notice') }}
        </div>

        <div class="box box-solid product-statistics-filter-box">
            <div class="box-body">
                <form id="product-statistics-filter-form" class="product-statistics-filter-form" autocomplete="off" method="get" action="{{ route('logistics.product-statistics.index') }}" data-loading="off">
                    <input id="product-statistics-sort-field" type="hidden" name="sort" value="{{ $filters['sort'] }}">
                    <input id="product-statistics-direction-field" type="hidden" name="direction" value="{{ $filters['direction'] }}">
                    <input id="product-statistics-per-page-field" type="hidden" name="per_page" value="{{ $filters['per_page'] }}">
                    <div class="product-statistics-filters">
                        <div class="product-statistics-filter-field product-statistics-date-field">
                            <label for="product-statistics-date-from">{{ __('logistics.product_statistics.filters.date_range') }}</label>
                            <div class="product-statistics-date-range">
                                <input id="product-statistics-date-from" name="date_from" type="date" class="form-control" value="{{ $filters['date_from'] }}">
                                <span>—</span>
                                <input id="product-statistics-date-to" name="date_to" type="date" class="form-control" value="{{ $filters['date_to'] }}">
                            </div>
                        </div>
                        <div class="product-statistics-filter-field">
                            <label for="product-statistics-store">{{ __('logistics.product_statistics.filters.store') }}</label>
                            <select id="product-statistics-store" name="store" class="form-control">
                                <option value="">{{ __('logistics.product_statistics.filters.all_stores') }}</option>
                                @foreach($stores as $store)
                                    <option value="{{ $store }}" @selected($filters['store'] === $store)>{{ $store }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="product-statistics-filter-field">
                            <label for="product-statistics-name">{{ __('logistics.product_statistics.filters.product_name') }}</label>
                            <input id="product-statistics-name" name="name" type="search" class="form-control" value="{{ $filters['name'] }}" placeholder="{{ __('logistics.product_statistics.filters.product_name_placeholder') }}">
                        </div>
                        <div class="product-statistics-filter-field product-statistics-search-field">
                            <label for="product-statistics-keyword">{{ __('logistics.product_statistics.filters.keyword') }}</label>
                            <div class="product-statistics-search-control">
                                <i class="fa fa-search" aria-hidden="true"></i>
                                <input id="product-statistics-keyword" name="keyword" type="search" class="form-control" value="{{ $filters['keyword'] }}" placeholder="{{ __('logistics.product_statistics.filters.keyword_placeholder') }}">
                            </div>
                        </div>
                        <div class="product-statistics-filter-field">
                            <label for="product-statistics-decision">{{ __('logistics.product_statistics.filters.decision') }}</label>
                            <select id="product-statistics-decision" name="decision" class="form-control">
                                <option value="">{{ __('logistics.common.all') }}</option>
                                @foreach($decisionOptions as $key => $label)
                                    <option value="{{ $key }}" @selected($filters['decision'] === $key)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="product-statistics-filter-actions">
                        <button class="btn btn-primary" type="submit"><i class="fa fa-search"></i> {{ __('logistics.common.search') }}</button>
                        <a class="btn btn-default" href="{{ route('logistics.product-statistics.index') }}">{{ __('logistics.common.reset') }}</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="product-statistics-summary" aria-live="polite">
            <div class="product-statistics-summary-item"><span>{{ __('logistics.product_statistics.metrics.products') }}</span><strong id="product-statistics-summary-products">—</strong><em>{{ __('logistics.product_statistics.metrics.current_filter') }}</em></div>
            <div class="product-statistics-summary-item"><span>{{ __('logistics.product_statistics.metrics.sales') }}</span><strong id="product-statistics-summary-sales">—</strong><em>{{ __('logistics.product_statistics.metrics.current_filter') }}</em></div>
            <div class="product-statistics-summary-item"><span>{{ __('logistics.product_statistics.metrics.revenue') }}</span><strong id="product-statistics-summary-revenue">—</strong><em>{{ __('logistics.product_statistics.metrics.current_filter') }}</em></div>
            <div class="product-statistics-summary-item"><span>{{ __('logistics.product_statistics.metrics.profit') }}</span><strong id="product-statistics-summary-profit">—</strong><em>{{ __('logistics.product_statistics.metrics.current_filter') }}</em></div>
            <div class="product-statistics-summary-item"><span>{{ __('logistics.product_statistics.metrics.profit_rate') }}</span><strong id="product-statistics-summary-profit-rate">—</strong><em>{{ __('logistics.product_statistics.metrics.weighted') }}</em></div>
        </div>

        <div class="box box-solid product-statistics-table-box">
            <div class="box-header with-border product-statistics-list-toolbar">
                <h3 class="box-title">{{ __('logistics.product_statistics.headers.list') }} <small id="product-statistics-total-label">—</small></h3>
                <div class="product-statistics-toolbar-actions">
                    <span class="product-statistics-toolbar-hint"><i class="fa fa-info-circle"></i> {{ __('logistics.product_statistics.ui.cross_product_note') }}</span>
                    <button class="btn btn-default btn-sm" type="button" data-toggle="modal" data-target="#product-statistics-export-modal"><i class="fa fa-download"></i> {{ __('logistics.common.export') }}</button>
                </div>
            </div>
            <div class="box-body table-responsive no-padding product-statistics-table-scroll">
                <table class="table table-hover product-statistics-table" id="product-statistics-table">
                    <thead><tr>
                        <th class="product-statistics-check-column"><input id="product-statistics-check-all" type="checkbox" aria-label="{{ __('logistics.product_statistics.ui.select_current_page') }}"></th>
                        @foreach([
                            'name' => 'product', 'product_code' => 'product_code', 'variant_count' => 'variant_count', 'shop_count' => 'stores', 'sales' => 'sales', 'revenue' => 'revenue', 'cost' => 'cost', 'fees' => 'fees',
                            'paypal_refund_amount' => 'paypal_refund_amount', 'paypal_refund_rate' => 'paypal_refund_rate', 'paypal_dispute_count' => 'paypal_dispute_count', 'paypal_dispute_rate' => 'paypal_dispute_rate',
                            'credit_card_refund_amount' => 'credit_card_refund_amount', 'credit_card_refund_rate' => 'credit_card_refund_rate', 'combined_refund_rate' => 'combined_refund_rate',
                            'profit' => 'profit', 'profit_rate' => 'profit_rate', 'decision' => 'decision',
                        ] as $sort => $label)
                            <th><button type="button" class="product-statistics-sort" data-sort="{{ $sort }}">{{ __('logistics.product_statistics.headers.'.$label) }} @if(isset($productHeaderTooltips[$sort]))<i class="fa fa-question-circle product-statistics-header-help" title="{{ $productHeaderTooltips[$sort] }}" aria-label="{{ $productHeaderTooltips[$sort] }}"></i>@endif <i class="fa fa-sort product-statistics-sort-indicator"></i></button></th>
                        @endforeach
                        <th>{{ __('logistics.common.operation') }}</th>
                    </tr></thead>
                    <tbody id="product-statistics-body"></tbody>
                </table>
                <div id="product-statistics-empty" class="product-statistics-empty" hidden>
                    <i class="fa fa-search"></i><strong>{{ __('logistics.product_statistics.ui.empty_title') }}</strong><span>{{ __('logistics.product_statistics.ui.empty_hint') }}</span>
                </div>
            </div>
            <div class="box-footer clearfix product-statistics-pagination">
                <span id="product-statistics-page-summary" class="product-statistics-page-summary">—</span>
                <div class="product-statistics-page-controls">
                    <button id="product-statistics-prev-page" type="button" class="btn btn-default btn-sm" aria-label="{{ __('logistics.product_statistics.ui.previous_page') }}"><i class="fa fa-angle-left"></i></button>
                    <span id="product-statistics-page-numbers"></span>
                    <button id="product-statistics-next-page" type="button" class="btn btn-default btn-sm" aria-label="{{ __('logistics.product_statistics.ui.next_page') }}"><i class="fa fa-angle-right"></i></button>
                </div>
                <div class="product-statistics-page-size"><label>{{ __('logistics.product_statistics.ui.per_page') }} <select id="product-statistics-page-size" class="form-control input-sm"><option value="5" @selected($filters['per_page'] === 5)>5</option><option value="10" @selected($filters['per_page'] === 10)>10</option><option value="20" @selected($filters['per_page'] === 20)>20</option></select> {{ __('logistics.product_statistics.ui.items') }}</label></div>
            </div>
        </div>

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

    <div class="modal fade" id="product-statistics-detail-modal" tabindex="-1" role="dialog" aria-labelledby="product-statistics-detail-title">
        <div class="modal-dialog modal-lg product-statistics-modal-dialog" role="document"><div class="modal-content product-statistics-modal-content">
            <div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="{{ __('logistics.common.close') }}"><span aria-hidden="true">&times;</span></button><h4 class="modal-title" id="product-statistics-detail-title">{{ __('logistics.product_statistics.ui.detail_title') }}</h4></div>
            <div class="modal-body">
                <div id="product-statistics-detail-identity" class="product-statistics-detail-identity"></div>
                <ul class="nav nav-tabs product-statistics-tabs" role="tablist">
                    <li class="active"><a href="#product-statistics-overview" role="tab" data-toggle="tab" data-product-detail-tab="overview">{{ __('logistics.product_statistics.ui.overview') }}</a></li>
                    <li><a href="#product-statistics-variants" role="tab" data-toggle="tab" data-product-detail-tab="variants">{{ __('logistics.product_statistics.ui.variant_details') }}</a></li>
                    <li><a href="#product-statistics-paypal-orders" role="tab" data-toggle="tab" data-product-detail-tab="paypal_orders">{{ __('logistics.product_statistics.ui.paypal_orders') }}</a></li>
                    <li><a href="#product-statistics-credit-card-orders" role="tab" data-toggle="tab" data-product-detail-tab="credit_card_orders">{{ __('logistics.product_statistics.ui.credit_card_orders') }}</a></li>
                    <li><a href="#product-statistics-refunds" role="tab" data-toggle="tab" data-product-detail-tab="refunds">{{ __('logistics.product_statistics.ui.refund_records') }}</a></li>
                    <li><a href="#product-statistics-disputes" role="tab" data-toggle="tab" data-product-detail-tab="disputes">{{ __('logistics.product_statistics.ui.paypal_disputes') }}</a></li>
                    <li><a href="#product-statistics-orders" role="tab" data-toggle="tab" data-product-detail-tab="orders">{{ __('logistics.product_statistics.ui.related_orders') }}</a></li>
                </ul>
                <div id="product-statistics-detail-filters" class="product-statistics-detail-filters" hidden></div>
                <div class="tab-content product-statistics-tab-content"><div role="tabpanel" class="tab-pane active" id="product-statistics-overview"></div><div role="tabpanel" class="tab-pane" id="product-statistics-variants"></div><div role="tabpanel" class="tab-pane" id="product-statistics-paypal-orders"></div><div role="tabpanel" class="tab-pane" id="product-statistics-credit-card-orders"></div><div role="tabpanel" class="tab-pane" id="product-statistics-refunds"></div><div role="tabpanel" class="tab-pane" id="product-statistics-disputes"></div><div role="tabpanel" class="tab-pane" id="product-statistics-orders"></div></div>
            </div>
            <div class="modal-footer"><span class="pull-left product-statistics-modal-footnote"><i class="fa fa-info-circle"></i> {{ __('logistics.product_statistics.ui.known_profit_only') }}</span><button type="button" class="btn btn-default" data-dismiss="modal">{{ __('logistics.common.close') }}</button></div>
        </div></div>
    </div>

    <div class="modal fade" id="product-statistics-image-modal" tabindex="-1" role="dialog" aria-labelledby="product-statistics-image-title">
        <div class="modal-dialog product-statistics-image-modal-dialog" role="document"><div class="modal-content product-statistics-image-modal-content">
            <div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="{{ __('logistics.common.close') }}"><span aria-hidden="true">&times;</span></button><h4 class="modal-title" id="product-statistics-image-title"><i class="fa fa-image"></i> {{ __('logistics.product_statistics.ui.image_preview') }}</h4></div>
            <div class="modal-body"><img id="product-statistics-image-preview" class="product-statistics-image-preview-content" src="" alt=""><p id="product-statistics-image-name" class="product-statistics-image-name"></p></div>
            <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">{{ __('logistics.common.close') }}</button></div>
        </div></div>
    </div>
    @include('portals.logistics.partials.order-detail-modal')
@endsection

@push('scripts')
    <script>
        window.ErpLogisticsProductStatistics = @json($productStatisticsClientConfig);
    </script>
    <script src="{{ $portalOrigin }}/assets/erp/product-statistics.js?v=20261021-2"></script>
    <script src="{{ $portalOrigin }}/assets/erp/logistics-order-detail.js?v=20261015-2"></script>
@endpush
