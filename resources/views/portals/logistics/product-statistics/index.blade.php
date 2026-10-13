@extends('portal.layout')

@section('page_title', __('logistics.product_statistics.title'))

@php
    $portalOrigin = rtrim(request()->getSchemeAndHttpHost(), '/');
@endphp

@push('styles')
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/product-statistics.css?v=20261009-6">
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
                    <span class="product-statistics-toolbar-hint"><i class="fa fa-info-circle"></i> {{ __('logistics.product_statistics.ui.expand_hint') }}</span>
                    <button class="btn btn-default btn-sm" type="button" data-toggle="modal" data-target="#product-statistics-export-modal"><i class="fa fa-download"></i> {{ __('logistics.common.export') }}</button>
                </div>
            </div>
            <div class="box-body table-responsive no-padding">
                <table class="table table-hover product-statistics-table" id="product-statistics-table">
                    <thead><tr>
                        <th class="product-statistics-check-column"><input id="product-statistics-check-all" type="checkbox" aria-label="{{ __('logistics.product_statistics.ui.select_current_page') }}"></th>
                        <th class="product-statistics-expand-column"></th>
                        @foreach(['name' => 'product', 'sku' => 'sku', 'shop_count' => 'stores', 'sales' => 'sales', 'revenue' => 'revenue', 'cost' => 'cost', 'fees' => 'fees', 'refunds' => 'refunds', 'profit' => 'profit', 'profit_rate' => 'profit_rate', 'refund_rate' => 'refund_rate', 'decision' => 'decision'] as $sort => $label)
                            <th><button type="button" class="product-statistics-sort" data-sort="{{ $sort }}">{{ __('logistics.product_statistics.headers.'.$label) }} <i class="fa fa-sort"></i></button></th>
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
                <div class="product-statistics-page-size"><label>{{ __('logistics.product_statistics.ui.per_page') }} <select id="product-statistics-page-size" class="form-control input-sm"><option value="5">5</option><option value="10" selected>10</option><option value="20">20</option></select> {{ __('logistics.product_statistics.ui.items') }}</label></div>
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
                    <li class="active"><a href="#product-statistics-overview" role="tab" data-toggle="tab">{{ __('logistics.product_statistics.ui.overview') }}</a></li>
                    <li><a href="#product-statistics-variants" role="tab" data-toggle="tab">{{ __('logistics.product_statistics.ui.variant_details') }}</a></li>
                    <li><a href="#product-statistics-stores" role="tab" data-toggle="tab">{{ __('logistics.product_statistics.ui.store_performance') }}</a></li>
                    <li><a href="#product-statistics-trend" role="tab" data-toggle="tab">{{ __('logistics.product_statistics.ui.sales_trend') }}</a></li>
                    <li><a href="#product-statistics-refunds" role="tab" data-toggle="tab">{{ __('logistics.product_statistics.ui.refund_details') }}</a></li>
                </ul>
                <div class="tab-content product-statistics-tab-content"><div role="tabpanel" class="tab-pane active" id="product-statistics-overview"></div><div role="tabpanel" class="tab-pane" id="product-statistics-variants"></div><div role="tabpanel" class="tab-pane" id="product-statistics-stores"></div><div role="tabpanel" class="tab-pane" id="product-statistics-trend"></div><div role="tabpanel" class="tab-pane" id="product-statistics-refunds"></div></div>
            </div>
            <div class="modal-footer"><span class="pull-left product-statistics-modal-footnote"><i class="fa fa-info-circle"></i> {{ __('logistics.product_statistics.ui.known_profit_only') }}</span><button type="button" class="btn btn-default" data-dismiss="modal">{{ __('logistics.common.close') }}</button></div>
        </div></div>
    </div>

    <div class="modal fade" id="product-statistics-image-modal" tabindex="-1" role="dialog" aria-labelledby="product-statistics-image-title">
        <div class="modal-dialog product-statistics-image-modal-dialog" role="document"><div class="modal-content product-statistics-image-modal-content">
            <div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="关闭"><span aria-hidden="true">&times;</span></button><h4 class="modal-title" id="product-statistics-image-title"><i class="fa fa-image"></i> 图片预览</h4></div>
            <div class="modal-body"><img id="product-statistics-image-preview" class="product-statistics-image-preview-content" src="" alt=""><p id="product-statistics-image-name" class="product-statistics-image-name"></p></div>
            <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">关闭</button></div>
        </div></div>
    </div>
@endsection

@push('scripts')
    <script>
        window.ErpLogisticsProductStatistics = {
            products: @json($products),
            decisions: @json($decisionOptions),
            labels: @json($labels),
            exportValidationError: @json($errors->first('scope')),
        };
    </script>
    <script src="{{ $portalOrigin }}/assets/erp/product-statistics.js?v=20261009-6"></script>
@endpush
