@extends('portal.layout')

@php
    $translationPrefix = 'payment.product_alerts';
    $portalOrigin = rtrim(request()->getSchemeAndHttpHost(), '/');
    $kpiPresentation = [
        'pending' => ['icon' => 'fa-clock-o', 'tone' => 'orange'],
        'processing' => ['icon' => 'fa-cogs', 'tone' => 'orange'],
        'completed' => ['icon' => 'fa-check-circle', 'tone' => 'blue'],
    ];
@endphp

@section('page_title', __($translationPrefix.'.page_title'))

@push('styles')
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/payment-portal.css?v=20261026-1">
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/payment-shared.css?v=20261026-1">
@endpush

@section('content')
<section class="content-header payment-page-heading">
    <h1>{{ __($translationPrefix.'.page_title') }} <small>{{ __($translationPrefix.'.page_subtitle') }}</small></h1>
</section>
<section class="content payment-portal-page payment-product-alerts-page">
    @if (session('success'))<div class="alert alert-success"><i class="fa fa-check-circle"></i> {{ session('success') }}</div>@endif
    @if ($errors->any())<div class="alert alert-danger"><i class="fa fa-exclamation-circle"></i> {{ $errors->first() }}</div>@endif

    <div class="box box-solid payment-filter-box">
        <div class="box-body"><form class="payment-operation-filter-form" method="get" action="{{ route('payment.product-alerts.index') }}" data-payment-filter-form data-payment-server-filter-form>
            <label class="payment-operation-filter-field"><span>{{ __($translationPrefix.'.filters.flow_status') }}</span><select class="form-control" name="flow_status"><option value="open" @selected(($dashboard['filters']['flow_status'] ?? 'open') === 'open')>{{ __($translationPrefix.'.flows.open') }}</option><option value="completed" @selected(($dashboard['filters']['flow_status'] ?? '') === 'completed')>{{ __($translationPrefix.'.flows.completed') }}</option><option value="all" @selected(($dashboard['filters']['flow_status'] ?? '') === 'all')>{{ __($translationPrefix.'.flows.all') }}</option></select></label>
            <label class="payment-operation-filter-field"><span>{{ __($translationPrefix.'.filters.processing_status') }}</span><select class="form-control" name="processing_status"><option value="">{{ __($translationPrefix.'.filters.all_statuses') }}</option>@foreach (['pending','stopped','observing','backend_optimized','other','completed'] as $status)<option value="{{ $status }}" @selected(($dashboard['filters']['processing_status'] ?? '') === $status)>{{ __($translationPrefix.'.statuses.'.$status) }}</option>@endforeach</select></label>
            <label class="payment-operation-filter-field"><span>{{ __($translationPrefix.'.filters.channel') }}</span><select class="form-control" name="channel"><option value="">{{ __($translationPrefix.'.filters.all_channels') }}</option>@foreach(['paypal','credit_card'] as $channel)<option value="{{ $channel }}" @selected(($dashboard['filters']['channel'] ?? '') === $channel)>{{ __($translationPrefix.'.channels.'.$channel) }}</option>@endforeach</select></label>
            <label class="payment-operation-filter-field"><span>{{ __($translationPrefix.'.filters.severity') }}</span><select class="form-control" name="severity"><option value="">{{ __($translationPrefix.'.filters.all_severities') }}</option>@foreach(['warning','critical'] as $severity)<option value="{{ $severity }}" @selected(($dashboard['filters']['severity'] ?? '') === $severity)>{{ __($translationPrefix.'.severities.'.$severity) }}</option>@endforeach</select></label>
            <label class="payment-operation-filter-field"><span>{{ __($translationPrefix.'.filters.sample') }}</span><select class="form-control" name="sample"><option value="">{{ __($translationPrefix.'.filters.all_samples') }}</option>@foreach(['under_50','50_500','over_500'] as $sample)<option value="{{ $sample }}" @selected(($dashboard['filters']['sample'] ?? '') === $sample)>{{ __($translationPrefix.'.sample_ranges.'.$sample) }}</option>@endforeach</select></label>
            <label class="payment-operation-filter-field"><span>{{ __($translationPrefix.'.filters.product') }}</span><input class="form-control" type="search" name="product" value="{{ $dashboard['filters']['product'] ?? '' }}" data-payment-filter="product" placeholder="{{ __($translationPrefix.'.filters.product_placeholder') }}"></label>
            <div class="payment-operation-filter-actions"><button class="btn btn-primary" type="submit"><i class="fa fa-search"></i> {{ __('payment.common.query') }}</button><button class="btn btn-default" type="button" onclick="window.location.href=@json(route('payment.product-alerts.index'))">{{ __('payment.common.reset') }}</button><button class="btn btn-success" type="button" data-payment-product-export data-export-url="{{ route('payment.product-alerts.export') }}"><i class="fa fa-file-excel-o"></i> {{ __($translationPrefix.'.export.button') }}</button></div>
        </form></div>
    </div>

    <aside class="payment-alert-guidance"><div><i class="fa fa-lightbulb-o" aria-hidden="true"></i><p><strong>{{ __($translationPrefix.'.guidance.title') }}</strong><span>{{ __($translationPrefix.'.guidance.description') }}</span><small>{{ __($translationPrefix.'.guidance.fixed_rule') }}</small></p></div></aside>

    <div class="payment-kpi-grid payment-kpi-grid-three">
        @foreach ($dashboard['kpis'] as $kpi)
            @php($presentation = $kpiPresentation[$kpi['key']])
            <article class="payment-kpi-card payment-tone-{{ $kpi['tone'] ?? 'primary' }}"><span class="payment-kpi-icon payment-kpi-icon-{{ $presentation['tone'] }}"><i class="fa {{ $presentation['icon'] }}"></i></span><div><p>{{ __($translationPrefix.'.kpis.'.$kpi['key']) }}</p><strong>{{ $kpi['value'] }}{{ __($translationPrefix.'.order_unit') }}</strong><small>{{ __($translationPrefix.'.kpis.'.$kpi['note_key']) }}</small></div></article>
        @endforeach
    </div>

    <article class="payment-panel payment-alert-list-panel">
        <h2>{{ __($translationPrefix.'.list_title') }}</h2>
        <div class="table-responsive payment-alert-scroll-table"><table class="table payment-data-table payment-alert-table"><thead><tr>@foreach (['pcl', 'alert_data', 'threshold', 'status', 'status_message', 'action'] as $column)<th>{{ __($translationPrefix.'.table.'.$column) }}</th>@endforeach</tr></thead><tbody>
        @foreach ($dashboard['alerts'] as $alert)
            <tr class="payment-alert-severity-{{ $alert['severity_key'] }}" tabindex="0" data-payment-alert-detail="{{ route('payment.product-alerts.show', ['alert' => $alert['code']]) }}">
                <td><strong>{{ $alert['sku'] }}</strong></td>
                <td><div class="payment-alert-data-cell"><div class="payment-alert-channel-line"><span>{{ __($translationPrefix.'.table.channel') }}</span><strong>{{ __($translationPrefix.'.channels.'.$alert['channel_key']) }}</strong></div><div class="payment-alert-summary-grid"><span><small>{{ __($translationPrefix.'.summary.orders') }}</small><strong>{{ number_format($alert['eligible_order_count']) }}{{ __($translationPrefix.'.order_unit') }}</strong></span><span><small>{{ __($translationPrefix.'.summary.disputes') }}</small><strong>{{ number_format($alert['dispute_order_count']) }}{{ __($translationPrefix.'.order_unit') }}</strong></span><span><small>{{ __($translationPrefix.'.summary.amount') }}</small><strong>${{ number_format($alert['dispute_amount'], 2) }}</strong></span><span><small>{{ __($translationPrefix.'.summary.delivered_rate') }}</small><strong>{{ number_format($alert['delivered_dispute_rate'], 2) }}%</strong><em>{{ number_format($alert['delivered_dispute_order_count']) }}/{{ number_format($alert['delivered_order_count']) }}</em></span>@if($alert['channel_type'] === 'paypal')<span class="payment-alert-summary-mismatch payment-alert-mismatch-{{ $alert['severity_key'] }}"><small>{{ __($translationPrefix.'.table.description_mismatch') }}</small><strong>{{ number_format($alert['description_mismatch_rate'], 2) }}%</strong><em>${{ number_format($alert['description_mismatch_amount'], 2) }} <i class="fa fa-info-circle payment-formula-icon" tabindex="0" data-payment-formula-tooltip data-toggle="tooltip" data-container="body" title="{{ __($translationPrefix.'.formulas.description_mismatch_rate') }}"></i></em></span>@endif</div></div></td>
                <td><div class="payment-alert-trigger-list">@foreach($alert['trigger_metrics'] as $metric)<span class="payment-alert-trigger-item payment-alert-trigger-{{ $alert['severity_key'] }}"><strong>{{ number_format($metric['value'], 2) }}%</strong><small>{{ __($translationPrefix.'.metrics.'.$metric['key']) }}</small></span>@endforeach</div></td>
                <td><span class="payment-table-status payment-table-status-{{ $alert['workflow_status_key'] }}" data-payment-alert-workflow-status>{{ __($translationPrefix.'.statuses.'.$alert['workflow_status_key']) }}</span></td>
                <td data-payment-alert-status-history>@forelse($alert['status_history'] as $history)<div class="payment-alert-history-line">{{ __($translationPrefix.'.history.line', ['status' => __($translationPrefix.'.statuses.'.$history['status']), 'remark' => $history['remark'], 'actor' => $history['actor'], 'time' => $history['time']]) }}</div>@empty<span data-payment-alert-history-empty>-</span>@endforelse</td>
                <td class="payment-alert-actions-cell">
                    <button type="button" class="btn btn-primary btn-sm payment-alert-action-button" data-payment-alert-detail="{{ route('payment.product-alerts.show', ['alert' => $alert['code']]) }}">
                        {{ __($translationPrefix.'.actions.'.$alert['action_key']) }} <i class="fa fa-angle-right"></i>
                    </button>
                    <button type="button" class="btn btn-default btn-sm" data-payment-alert-status data-action="{{ route('payment.product-alerts.status.update', ['alert' => $alert['code']]) }}" data-product="{{ $alert['sku'] }}" data-current-status="{{ $alert['status_key'] }}">{{ __($translationPrefix.'.operations.process') }}</button>
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
<script>window.PaymentPortal = { actionTitle: @json(__('payment.common.mock_action_title')), actionMessage: @json(__('payment.common.mock_action_message')), alertModal: @json(__($translationPrefix.'.modals')), alertDetail: @json(__($translationPrefix.'.detail_modal')), productAlerts: { submit_timeout: @json(__($translationPrefix.'.status_update.timeout')) }, productExport: @json(__($translationPrefix.'.export')) };</script>
<script src="{{ $portalOrigin }}/assets/erp/payment-portal.js?v=20261026-1"></script>
@endpush
