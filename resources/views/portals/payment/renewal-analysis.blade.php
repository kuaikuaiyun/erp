@extends('portal.layout')

@php
    $t = 'payment.renewal_analysis';
    $portalOrigin = rtrim(request()->getSchemeAndHttpHost(), '/');
    $filters = $dashboard['filters'];
    $summary = $dashboard['summary'];
    $money = static fn ($value) => '$'.number_format((float) $value, 2);
    $detailTabs = ['' => 'all', 'paypal' => 'paypal', 'credit_card' => 'credit_card'];
    $detailTabQuery = request()->except(['page', 'per_page', 'detail_channel']);
@endphp

@section('page_title', __($t.'.page_title'))

@push('styles')
<link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/payment-portal.css?v=20261102-3">
<link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/payment-shared.css?v=20261026-1">
@endpush

@section('content')
<section class="content payment-portal-page payment-renewal-page">
    <header class="payment-page-heading payment-renewal-heading">
        <div><h1>{{ __($t.'.page_title') }}</h1><p>{{ __($t.'.page_subtitle') }}</p></div>
        <div class="payment-renewal-heading-summary"><i class="fa fa-refresh"></i><span>{{ number_format($summary['renewed']) }}</span> {{ __($t.'.kpis.renewal_orders') }}</div>
    </header>

    <form class="payment-renewal-filter" method="get" action="{{ route('payment.renewal-analysis.index') }}">
        <label class="payment-renewal-cohort"><span>{{ __($t.'.filters.cohort_period') }}</span><span class="payment-operation-date-range"><input class="form-control" type="date" name="date_from" value="{{ $filters['date_from'] }}"><b>{{ __('payment.common.range_to') }}</b><input class="form-control" type="date" name="date_to" value="{{ $filters['date_to'] }}"></span></label>
        <label class="payment-renewal-mode"><span>{{ __($t.'.filters.risk_date_mode') }}</span><select class="form-control" name="risk_date_mode"><option value="payment" @selected($filters['risk_date_mode']==='payment')>{{ __($t.'.filters.payment_mode') }}</option><option value="event" @selected($filters['risk_date_mode']==='event')>{{ __($t.'.filters.event_mode') }}</option></select></label>
        <label><span>{{ __($t.'.filters.channel') }}</span><select class="form-control" name="channel"><option value="">{{ __($t.'.filters.all_channels') }}</option>@foreach(['paypal','credit_card'] as $channel)<option value="{{ $channel }}" @selected($filters['channel']===$channel)>{{ __($t.'.channels.'.$channel) }}</option>@endforeach</select></label>
        <label><span>{{ __($t.'.filters.stage') }}</span><select class="form-control" name="stage"><option value="">{{ __($t.'.filters.all_stages') }}</option>@foreach($dashboard['stage_options'] as $stage)<option value="{{ $stage }}" @selected($filters['stage']===$stage)>{{ __($t.'.funnel.stage_name',['number'=>(int)str_replace('renewal_','',$stage)]) }}</option>@endforeach</select></label>
        <label><span>{{ __($t.'.filters.store') }}</span><select class="form-control" name="store"><option value="">{{ __($t.'.filters.all_stores') }}</option>@foreach($dashboard['stores'] as $store)<option value="{{ $store }}" @selected($filters['store']===$store)>{{ $store }}</option>@endforeach</select></label>
        <label class="payment-renewal-keyword"><span>{{ __($t.'.filters.keyword') }}</span><input class="form-control" type="search" name="keyword" value="{{ $filters['keyword'] }}" placeholder="{{ __($t.'.filters.keyword_placeholder') }}"></label>
        <div class="payment-renewal-filter-actions"><button class="btn btn-primary" type="submit"><i class="fa fa-search"></i> {{ __('payment.common.query') }}</button><a class="btn btn-default" href="{{ route('payment.renewal-analysis.index') }}">{{ __('payment.common.reset') }}</a><a class="btn btn-success" href="{{ route('payment.renewal-analysis.export', request()->except(['page','per_page'])) }}"><i class="fa fa-file-excel-o"></i> {{ __($t.'.export.button') }}</a></div>
    </form>

    <div class="payment-kpi-grid payment-kpi-grid-four payment-renewal-kpis">
        <article class="payment-kpi-card payment-renewal-kpi payment-renewal-kpi-primary"><i class="fa fa-refresh"></i><div><p>{{ __($t.'.kpis.total_rate') }}</p><strong>{{ number_format($summary['rate'],2) }}%</strong><small>{{ number_format($summary['renewed']) }} / {{ number_format($summary['eligible']) }}</small></div></article>
        <article class="payment-kpi-card payment-renewal-kpi payment-renewal-kpi-paypal"><i class="fa fa-paypal"></i><div><p>{{ __($t.'.kpis.paypal_rate') }}</p><strong>{{ number_format($summary['paypal']['rate'],2) }}%</strong><small>{{ number_format($summary['paypal']['renewed']) }} / {{ number_format($summary['paypal']['eligible']) }}</small></div></article>
        <article class="payment-kpi-card payment-renewal-kpi payment-renewal-kpi-card"><i class="fa fa-credit-card"></i><div><p>{{ __($t.'.kpis.card_rate') }}</p><strong>{{ number_format($summary['credit_card']['rate'],2) }}%</strong><small>{{ number_format($summary['credit_card']['renewed']) }} / {{ number_format($summary['credit_card']['eligible']) }}</small></div></article>
        <article class="payment-kpi-card payment-renewal-kpi payment-renewal-kpi-revenue"><i class="fa fa-usd"></i><div><p>{{ __($t.'.kpis.renewal_revenue') }}</p><strong>{{ $money($summary['renewal_revenue']) }}</strong><small>{{ number_format($summary['renewal_orders']) }} {{ __($t.'.kpis.renewal_orders') }}</small></div></article>
    </div>
    <p class="payment-renewal-observation"><i class="fa fa-info-circle"></i> {{ __($t.'.observation_note',['days'=>$dashboard['observation_days']]) }}</p>

    <article class="payment-panel payment-renewal-funnel"><h2>{{ __($t.'.funnel.title') }}</h2>
        <div class="table-responsive"><table class="table payment-data-table"><thead><tr>@foreach(['stage','eligible','completed','renewal_rate','loss','orders_revenue','refund','dispute'] as $column)<th>{{ __($t.'.funnel.'.$column) }}</th>@endforeach</tr></thead><tbody>
        @forelse($dashboard['stages'] as $stage)<tr><td><strong>{{ __($t.'.funnel.stage_name',['number'=>$stage['number']]) }}</strong></td><td>{{ number_format($stage['eligible_chains']) }}</td><td>{{ number_format($stage['completed_chains']) }}</td><td><strong>{{ number_format($stage['rate'],2) }}%</strong></td><td><span class="text-danger">{{ number_format($stage['loss_count']) }} / {{ number_format($stage['loss_rate'],2) }}%</span></td><td>{{ number_format($stage['orders']) }} / {{ $money($stage['revenue']) }}</td><td>{{ number_format($stage['refund_count']) }} / {{ $money($stage['refund_amount']) }}<small>{{ number_format($stage['refund_rate'],2) }}%</small></td><td>{{ number_format($stage['dispute_count']) }} / {{ $money($stage['dispute_amount']) }}<small>{{ number_format($stage['dispute_rate'],2) }}%</small></td></tr>
        @empty<tr><td colspan="8" class="payment-product-empty">{{ __($t.'.funnel.empty') }}</td></tr>@endforelse
        </tbody></table></div>
    </article>

    <div class="payment-renewal-two-column">
        <article class="payment-panel"><h2>{{ __($t.'.risk.title') }}</h2><table class="table payment-data-table"><thead><tr>@foreach(['channel','orders','revenue','refunds','disputes'] as $column)<th>{{ __($t.'.risk.'.$column) }}</th>@endforeach</tr></thead><tbody>@foreach($dashboard['risk_by_channel'] as $channel=>$risk)<tr><td><strong>{{ __($t.'.channels.'.$channel) }}</strong></td><td>{{ number_format($risk['orders']) }}</td><td>{{ $money($risk['revenue']) }}</td><td>{{ number_format($risk['refund_count']) }} / {{ $money($risk['refund_amount']) }}</td><td>{{ number_format($risk['dispute_count']) }} / {{ $money($risk['dispute_amount']) }}</td></tr>@endforeach</tbody></table></article>
        <article class="payment-panel payment-renewal-upsell"><h2>{{ __($t.'.upsell.title') }}</h2><div><span>{{ __($t.'.upsell.linked_orders') }}<strong>{{ number_format($dashboard['upsell']['linked_orders']) }}</strong></span><span>{{ __($t.'.upsell.measured') }}<strong>{{ number_format($dashboard['upsell']['measured_orders']) }} / {{ $money($dashboard['upsell']['revenue']) }}</strong></span><span>{{ __($t.'.upsell.refund') }}<strong>{{ number_format($dashboard['upsell']['refund_count']) }} / {{ $money($dashboard['upsell']['refund_amount']) }}</strong></span><span>{{ __($t.'.upsell.dispute') }}<strong>{{ number_format($dashboard['upsell']['dispute_count']) }} / {{ $money($dashboard['upsell']['dispute_amount']) }}</strong></span><span>{{ __($t.'.upsell.references') }}<strong>{{ number_format($dashboard['upsell']['references']) }}</strong></span><span>{{ __($t.'.upsell.unmeasured') }}<strong>{{ number_format($dashboard['upsell']['unmeasured']) }}</strong></span></div><p>{{ __($t.'.upsell.note') }}</p></article>
    </div>

    <article class="payment-panel payment-renewal-detail"><h2>{{ __($t.'.detail.title') }}</h2><nav class="payment-tabs payment-renewal-detail-tabs" role="tablist">@foreach($detailTabs as $channel=>$tab)<a href="{{ route('payment.renewal-analysis.index', array_merge($detailTabQuery, $channel === '' ? [] : ['detail_channel' => $channel])) }}" class="{{ $filters['detail_channel'] === $channel ? 'active' : '' }}" role="tab" aria-selected="{{ $filters['detail_channel'] === $channel ? 'true' : 'false' }}">{{ __($t.'.detail.tabs.'.$tab) }}</a>@endforeach</nav><div class="table-responsive payment-fixed-table"><table class="table payment-data-table"><thead><tr>@foreach(['stage','initial_channel','actual_channel','initial_order','renewal_order','store','transaction','paid_at','amount','refund','dispute'] as $column)<th>{{ __($t.'.detail.'.$column) }}</th>@endforeach</tr></thead><tbody>
        @forelse($dashboard['rows'] as $row)
            <tr>
                <td>{{ __($t.'.funnel.stage_name',['number'=>$row['stage_number']]) }}</td><td>{{ __($t.'.channels.'.$row['initial_channel']) }}</td><td>{{ __($t.'.channels.'.$row['actual_channel']) }}</td><td>{{ $row['initial_order'] }}</td><td><strong>{{ $row['renewal_order'] }}</strong></td><td>{{ $row['store_name'] ?: '-' }}</td><td>{{ $row['transaction_id'] ?: '-' }}</td><td>{{ $row['paid_at'] ?: '-' }}</td><td>{{ $money($row['amount']) }}</td>
                <td class="payment-renewal-risk-detail"><strong>{{ $row['refund_count'] }} / {{ $money($row['refund_amount']) }}</strong>
                    @if (!empty($row['refund_details']))
                        @foreach ($row['refund_details'] as $detail)
                            <div><b>{{ $money($detail['amount']) }}</b> {{ $detail['reason'] ?: '-' }}<small>{{ $detail['time'] }} / {{ $detail['status'] }}</small></div>
                        @endforeach
                    @endif
                </td>
                <td class="payment-renewal-risk-detail"><strong>{{ $row['dispute_count'] }} / {{ $money($row['dispute_amount']) }}</strong>
                    @if (!empty($row['dispute_details']))
                        @foreach ($row['dispute_details'] as $detail)
                            <div><b>{{ $money($detail['amount']) }}</b> {{ $detail['reason'] ?: '-' }}<small>{{ $detail['time'] }} / {{ $detail['status'] }}</small></div>
                        @endforeach
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="11" class="payment-product-empty">{{ __($t.'.detail.empty') }}</td></tr>
        @endforelse
    </tbody></table></div></article>

    @include('portals.payment.partials.pagination', ['pagination'=>$dashboard['pagination'], 'routeName'=>'payment.renewal-analysis.index'])
</section>
@endsection

@push('scripts')
<script src="{{ $portalOrigin }}/assets/erp/payment-portal.js?v=20261026-1"></script>
@endpush
