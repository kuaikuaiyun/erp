@extends('portal.layout')

@php
    $t = 'payment.renewal_analysis';
    $portalOrigin = rtrim(request()->getSchemeAndHttpHost(), '/');
    $filters = $dashboard['filters'];
    $summary = $dashboard['summary'];
    $calc = $dashboard['calculation'];
    $text = static fn (string $key, array $values = []) => __('payment.renewal_two_sections.'.$key, $values);
    $audit = static fn (string $key, array $values = []) => __('payment.renewal_audit.'.$key, $values);
    $money = static fn ($value) => '$'.number_format((float) $value, 2);
    $notes = static fn (array $lines) => new \Illuminate\Support\HtmlString(implode('', array_map(
        static fn ($line) => '<p class="payment-calculation-note">'.e($line).'</p>', $lines)));
    $ratio = static function ($a, $b, $result, int $decimals = 0) use ($audit): array {
        $lines = [$audit('ratio',['a'=>number_format($a,$decimals),'b'=>number_format($b,$decimals),'result'=>number_format($result,2)])];
        if ($b <= 0) $lines[] = $audit('zero');
        return $lines;
    };
    $formulaNumber = static fn ($value, bool $amount = false) => $amount
        ? rtrim(rtrim(number_format((float) $value, 4), '0'), '.') : number_format((float) $value);
    $inlineRatio = static fn ($a, $b, bool $amount = false) => $b > 0
        ? $text('inline_formula', ['numerator'=>$formulaNumber($a,$amount),'denominator'=>$formulaNumber($b,$amount)])
        : $text('inline_no_sample');
    $inlinePeriod = static fn ($name, $from, $to) => $text('inline_period', [
        'stage'=>$name,'from'=>substr($from,0,10),'to'=>substr($to,0,10)]);
    $stageName = static fn (int $number) => __($t.'.funnel.stage_name',['number'=>$number]);
    $stageNumber = static fn (string $key) => (int) str_replace('renewal_', '', $key);
    $channels = ['total'=>$text('total'),'paypal'=>__($t.'.channels.paypal'),'credit_card'=>__($t.'.channels.credit_card')];
    $period = $audit('first_period',['from'=>$calc['period_from'],'to'=>$calc['period_to']]);
    $comparison = $audit('cohort_period',['from'=>$calc['comparison_from'],'to'=>$calc['comparison_to']]);
    $utcPeriod = $audit('utc_period',['from'=>$calc['utc_period_from'],'to'=>$calc['utc_period_to']]);
    $riskTime = $audit('risk_time');
    $exportQuery = array_diff_key($filters,['stage_page'=>true,'risk_date_mode'=>true]);
@endphp

@section('page_title', __($t.'.page_title'))
@push('styles')
<link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/payment-portal.css?v=20261104-2">
<link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/payment-shared.css?v=20261117-6">
@endpush

@section('content')
<section class="content payment-portal-page payment-renewal-page payment-renewal-two-sections">
    <header class="payment-page-heading"><div><h1>{{ __($t.'.page_title') }}</h1><p>{{ $text('subtitle') }}</p></div></header>
    <form class="payment-renewal-filter" method="get" action="{{ route('payment.renewal-analysis.index') }}">
        <label class="payment-renewal-cohort"><span>{{ __($t.'.filters.cohort_period') }}</span><span class="payment-operation-date-range"><input class="form-control" type="date" name="date_from" value="{{ $filters['date_from'] }}"><b>{{ __('payment.common.range_to') }}</b><input class="form-control" type="date" name="date_to" value="{{ $filters['date_to'] }}"></span></label>
        <label class="payment-renewal-timezone"><span>{{ __($t.'.filters.timezone') }}</span><select class="form-control" name="timezone">@foreach(\App\Portals\Payment\PaymentRenewalAnalysisService::timezones() as $offset=>$label)<option value="{{ $offset }}" @selected($filters['timezone']===$offset)>{{ $label }}</option>@endforeach</select></label>
        <label><span>{{ __($t.'.filters.channel') }}</span><select class="form-control" name="channel"><option value="">{{ __($t.'.filters.all_channels') }}</option>@foreach(['paypal','credit_card'] as $channel)<option value="{{ $channel }}" @selected($filters['channel']===$channel)>{{ __($t.'.channels.'.$channel) }}</option>@endforeach</select></label>
        <label><span>{{ $text('renewal_stage') }}</span><select class="form-control" name="stage"><option value="">{{ $text('all_renewals') }}</option>@foreach($dashboard['stage_options'] as $key)<option value="{{ $key }}" @selected($filters['stage']===$key)>{{ $stageName($stageNumber($key)) }}</option>@endforeach</select></label>
        <label><span>{{ __($t.'.filters.store') }}</span><select class="form-control" name="store"><option value="">{{ __($t.'.filters.all_stores') }}</option>@foreach($dashboard['stores'] as $store)<option value="{{ $store }}" @selected($filters['store']===$store)>{{ $store }}</option>@endforeach</select></label>
        <div class="payment-renewal-filter-actions"><button class="btn btn-primary" type="submit"><i class="fa fa-search"></i> {{ __('payment.common.query') }}</button><a class="btn btn-default" href="{{ route('payment.renewal-analysis.index') }}">{{ __('payment.common.reset') }}</a><a class="btn btn-success" href="{{ route('payment.renewal-analysis.export', $exportQuery) }}"><i class="fa fa-file-excel-o"></i> {{ __($t.'.export.button') }}</a></div>
    </form>
    <p class="payment-renewal-compact-context">{{ $text('context',['from'=>$filters['date_from'],'to'=>$filters['date_to'],'timezone'=>$calc['timezone'],'generated'=>$calc['generated_at']]) }}</p>

    <article class="payment-panel payment-subscription-block" data-payment-analysis-section="subscription">
        <h2>{{ __($t.'.sections.subscription_rate') }}</h2>
        <div class="payment-kpi-grid payment-kpi-grid-three payment-renewal-kpis">
            @foreach($channels as $channel=>$label)
            @php
                $rate = $summary['subscription_rate'][$channel];
            @endphp
            <div class="payment-kpi-card payment-renewal-kpi"><div><p>{{ $label }}</p><strong>{{ number_format($rate['rate'],2) }}%</strong><small>{{ $text('subscription_orders') }} {{ number_format($rate['subscription_orders']) }} / {{ $text('qualified_orders') }} {{ number_format($rate['total_orders']) }}</small><small class="payment-inline-formula">{{ $inlineRatio($rate['subscription_orders'],$rate['total_orders']) }}</small></div></div>
            @endforeach
        </div>
        <p class="payment-renewal-scope-note">{{ $text('first_scope') }}</p>
        <div class="table-responsive"><table class="table payment-data-table payment-subscription-refunds"><caption>{{ $text('first_refunds') }}</caption><thead><tr>
            <th>{{ $text('channel') }}</th><th>{{ $text('refund_base') }}</th><th>{{ $text('revenue') }}</th><th>{{ $text('refund_orders') }}</th><th>{{ $text('refund_amount') }}</th><th>{{ $text('refund_count_rate') }}</th><th>{{ $text('refund_amount_rate') }}</th>
        </tr></thead><tbody>
        @foreach($channels as $channel=>$label)
            @php
                $refund = $dashboard['initial_refunds'][$channel];
            @endphp
            <tr><td>{{ $label }}</td><td>{{ number_format($refund['orders']) }}</td><td>{{ $money($refund['revenue']) }}</td><td>{{ number_format($refund['refund_order_count']) }}</td><td>{{ $money($refund['refund_amount']) }}</td><td>{{ number_format($refund['refund_count_rate'],2) }}%<small class="payment-inline-formula">{{ $inlineRatio($refund['refund_order_count'],$refund['orders']) }}</small></td><td>{{ number_format($refund['refund_amount_rate'],2) }}%<small class="payment-inline-formula">{{ $inlineRatio($refund['refund_amount'],$refund['revenue'],true) }}</small></td></tr>
        @endforeach
        </tbody></table></div>
        <details class="payment-calculation-details"><summary>{{ $text('view_calculation') }}</summary>
            {{ $notes([$period,$utcPeriod,$audit('subscription_scope'),$audit('fixed_scope'),$audit('source_scope'),$audit('channel_rule')]) }}
            @foreach($channels as $channel=>$label)
                @php
                    $rate = $summary['subscription_rate'][$channel];
                    $refund = $dashboard['initial_refunds'][$channel];
                @endphp
                <div class="payment-calculation-group"><strong>{{ $label }}</strong>
                    {{ $notes(array_merge([$audit('subscription',['a'=>$rate['subscription_orders'],'b'=>$rate['total_orders']])],$ratio($rate['subscription_orders'],$rate['total_orders'],$rate['rate']),[
                        $text('first_refund_scope',['orders'=>$refund['orders'],'revenue'=>$money($refund['revenue']),'records'=>$refund['refund_count'],'refunded'=>$refund['refund_order_count'],'amount'=>$money($refund['refund_amount'])])],
                        $ratio($refund['refund_order_count'],$refund['orders'],$refund['refund_count_rate']),$ratio($refund['refund_amount'],$refund['revenue'],$refund['refund_amount_rate'],4))) }}
                </div>
            @endforeach
            {{ $notes([$audit('refund_rule'),$riskTime,$audit('rounding')]) }}
        </details>
    </article>

    <article class="payment-panel payment-renewal-funnel" data-payment-analysis-section="renewals">
        <h2>{{ $text('renewal_title') }}</h2>
        <p class="payment-renewal-scope-note">{{ $text('stage_note') }}</p>
        <div class="table-responsive"><table class="table payment-data-table"><thead><tr>
            <th>{{ $text('renewal_stage') }}</th><th>{{ $text('previous_orders') }}</th><th>{{ $text('current_renewal_orders') }}</th><th>{{ __($t.'.funnel.renewal_rate') }}</th>
            <th>{{ $text('revenue') }}</th><th>{{ $text('refund_orders') }}</th><th>{{ $text('refund_amount') }}</th><th>{{ $text('refund_count_rate') }}</th><th>{{ $text('refund_amount_rate') }}</th>
        </tr></thead><tbody>
        @forelse($dashboard['stages'] as $stage)
            <tr data-payment-renewal-number="{{ $stage['number'] }}"><td><strong>{{ $stageName($stage['number']) }}</strong></td><td>{{ number_format($stage['previous_orders']) }}<small class="payment-inline-formula">{{ $inlinePeriod($stage['number'] === 1 ? __($t.'.funnel.initial_stage') : $stageName($stage['number']-1),$calc['comparison_from'],$calc['comparison_to']) }}</small></td><td>{{ number_format($stage['orders']) }}<small class="payment-inline-formula">{{ $inlinePeriod($stageName($stage['number']),$calc['period_from'],$calc['period_to']) }}</small></td><td><strong>{{ $stage['rate'] === null ? __($t.'.funnel.not_applicable') : number_format($stage['rate'],2).'%' }}</strong><small class="payment-inline-formula">{{ $stage['rate'] === null ? $text('inline_no_base') : $inlineRatio($stage['orders'],$stage['previous_orders']) }}</small></td><td>{{ $money($stage['revenue']) }}</td><td>{{ number_format($stage['refund_order_count']) }}</td><td>{{ $money($stage['refund_amount']) }}</td><td>{{ number_format($stage['refund_count_rate'],2) }}%<small class="payment-inline-formula">{{ $inlineRatio($stage['refund_order_count'],$stage['orders']) }}</small></td><td>{{ number_format($stage['refund_amount_rate'],2) }}%<small class="payment-inline-formula">{{ $inlineRatio($stage['refund_amount'],$stage['revenue'],true) }}</small></td></tr>
            <tr class="payment-calculation-row"><td colspan="9">
                <details class="payment-calculation-details"><summary>{{ $text('stage_calculation',['number'=>$stage['number']]) }}</summary>
                    {{ $notes([$audit('base',['number'=>$stage['number'],'previous'=>$stage['number'] === 1 ? __($t.'.funnel.initial_stage') : $stageName($stage['number']-1),'count'=>$stage['previous_orders']]),
                        $audit('completed',['number'=>$stage['number'],'count'=>$stage['orders']]),$period,$comparison]) }}
                    @if($stage['rate'] === null)
                        {{ $notes([$text('no_base')]) }}
                    @else
                        {{ $notes($ratio($stage['orders'],$stage['previous_orders'],$stage['rate'])) }}
                    @endif
                    {{ $notes([$audit('revenue',['amount'=>$money($stage['revenue'])]),
                        $audit('refund',['records'=>$stage['refund_count'],'orders'=>$stage['refund_order_count'],'amount'=>$money($stage['refund_amount'])])]) }}
                    {{ $notes($ratio($stage['refund_order_count'],$stage['orders'],$stage['refund_count_rate'])) }}
                    {{ $notes($ratio($stage['refund_amount'],$stage['revenue'],$stage['refund_amount_rate'],4)) }}
                    {{ $notes([$stage['paid_from'] === null ? $audit('no_payments') : $audit('actual_period',['from'=>$stage['paid_from'],'to'=>$stage['paid_to']]),$audit('stage_number',['number'=>$stage['number']])]) }}
                </details>
            </td></tr>
        @empty<tr><td colspan="9" class="payment-product-empty">{{ __($t.'.funnel.empty') }}</td></tr>@endforelse
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

        <details class="payment-calculation-details"><summary>{{ $text('view_calculation') }}</summary>
            {{ $notes([$period,$comparison,$utcPeriod,$audit('utc_comparison',['from'=>$calc['utc_comparison_from'],'to'=>$calc['utc_comparison_to']]),
                $audit('time',['timezone'=>$calc['timezone'],'generated'=>$calc['generated_at'],'expires'=>$calc['expires_at']]),
                $audit('source_scope'),$audit('channel_rule'),$audit('refund_rule'),$riskTime,$audit('rounding')]) }}
        </details>
    </article>
</section>
@endsection

@push('scripts')
<script src="{{ $portalOrigin }}/assets/erp/payment-portal.js?v=20261117-1"></script>
@endpush
