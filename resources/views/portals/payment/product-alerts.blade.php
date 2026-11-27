@extends('portal.layout')

@php
    $translationPrefix = 'payment.product_alerts';
    $portalOrigin = rtrim(request()->getSchemeAndHttpHost(), '/');
    $filters = $dashboard['filters'];
    $pagination = $dashboard['pagination'];
    $listUrl = route('payment.product-alerts.index');
    $pageUrl = static fn (int $page) => route('payment.product-alerts.index', [
        ...$filters, 'per_page' => $pagination['per_page'], 'page' => $page,
    ]);
    $filterOptions = [
        'flow_status' => ['flows', ['open', 'completed', 'all'], null],
        'processing_status' => ['statuses', ['pending', 'stopped', 'observing', 'backend_optimized', 'other', 'completed'], 'all_statuses'],
        'channel' => ['channels', ['paypal', 'credit_card'], 'all_channels'],
        'severity' => ['severities', ['warning', 'critical'], 'all_severities'],
        'sample' => ['sample_ranges', ['all', 'under_50', '50_500', 'over_500'], null],
    ];
    $kpiPresentation = [
        'pending' => ['icon' => 'fa-clock-o', 'tone' => 'orange'],
        'processing' => ['icon' => 'fa-cogs', 'tone' => 'orange'],
        'completed' => ['icon' => 'fa-check-circle', 'tone' => 'blue'],
    ];
@endphp

@section('page_title', __($translationPrefix.'.page_title'))

@push('styles')
    <link rel="stylesheet" href="{{ $portalOrigin }}/css/frontend-list-template.css?v=20261125-1">
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/payment-portal.css?v=20261026-1">
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/payment-shared.css?v=20261117-alert1">
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/payment-product-alerts.css?v=1">
@endpush

@section('content')
<section class="content-header payment-page-heading">
    <h1>{{ __($translationPrefix.'.page_title') }} <small>{{ __($translationPrefix.'.page_subtitle') }}</small></h1>
</section>
<section class="content payment-portal-page payment-product-alerts-page flt"
    data-alert-list data-alert-selection-key="payment:product-alerts:selection:{{ $user->getKey() }}"
    data-alert-selection-label="{{ __('frontend_list_template.selected_records') }}">
    @if (session('success'))<div class="alert alert-success"><i class="fa fa-check-circle"></i> {{ session('success') }}</div>@endif
    @if ($errors->any())<div class="alert alert-danger"><i class="fa fa-exclamation-circle"></i> {{ $errors->first() }}</div>@endif

    <form class="flt-card flt-filters" method="get" action="{{ $listUrl }}"
        data-logistics-filters data-loading="region" data-loading-target="#payment-alert-records"
        data-search-options="{{ __('frontend_list_template.search_options') }}"
        data-no-options="{{ __('frontend_list_template.no_options') }}"
        data-clear-choice="{{ __('frontend_list_template.clear_choice') }}"
        data-selected-options="{{ __('frontend_list_template.selected_options', ['names' => '__names__', 'count' => '__count__']) }}">
        <input type="hidden" name="per_page" value="{{ $pagination['per_page'] }}">
        <div class="flt-filter-grid">
            @foreach ($filterOptions as $field => [$group, $options, $placeholder])
                <div>
                    <label class="flt-field-label" for="payment-alert-filter-{{ $field }}">{{ __($translationPrefix.'.filters.'.$field) }}</label>
                    <select id="payment-alert-filter-{{ $field }}" name="{{ $field }}{{ $placeholder !== null ? '[]' : '' }}"
                        @if ($placeholder !== null) multiple @else data-filter-fixed-choice @endif
                        @if ($field === 'processing_status') data-filter-searchable @endif>
                        @if ($placeholder !== null)
                            <option value="" disabled>{{ __($translationPrefix.'.filters.'.$placeholder) }}</option>
                        @endif
                        @foreach ($options as $option)
                            <option value="{{ $option }}" @selected(in_array($option, (array) $filters[$field], true))>
                                {{ __($translationPrefix.'.'.$group.'.'.$option) }}
                            </option>
                        @endforeach
                    </select>
                </div>
            @endforeach
            <label>{{ __($translationPrefix.'.filters.product') }}
                <input type="search" name="product" maxlength="191" value="{{ $filters['product'] }}"
                    placeholder="{{ __($translationPrefix.'.filters.product_placeholder') }}">
            </label>
        </div>
        <div class="flt-filter-actions">
            <button class="flt-btn flt-primary" type="submit"><i class="fa fa-search" aria-hidden="true"></i> {{ __('frontend_list_template.query') }}</button>
            <a class="flt-btn" href="{{ route('payment.product-alerts.index', ['per_page' => $pagination['per_page']]) }}"
                data-alert-reset data-loading="region" data-loading-target="#payment-alert-records">{{ __('frontend_list_template.reset') }}</a>
        </div>
    </form>

    <div class="payment-kpi-grid payment-kpi-grid-three">
        @foreach ($dashboard['kpis'] as $kpi)
            @php($presentation = $kpiPresentation[$kpi['key']])
            <article class="payment-kpi-card payment-tone-{{ $kpi['tone'] ?? 'primary' }}"><span class="payment-kpi-icon payment-kpi-icon-{{ $presentation['tone'] }}"><i class="fa {{ $presentation['icon'] }}"></i></span><div><p>{{ __($translationPrefix.'.kpis.'.$kpi['key']) }}</p><strong>{{ $kpi['value'] }}{{ __($translationPrefix.'.order_unit') }}</strong><small>{{ __($translationPrefix.'.kpis.'.$kpi['note_key']) }}</small></div></article>
        @endforeach
    </div>

    <details class="flt-guidance">
        <summary><i class="fa fa-info-circle" aria-hidden="true"></i><strong>{{ __('frontend_list_template.guidance_title', ['count' => 2]) }}</strong>
            <span class="flt-guidance-toggle"><span class="flt-guidance-expand">{{ __('frontend_list_template.guidance_expand') }}</span><span class="flt-guidance-collapse">{{ __('frontend_list_template.guidance_collapse') }}</span></span>
        </summary>
        <ul class="flt-guidance-items">
            <li>{{ __($translationPrefix.'.guidance.description') }}</li>
            <li>{{ __($translationPrefix.'.guidance.fixed_rule') }}</li>
        </ul>
    </details>

    <article class="flt-card flt-records" id="payment-alert-records">
        <div class="flt-toolbar">
            <div class="flt-selection">
                <span>{{ __('frontend_list_template.records', ['count' => $pagination['total']]) }}</span>
                <span class="flt-toolbar-divider" aria-hidden="true"></span>
                <span data-alert-selected-count aria-live="polite">{{ __('frontend_list_template.selected_records', ['count' => 0]) }}</span>
                <button type="button" data-alert-clear-selection disabled>{{ __('frontend_list_template.clear_selected') }}</button>
            </div>
            <div class="flt-toolbar-actions">
                <a class="flt-btn" href="{{ route('payment.product-alerts.export') }}" data-loading="off"><i class="fa fa-file-excel-o" aria-hidden="true"></i> {{ __($translationPrefix.'.export.button') }}</a>
            </div>
        </div>
        <div class="flt-table-region">
            <div class="flt-table-scroll payment-alert-scroll-table" tabindex="0" aria-label="{{ __($translationPrefix.'.list_title') }}">
                <table class="payment-alert-table">
                    <caption class="flt-sr-only">{{ __($translationPrefix.'.list_title') }}</caption>
                    <thead><tr>
                        <th scope="col" class="flt-check"><input type="checkbox" data-alert-check-page aria-label="{{ __('frontend_list_template.check_page') }}" disabled></th>
                        @foreach (['product_information', 'alert_data', 'threshold', 'status', 'status_message', 'action'] as $column)
                            <th scope="col">{{ __($translationPrefix.'.table.'.$column) }}</th>
                        @endforeach
                    </tr></thead>
                    <tbody>
        @foreach ($dashboard['alerts'] as $alert)
            <tr class="payment-alert-severity-{{ $alert['severity_key'] }}" tabindex="0" data-payment-alert-detail="{{ route('payment.product-alerts.show', ['alert' => $alert['code']]) }}">
                <td data-alert-selection-cell><input type="checkbox" data-alert-check-row value="{{ $alert['code'] }}"
                    aria-label="{{ __('frontend_list_template.check_record', ['id' => $alert['code']]) }}" disabled></td>
                <td><div class="payment-alert-product-information">
                    @if(!empty($alert['product_image_url']))
                        <img src="{{ $alert['product_image_url'] }}" alt="{{ $alert['product_name'] }}" loading="lazy" onerror="this.hidden=true;this.nextElementSibling.hidden=false;">
                        <span hidden>{{ __($translationPrefix.'.no_image') }}</span>
                    @else
                        <span>{{ __($translationPrefix.'.no_image') }}</span>
                    @endif
                    <strong>{{ $alert['sku'] }}</strong>
                    <div>{{ $alert['product_name'] }}</div>
                </div></td>
                <td><div class="payment-alert-data-cell"><div class="payment-alert-channel-line"><span>{{ __($translationPrefix.'.table.channel') }}</span><strong>{{ __($translationPrefix.'.channels.'.$alert['channel_key']) }}</strong></div><div class="payment-alert-summary-grid"><span><small>{{ __($translationPrefix.'.summary.orders') }}</small><strong>{{ number_format($alert['eligible_order_count']) }}{{ __($translationPrefix.'.order_unit') }}</strong></span><span><small>{{ __($translationPrefix.'.summary.disputes') }}</small><strong>{{ number_format($alert['dispute_order_count']) }}{{ __($translationPrefix.'.order_unit') }}</strong></span><span><small>{{ __($translationPrefix.'.summary.amount') }}</small><strong>${{ number_format($alert['dispute_amount'], 2) }}</strong></span><span><small>{{ __($translationPrefix.'.summary.delivered_rate') }}</small><strong>{{ number_format($alert['delivered_dispute_rate'], 2) }}%</strong><em>{{ number_format($alert['delivered_dispute_order_count']) }}/{{ number_format($alert['delivered_order_count']) }}</em></span>@if($alert['channel_type'] === 'paypal')<span class="payment-alert-summary-mismatch payment-alert-mismatch-{{ $alert['severity_key'] }}"><small>{{ __($translationPrefix.'.table.description_mismatch') }}</small><strong>{{ number_format($alert['description_mismatch_rate'], 2) }}%</strong><em>${{ number_format($alert['description_mismatch_amount'], 2) }} <i class="fa fa-info-circle payment-formula-icon" tabindex="0" data-payment-formula-tooltip data-toggle="tooltip" data-container="body" title="{{ __($translationPrefix.'.formulas.description_mismatch_rate') }}"></i></em></span>@endif</div></div></td>
                <td><div class="payment-alert-trigger-list">@foreach($alert['trigger_metrics'] as $metric)<span class="payment-alert-trigger-item payment-alert-trigger-{{ $alert['severity_key'] }}"><strong>{{ number_format($metric['value'], 2) }}%</strong><small>{{ __($translationPrefix.'.metrics.'.$metric['key']) }}</small></span>@endforeach</div></td>
                <td><span class="payment-table-status payment-table-status-{{ $alert['workflow_status_key'] }}" data-payment-alert-workflow-status>{{ __($translationPrefix.'.statuses.'.$alert['workflow_status_key']) }}</span></td>
                <td data-payment-alert-status-history>@forelse($alert['status_history'] as $history)<div class="payment-alert-history-line">{{ __($translationPrefix.'.history.line', ['status' => __($translationPrefix.'.statuses.'.$history['status']), 'remark' => $history['remark'], 'actor' => $history['actor'], 'time' => $history['time']]) }}</div>@empty<span data-payment-alert-history-empty>-</span>@endforelse</td>
                <td class="payment-alert-actions-cell">
                    <button type="button" class="flt-row-action" data-payment-alert-detail="{{ route('payment.product-alerts.show', ['alert' => $alert['code']]) }}">
                        {{ __($translationPrefix.'.actions.'.$alert['action_key']) }} <i class="fa fa-angle-right"></i>
                    </button>
                    <button type="button" class="flt-row-action" data-payment-alert-status data-action="{{ route('payment.product-alerts.status.update', ['alert' => $alert['code']]) }}" data-product="{{ $alert['sku'] }}" data-current-status="{{ $alert['status_key'] }}">{{ __($translationPrefix.'.operations.process') }}</button>
                </td>
            </tr>
        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($pagination['total'] === 0)
                <div class="flt-empty"><i class="fa fa-search" aria-hidden="true"></i><h4>{{ __('frontend_list_template.empty_title') }}</h4><p>{{ __('frontend_list_template.empty_hint') }}</p></div>
            @endif
        </div>
        <nav class="flt-pagination" aria-label="{{ __('frontend_list_template.pagination') }}">
            <label>{{ __('frontend_list_template.per_page') }}
                <select name="per_page" form="payment-alert-pagination" data-alert-page-size aria-label="{{ __('frontend_list_template.page_size') }}">
                    @foreach ([20, 50, 100] as $size)
                        <option value="{{ $size }}" @selected($size === $pagination['per_page'])>{{ $size }}</option>
                    @endforeach
                </select>
            </label>
            @if ($pagination['current_page'] > 1)
                <a class="flt-btn" href="{{ $pageUrl($pagination['current_page'] - 1) }}" data-loading="region" data-loading-target="#payment-alert-records">{{ __('frontend_list_template.previous') }}</a>
            @else
                <button type="button" class="flt-btn" disabled>{{ __('frontend_list_template.previous') }}</button>
            @endif
            <strong>{{ __('frontend_list_template.page_label', ['page' => $pagination['current_page'], 'pages' => $pagination['last_page']]) }}</strong>
            @if ($pagination['current_page'] < $pagination['last_page'])
                <a class="flt-btn" href="{{ $pageUrl($pagination['current_page'] + 1) }}" data-loading="region" data-loading-target="#payment-alert-records">{{ __('frontend_list_template.next') }}</a>
            @else
                <button type="button" class="flt-btn" disabled>{{ __('frontend_list_template.next') }}</button>
            @endif
            <form id="payment-alert-pagination" method="get" action="{{ $listUrl }}" data-loading="region" data-loading-target="#payment-alert-records">
                @foreach ($filters as $field => $value)
                    @if (is_array($value))
                        @foreach ($value as $choice)
                            <input type="hidden" name="{{ $field }}[]" value="{{ $choice }}">
                        @endforeach
                    @else
                        <input type="hidden" name="{{ $field }}" value="{{ $value }}">
                    @endif
                @endforeach
                <label>{{ __('frontend_list_template.jump_to') }}
                    <input type="number" name="page" min="1" max="{{ $pagination['last_page'] }}" step="1" required placeholder="{{ __('frontend_list_template.page_placeholder') }}"
                        data-alert-invalid-page="{{ __('frontend_list_template.invalid_page', ['pages' => $pagination['last_page']]) }}">
                </label>
                <button class="flt-btn flt-primary" type="submit">{{ __('frontend_list_template.jump') }}</button>
            </form>
        </nav>
    </article>

    <div class="modal fade payment-alert-detail-modal" id="payment-alert-detail-modal" tabindex="-1" role="dialog" aria-labelledby="payment-alert-detail-modal-title" aria-hidden="true"><div class="modal-dialog modal-lg" role="document"><div class="modal-content"><div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="{{ __($translationPrefix.'.modals.close') }}"><span>&times;</span></button><h4 id="payment-alert-detail-modal-title">{{ __($translationPrefix.'.detail_modal.title') }}</h4><p>{{ __($translationPrefix.'.detail_modal.subtitle') }}</p></div><div class="modal-body"><div class="payment-detail-state" data-payment-alert-detail-loading><i class="fa fa-spinner fa-spin"></i> {{ __($translationPrefix.'.detail_modal.loading') }}</div><div class="payment-detail-state payment-detail-error" data-payment-alert-detail-error hidden></div><div data-payment-alert-detail-body></div></div><div class="modal-footer"><button class="btn btn-default" type="button" data-dismiss="modal">{{ __($translationPrefix.'.modals.close') }}</button></div></div></div></div>
    @include('portals.payment.partials.product-alert-status-modal')

</section>
@endsection

@push('scripts')
<script>window.PaymentPortal = { actionTitle: @json(__('payment.common.mock_action_title')), actionMessage: @json(__('payment.common.mock_action_message')), alertModal: @json(__($translationPrefix.'.modals')), alertDetail: @json(__($translationPrefix.'.detail_modal')), productAlerts: { submit_timeout: @json(__($translationPrefix.'.status_update.timeout')) }, productExport: @json(__($translationPrefix.'.export')) };</script>
<script src="{{ $portalOrigin }}/assets/erp/shopify-orders-filters.js?v=5"></script>
<script src="{{ $portalOrigin }}/assets/erp/payment-portal.js?v=20261026-1"></script>
<script src="{{ $portalOrigin }}/assets/erp/payment-product-alerts.js?v=1"></script>
@endpush
