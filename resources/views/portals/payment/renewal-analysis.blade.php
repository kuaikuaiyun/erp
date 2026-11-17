@extends('portal.layout')

@php
    $t = 'payment.renewal_analysis';
    $portalOrigin = rtrim(request()->getSchemeAndHttpHost(), '/');
    $filters = $dashboard['filters'];
    $summary = $dashboard['summary'];
    $calc = $dashboard['calculation'];
    $money = static fn ($value) => '$'.number_format((float) $value, 2);
    $auditText = static fn (string $key, array $values = []) => __('payment.renewal_audit.'.$key, $values);
    $notes = static fn (array $lines) => new \Illuminate\Support\HtmlString(implode('', array_map(
        static fn ($line) => '<small class="payment-calculation-note">'.e($line).'</small>', $lines)));
    $ratio = static function ($a, $b, $result, int $decimals = 0) use ($auditText): array {
        $lines = [$auditText('ratio', ['a'=>number_format($a,$decimals),'b'=>number_format($b,$decimals),'result'=>number_format($result,2)])];
        if ($b <= 0) $lines[] = $auditText('zero');
        return $lines;
    };
    $stageName = static fn (int $number) => $number === 0 ? __($t.'.funnel.initial_stage') : __($t.'.funnel.stage_name',['number'=>$number]);
    $stageNumber = static fn (string $key) => $key === 'initial' ? 0 : (int) str_replace('renewal_', '', $key);
    $firstPeriod = $auditText('first_period', ['from'=>$calc['period_from'],'to'=>$calc['period_to']]);
    $comparisonPeriod = $auditText('cohort_period', ['from'=>$calc['comparison_from'],'to'=>$calc['comparison_to']]);
    $riskTime = $auditText('risk_time');
    $allScope = $auditText('fixed_scope');
    $stageScope = $auditText('stage_scope');
    $selectedScope = $auditText('selected_scope');
    $na = __($t.'.funnel.not_applicable');
    $exportQuery = array_diff_key($filters, ['stage_page'=>true, 'risk_date_mode'=>true]);
@endphp

@section('page_title', __($t.'.page_title'))

@push('styles')
<link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/payment-portal.css?v=20261104-2">
<link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/payment-shared.css?v=20261117-3">
@endpush

@section('content')
<section class="content payment-portal-page payment-renewal-page">
    <header class="payment-page-heading payment-renewal-heading">
        <div><h1>{{ __($t.'.page_title') }}</h1><p>{{ __($t.'.page_subtitle') }}</p></div>
        <div class="payment-renewal-heading-summary"><i class="fa fa-refresh"></i><span>{{ number_format($summary['orders']) }}</span> {{ __($t.'.kpis.period_orders') }}</div>
    </header>
    {{ $notes([$auditText('sum_orders',['count'=>$summary['orders']]),$selectedScope]) }}
    <form class="payment-renewal-filter" method="get" action="{{ route('payment.renewal-analysis.index') }}">
        <label class="payment-renewal-cohort"><span>{{ __($t.'.filters.cohort_period') }}</span><span class="payment-operation-date-range"><input class="form-control" type="date" name="date_from" value="{{ $filters['date_from'] }}"><b>{{ __('payment.common.range_to') }}</b><input class="form-control" type="date" name="date_to" value="{{ $filters['date_to'] }}"></span></label>
        <label><span>{{ __($t.'.filters.channel') }}</span><select class="form-control" name="channel"><option value="">{{ __($t.'.filters.all_channels') }}</option>@foreach(['paypal','credit_card'] as $channel)<option value="{{ $channel }}" @selected($filters['channel']===$channel)>{{ __($t.'.channels.'.$channel) }}</option>@endforeach</select></label>
        <label><span>{{ __($t.'.filters.stage') }}</span><select class="form-control" name="stage"><option value="">{{ __($t.'.filters.all_stages') }}</option>@foreach($dashboard['stage_options'] as $key)<option value="{{ $key }}" @selected($filters['stage']===$key)>{{ $stageName($stageNumber($key)) }}</option>@endforeach</select></label>
        <label><span>{{ __($t.'.filters.store') }}</span><select class="form-control" name="store"><option value="">{{ __($t.'.filters.all_stores') }}</option>@foreach($dashboard['stores'] as $store)<option value="{{ $store }}" @selected($filters['store']===$store)>{{ $store }}</option>@endforeach</select></label>
        <div class="payment-renewal-filter-actions"><button class="btn btn-primary" type="submit"><i class="fa fa-search"></i> {{ __('payment.common.query') }}</button><a class="btn btn-default" href="{{ route('payment.renewal-analysis.index') }}">{{ __('payment.common.reset') }}</a><a class="btn btn-success" href="{{ route('payment.renewal-analysis.export', $exportQuery) }}"><i class="fa fa-file-excel-o"></i> {{ __($t.'.export.button') }}</a></div>
    </form>

    <aside class="payment-calculation-context">
        <h2>{{ $auditText('title') }}</h2>
        {{ $notes([$firstPeriod,$comparisonPeriod,
            $auditText('time',['timezone'=>$calc['timezone'],'generated'=>$calc['generated_at'],'expires'=>$calc['expires_at']]),
            $auditText('filters',['channel'=>$filters['channel'] === '' ? __($t.'.filters.all_channels') : __($t.'.channels.'.$filters['channel']),
                'store'=>$filters['store'] ?: __($t.'.filters.all_stores'),
                'stage'=>$filters['stage'] === '' ? __($t.'.filters.all_stages') : $stageName($stageNumber($filters['stage']))]),
            $auditText('source_scope'),$auditText('channel_rule'),$auditText('rounding')]) }}
    </aside>

    <section class="payment-renewal-metric-section">
        <header><h2>{{ __($t.'.sections.renewal_performance') }}</h2><p>{{ __($t.'.sections.renewal_performance_note') }}</p></header>
        <div class="payment-kpi-grid payment-renewal-kpis">
            <article class="payment-kpi-card payment-renewal-kpi payment-renewal-kpi-primary"><i class="fa fa-list"></i><div><p>{{ __($t.'.kpis.period_orders') }}</p><strong>{{ number_format($summary['orders']) }}</strong>{{ $notes([$auditText('sum_orders',['count'=>$summary['orders']]),$firstPeriod,$selectedScope]) }}</div></article>
            <article class="payment-kpi-card payment-renewal-kpi payment-renewal-kpi-revenue"><i class="fa fa-usd"></i><div><p>{{ __($t.'.kpis.period_revenue') }}</p><strong>{{ $money($summary['revenue']) }}</strong>{{ $notes([$auditText('sum_money',['count'=>$summary['orders'],'amount'=>$money($summary['revenue'])]),$firstPeriod,$selectedScope]) }}</div></article>
            <article class="payment-kpi-card payment-renewal-kpi payment-renewal-kpi-paypal"><i class="fa fa-reply"></i><div><p>{{ __($t.'.kpis.refund_count_rate') }}</p><strong>{{ number_format($summary['refund_count_rate'],2) }}%</strong>{{ $notes(array_merge($ratio($summary['refund_order_count'],$summary['orders'],$summary['refund_count_rate']),[$auditText('refund_count',['a'=>$summary['refund_order_count'],'b'=>$summary['orders']]),$firstPeriod,$selectedScope,$riskTime])) }}</div></article>
            <article class="payment-kpi-card payment-renewal-kpi payment-renewal-kpi-card"><i class="fa fa-reply-all"></i><div><p>{{ __($t.'.kpis.refund_amount_rate') }}</p><strong>{{ number_format($summary['refund_amount_rate'],2) }}%</strong>{{ $notes(array_merge($ratio($summary['refund_amount'],$summary['revenue'],$summary['refund_amount_rate'],4),[$auditText('refund_money',['a'=>$money($summary['refund_amount']),'b'=>$money($summary['revenue'])]),$firstPeriod,$selectedScope,$riskTime])) }}</div></article>
        </div>
    </section>

    <section class="payment-renewal-metric-section payment-renewal-subscription-section">
        <header><h2>{{ __($t.'.sections.subscription_rate') }}</h2><p>{{ __($t.'.sections.subscription_rate_note') }}</p></header>
        <div class="payment-kpi-grid payment-kpi-grid-three payment-renewal-kpis">
            @foreach(['total' => 'total_subscription_rate', 'paypal' => 'paypal_subscription_rate', 'credit_card' => 'credit_card_subscription_rate'] as $channel => $label)
                @php
                    $subscriptionRate = $summary['subscription_rate'][$channel];
                @endphp
                <article class="payment-kpi-card payment-renewal-kpi payment-renewal-kpi-subscription payment-renewal-kpi-subscription-{{ $channel }}"><i class="fa {{ $channel === 'paypal' ? 'fa-paypal' : ($channel === 'credit_card' ? 'fa-credit-card' : 'fa-repeat') }}"></i><div><p>{{ __($t.'.kpis.'.$label) }}</p><strong>{{ number_format($subscriptionRate['rate'],2) }}%</strong><small>{{ __($t.'.kpis.subscription_first_purchase') }} {{ number_format($subscriptionRate['subscription_orders']) }} / {{ __($t.'.kpis.qualified_first_orders') }} {{ number_format($subscriptionRate['total_orders']) }}</small>{{ $notes(array_merge($ratio($subscriptionRate['subscription_orders'],$subscriptionRate['total_orders'],$subscriptionRate['rate']),[$auditText('subscription',['a'=>number_format($subscriptionRate['subscription_orders']),'b'=>number_format($subscriptionRate['total_orders'])]),$firstPeriod,$auditText('subscription_scope'),$allScope],$channel === 'total' ? [$auditText('subscription_total',['pa'=>$summary['subscription_rate']['paypal']['subscription_orders'],'ca'=>$summary['subscription_rate']['credit_card']['subscription_orders'],'a'=>$subscriptionRate['subscription_orders'],'pb'=>$summary['subscription_rate']['paypal']['total_orders'],'cb'=>$summary['subscription_rate']['credit_card']['total_orders'],'b'=>$subscriptionRate['total_orders']])] : [])) }}</div></article>
            @endforeach
        </div>
        <p class="payment-renewal-metric-formula"><i class="fa fa-calculator"></i> {{ __($t.'.formulas.subscription_rate') }}<span>{{ __($t.'.formulas.subscription_scope') }}</span></p>
    </section>
    <p class="payment-renewal-observation"><i class="fa fa-info-circle"></i> {{ __($t.'.observation_note') }}</p>

    <article class="payment-panel payment-renewal-funnel"><h2>{{ __($t.'.funnel.title') }}</h2>
        <div class="table-responsive"><table class="table payment-data-table"><thead><tr>@foreach(['stage','eligible','completed','renewal_rate','loss','orders_revenue','refund','refund_count_rate','refund_amount_rate','dispute'] as $column)<th>{{ __($t.'.funnel.'.$column) }}</th>@endforeach</tr></thead><tbody>
        @forelse($dashboard['stages'] as $stage)
        @php
            $initialStage = $stage['number'] === 0;
            $actualPeriod = $stage['paid_from'] === null ? $auditText('no_payments') : $auditText('actual_period',['from'=>$stage['paid_from'],'to'=>$stage['paid_to']]);
            $baseNote = $initialStage ? $auditText('not_applicable') : $auditText('base',['number'=>$stage['number'],'previous'=>$stageName($stage['number']-1),'count'=>$stage['eligible_chains']]);
            $outsideNote = $initialStage ? $auditText('initial_stage') : $auditText('outside_base',['orders'=>$stage['orders'],'outside'=>$stage['outside_base_chains']]);
        @endphp
        <tr class="{{ $initialStage ? 'payment-stage-initial' : '' }}">
            <td><strong>{{ $stageName($stage['number']) }}</strong>{{ $notes([$initialStage ? $auditText('initial_stage') : $auditText('stage_number',['number'=>$stage['number']]),$firstPeriod,$actualPeriod]) }}</td>
            <td>{{ $initialStage ? $na : number_format($stage['eligible_chains']) }}{{ $notes([$baseNote,$comparisonPeriod]) }}</td>
            <td>{{ $initialStage ? $na : number_format($stage['completed_chains']) }}{{ $notes([$initialStage ? $auditText('not_applicable') : $auditText('completed',['number'=>$stage['number'],'count'=>$stage['completed_chains']]),$outsideNote,$firstPeriod]) }}</td>
            <td>@if($initialStage){{ $na }}{{ $notes([$auditText('not_applicable')]) }}@else<strong>{{ number_format($stage['rate'],2) }}%</strong>{{ $notes(array_merge($ratio($stage['completed_chains'],$stage['eligible_chains'],$stage['rate']),[$baseNote,$comparisonPeriod])) }}@endif</td>
            <td>@if($initialStage){{ $na }}{{ $notes([$auditText('not_applicable')]) }}@else<span class="text-danger">{{ number_format($stage['loss_count']) }} / {{ number_format($stage['loss_rate'],2) }}%</span>{{ $notes(array_merge([$auditText('loss',['eligible'=>$stage['eligible_chains'],'completed'=>$stage['completed_chains'],'loss'=>$stage['loss_count']])],$ratio($stage['loss_count'],$stage['eligible_chains'],$stage['loss_rate']),[$firstPeriod,$comparisonPeriod])) }}@endif</td>
            <td>{{ number_format($stage['orders']) }} / {{ $money($stage['revenue']) }}{{ $notes([$auditText('orders',['count'=>$stage['orders']]),$auditText('revenue',['amount'=>$money($stage['revenue'])]),$actualPeriod]) }}</td>
            <td>{{ number_format($stage['refund_count']) }} / {{ $money($stage['refund_amount']) }}{{ $notes([$auditText('refund',['records'=>$stage['refund_count'],'orders'=>$stage['refund_order_count'],'amount'=>$money($stage['refund_amount'])]),$auditText('refund_rule'),$riskTime]) }}</td>
            <td>{{ number_format($stage['refund_count_rate'],2) }}%{{ $notes(array_merge($ratio($stage['refund_order_count'],$stage['orders'],$stage['refund_count_rate']),[$auditText('refund_count',['a'=>$stage['refund_order_count'],'b'=>$stage['orders']]),$actualPeriod,$riskTime])) }}</td>
            <td>{{ number_format($stage['refund_amount_rate'],2) }}%{{ $notes(array_merge($ratio($stage['refund_amount'],$stage['revenue'],$stage['refund_amount_rate'],4),[$auditText('refund_money',['a'=>$money($stage['refund_amount']),'b'=>$money($stage['revenue'])]),$actualPeriod,$riskTime])) }}</td>
            <td>{{ number_format($stage['dispute_count']) }} / {{ $money($stage['dispute_amount']) }}<small>{{ number_format($stage['dispute_rate'],2) }}%</small>{{ $notes(array_merge([$auditText('dispute',['count'=>$stage['dispute_count'],'amount'=>$money($stage['dispute_amount'])])],$ratio($stage['dispute_count'],$stage['orders'],$stage['dispute_rate']),[$riskTime])) }}</td>
        </tr>
        @empty<tr><td colspan="10" class="payment-product-empty">{{ __($t.'.funnel.empty') }}</td></tr>@endforelse
        </tbody></table></div>
        @php
            $p = $dashboard['stage_pagination'];
            $stageQuery = array_diff_key($filters, ['stage_page'=>true, 'risk_date_mode'=>true]);
            $stageUrl = static fn (int $number) => route('payment.renewal-analysis.index', array_merge($stageQuery, ['stage_page'=>$number]));
        @endphp
        <div class="payment-unified-pagination">
            <span>{{ __($t.'.funnel.pagination_summary',['from'=>$p['from'],'to'=>$p['to'],'total'=>$p['total']]) }}</span>
            <form method="get" action="{{ route('payment.renewal-analysis.index') }}" class="payment-pagination-form">
                @foreach($stageQuery as $name=>$value)<input type="hidden" name="{{ $name }}" value="{{ $value }}">@endforeach
                <span>{{ __($t.'.funnel.per_page') }}</span>
                <a class="btn btn-default btn-sm {{ $p['current_page']<=1 ? 'disabled' : '' }}" href="{{ $stageUrl(max(1,$p['current_page']-1)) }}">{{ __('payment.common.pagination.previous') }}</a>
                <strong>{{ __('payment.common.pagination.page_status',['current'=>$p['current_page'],'last'=>$p['last_page']]) }}</strong>
                <a class="btn btn-default btn-sm {{ $p['current_page']>=$p['last_page'] ? 'disabled' : '' }}" href="{{ $stageUrl(min($p['last_page'],$p['current_page']+1)) }}">{{ __('payment.common.pagination.next') }}</a>
                <label><span>{{ __('payment.common.pagination.jump_to') }}</span><input class="form-control input-sm payment-page-jump-input" type="number" name="stage_page" min="1" max="{{ $p['last_page'] }}" placeholder="{{ __('payment.common.pagination.page_placeholder') }}" required></label>
                <button class="btn btn-primary btn-sm" type="submit">{{ __('payment.common.pagination.jump') }}</button>
            </form>
        </div>
        <p class="payment-renewal-metric-formula">{{ __($t.'.formulas.refund_rates') }}</p>
    </article>

    <div class="payment-renewal-two-column">
        <article class="payment-panel"><h2>{{ __($t.'.risk.title') }}</h2><div class="table-responsive"><table class="table payment-data-table"><thead><tr>@foreach(['channel','orders','revenue','refunds','refund_count_rate','refund_amount_rate','disputes'] as $column)<th>{{ __($t.'.risk.'.$column) }}</th>@endforeach</tr></thead><tbody>
        @foreach($dashboard['risk_by_channel'] as $channel=>$risk)
        <tr>
            <td><strong>{{ __($t.'.channels.'.$channel) }}</strong>{{ $notes([$auditText('channel_rule'),$stageScope,$firstPeriod]) }}</td>
            <td>{{ number_format($risk['orders']) }}{{ $notes([$auditText('channel_orders',['count'=>$risk['orders']]),$firstPeriod,$stageScope]) }}</td>
            <td>{{ $money($risk['revenue']) }}{{ $notes([$auditText('revenue',['amount'=>$money($risk['revenue'])]),$auditText('channel_orders',['count'=>$risk['orders']]),$firstPeriod,$stageScope]) }}</td>
            <td>{{ number_format($risk['refund_count']) }} / {{ $money($risk['refund_amount']) }}{{ $notes([$auditText('refund',['records'=>$risk['refund_count'],'orders'=>$risk['refund_order_count'],'amount'=>$money($risk['refund_amount'])]),$auditText('refund_rule'),$riskTime]) }}</td>
            <td>{{ number_format($risk['refund_count_rate'],2) }}%{{ $notes(array_merge($ratio($risk['refund_order_count'],$risk['orders'],$risk['refund_count_rate']),[$auditText('refund_count',['a'=>$risk['refund_order_count'],'b'=>$risk['orders']]),$stageScope,$riskTime])) }}</td>
            <td>{{ number_format($risk['refund_amount_rate'],2) }}%{{ $notes(array_merge($ratio($risk['refund_amount'],$risk['revenue'],$risk['refund_amount_rate'],4),[$auditText('refund_money',['a'=>$money($risk['refund_amount']),'b'=>$money($risk['revenue'])]),$stageScope,$riskTime])) }}</td>
            <td>{{ number_format($risk['dispute_count']) }} / {{ $money($risk['dispute_amount']) }}{{ $notes([$auditText('dispute',['count'=>$risk['dispute_count'],'amount'=>$money($risk['dispute_amount'])]),$stageScope,$riskTime]) }}</td>
        </tr>
        @endforeach
        </tbody></table></div></article>
        @php
            $u = $dashboard['upsell'];
            $upsellScope = $auditText('upsell_scope',['count'=>$u['scope_orders']]);
        @endphp
        <article class="payment-panel payment-renewal-upsell"><h2>{{ __($t.'.upsell.title') }}</h2>
            <div>
                <span>{{ __($t.'.upsell.linked_orders') }}<strong>{{ number_format($u['linked_orders']) }}</strong>{{ $notes([$auditText('linked',['tagged'=>$u['measured_orders'],'referenced'=>$u['reference_orders'],'result'=>$u['linked_orders']]),$upsellScope,$firstPeriod]) }}</span>
                <span>{{ __($t.'.upsell.measured') }}<strong>{{ number_format($u['measured_orders']) }} / {{ $money($u['revenue']) }}</strong>{{ $notes([$auditText('measured',['count'=>$u['measured_orders'],'amount'=>$money($u['revenue'])]),$upsellScope,$firstPeriod]) }}</span>
                <span>{{ __($t.'.upsell.refund') }}<strong>{{ number_format($u['refund_count']) }} / {{ $money($u['refund_amount']) }}</strong>{{ $notes([$auditText('upsell_refund',['count'=>$u['measured_orders'],'records'=>$u['refund_count'],'amount'=>$money($u['refund_amount'])]),$upsellScope,$riskTime]) }}</span>
                <span>{{ __($t.'.upsell.dispute') }}<strong>{{ number_format($u['dispute_count']) }} / {{ $money($u['dispute_amount']) }}</strong>{{ $notes([$auditText('upsell_dispute',['count'=>$u['measured_orders'],'records'=>$u['dispute_count'],'amount'=>$money($u['dispute_amount'])]),$upsellScope,$riskTime]) }}</span>
                <span>{{ __($t.'.upsell.references') }}<strong>{{ number_format($u['references']) }}</strong>{{ $notes([$auditText('references',['count'=>$u['references']]),$upsellScope,$firstPeriod]) }}</span>
                <span>{{ __($t.'.upsell.unmeasured') }}<strong>{{ number_format($u['unmeasured']) }}</strong>{{ $notes([$auditText('unmeasured',['count'=>$u['unmeasured']]),$upsellScope,$firstPeriod]) }}</span>
            </div>
            <p>{{ $auditText('upsell_limitation') }}</p>
        </article>
    </div>

</section>
@endsection

@push('scripts')
<script src="{{ $portalOrigin }}/assets/erp/payment-portal.js?v=20261117-1"></script>
@endpush
