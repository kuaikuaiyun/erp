@extends('portal.layout')

@php
    $t = 'payment.renewal_analysis';
    $portalOrigin = rtrim(request()->getSchemeAndHttpHost(), '/');
    $filters = $dashboard['filters'];
    $summary = $dashboard['summary'];
    $money = static fn ($value) => '$'.number_format((float) $value, 2);
@endphp

@section('page_title', __($t.'.page_title'))

@push('styles')
<link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/payment-portal.css?v=20261104-2">
<link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/payment-shared.css?v=20261026-1">
@endpush

@section('content')
<section class="content payment-portal-page payment-renewal-page">
    <header class="payment-page-heading payment-renewal-heading">
        <div><h1>{{ __($t.'.page_title') }}</h1><p>{{ __($t.'.page_subtitle') }}</p></div>
        <div class="payment-renewal-heading-summary"><i class="fa fa-refresh"></i><span>{{ number_format($summary['renewal_orders']) }}</span> {{ __($t.'.kpis.renewal_orders') }}</div>
    </header>

    <form class="payment-renewal-filter" method="get" action="{{ route('payment.renewal-analysis.index') }}">
        <label class="payment-renewal-cohort"><span>{{ __($t.'.filters.cohort_period') }}</span><span class="payment-operation-date-range"><input class="form-control" type="date" name="date_from" value="{{ $filters['date_from'] }}"><b>{{ __('payment.common.range_to') }}</b><input class="form-control" type="date" name="date_to" value="{{ $filters['date_to'] }}"></span></label>
        <label><span>{{ __($t.'.filters.channel') }}</span><select class="form-control" name="channel"><option value="">{{ __($t.'.filters.all_channels') }}</option>@foreach(['paypal','credit_card'] as $channel)<option value="{{ $channel }}" @selected($filters['channel']===$channel)>{{ __($t.'.channels.'.$channel) }}</option>@endforeach</select></label>
        <label><span>{{ __($t.'.filters.stage') }}</span><select class="form-control" name="stage"><option value="">{{ __($t.'.filters.all_stages') }}</option>@foreach($dashboard['stage_options'] as $stage)<option value="{{ $stage }}" @selected($filters['stage']===$stage)>{{ __($t.'.funnel.stage_name',['number'=>(int)str_replace('renewal_','',$stage)]) }}</option>@endforeach</select></label>
        <label><span>{{ __($t.'.filters.store') }}</span><select class="form-control" name="store"><option value="">{{ __($t.'.filters.all_stores') }}</option>@foreach($dashboard['stores'] as $store)<option value="{{ $store }}" @selected($filters['store']===$store)>{{ $store }}</option>@endforeach</select></label>
        <div class="payment-renewal-filter-actions"><button class="btn btn-primary" type="submit"><i class="fa fa-search"></i> {{ __('payment.common.query') }}</button><a class="btn btn-default" href="{{ route('payment.renewal-analysis.index') }}">{{ __('payment.common.reset') }}</a><a class="btn btn-success" href="{{ route('payment.renewal-analysis.export', array_diff_key($filters, ['stage_page' => true, 'risk_date_mode' => true])) }}"><i class="fa fa-file-excel-o"></i> {{ __($t.'.export.button') }}</a></div>
    </form>

    <section class="payment-renewal-metric-section">
        <header><h2>{{ __($t.'.sections.renewal_performance') }}</h2><p>{{ __($t.'.sections.renewal_performance_note') }}</p></header>
        <div class="payment-kpi-grid payment-renewal-kpis">
            <article class="payment-kpi-card payment-renewal-kpi payment-renewal-kpi-primary"><i class="fa fa-refresh"></i><div><p>{{ __($t.'.kpis.total_rate') }}</p><strong>{{ number_format($summary['rate'],2) }}%</strong><small>{{ __($t.'.kpis.completed_first_renewal') }} {{ number_format($summary['renewed']) }} / {{ __($t.'.kpis.expected_first_renewal') }} {{ number_format($summary['eligible']) }}</small></div></article>
            <article class="payment-kpi-card payment-renewal-kpi payment-renewal-kpi-paypal"><i class="fa fa-paypal"></i><div><p>{{ __($t.'.kpis.paypal_rate') }}</p><strong>{{ number_format($summary['paypal']['rate'],2) }}%</strong><small>{{ __($t.'.kpis.completed_first_renewal') }} {{ number_format($summary['paypal']['renewed']) }} / {{ __($t.'.kpis.expected_first_renewal') }} {{ number_format($summary['paypal']['eligible']) }}</small></div></article>
            <article class="payment-kpi-card payment-renewal-kpi payment-renewal-kpi-card"><i class="fa fa-credit-card"></i><div><p>{{ __($t.'.kpis.card_rate') }}</p><strong>{{ number_format($summary['credit_card']['rate'],2) }}%</strong><small>{{ __($t.'.kpis.completed_first_renewal') }} {{ number_format($summary['credit_card']['renewed']) }} / {{ __($t.'.kpis.expected_first_renewal') }} {{ number_format($summary['credit_card']['eligible']) }}</small></div></article>
            <article class="payment-kpi-card payment-renewal-kpi payment-renewal-kpi-revenue"><i class="fa fa-usd"></i><div><p>{{ __($t.'.kpis.renewal_revenue') }}</p><strong>{{ $money($summary['renewal_revenue']) }}</strong><small>{{ number_format($summary['renewal_orders']) }} {{ __($t.'.kpis.renewal_orders') }}</small></div></article>
        </div>
        <p class="payment-renewal-metric-formula"><i class="fa fa-calculator"></i> {{ __($t.'.formulas.first_renewal_rate') }}</p>
    </section>

    <section class="payment-renewal-metric-section payment-renewal-subscription-section">
        <header><h2>{{ __($t.'.sections.subscription_rate') }}</h2><p>{{ __($t.'.sections.subscription_rate_note') }}</p></header>
        <div class="payment-kpi-grid payment-kpi-grid-three payment-renewal-kpis">
            @foreach(['total' => 'total_subscription_rate', 'paypal' => 'paypal_subscription_rate', 'credit_card' => 'credit_card_subscription_rate'] as $channel => $label)
                @php
                    $subscriptionRate = $summary['subscription_rate'][$channel];
                @endphp
                <article class="payment-kpi-card payment-renewal-kpi payment-renewal-kpi-subscription payment-renewal-kpi-subscription-{{ $channel }}"><i class="fa {{ $channel === 'paypal' ? 'fa-paypal' : ($channel === 'credit_card' ? 'fa-credit-card' : 'fa-repeat') }}"></i><div><p>{{ __($t.'.kpis.'.$label) }}</p><strong>{{ number_format($subscriptionRate['rate'],2) }}%</strong><small>{{ __($t.'.kpis.subscription_first_purchase') }} {{ number_format($subscriptionRate['subscription_orders']) }} / {{ __($t.'.kpis.qualified_first_orders') }} {{ number_format($subscriptionRate['total_orders']) }}</small></div></article>
            @endforeach
        </div>
        <p class="payment-renewal-metric-formula"><i class="fa fa-calculator"></i> {{ __($t.'.formulas.subscription_rate') }}<span>{{ __($t.'.formulas.subscription_scope') }}</span></p>
    </section>
    <p class="payment-renewal-observation"><i class="fa fa-info-circle"></i> {{ __($t.'.observation_note',['days'=>$dashboard['observation_days']]) }}</p>

    <article class="payment-panel payment-renewal-funnel"><h2>{{ __($t.'.funnel.title') }}</h2>
        <div class="table-responsive"><table class="table payment-data-table"><thead><tr>@foreach(['stage','eligible','completed','renewal_rate','loss','orders_revenue','refund','refund_count_rate','refund_amount_rate','dispute'] as $column)<th>{{ __($t.'.funnel.'.$column) }}</th>@endforeach</tr></thead><tbody>
        @forelse($dashboard['stages'] as $stage)<tr><td><strong>{{ __($t.'.funnel.stage_name',['number'=>$stage['number']]) }}</strong></td><td>{{ number_format($stage['eligible_chains']) }}</td><td>{{ number_format($stage['completed_chains']) }}</td><td><strong>{{ number_format($stage['rate'],2) }}%</strong></td><td><span class="text-danger">{{ number_format($stage['loss_count']) }} / {{ number_format($stage['loss_rate'],2) }}%</span></td><td>{{ number_format($stage['orders']) }} / {{ $money($stage['revenue']) }}</td><td>{{ number_format($stage['refund_count']) }} / {{ $money($stage['refund_amount']) }}</td><td>{{ number_format($stage['refund_count_rate'],2) }}%</td><td>{{ number_format($stage['refund_amount_rate'],2) }}%</td><td>{{ number_format($stage['dispute_count']) }} / {{ $money($stage['dispute_amount']) }}<small>{{ number_format($stage['dispute_rate'],2) }}%</small></td></tr>
        @empty<tr><td colspan="10" class="payment-product-empty">{{ __($t.'.funnel.empty') }}</td></tr>@endforelse
        </tbody></table></div>
        @php
            $p = $dashboard['stage_pagination'];
            $stageQuery = array_diff_key($filters, ['stage_page' => true, 'risk_date_mode' => true]);
            $stageUrl = static fn (int $number) => route('payment.renewal-analysis.index', array_merge($stageQuery, ['stage_page' => $number]));
        @endphp
        <div class="payment-unified-pagination">
            <span>{{ __($t.'.funnel.pagination_summary', ['from'=>$p['from'],'to'=>$p['to'],'total'=>$p['total']]) }}</span>
            <form method="get" action="{{ route('payment.renewal-analysis.index') }}" class="payment-pagination-form">
                @foreach($stageQuery as $name=>$value)<input type="hidden" name="{{ $name }}" value="{{ $value }}">@endforeach
                <span>{{ __($t.'.funnel.per_page') }}</span>
                <a class="btn btn-default btn-sm {{ $p['current_page'] <= 1 ? 'disabled' : '' }}" href="{{ $stageUrl(max(1,$p['current_page']-1)) }}">{{ __('payment.common.pagination.previous') }}</a>
                <strong>{{ __('payment.common.pagination.page_status',['current'=>$p['current_page'],'last'=>$p['last_page']]) }}</strong>
                <a class="btn btn-default btn-sm {{ $p['current_page'] >= $p['last_page'] ? 'disabled' : '' }}" href="{{ $stageUrl(min($p['last_page'],$p['current_page']+1)) }}">{{ __('payment.common.pagination.next') }}</a>
                <label><span>{{ __('payment.common.pagination.jump_to') }}</span><input class="form-control input-sm payment-page-jump-input" type="number" name="stage_page" min="1" max="{{ $p['last_page'] }}" placeholder="{{ __('payment.common.pagination.page_placeholder') }}" required></label>
                <button class="btn btn-primary btn-sm" type="submit">{{ __('payment.common.pagination.jump') }}</button>
            </form>
        </div>
        <p class="payment-renewal-metric-formula">{{ __($t.'.formulas.refund_rates') }}</p>
    </article>

    <div class="payment-renewal-two-column">
        <article class="payment-panel"><h2>{{ __($t.'.risk.title') }}</h2><table class="table payment-data-table"><thead><tr>@foreach(['channel','orders','revenue','refunds','refund_count_rate','refund_amount_rate','disputes'] as $column)<th>{{ __($t.'.risk.'.$column) }}</th>@endforeach</tr></thead><tbody>@foreach($dashboard['risk_by_channel'] as $channel=>$risk)<tr><td><strong>{{ __($t.'.channels.'.$channel) }}</strong></td><td>{{ number_format($risk['orders']) }}</td><td>{{ $money($risk['revenue']) }}</td><td>{{ number_format($risk['refund_count']) }} / {{ $money($risk['refund_amount']) }}</td><td>{{ number_format($risk['refund_count_rate'],2) }}%</td><td>{{ number_format($risk['refund_amount_rate'],2) }}%</td><td>{{ number_format($risk['dispute_count']) }} / {{ $money($risk['dispute_amount']) }}</td></tr>@endforeach</tbody></table></article>
        <article class="payment-panel payment-renewal-upsell"><h2>{{ __($t.'.upsell.title') }}</h2><div><span>{{ __($t.'.upsell.linked_orders') }}<strong>{{ number_format($dashboard['upsell']['linked_orders']) }}</strong></span><span>{{ __($t.'.upsell.measured') }}<strong>{{ number_format($dashboard['upsell']['measured_orders']) }} / {{ $money($dashboard['upsell']['revenue']) }}</strong></span><span>{{ __($t.'.upsell.refund') }}<strong>{{ number_format($dashboard['upsell']['refund_count']) }} / {{ $money($dashboard['upsell']['refund_amount']) }}</strong></span><span>{{ __($t.'.upsell.dispute') }}<strong>{{ number_format($dashboard['upsell']['dispute_count']) }} / {{ $money($dashboard['upsell']['dispute_amount']) }}</strong></span><span>{{ __($t.'.upsell.references') }}<strong>{{ number_format($dashboard['upsell']['references']) }}</strong></span><span>{{ __($t.'.upsell.unmeasured') }}<strong>{{ number_format($dashboard['upsell']['unmeasured']) }}</strong></span></div><p>{{ __($t.'.upsell.note') }}</p></article>
    </div>

</section>
@endsection

@push('scripts')
<script src="{{ $portalOrigin }}/assets/erp/payment-portal.js?v=20261117-1"></script>
@endpush
