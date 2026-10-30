@extends('portal.layout')

@php
    $translationPrefix = 'payment.product_risk_profit';
    $portalOrigin = rtrim(request()->getSchemeAndHttpHost(), '/');
    $summary = $dashboard['summary'];
    $pagination = $dashboard['pagination'];
    $filters = $dashboard['filters'];
    $money = static fn ($value) => '$'.number_format((float) $value, 2);
    $number = static fn ($value) => number_format((float) $value, ((float) $value == (int) $value) ? 0 : 2);
    $rate = static fn ($value) => number_format((float) $value, 2).'%';
    $sortableHeaders = ['product'=>'name','product_code'=>'product_code','stores'=>'shop_count','sales'=>'sales','revenue'=>'revenue','paypal_refund'=>'paypal_refund_amount','paypal_dispute'=>'paypal_dispute_count','card_refund'=>'credit_card_refund_amount','combined_refund'=>'combined_refund_rate'];
@endphp

@section('page_title', __($translationPrefix.'.page_title'))

@push('styles')
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/payment-portal.css?v=20261026-1">
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/payment-shared.css?v=20261026-1">
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/product-statistics.css?v=20261021-2">
@endpush

@section('content')
<section class="content-header payment-page-heading">
    <h1>{{ __($translationPrefix.'.page_title') }} <small>{{ __($translationPrefix.'.page_subtitle') }}</small></h1>
</section>
<section class="content payment-portal-page payment-product-risk-page">
    <div class="box box-solid payment-filter-box"><div class="box-body">
        <form class="payment-operation-filter-form" method="get" action="{{ route('payment.product-risk-profit.index') }}" data-payment-product-filter>
            <input type="hidden" name="per_page" value="{{ $pagination['per_page'] }}">
            <input type="hidden" name="sort" value="{{ $filters['sort'] }}"><input type="hidden" name="direction" value="{{ $filters['direction'] }}">
            <div class="payment-account-filter-field payment-account-date-mode-field"><span>{{ __('payment.account_risk.filters.date_mode') }}</span><input type="hidden" name="date_mode" value="{{ $filters['date_mode'] }}" data-payment-account-date-mode-input><div class="payment-account-date-mode" role="group" aria-label="{{ __('payment.account_risk.filters.date_mode') }}"><button type="button" class="{{ $filters['date_mode'] === 'order' ? 'active' : '' }}" data-payment-account-date-mode="order">{{ __('payment.account_risk.filters.payment_date') }}</button><button type="button" class="{{ $filters['date_mode'] === 'event' ? 'active' : '' }}" data-payment-account-date-mode="event">{{ __('payment.account_risk.filters.event_date') }}</button></div></div>
            <label class="payment-operation-filter-field"><span>{{ __('payment.common.period') }}</span><span class="payment-operation-date-range"><input class="form-control" type="date" name="date_from" value="{{ $filters['date_from'] }}"><b>{{ __('payment.common.range_to') }}</b><input class="form-control" type="date" name="date_to" value="{{ $filters['date_to'] }}"></span></label>
            <label class="payment-operation-filter-field"><span>{{ __($translationPrefix.'.filters.store') }}</span><select class="form-control" name="store"><option value="">{{ __($translationPrefix.'.filters.all_stores') }}</option>@foreach($dashboard['stores'] as $store)<option value="{{ $store }}" @selected($filters['store'] === $store)>{{ $store }}</option>@endforeach</select></label>
            <label class="payment-operation-filter-field"><span>{{ __($translationPrefix.'.filters.product') }}</span><input class="form-control" type="search" name="keyword" value="{{ $filters['keyword'] }}" placeholder="{{ __($translationPrefix.'.filters.product_placeholder') }}"></label>
            <label class="payment-operation-filter-field"><span>{{ __($translationPrefix.'.filters.only_abnormal') }}</span><select class="form-control" name="only_abnormal"><option value="">{{ __($translationPrefix.'.filters.all_products') }}</option><option value="1" @selected($filters['only_abnormal'] === '1')>{{ __($translationPrefix.'.filters.abnormal') }}</option></select></label>
            <div class="payment-operation-filter-actions"><button class="btn btn-primary" type="submit"><i class="fa fa-search"></i> {{ __('payment.common.query') }}</button><a class="btn btn-default" href="{{ route('payment.product-risk-profit.index') }}">{{ __('payment.common.reset') }}</a><button class="btn btn-success" type="button" data-payment-product-export data-export-url="{{ route('payment.product-risk-profit.export') }}"><i class="fa fa-file-excel-o"></i> {{ __($translationPrefix.'.export.button') }}</button></div>
        </form>
    </div></div>

    <div class="payment-kpi-grid payment-kpi-grid-three">
        <article class="payment-kpi-card"><span class="payment-kpi-icon payment-kpi-icon-blue"><i class="fa fa-cubes"></i></span><div><p>{{ __($translationPrefix.'.kpis.products') }}</p><strong>{{ $number($summary['product_count']) }} / {{ $number($summary['sales']) }}</strong><small>{{ __($translationPrefix.'.kpis.products_real_note') }}</small></div></article>
        <article class="payment-kpi-card"><span class="payment-kpi-icon payment-kpi-icon-cyan"><i class="fa fa-usd"></i></span><div><p>{{ __($translationPrefix.'.kpis.receipts') }}</p><strong>{{ $money($summary['revenue']) }}</strong><small>{{ __($translationPrefix.'.kpis.receipts_real_note') }}</small></div></article>
        <article class="payment-kpi-card payment-tone-warning"><span class="payment-kpi-icon payment-kpi-icon-orange"><i class="fa fa-reply"></i></span><div><p>{{ __($translationPrefix.'.kpis.refund') }} <i class="fa fa-info-circle payment-formula-icon" tabindex="0" data-payment-formula-tooltip data-toggle="tooltip" data-container="body" title="{{ __($translationPrefix.'.formulas.refund_amount_rate') }}"></i></p><strong>{{ $money($summary['actual_refunds']) }} / {{ $rate($dashboard['refund_rate']) }}</strong><small>{{ __($translationPrefix.'.kpis.refund_real_note') }}</small></div></article>
    </div>

    <section class="payment-refund-reason-report is-collapsed" data-payment-refund-report data-url="{{ route('payment.product-risk-profit.tab') }}" data-loaded="false">
        <header><div><h3><i class="fa fa-pie-chart"></i> {{ __($translationPrefix.'.refund_report.title') }}</h3><p>{{ __($translationPrefix.'.refund_report.hint') }}</p></div><div class="payment-refund-report-actions"><button type="button" class="btn btn-default btn-sm" data-payment-refund-report-toggle data-expand-text="{{ __($translationPrefix.'.refund_report.expand') }}" data-collapse-text="{{ __($translationPrefix.'.refund_report.collapse') }}"><i class="fa fa-angle-down"></i> <span>{{ __($translationPrefix.'.refund_report.expand') }}</span></button></div></header>
    </section>

    <div class="payment-tab-panel payment-product-risk-primary-panel">@include('portals.payment.partials.product-risk-tab', ['tab' => 'risk_metrics', 'dashboard' => $dashboard])</div>

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
<script src="{{ $portalOrigin }}/assets/erp/payment-portal.js?v=20261026-6"></script>
<script src="{{ $portalOrigin }}/assets/erp/logistics-order-detail.js?v=20261015-2"></script>
@endpush
