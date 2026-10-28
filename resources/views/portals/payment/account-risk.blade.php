@extends('portal.layout')

@php
    $translationPrefix = 'payment.account_risk';
    $portalOrigin = rtrim(request()->getSchemeAndHttpHost(), '/');
    $kpiPresentation = [
        'paid_orders' => ['icon' => 'fa-shopping-bag', 'tone' => 'indigo'],
        'refund' => ['icon' => 'fa-reply', 'tone' => 'orange'],
        'full_refund' => ['icon' => 'fa-exchange', 'tone' => 'red'],
        'risk_cases' => ['icon' => 'fa-gavel', 'tone' => 'red'],
    ];
@endphp

@section('page_title', __($translationPrefix.'.page_title'))

@push('styles')
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/payment-portal.css?v=20261026-1">
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/payment-shared.css?v=20261026-1">
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/payment-account-risk.css?v=20261026-7">
@endpush

@section('content')
    <section class="content-header payment-account-risk-header">
        <h1>{{ __($translationPrefix.'.title') }} <small>{{ __($translationPrefix.'.subtitle') }}</small></h1>
    </section>
    <section class="content payment-portal-page payment-account-risk-page">
        <div class="box box-solid payment-account-filter-box">
            <div class="box-header with-border"><h3 class="box-title"><i class="fa fa-filter" aria-hidden="true"></i> {{ __($translationPrefix.'.filters.title') }}</h3></div>
            <div class="box-body">
                <form class="payment-account-filter-form" method="get" action="{{ route('payment.account-risk.index') }}" data-payment-filter-form data-payment-server-filter-form>
                    <input type="hidden" name="per_page" value="{{ $dashboard['pagination']['per_page'] }}">
                    <div class="payment-account-filter-field payment-account-date-mode-field"><span>{{ __($translationPrefix.'.filters.date_mode') }}</span><input type="hidden" name="date_mode" value="{{ $dashboard['date_mode'] }}" data-payment-account-date-mode-input><div class="payment-account-date-mode" role="group" aria-label="{{ __($translationPrefix.'.filters.date_mode') }}"><button type="button" class="{{ $dashboard['date_mode'] === 'order' ? 'active' : '' }}" data-payment-account-date-mode="order">{{ __($translationPrefix.'.filters.payment_date') }}</button><button type="button" class="{{ $dashboard['date_mode'] === 'event' ? 'active' : '' }}" data-payment-account-date-mode="event">{{ __($translationPrefix.'.filters.event_date') }}</button></div></div>
                    <label class="payment-account-filter-field payment-account-period-field"><span>{{ __('payment.common.period') }}</span><span class="payment-account-date-range"><input class="form-control" type="date" name="period_start" value="{{ $dashboard['period_start'] }}" data-payment-filter="period_start"><b>{{ __($translationPrefix.'.filters.range_to') }}</b><input class="form-control" type="date" name="period_end" value="{{ $dashboard['period_end'] }}" data-payment-filter="period_end"></span></label>
                    <div class="payment-account-filter-field"><label for="payment-account-input">{{ __($translationPrefix.'.filters.account') }}</label><div class="payment-account-combobox" data-payment-account-combobox><input id="payment-account-input" class="form-control" type="search" name="account" value="{{ $dashboard['filters']['account'] }}" autocomplete="off" placeholder="{{ __($translationPrefix.'.filters.account_placeholder') }}" data-payment-filter="account" data-payment-account-input><button class="payment-account-combobox-clear" type="button" data-payment-account-clear aria-label="{{ __($translationPrefix.'.filters.account_clear') }}" @if($dashboard['filters']['account'] === '') hidden @endif><i class="fa fa-times" aria-hidden="true"></i></button><button class="payment-account-combobox-toggle" type="button" data-payment-account-toggle aria-label="{{ __($translationPrefix.'.filters.account') }}" aria-expanded="false"><i class="fa fa-angle-down" aria-hidden="true"></i></button><div class="payment-account-combobox-options" data-payment-account-options role="listbox" hidden>@foreach ($dashboard['account_options'] as $account)<button type="button" data-payment-account-option data-account-value="{{ $account['name'] }}" role="option">{{ $account['name'] }}</button>@endforeach</div></div></div>
                    <label class="payment-account-filter-field"><span>{{ __($translationPrefix.'.filters.deduction_type') }}</span><select class="form-control" disabled aria-describedby="payment-account-deduction-note"><option>{{ __($translationPrefix.'.filters.deduction_pending') }}</option></select></label>
                    <label class="payment-account-filter-field"><span>{{ __($translationPrefix.'.filters.status') }}</span><select class="form-control" name="import_status" data-payment-filter="status"><option value="">{{ __($translationPrefix.'.filters.all_statuses') }}</option>@foreach (['never', 'success', 'partial', 'failed'] as $status)<option value="{{ $status }}" @selected($dashboard['filters']['import_status'] === $status)>{{ __($translationPrefix.'.import_status.'.$status) }}</option>@endforeach</select></label>
                    <label class="payment-account-filter-field"><span>{{ __('payment.common.cutoff') }}</span><input class="form-control" type="text" value="{{ $dashboard['cutoff'] }}" readonly></label>
                    <div class="payment-account-filter-actions"><button class="btn btn-primary" type="submit"><i class="fa fa-search" aria-hidden="true"></i> {{ __($translationPrefix.'.filters.query') }}</button><button class="btn btn-default" type="reset">{{ __($translationPrefix.'.filters.reset') }}</button></div>
                </form>
            </div>
        </div>
        <span id="payment-account-deduction-note" class="sr-only">{{ __($translationPrefix.'.filters.deduction_pending') }}</span>
        @if (session('success'))<div class="alert alert-success payment-account-result-message"><i class="fa fa-check-circle" aria-hidden="true"></i> {{ session('success') }}</div>@endif
        @if ($errors->any())<div class="alert alert-danger payment-account-result-message"><i class="fa fa-exclamation-circle" aria-hidden="true"></i> {{ __($translationPrefix.'.create_modal.validation_failed') }}<ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        <div class="payment-kpi-grid payment-kpi-grid-four">
            @foreach ($dashboard['kpis'] as $kpi)
                @php
                    $presentation = $kpiPresentation[$kpi['key']];
                @endphp
                <article class="payment-kpi-card payment-tone-{{ $kpi['tone'] ?? 'primary' }}">
                    <span class="payment-kpi-icon payment-kpi-icon-{{ $presentation['tone'] }}"><i class="fa {{ $presentation['icon'] }}" aria-hidden="true"></i></span>
                    <div><p>{{ __($translationPrefix.'.kpis.'.$kpi['key']) }}</p><strong>{{ $kpi['value'] }}</strong><small>{{ __($translationPrefix.'.kpis.'.$kpi['note_key']) }}</small></div>
                </article>
            @endforeach
        </div>
        <div class="payment-section-heading payment-account-board-heading"><h2>{{ __($translationPrefix.'.dashboard_title') }}</h2><div class="payment-account-board-actions"><span>{{ __($translationPrefix.'.dashboard_note') }}</span><button type="button" class="btn btn-primary btn-sm" data-payment-account-create><i class="fa fa-plus" aria-hidden="true"></i> {{ __($translationPrefix.'.add_account') }}</button></div></div>
        <div class="payment-merchant-grid">
            @foreach ($dashboard['merchants'] as $merchant)
                <article class="payment-merchant-card">
                    <header>
                        <div class="payment-account-identity"><span class="payment-account-logo"><i class="fa fa-paypal" aria-hidden="true"></i></span><div><h3>{{ $merchant['name'] }}</h3><p>{{ __('payment.common.channels.paypal') }} {{ __('payment.common.separator') }} {{ $merchant['meta'] }}</p><div class="payment-account-order-times"><span>{{ __($translationPrefix.'.order_times.first') }}：<b>{{ $merchant['first_order_at'] }}</b></span><span>{{ __($translationPrefix.'.order_times.last') }}：<b>{{ $merchant['last_order_at'] }}</b></span></div></div></div>
                        <div class="payment-account-header-badges"><span class="payment-account-usage-badge">{{ __($translationPrefix.'.order_times.used') }} {{ $merchant['usage_days'] === null ? '-' : __($translationPrefix.'.order_times.days', ['count' => $merchant['usage_days']]) }}</span><span class="payment-account-risk-badge payment-account-risk-{{ $merchant['risk_status'] }}" title="{{ __($translationPrefix.'.risk_status.window', ['range' => $merchant['risk_window']]) }}">{{ __($translationPrefix.'.risk_status.'.$merchant['risk_status']) }}@if($merchant['risk_status'] === 'risk') {{ $merchant['risk_rate'] }}@endif</span></div>
                    </header>
                    <div class="payment-merchant-overview">
                        <div><span>{{ __($translationPrefix.'.metrics.orders') }}</span><strong>{{ $merchant['orders'] }}<em>{{ __($translationPrefix.'.metrics.orders_unit') }}</em></strong><small>{{ __($translationPrefix.'.metrics.orders_note') }}</small></div>
                        <div><span>{{ __($translationPrefix.'.metrics.amount') }}</span><strong>{{ $merchant['amount'] }}</strong><small>{{ __($translationPrefix.'.metrics.amount_note') }}</small></div>
                        <div><span>{{ __($translationPrefix.'.metrics.stores') }}</span><strong>{{ $merchant['stores'] }}<em>{{ __($translationPrefix.'.metrics.stores_unit') }}</em></strong><small>{{ __($translationPrefix.'.metrics.stores_note') }}</small></div>
                        <div><span>{{ __($translationPrefix.'.metrics.attention_rate') }}</span><strong>{{ $merchant['shipping_rate'] }} / {{ $merchant['delivery_rate'] }}</strong><small>{{ __($translationPrefix.'.metrics.attention_rate_note') }}</small></div>
                    </div>
                    <div class="payment-rate-list">
                        @foreach ($merchant['rates'] as $rate)
                            <div class="payment-rate-item payment-rate-{{ $rate['tone'] }}"><div class="payment-rate-heading"><span>{{ __($translationPrefix.'.rate_labels.'.$rate['key']) }}</span><strong>{{ $rate['value'] }}</strong></div><p class="payment-rate-formula">{{ __($translationPrefix.'.rate_formulas.'.$rate['key']) }}</p><div class="payment-rate-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $rate['progress'] }}"><span style="width: {{ $rate['progress'] }}%"></span></div><small>{{ __($translationPrefix.'.rate_notes.'.$rate['key'], $rate['note_params']) }}</small></div>
                        @endforeach
                    </div>
                    <footer class="payment-account-compact-footer {{ ($merchant['balance']['is_stale'] ?? false) ? 'is-stale' : '' }}">
                        @if($merchant['balance'])
                            <div class="payment-account-compact-balance">
                                <div class="payment-account-compact-values"><p><span>{{ __($translationPrefix.'.balance.available') }}：</span><strong>{{ $merchant['balance']['available'] }}</strong></p><p><span>{{ __($translationPrefix.'.balance.withheld') }}：</span><strong>{{ $merchant['balance']['withheld'] }}</strong></p></div>
                                <small><i class="fa fa-clock-o" aria-hidden="true"></i> {{ __($translationPrefix.'.balance.updated_ago', ['time' => __($translationPrefix.'.balance.relative.'.$merchant['balance']['relative_time']['key'], ['count' => $merchant['balance']['relative_time']['count']])]) }}@if($merchant['balance']['is_stale']) · {{ __($translationPrefix.'.balance.stale') }}@endif</small>
                            </div>
                        @else
                            <p class="payment-account-balance-pending"><i class="fa fa-info-circle" aria-hidden="true"></i> {{ __($translationPrefix.'.balance.pending') }}</p>
                        @endif
                        <div class="payment-account-card-actions"><button type="button" class="btn btn-default btn-sm payment-account-detail-button" data-payment-account-detail data-account-id="{{ $merchant['id'] }}" data-account-name="{{ $merchant['name'] }}">{{ __($translationPrefix.'.view_detail') }}</button><button type="button" class="btn btn-default btn-sm payment-account-edit-button" data-payment-account-edit data-account-id="{{ $merchant['id'] }}" data-account-name="{{ $merchant['edit']['account_name'] }}" data-account-merchant="{{ $merchant['edit']['merchant_id'] }}" data-account-email="{{ $merchant['edit']['account_email'] }}" data-account-environment="{{ $merchant['edit']['environment'] }}" data-account-enabled="{{ $merchant['edit']['is_enabled'] ? '1' : '0' }}"><i class="fa fa-pencil" aria-hidden="true"></i> {{ __($translationPrefix.'.edit_modal.button') }}</button></div>
                    </footer>
                </article>
            @endforeach
        </div>
        @if ($dashboard['merchants'] === [])<p class="payment-empty-filter">{{ __($translationPrefix.'.empty_results') }}</p>@endif
        @include('portals.payment.partials.pagination', ['pagination' => $dashboard['pagination'], 'routeName' => 'payment.account-risk.index'])
        <div class="modal fade payment-account-create-modal" id="payment-account-create-modal" tabindex="-1" role="dialog" aria-labelledby="payment-account-create-title" aria-hidden="true">
            <div class="modal-dialog" role="document"><form class="modal-content" method="post" action="{{ route('payment.account-risk.accounts.store') }}" data-payment-account-form>
                @csrf
                <input type="hidden" name="_method" value="PUT" disabled data-payment-account-method>
                <div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="{{ __($translationPrefix.'.create_modal.cancel') }}"><span aria-hidden="true">&times;</span></button><h4 class="modal-title" id="payment-account-create-title" data-payment-account-modal-title><i class="fa fa-paypal" aria-hidden="true"></i> {{ __($translationPrefix.'.create_modal.title') }}</h4></div>
                <div class="modal-body">
                    <div class="alert alert-info payment-account-create-note"><i class="fa fa-info-circle" aria-hidden="true"></i> <span data-payment-account-modal-note>{{ __($translationPrefix.'.create_modal.note') }}</span></div>
                    <div class="row">
                        <div class="form-group col-sm-6"><label for="payment-account-create-name">{{ __($translationPrefix.'.create_modal.account_name') }} <span class="text-red">*</span></label><input id="payment-account-create-name" class="form-control" type="text" name="account_name" maxlength="191" required value="{{ old('account_name') }}" placeholder="{{ __($translationPrefix.'.create_modal.account_name_placeholder') }}"></div>
                        <div class="form-group col-sm-6"><label for="payment-account-create-environment">{{ __($translationPrefix.'.create_modal.environment') }} <span class="text-red">*</span></label><select id="payment-account-create-environment" class="form-control" name="environment" required><option value="live" @selected(old('environment', 'live') === 'live')>{{ __($translationPrefix.'.create_modal.environment_live') }}</option><option value="sandbox" @selected(old('environment') === 'sandbox')>{{ __($translationPrefix.'.create_modal.environment_sandbox') }}</option></select></div>
                        <div class="form-group col-sm-6"><label for="payment-account-create-merchant">{{ __($translationPrefix.'.create_modal.merchant_id') }} <span class="text-red">*</span></label><input id="payment-account-create-merchant" class="form-control" type="text" name="merchant_id" maxlength="100" required value="{{ old('merchant_id') }}" placeholder="{{ __($translationPrefix.'.create_modal.merchant_id_placeholder') }}"></div>
                        <div class="form-group col-sm-6"><label for="payment-account-create-email">{{ __($translationPrefix.'.create_modal.account_email') }}</label><input id="payment-account-create-email" class="form-control" type="email" name="account_email" maxlength="191" value="{{ old('account_email') }}" placeholder="{{ __($translationPrefix.'.create_modal.account_email_placeholder') }}"></div>
                        <div class="form-group col-sm-12 payment-account-create-enabled"><input type="hidden" name="is_enabled" value="{{ (bool) old('is_enabled', true) ? '1' : '0' }}" data-payment-account-enabled-value><label><input id="payment-account-create-enabled" type="checkbox" data-payment-account-enabled @checked((bool) old('is_enabled', true))> {{ __($translationPrefix.'.create_modal.enabled') }}</label></div>
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">{{ __($translationPrefix.'.create_modal.cancel') }}</button><button type="submit" class="btn btn-primary"><i class="fa fa-save" aria-hidden="true"></i> {{ __($translationPrefix.'.create_modal.save') }}</button></div>
            </form></div>
        </div>
        <div class="modal fade payment-account-detail-modal" id="payment-account-detail-modal" tabindex="-1" role="dialog" aria-labelledby="payment-account-detail-title" aria-hidden="true">
            <div class="modal-dialog modal-lg" role="document"><div class="modal-content">
                <div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="{{ __($translationPrefix.'.detail.close') }}"><span aria-hidden="true">&times;</span></button><h4 class="modal-title" id="payment-account-detail-title">{{ __($translationPrefix.'.detail.title') }}</h4><p data-payment-detail-subtitle></p></div>
                <div class="modal-body">
                    <div class="payment-detail-tabs" role="tablist">@foreach (['overview', 'monthly_report', 'order_analysis', 'products', 'orders', 'refunds', 'disputes', 'funding'] as $tab)<button type="button" class="{{ $tab === 'overview' ? 'active' : '' }}" data-payment-detail-tab="{{ $tab }}" role="tab" aria-selected="{{ $tab === 'overview' ? 'true' : 'false' }}">{{ $tab === 'monthly_report' ? __('payment.account_risk.monthly_report.title') : __($translationPrefix.'.detail.tabs.'.$tab) }}</button>@endforeach</div>
                    <div class="payment-detail-summary" data-payment-detail-summary hidden></div>
                    <form class="payment-detail-filter payment-risk-style-filter" data-payment-detail-filter hidden><label class="payment-detail-filter-keyword"><span>{{ __($translationPrefix.'.detail.filters.keyword') }}</span><input class="form-control" type="search" name="keyword" maxlength="191" placeholder="{{ __($translationPrefix.'.detail.keyword_placeholder') }}"></label><div class="payment-detail-filter-selects" data-payment-detail-extra-filters></div><div class="payment-detail-filter-actions"><button class="btn btn-primary" type="submit"><i class="fa fa-search" aria-hidden="true"></i> {{ __($translationPrefix.'.detail.query') }}</button><button class="btn btn-default" type="reset">{{ __($translationPrefix.'.detail.reset') }}</button></div></form>
                    <div class="payment-detail-state" data-payment-detail-loading><i class="fa fa-spinner fa-spin" aria-hidden="true"></i> {{ __($translationPrefix.'.detail.loading') }}</div>
                    <div class="payment-detail-state payment-detail-error" data-payment-detail-error hidden></div>
                    <div class="payment-detail-body" data-payment-detail-body></div>
                    <div class="payment-detail-pagination" data-payment-detail-pagination></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">{{ __($translationPrefix.'.detail.close') }}</button></div>
            </div></div>
        </div>
    </section>
@endsection

@push('scripts')
    @php
        $accountDetailConfig = array_replace_recursive(__($translationPrefix.'.detail'), [
            'monthly_report' => __('payment.account_risk.monthly_report'),
            'filter_options' => [
                'refund_status' => [
                    \App\Models\LogisticsPayPalRefund::statusLabelFor('D'),
                    \App\Models\LogisticsPayPalRefund::statusLabelFor('P'),
                    \App\Models\LogisticsPayPalRefund::statusLabelFor('S'),
                    \App\Models\LogisticsPayPalRefund::statusLabelFor('V'),
                ],
                'dispute_type' => [
                    \App\Models\LogisticsPayPalDispute::typeLabelFor('ALERT'),
                    \App\Models\LogisticsPayPalDispute::typeLabelFor('INTERNAL', 'INQUIRY'),
                    \App\Models\LogisticsPayPalDispute::typeLabelFor('INTERNAL'),
                    \App\Models\LogisticsPayPalDispute::typeLabelFor('EXTERNAL'),
                ],
                'dispute_status' => [
                    \App\Models\LogisticsPayPalDispute::statusLabelFor('OPEN'),
                    \App\Models\LogisticsPayPalDispute::statusLabelFor('WAITING_FOR_BUYER_RESPONSE'),
                    \App\Models\LogisticsPayPalDispute::statusLabelFor('WAITING_FOR_SELLER_RESPONSE'),
                    \App\Models\LogisticsPayPalDispute::statusLabelFor('UNDER_REVIEW'),
                    \App\Models\LogisticsPayPalDispute::statusLabelFor('RESOLVED'),
                ],
                'dispute_outcome' => [
                    \App\Models\LogisticsPayPalDispute::outcomeLabelFor('RESOLVED_BUYER_FAVOUR'),
                    \App\Models\LogisticsPayPalDispute::outcomeLabelFor('RESOLVED_SELLER_FAVOUR'),
                    \App\Models\LogisticsPayPalDispute::outcomeLabelFor('CANCELED_BY_BUYER'),
                    \App\Models\LogisticsPayPalDispute::outcomeLabelFor('CANCELED_BY_SELLER'),
                    \App\Models\LogisticsPayPalDispute::outcomeLabelFor('RESOLVED_WITH_REFUND'),
                    \App\Models\LogisticsPayPalDispute::outcomeLabelFor('RESOLVED_WITH_PAYOUT'),
                ],
            ],
        ]);
        $accountFormConfig = [
            'create_title' => __($translationPrefix.'.create_modal.title'),
            'create_note' => __($translationPrefix.'.create_modal.note'),
            'edit_title' => __($translationPrefix.'.edit_modal.title'),
            'edit_note' => __($translationPrefix.'.edit_modal.note'),
        ];
    @endphp
    <script>window.PaymentPortal = {
        actionTitle: @json(__('payment.common.mock_action_title')),
        actionMessage: @json(__('payment.common.mock_action_message')),
        accountDetailUrl: @json(route('payment.account-risk.accounts.detail', ['account' => '__ACCOUNT__'])),
        accountMonthlyExportUrl: @json(route('payment.account-risk.accounts.monthly-report.export', ['account' => '__ACCOUNT__'])),
        accountStoreUrl: @json(route('payment.account-risk.accounts.store')),
        accountUpdateUrl: @json(route('payment.account-risk.accounts.update', ['account' => '__ACCOUNT__'])),
        accountForm: @json($accountFormConfig),
        accountDetail: @json($accountDetailConfig),
        pagination: @json(__('payment.common.pagination'))
    };</script>
    @if ($errors->any())<script>jQuery(function () { jQuery('#payment-account-create-modal').modal('show'); });</script>@endif
    <script src="{{ $portalOrigin }}/assets/erp/payment-portal.js?v=20261026-6"></script>
@endpush
