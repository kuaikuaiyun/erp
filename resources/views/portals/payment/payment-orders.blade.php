@extends('portal.layout')

@php
    $translationPrefix = 'payment.payment_orders';
    $portalOrigin = rtrim(request()->getSchemeAndHttpHost(), '/');
    $filters = $dashboard['filters'];
    $summary = $dashboard['summary'];
    $pagination = $dashboard['pagination'];
    $money = fn($value) => '$'.number_format((float)$value, 2);
    $pageUrl = fn($page) => route('payment.orders.index', array_merge(request()->except('page'), ['page' => $page]));
@endphp

@section('page_title', __($translationPrefix.'.page_title'))

@push('styles')
<link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/payment-portal.css?v=20261026-1">
<link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/payment-shared.css?v=20261026-1">
@endpush

@section('content')
<section class="content payment-portal-page payment-orders-page">
    <div class="box box-solid payment-filter-box"><div class="box-header with-border"><h3 class="box-title"><i class="fa fa-filter"></i> {{ __('payment.common.filter_title') }}</h3></div><div class="box-body">
        <form class="payment-operation-filter-form" method="get" action="{{ route('payment.orders.index') }}" data-payment-order-filter>
            <input type="hidden" name="per_page" value="{{ $pagination['per_page'] }}">
            <input type="hidden" name="sort" value="{{ $filters['sort'] }}"><input type="hidden" name="direction" value="{{ $filters['direction'] }}">
            <label class="payment-operation-filter-field"><span>{{ __($translationPrefix.'.filters.keyword') }}</span><input class="form-control" type="search" name="keyword" value="{{ $filters['keyword'] }}" placeholder="{{ __($translationPrefix.'.filters.keyword_placeholder') }}"></label>
            <label class="payment-operation-filter-field"><span>{{ __($translationPrefix.'.filters.account') }}</span><select class="form-control" name="account"><option value="">{{ __($translationPrefix.'.filters.all_accounts') }}</option>@foreach($dashboard['accounts'] as $account)<option value="{{ $account['value'] }}" @selected($filters['account'] === $account['value'])>{{ $account['label'] }}</option>@endforeach</select></label>
            <label class="payment-operation-filter-field"><span>{{ __($translationPrefix.'.filters.channel') }}</span><select class="form-control" name="channel"><option value="">{{ __($translationPrefix.'.filters.all_channels') }}</option>@foreach(['paypal','card'] as $channel)<option value="{{ $channel }}" @selected($filters['channel'] === $channel)>{{ __($translationPrefix.'.channels.'.$channel) }}</option>@endforeach</select></label>
            <label class="payment-operation-filter-field"><span>{{ __($translationPrefix.'.filters.deduction_type') }}</span><select class="form-control" name="deduction_type"><option value="">{{ __($translationPrefix.'.filters.all_deduction_types') }}</option>@foreach(['one_time','subscription','renewal','unmatched'] as $type)<option value="{{ $type }}" @selected($filters['deduction_type'] === $type)>{{ __($translationPrefix.'.deduction_types.'.$type) }}</option>@endforeach</select></label>
            <label class="payment-operation-filter-field"><span>{{ __($translationPrefix.'.filters.date_mode') }}</span><select class="form-control" name="date_mode"><option value="paid" @selected($filters['date_mode'] === 'paid')>{{ __($translationPrefix.'.filters.paid_date') }}</option><option value="order" @selected($filters['date_mode'] === 'order')>{{ __($translationPrefix.'.filters.order_date') }}</option></select></label>
            <label class="payment-operation-filter-field"><span>{{ $filters['date_mode'] === 'order' ? __($translationPrefix.'.filters.order_period') : __($translationPrefix.'.filters.success_period') }}</span><span class="payment-operation-date-range"><input class="form-control" type="date" name="date_from" value="{{ $filters['date_from'] }}"><b>{{ __('payment.common.range_to') }}</b><input class="form-control" type="date" name="date_to" value="{{ $filters['date_to'] }}"></span></label>
            <div class="payment-operation-filter-actions"><button class="btn btn-primary" type="submit"><i class="fa fa-search"></i> {{ __('payment.common.query') }}</button><a class="btn btn-default" href="{{ route('payment.orders.index') }}">{{ __('payment.common.reset') }}</a></div>
        </form>
    </div></div>

    <div class="payment-kpi-grid payment-kpi-grid-four">
        @foreach ([['paid_orders','payment_count','indigo','fa-shopping-bag'],['received_amount','received_amount','cyan','fa-usd'],['risk_events','risk_payment_count','red','fa-gavel'],['missing_orders','unmatched_count','orange','fa-chain-broken']] as [$key,$field,$tone,$icon])
        <article class="payment-kpi-card payment-tone-{{ in_array($key, ['risk_events','missing_orders']) ? ($key === 'risk_events' ? 'danger' : 'warning') : 'primary' }}"><span class="payment-kpi-icon payment-kpi-icon-{{ $tone }}"><i class="fa {{ $icon }}"></i></span><div><p>{{ __($translationPrefix.'.kpis.'.$key) }}</p><strong>{{ $key === 'received_amount' ? $money($summary[$field]) : number_format($summary[$field]).__($translationPrefix.'.order_unit') }}</strong><small>{{ __($translationPrefix.'.kpis.'.$key.'_note') }}</small></div></article>
        @endforeach
    </div>

    <article class="payment-panel payment-order-list-panel"><h2>{{ __($translationPrefix.'.list_title') }}</h2><div class="table-responsive"><table class="table payment-data-table payment-orders-table"><thead><tr>@foreach(['transaction_id','channel_account','store_order','product_type','amount','status','time_match','action'] as $column)<th>@if(in_array($column,['transaction_id','channel_account','amount','time_match']))<button type="button" class="payment-order-sort" data-payment-order-sort="{{ ['transaction_id'=>'transaction_id','channel_account'=>'account','amount'=>'amount','time_match'=>'paid_at'][$column] }}">{{ __($translationPrefix.'.table.'.$column) }} <i class="fa fa-sort"></i></button>@else{{ __($translationPrefix.'.table.'.$column) }}@endif</th>@endforeach</tr></thead><tbody>
        @forelse($dashboard['rows'] as $row)<tr class="payment-order-row" tabindex="0" data-payment-order-open="{{ $row['record_key'] }}"><td><strong>{{ $row['transaction_id'] }}</strong></td><td><strong>{{ __($translationPrefix.'.channels.'.$row['channel']) }}</strong><small>{{ $row['account_name'] }}{{ $row['merchant_id'] ? ' · '.$row['merchant_id'] : '' }}</small></td><td><strong>{{ $row['store_name'] ?: __($translationPrefix.'.unmatched') }}</strong><small>{{ $row['shopify_order_number'] ?: __($translationPrefix.'.unmatched') }}</small></td><td><strong>{{ $row['product_text'] ?: __($translationPrefix.'.pending_product') }}</strong><small>{{ __($translationPrefix.'.deduction_types.'.$row['deduction_type']) }}</small></td><td><strong>{{ $row['currency_code'] }} {{ number_format($row['original_amount'],2) }}</strong><small>{{ $money($row['amount_usd']) }} USD</small></td><td><span class="payment-table-status {{ $row['risk_count'] ? 'payment-status-danger' : 'payment-status-normal' }}">{{ $row['payment_status'] ?: __($translationPrefix.'.paid') }}</span><small>{{ $row['risk_count'] ? __($translationPrefix.'.risk_count',['count'=>$row['risk_count']]) : __($translationPrefix.'.no_risk') }}</small></td><td><strong>{{ ($filters['date_mode'] === 'order' ? $row['order_created_at'] : $row['paid_at']) ?: '-' }}</strong><small>{{ __($translationPrefix.'.match_status.'.$row['match_status']) }}</small></td><td><button type="button" class="payment-text-action" data-payment-order-open="{{ $row['record_key'] }}">{{ __($translationPrefix.'.view_detail') }} <i class="fa fa-angle-right"></i></button></td></tr>@empty<tr><td class="payment-product-empty" colspan="8">{{ __($translationPrefix.'.empty') }}</td></tr>@endforelse
        </tbody></table></div></article>

    @include('portals.payment.partials.pagination', ['pagination' => $pagination, 'routeName' => 'payment.orders.index'])
</section>
@include('portals.payment.partials.payment-order-detail')
@endsection

@push('scripts')
<script>window.PaymentPortal = { paymentOrderDetailUrl: @json(route('payment.orders.detail')), paymentOrderDetail: @json(__($translationPrefix.'.detail')), paymentProductRiskUrl: @json(route('payment.product-risk-profit.index')), paymentAlertsUrl: @json(route('payment.product-alerts.index')) };</script>
<script src="{{ $portalOrigin }}/assets/erp/payment-portal.js?v=20261026-1"></script>
@endpush
