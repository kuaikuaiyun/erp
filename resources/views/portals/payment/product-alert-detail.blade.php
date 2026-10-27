@extends('portal.layout')

@php
    $translationPrefix = 'payment.product_alerts';
    $detailPrefix = $translationPrefix.'.detail_page';
    $portalOrigin = rtrim(request()->getSchemeAndHttpHost(), '/');
    $listQuery = request()->except('alert');
@endphp

@section('page_title', __($detailPrefix.'.page_title', ['code' => $alert['code']]))

@push('styles')
<link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/payment-portal.css?v=20261026-1">
<link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/payment-shared.css?v=20261026-1">
@endpush

@section('content')
<section class="content payment-portal-page payment-alert-detail-page"><nav class="payment-alert-detail-actions"><a class="btn btn-default" href="{{ route('payment.product-alerts.index', $listQuery) }}"><i class="fa fa-angle-left"></i> {{ __($detailPrefix.'.back') }}</a></nav>@include('portals.payment.partials.product-alert-detail-content', ['alert' => $alert])@include('portals.payment.partials.product-alert-status-modal')</section>
@endsection

@push('scripts')
<script>window.PaymentPortal = { actionTitle: @json(__('payment.common.mock_action_title')), actionMessage: @json(__('payment.common.mock_action_message')) };</script>
<script src="{{ $portalOrigin }}/assets/erp/payment-portal.js?v=20261026-1"></script>
@endpush
