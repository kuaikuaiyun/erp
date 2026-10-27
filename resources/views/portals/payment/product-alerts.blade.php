@extends('portal.layout')

@php
    $translationPrefix = 'payment.product_alerts';
    $portalOrigin = rtrim(request()->getSchemeAndHttpHost(), '/');
    $kpiPresentation = [
        'in_progress' => ['icon' => 'fa-cogs', 'tone' => 'orange'],
        'completed' => ['icon' => 'fa-check-circle', 'tone' => 'blue'],
    ];
@endphp

@section('page_title', __($translationPrefix.'.page_title'))

@push('styles')
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/payment-portal.css?v=20261026-1">
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/payment-shared.css?v=20261026-1">
@endpush

@section('content')
<section class="content payment-portal-page payment-product-alerts-page">
    @if (session('success'))<div class="alert alert-success"><i class="fa fa-check-circle"></i> {{ session('success') }}</div>@endif
    @if ($errors->any())<div class="alert alert-danger"><i class="fa fa-exclamation-circle"></i> {{ $errors->first() }}</div>@endif

    <div class="box box-solid payment-filter-box">
        <div class="box-header with-border"><h3 class="box-title"><i class="fa fa-filter"></i> {{ __('payment.common.filter_title') }}</h3></div>
        <div class="box-body"><form class="payment-operation-filter-form" method="get" action="{{ route('payment.product-alerts.index') }}" data-payment-filter-form data-payment-server-filter-form>
            <label class="payment-operation-filter-field"><span>{{ __($translationPrefix.'.filters.status') }}</span><select class="form-control" name="status" data-payment-filter="status"><option value="">{{ __($translationPrefix.'.filters.all_statuses') }}</option>@foreach (['in_progress', 'completed'] as $status)<option value="{{ $status }}" @selected(($dashboard['filters']['status'] ?? '') === $status)>{{ __($translationPrefix.'.statuses.'.$status) }}</option>@endforeach</select></label>
            <label class="payment-operation-filter-field"><span>{{ __($translationPrefix.'.filters.product') }}</span><input class="form-control" type="search" name="product" value="{{ $dashboard['filters']['product'] ?? '' }}" data-payment-filter="product" placeholder="{{ __($translationPrefix.'.filters.product_placeholder') }}"></label>
            <div class="payment-operation-filter-actions"><button class="btn btn-primary" type="submit"><i class="fa fa-search"></i> {{ __('payment.common.query') }}</button><button class="btn btn-default" type="button" onclick="window.location.href=@json(route('payment.product-alerts.index'))">{{ __('payment.common.reset') }}</button></div>
        </form></div>
    </div>

    <aside class="payment-alert-guidance"><div><i class="fa fa-lightbulb-o" aria-hidden="true"></i><p><strong>{{ __($translationPrefix.'.guidance.title') }}</strong><span>{{ __($translationPrefix.'.guidance.description') }}</span><small>{{ __($translationPrefix.'.guidance.fixed_rule') }}</small></p></div></aside>

    <div class="payment-kpi-grid payment-kpi-grid-four">
        @foreach ($dashboard['kpis'] as $kpi)
            @php($presentation = $kpiPresentation[$kpi['key']])
            <article class="payment-kpi-card payment-tone-{{ $kpi['tone'] ?? 'primary' }}"><span class="payment-kpi-icon payment-kpi-icon-{{ $presentation['tone'] }}"><i class="fa {{ $presentation['icon'] }}"></i></span><div><p>{{ __($translationPrefix.'.kpis.'.$kpi['key']) }}</p><strong>{{ $kpi['value'] }}{{ __($translationPrefix.'.order_unit') }}</strong><small>{{ __($translationPrefix.'.kpis.'.$kpi['note_key']) }}</small></div></article>
        @endforeach
    </div>

    <article class="payment-panel payment-alert-list-panel">
        <h2>{{ __($translationPrefix.'.list_title') }}</h2>
        <div class="table-responsive"><table class="table payment-data-table payment-alert-table"><thead><tr>@foreach (['alert_product', 'channel', 'sample', 'threshold', 'status', 'progress', 'action'] as $column)<th>{{ __($translationPrefix.'.table.'.$column) }}</th>@endforeach</tr></thead><tbody>
        @foreach ($dashboard['alerts'] as $alert)
            <tr class="payment-alert-severity-{{ $alert['severity_key'] }}" tabindex="0" data-payment-alert-detail="{{ route('payment.product-alerts.show', ['alert' => $alert['code']]) }}">
                <td><strong>{{ $alert['product_name'] }}</strong><br><small>{{ $alert['sku'] }}</small></td>
                <td>{{ __($translationPrefix.'.channels.'.$alert['channel_key']) }}</td>
                <td>{{ $alert['sample'] }}<br><small>{{ __($translationPrefix.'.sample_note') }}</small></td>
                <td><span class="payment-alert-threshold payment-alert-threshold-{{ $alert['severity_key'] }}">{{ number_format($alert['dispute_rate'], 2) }}% <i class="fa fa-info-circle payment-formula-icon" tabindex="0" data-payment-formula-tooltip data-toggle="tooltip" data-container="body" title="{{ __($translationPrefix.'.formulas.'.$alert['threshold_key']) }}"></i></span><br><small>{{ __($translationPrefix.'.threshold_value', ['threshold' => number_format($alert['threshold'], 2)]) }}</small></td>
                <td><span class="payment-table-status payment-table-status-{{ $alert['status_key'] }}">{{ __($translationPrefix.'.statuses.'.$alert['status_key']) }}</span></td>
                <td>{{ __($translationPrefix.'.progress.real_metrics', ['orders' => number_format($alert['eligible_order_count']), 'disputes' => number_format($alert['dispute_order_count']), 'amount' => number_format($alert['dispute_amount'], 2)]) }}</td>
                <td>
                    <button type="button" class="btn btn-primary btn-sm payment-alert-action-button" data-payment-alert-detail="{{ route('payment.product-alerts.show', ['alert' => $alert['code']]) }}">
                        {{ __($translationPrefix.'.actions.'.$alert['action_key']) }} <i class="fa fa-angle-right"></i>
                    </button>
                </td>
            </tr>
        @endforeach
        </tbody></table></div>
        @if ($dashboard['pagination']['total'] === 0)<p class="payment-empty-filter">{{ __('payment.common.empty_filter') }}</p>@endif
    </article>
    @include('portals.payment.partials.pagination', ['pagination' => $dashboard['pagination'], 'routeName' => 'payment.product-alerts.index'])

    <div class="modal fade payment-alert-detail-modal" id="payment-alert-detail-modal" tabindex="-1" role="dialog" aria-labelledby="payment-alert-detail-modal-title" aria-hidden="true"><div class="modal-dialog modal-lg" role="document"><div class="modal-content"><div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="{{ __($translationPrefix.'.modals.close') }}"><span>&times;</span></button><h4 id="payment-alert-detail-modal-title">{{ __($translationPrefix.'.detail_modal.title') }}</h4><p>{{ __($translationPrefix.'.detail_modal.subtitle') }}</p></div><div class="modal-body"><div class="payment-detail-state" data-payment-alert-detail-loading><i class="fa fa-spinner fa-spin"></i> {{ __($translationPrefix.'.detail_modal.loading') }}</div><div class="payment-detail-state payment-detail-error" data-payment-alert-detail-error hidden></div><div data-payment-alert-detail-body></div></div><div class="modal-footer"><button class="btn btn-default" type="button" data-dismiss="modal">{{ __($translationPrefix.'.modals.close') }}</button></div></div></div></div>
    @include('portals.payment.partials.product-alert-status-modal')

</section>
@endsection

@push('scripts')
<script>window.PaymentPortal = { actionTitle: @json(__('payment.common.mock_action_title')), actionMessage: @json(__('payment.common.mock_action_message')), alertModal: @json(__($translationPrefix.'.modals')), alertDetail: @json(__($translationPrefix.'.detail_modal')) };</script>
<script src="{{ $portalOrigin }}/assets/erp/payment-portal.js?v=20261026-1"></script>
@endpush
