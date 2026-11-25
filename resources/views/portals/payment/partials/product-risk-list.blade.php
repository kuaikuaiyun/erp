@php
    $total = $dashboard['pagination']['total'];
    $count = number_format($total);
@endphp

<div class="flt-toolbar payment-risk-list-toolbar">
    <div class="flt-selection">
        <span data-payment-risk-total>{{ __('frontend_list_template.records', ['count' => $count]) }}</span>
        <span class="flt-toolbar-divider" aria-hidden="true"></span>
        <span aria-live="polite" data-payment-risk-selected data-count-label="{{ __('frontend_list_template.selected_records') }}">{{ __('frontend_list_template.selected_records', ['count' => 0]) }}</span>
        <button type="button" class="flt-btn" data-payment-risk-clear disabled>{{ __('frontend_list_template.clear_selected') }}</button>
    </div>
    <div class="flt-toolbar-actions">
        <button class="flt-btn" type="button" data-payment-product-export aria-haspopup="dialog" aria-controls="payment-risk-export-modal" @disabled($total === 0)>
            <i class="fa fa-download" aria-hidden="true"></i> {{ __('frontend_list_template.export') }}
        </button>
    </div>
</div>
@include('portals.payment.partials.product-risk-tab', ['tab' => 'risk_metrics', 'dashboard' => $dashboard])
