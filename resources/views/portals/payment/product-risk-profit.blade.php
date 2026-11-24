@extends('portal.layout')

@php
    $translationPrefix = 'payment.product_risk_profit';
    $portalOrigin = rtrim(request()->getSchemeAndHttpHost(), '/');
    $pagination = $dashboard['pagination'];
    $filters = $dashboard['filters'];
    $number = static fn ($value) => number_format((float) $value, ((float) $value == (int) $value) ? 0 : 2);
    $metricFilterText = $translationPrefix.'.metric_filters';
    $rateFilterGroups = [
        'refund_filters' => ['paypal_refund_rate', 'credit_card_refund_rate', 'combined_refund_rate'],
        'dispute_filters' => ['paypal_dispute_rate', 'credit_card_dispute_rate', 'paypal_delivered_dispute_rate', 'credit_card_delivered_dispute_rate'],
    ];
    $metricValue = static function (string $metric, string $bound) use ($filters): string {
        $value = old($metric.'_'.$bound, $filters['metric_ranges'][$metric][$bound] ?? '');

        return is_scalar($value) ? (string) $value : '';
    };
    $metricFields = ['order_count_min'];
    foreach (array_merge(...array_values($rateFilterGroups)) as $metric) {
        $metricFields[] = $metric.'_min';
        $metricFields[] = $metric.'_max';
    }
    $metricErrors = collect($metricFields)->flatMap(fn ($field) => $errors->get($field));
    $exportFilters = array_intersect_key($filters, array_flip(['date_from', 'date_to', 'store', 'name', 'keyword', 'only_abnormal', 'sort', 'direction']));
    foreach ($filters['metric_ranges'] ?? [] as $metric => $bounds) {
        foreach ($bounds as $bound => $value) {
            $exportFilters[$metric.'_'.$bound] = $value;
        }
    }
@endphp

@section('page_title', __($translationPrefix.'.page_title'))

@push('styles')
    <link rel="stylesheet" href="{{ $portalOrigin }}/css/frontend-list-template.css?v=20261124-21">
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/payment-portal.css?v=20261124-risk-table8">
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/payment-shared.css?v=20261026-1">
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/product-statistics.css?v=20261106-2">
@endpush

@section('content')
<section class="content-header payment-page-heading">
    <h1>{{ __($translationPrefix.'.page_title') }} <small>{{ __($translationPrefix.'.page_subtitle') }}</small></h1>
</section>
<section class="content payment-portal-page payment-product-risk-page">
    <div class="box box-solid payment-filter-box payment-risk-filter-panel"><div class="box-body">
        <form class="payment-risk-filter-form flt" method="get" action="{{ route('payment.product-risk-profit.index') }}" data-payment-product-filter
            data-logistics-filters data-search-options="{{ __('frontend_list_template.search_options') }}"
            data-no-options="{{ __('frontend_list_template.no_options') }}" data-clear-choice="{{ __('frontend_list_template.clear_choice') }}">
            <input type="hidden" name="per_page" value="{{ $pagination['per_page'] }}">
            <input type="hidden" name="sort" value="{{ $filters['sort'] }}"><input type="hidden" name="direction" value="{{ $filters['direction'] }}">
            <div class="flt-filter-grid payment-risk-filter-grid">
                <div><label class="flt-field-label" for="payment-risk-date-from">{{ __($translationPrefix.'.layout.order_date') }}</label><div class="flt-time-range">
                    <input id="payment-risk-date-from" class="form-control" type="date" name="date_from" aria-label="{{ __($translationPrefix.'.layout.date_from') }}" value="{{ $filters['date_from'] }}">
                    <input class="form-control" type="date" name="date_to" aria-label="{{ __($translationPrefix.'.layout.date_to') }}" value="{{ $filters['date_to'] }}">
                </div></div>
                <div><label class="flt-field-label" for="payment-risk-store">{{ __($translationPrefix.'.filters.store') }}</label>
                    <select id="payment-risk-store" class="form-control" name="store" data-filter-searchable>
                        <option value="">{{ __($translationPrefix.'.filters.all_stores') }}</option>
                        @foreach(array_unique(array_filter([$filters['store'], ...$dashboard['stores']])) as $store)
                            <option value="{{ $store }}" @selected($filters['store'] === $store)>{{ $store }}</option>
                        @endforeach
                    </select>
                </div>
                <div><label class="flt-field-label" for="payment-risk-keyword">{{ __($translationPrefix.'.filters.product') }}</label>
                    <input id="payment-risk-keyword" class="form-control" type="search" name="keyword" value="{{ $filters['keyword'] }}" placeholder="{{ __($translationPrefix.'.filters.product_placeholder') }}">
                </div>
                <div><label class="flt-field-label" for="payment-risk-status">{{ __($translationPrefix.'.filters.only_abnormal') }}</label>
                    <select id="payment-risk-status" class="form-control" name="only_abnormal"><option value="">{{ __($translationPrefix.'.filters.all_products') }}</option><option value="1" @selected($filters['only_abnormal'] === '1')>{{ __($translationPrefix.'.filters.abnormal') }}</option></select>
                </div>
            </div>
            <p class="payment-risk-refund-scope">{{ __($translationPrefix.'.layout.refund_scope') }}</p>
            <details class="payment-product-metric-filters" @if(!empty($filters['metric_ranges']) || $metricErrors->isNotEmpty()) open @endif>
                <summary>{{ __($metricFilterText.'.title') }}</summary>
                <div class="payment-product-metric-filter-content">
                    <p>{{ __($metricFilterText.'.hint') }}</p>
                    @if($metricErrors->isNotEmpty())
                        <ul class="text-danger" role="alert">
                            @foreach($metricErrors as $message)
                                <li>{{ $message }}</li>
                            @endforeach
                        </ul>
                    @endif
                    <div class="payment-product-metric-filter-grid payment-risk-metric-count">
                        <fieldset>
                            <legend>{{ __($metricFilterText.'.metrics.order_count') }}</legend>
                            <div class="payment-product-metric-bounds payment-product-metric-bounds-single">
                                <label>
                                    <span>{{ __($metricFilterText.'.order_count_min') }}</span>
                                    <input class="form-control" type="number" name="order_count_min" min="0" max="2147483647" step="1" value="{{ $metricValue('order_count', 'min') }}" @if($errors->has('order_count_min')) aria-invalid="true" @endif>
                                </label>
                            </div>
                        </fieldset>
                    </div>
                    @foreach($rateFilterGroups as $groupLabel => $metrics)
                        <section class="payment-risk-metric-section" aria-labelledby="payment-risk-{{ $groupLabel }}">
                        <h3 id="payment-risk-{{ $groupLabel }}">{{ __($translationPrefix.'.layout.'.$groupLabel) }}</h3>
                        <div class="payment-product-metric-filter-grid">
                        @foreach($metrics as $metric)
                            @php($metricLabel = __($metricFilterText.'.metrics.'.$metric))
                            <fieldset>
                                <legend>{{ $metricLabel }}</legend>
                                <div class="payment-product-metric-bounds">
                                    @foreach(['min', 'max'] as $bound)
                                        @php($field = $metric.'_'.$bound)
                                        <label>
                                            <span aria-hidden="true">{{ __($metricFilterText.'.bound_'.$bound) }}</span>
                                            <span class="sr-only">{{ __($metricFilterText.'.'.$bound, ['metric' => $metricLabel]) }}</span>
                                            <input class="form-control" type="number" name="{{ $field }}" min="0" max="99999999" step="0.01" value="{{ $metricValue($metric, $bound) }}" @if($errors->has($field)) aria-invalid="true" @endif>
                                        </label>
                                    @endforeach
                                </div>
                            </fieldset>
                        @endforeach
                        </div>
                        </section>
                    @endforeach
                </div>
            </details>
            <div class="payment-operation-filter-actions"><button class="btn btn-primary" type="submit"><i class="fa fa-search"></i> {{ __('payment.common.query') }}</button><a class="btn btn-default" href="{{ route('payment.product-risk-profit.index') }}">{{ __('payment.common.reset') }}</a></div>
        </form>
    </div></div>

    <section class="payment-refund-reason-report is-collapsed" data-payment-refund-report data-url="{{ route('payment.product-risk-profit.tab') }}" data-loaded="false">
        <header><div><h3><i class="fa fa-pie-chart"></i> {{ __($translationPrefix.'.refund_report.title') }}</h3><p>{{ __($translationPrefix.'.refund_report.hint') }}</p></div><div class="payment-refund-report-actions"><button type="button" class="btn btn-default btn-sm" data-payment-refund-report-toggle data-expand-text="{{ __($translationPrefix.'.refund_report.expand') }}" data-collapse-text="{{ __($translationPrefix.'.refund_report.collapse') }}"><i class="fa fa-angle-down"></i> <span>{{ __($translationPrefix.'.refund_report.expand') }}</span></button></div></header>
    </section>

    <div class="payment-tab-panel payment-product-risk-primary-panel">
        <div class="flt-toolbar payment-risk-list-toolbar">
            <div class="flt-selection">
                <span>{{ __('frontend_list_template.records', ['count' => $number($pagination['total'])]) }}</span>
                <span class="flt-toolbar-divider" aria-hidden="true"></span>
                <span aria-live="polite" data-payment-risk-selected data-count-label="{{ __('frontend_list_template.selected_records') }}">{{ __('frontend_list_template.selected_records', ['count' => 0]) }}</span>
                <button type="button" class="flt-btn" data-payment-risk-clear disabled>{{ __('frontend_list_template.clear_selected') }}</button>
            </div>
            <div class="flt-toolbar-actions">
                <button class="flt-btn" type="button" data-payment-product-export aria-haspopup="dialog" aria-controls="payment-risk-export-modal" @disabled($pagination['total'] === 0)>
                    <i class="fa fa-download" aria-hidden="true"></i> {{ __('frontend_list_template.export') }}
                </button>
            </div>
        </div>
        @include('portals.payment.partials.product-risk-tab', ['tab' => 'risk_metrics', 'dashboard' => $dashboard])
    </div>

    <div class="modal fade" id="payment-risk-export-modal" tabindex="-1" role="dialog" aria-labelledby="payment-risk-export-title">
        <div class="modal-dialog modal-sm" role="document">
            <form class="modal-content" method="post" action="{{ route('payment.product-risk-profit.export-list') }}" data-payment-risk-export-form>
                @csrf
                @foreach($exportFilters as $field => $value)
                    <input type="hidden" name="{{ $field }}" value="{{ $value }}">
                @endforeach
                <input type="hidden" name="page" value="{{ $pagination['current_page'] }}">
                <input type="hidden" name="per_page" value="{{ $pagination['per_page'] }}">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-label="{{ __($translationPrefix.'.detail.close') }}"><span aria-hidden="true">&times;</span></button>
                    <h4 id="payment-risk-export-title" class="modal-title">{{ __('frontend_list_template.export_title') }}</h4>
                </div>
                <div class="modal-body payment-risk-export-choices">
                    <label><input type="radio" name="scope" value="filtered" checked> {{ __('frontend_list_template.export_filtered', ['count' => $number($pagination['total'])]) }}</label>
                    <label><input type="radio" name="scope" value="selected" disabled> <span data-payment-risk-export-selected data-count-label="{{ __($translationPrefix.'.layout.export_selected') }}">{{ __($translationPrefix.'.layout.export_selected', ['count' => 0]) }}</span></label>
                </div>
                <div class="modal-footer">
                    <button type="button" class="flt-btn" data-dismiss="modal">{{ __('frontend_list_template.cancel') }}</button>
                    <button type="submit" class="btn btn-primary"><i class="fa fa-download" aria-hidden="true"></i> {{ __('frontend_list_template.export') }}</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal fade payment-product-image-modal" id="payment-product-image-modal" tabindex="-1" role="dialog" aria-labelledby="payment-product-image-title"><div class="modal-dialog modal-lg" role="document"><div class="modal-content"><div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="{{ __($translationPrefix.'.detail.close') }}"><span>&times;</span></button><h4 id="payment-product-image-title">{{ __($translationPrefix.'.image_preview.title') }}</h4><p data-payment-product-image-name></p></div><div class="modal-body"><img data-payment-product-image-preview src="" alt=""></div></div></div></div>

    <div class="modal fade payment-product-detail-modal" id="payment-product-detail-modal" tabindex="-1" role="dialog" aria-labelledby="payment-product-detail-title"><div class="modal-dialog modal-lg" role="document"><div class="modal-content"><div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="{{ __($translationPrefix.'.detail.close') }}"><span>&times;</span></button><h4 id="payment-product-detail-title">{{ __($translationPrefix.'.detail.title') }}</h4><p data-payment-product-detail-subtitle></p></div><div class="modal-body">
        <div class="payment-detail-tabs" role="tablist">@foreach(['overview','first_renewal','orders','variants','refunds','disputes'] as $tab)<button type="button" class="{{ $loop->first ? 'active' : '' }}" data-payment-product-detail-tab="{{ $tab }}">{{ __($translationPrefix.'.detail.tabs.'.$tab) }}</button>@endforeach</div>
        <form class="payment-detail-filter payment-risk-style-filter product-statistics-detail-filter-form" data-payment-product-detail-filter hidden><label class="payment-detail-filter-keyword is-keyword"><span>{{ __($translationPrefix.'.detail.filters.keyword') }}</span><input class="form-control" name="detail_keyword" type="search" placeholder="{{ __($translationPrefix.'.detail.search_placeholder') }}"></label><div class="payment-detail-filter-selects" data-payment-product-extra-filters></div><div class="payment-detail-filter-actions"><button class="btn btn-primary" type="submit"><i class="fa fa-search"></i> {{ __('payment.common.query') }}</button><button class="btn btn-default" type="reset">{{ __('payment.common.reset') }}</button></div></form>
        <div class="payment-detail-state" data-payment-product-loading><i class="fa fa-spinner fa-spin"></i> {{ __($translationPrefix.'.detail.loading') }}</div><div class="payment-detail-state payment-detail-error" data-payment-product-error hidden></div><div data-payment-product-detail-body></div><div class="payment-detail-pagination" data-payment-product-detail-pagination></div>
    </div><div class="modal-footer"><button class="btn btn-default" type="button" data-dismiss="modal">{{ __($translationPrefix.'.detail.close') }}</button></div></div></div></div>
    @include('portals.logistics.partials.order-detail-modal')
</section>
@endsection

@push('scripts')
<script>window.PaymentPortal = { productDetailUrl: @json(route('payment.product-risk-profit.detail')), productDetail: @json(__($translationPrefix.'.detail')), productExport: @json(__($translationPrefix.'.export')), refundReport: @json(__($translationPrefix.'.refund_report')), pagination: @json(__('payment.common.pagination')) };</script>
<script src="{{ $portalOrigin }}/assets/erp/shopify-orders-filters.js?v=5" defer></script>
<script src="{{ $portalOrigin }}/assets/erp/payment-portal.js?v=20261124-risk-export7"></script>
<script src="{{ $portalOrigin }}/assets/erp/logistics-order-detail.js?v=20261015-2"></script>
@endpush
