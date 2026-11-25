@extends('portal.layout')

@php
    $t = 'payment.renewal_analysis';
    $portalOrigin = rtrim(request()->getSchemeAndHttpHost(), '/');
    $filters = $dashboard['filters'];
    $text = static fn (string $key, array $values = []) => __('payment.renewal_two_sections.'.$key, $values);
@endphp

@section('page_title', __($t.'.page_title'))
@push('styles')
<link rel="stylesheet" href="{{ $portalOrigin }}/css/frontend-list-template.css?v=20261125-1">
<link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/payment-portal.css?v=20261104-2">
<link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/payment-shared.css?v=20261125-13">
@endpush

@section('content')
<section class="content payment-portal-page payment-renewal-page payment-renewal-two-sections"
    data-renewal-url="{{ route('payment.renewal-analysis.index') }}"
    data-renewal-filters="{{ json_encode($filters, JSON_UNESCAPED_UNICODE) }}"
    data-renewal-section-messages="{{ json_encode(['loading'=>__('payment-renewal.section_loading'),'error'=>__('payment-renewal.section_error'),'invalidDate'=>$text('date_range_error')], JSON_UNESCAPED_UNICODE) }}"
    data-renewal-export-messages="{{ json_encode(['exporting'=>__('payment.renewal_details.page_exporting'),'error'=>__('payment.renewal_details.page_export_error'),'done'=>__('payment.renewal_details.page_export_done'),'filename'=>__('payment.renewal_analysis.export.filename',['time'=>now()->format('Ymd_His')])],JSON_UNESCAPED_UNICODE) }}">
    <header class="payment-page-heading"><div><h1>{{ __($t.'.page_title') }}</h1><p>{{ $text('subtitle') }}</p></div></header>
    <p data-renewal-export-feedback role="status" aria-live="polite" hidden></p>
    <div data-renewal-region="initial">
        @if (isset($dashboard['initial_calculation']))
            @include('portals.payment.partials.renewal-analysis-section', ['module' => 'initial'])
        @else
            <article class="payment-panel payment-subscription-block payment-renewal-initial-placeholder" data-renewal-placeholder>
                <h2><span class="payment-section-number">01</span>{{ __('payment-renewal.initial_data_title') }}</h2>
                <p class="payment-section-hint">{{ $text('subscription_hint') }}</p>
                @include('portals.payment.partials.renewal-section-status')
                @include('portals.payment.partials.renewal-table-skeleton')
                <a class="flt-btn" data-renewal-navigation href="{{ route('payment.renewal-analysis.index', [...array_diff_key($filters, ['risk_date_mode'=>true]), 'load_initial'=>1]) }}">{{ __('payment-renewal.load_initial_section') }}</a>
            </article>
        @endif
    </div>
    <div id="renewal-analysis-renewal" data-renewal-region="renewal">
        @if (isset($dashboard['stages']))
            @include('portals.payment.partials.renewal-analysis-section', ['module' => 'renewal'])
        @else
            <article class="payment-panel payment-renewal-funnel" data-renewal-placeholder>
                <h2><span class="payment-section-number">02</span>{{ $text('renewal_title') }}</h2>
                <p class="payment-section-hint">{{ $text('renewal_hint') }}</p>
                @include('portals.payment.partials.renewal-section-status')
                @include('portals.payment.partials.renewal-table-skeleton')
                <a class="flt-btn" data-renewal-navigation href="{{ route('payment.renewal-analysis.index', [...array_diff_key($filters, ['risk_date_mode'=>true]), 'load_renewal'=>1]) }}#renewal-analysis-renewal">{{ __('payment-renewal.load_section') }}</a>
            </article>
        @endif
    </div>
</section>
<div class="modal fade payment-renewal-detail-modal" id="payment-renewal-detail-modal" tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="payment-renewal-detail-title"
    data-export-endpoint="{{ route('payment.renewal-analysis.order-details.export') }}"
    data-endpoint="{{ route('payment.renewal-analysis.order-details') }}"
    data-parent-filters="{{ json_encode(array_intersect_key($filters,array_flip(['initial_date_from','initial_date_to','initial_timezone'])),JSON_UNESCAPED_UNICODE) }}"
    data-messages="{{ json_encode(['loading'=>__('payment.renewal_details.loading'),'error'=>__('payment.renewal_details.error'),'retry'=>__('payment.renewal_details.retry'),'exporting'=>__('payment.renewal_details.exporting'),'exportError'=>__('payment.renewal_details.export_error'),'exportFilename'=>__('payment.renewal_details.export_default_filename')],JSON_UNESCAPED_UNICODE) }}">
    <div class="modal-dialog" role="document"><div class="modal-content">
        <div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="{{ __('payment.renewal_details.close') }}"><span aria-hidden="true">&times;</span></button><h3 class="modal-title" id="payment-renewal-detail-title">{{ __('payment.renewal_details.title') }}</h3></div>
        <div class="modal-body"><p data-renewal-detail-feedback role="status" aria-live="polite"></p><div data-renewal-detail-content></div></div>
        <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">{{ __('payment.renewal_details.close') }}</button></div>
    </div></div>
</div>
@endsection

@push('scripts')
<script src="{{ $portalOrigin }}/assets/erp/shopify-orders-filters.js?v=20261125-renewal"></script>
<script src="{{ $portalOrigin }}/assets/erp/payment-renewal-details.js?v=20261125-8"></script>
<script src="{{ $portalOrigin }}/assets/erp/payment-portal.js?v=20261117-1"></script>
@endpush
