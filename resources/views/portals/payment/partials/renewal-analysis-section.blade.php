@php
    $t = 'payment.renewal_analysis';
    $filters = $dashboard['filters'];
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
    $allGroups = \App\Portals\Payment\PaymentRenewalAnalysisService::breakdownGroups();
    $groups = array_replace(array_flip(['total','method_pp','method_cc','method_gp','method_ap']), array_intersect_key($allGroups, array_flip(['total','method_pp','method_cc','method_gp','method_ap'])));
    $appliedFilters = json_encode(array_filter($filters, static fn ($name) => str_starts_with($name, $module.'_')
        || ($module === 'renewal' && in_array($name, ['stage','stage_page'], true)), ARRAY_FILTER_USE_KEY), JSON_UNESCAPED_UNICODE);
    $methodLabel = static fn (array $group) => $group['method'] === 'all' ? $text('total') : ($group['method'] === 'unknown' ? $text('unknown') : $text('method_names.'.$group['method']));
    $riskTime = $audit('risk_time');
    $exportQuery = array_diff_key($filters,['stage_page'=>true,'risk_date_mode'=>true]);
@endphp

@if ($module === 'initial')
    @php
        $firstCalc = $dashboard['initial_calculation'];
        $summary = $dashboard['summary'];
    @endphp
    <article class="payment-panel payment-subscription-block" data-payment-analysis-section="subscription" data-renewal-section="initial" data-renewal-applied="{{ $appliedFilters }}">
        <h2><span class="payment-section-number">01</span>{{ __($t.'.sections.subscription_rate') }}</h2>
        <p class="payment-section-hint">{{ $text('subscription_hint') }}</p>
        @php $moduleReset = array_diff_key($filters, array_flip(['initial_date_from','initial_date_to','initial_timezone'])); @endphp
        <form method="get" action="{{ route('payment.renewal-analysis.index') }}" class="payment-renewal-filter" data-renewal-module="initial">
            @foreach($filters as $name=>$value)
                @if(str_starts_with($name, 'renewal_') || in_array($name,['stage','stage_page'],true))<input type="hidden" name="{{ $name }}" value="{{ $value }}">@endif
            @endforeach
            <label class="payment-renewal-cohort"><span>{{ __($t.'.filters.cohort_period') }}</span><span class="payment-renewal-date-range"><input class="form-control" type="date" name="initial_date_from" value="{{ $filters['initial_date_from'] }}" required><b>{{ __('payment.common.range_to') }}</b><input class="form-control" type="date" name="initial_date_to" value="{{ $filters['initial_date_to'] }}" required></span></label>
            <label><span>{{ __($t.'.filters.timezone') }}</span><select class="form-control" name="initial_timezone">@foreach(\App\Portals\Payment\PaymentRenewalAnalysisService::timezones() as $offset=>$label)<option value="{{ $offset }}" @selected($filters['initial_timezone']===$offset)>{{ $label }}</option>@endforeach</select></label>
            <div class="payment-renewal-filter-actions"><button class="btn btn-primary" type="submit">{{ __('payment.common.query') }}</button><a class="btn btn-default" data-renewal-navigation href="{{ route('payment.renewal-analysis.index',$moduleReset) }}">{{ __('payment.common.reset') }}</a><button class="btn btn-success" type="button" data-renewal-export-url="{{ route('payment.renewal-analysis.export', $exportQuery) }}">{{ __($t.'.export.button') }}</button></div>
            @foreach(['initial_date_from','initial_date_to','initial_timezone'] as $field)@error($field)<p class="text-danger">{{ $message }}</p>@enderror
            @endforeach
        </form>
        <p class="payment-renewal-compact-context">{{ $text('context',['from'=>$filters['initial_date_from'],'to'=>$filters['initial_date_to'],'timezone'=>$firstCalc['timezone'],'generated'=>$firstCalc['generated_at']]) }}</p>

        <div class="payment-kpi-grid payment-kpi-grid-three payment-renewal-kpis">
            @foreach(['total'=>$text('total')] as $channel=>$label)
            @php
                $rate = $summary['subscription_rate'][$channel];
            @endphp
            <div class="payment-kpi-card payment-renewal-kpi"><div><p>{{ $label }}</p><strong>{{ number_format($rate['rate'],2) }}%</strong><small>{{ $text('subscription_orders') }} {{ number_format($rate['subscription_orders']) }} / {{ $text('qualified_orders') }} {{ number_format($rate['total_orders']) }}</small><small class="payment-inline-formula">{{ $inlineRatio($rate['subscription_orders'],$rate['total_orders']) }}</small></div></div>
            @endforeach
        </div>
        <div class="payment-method-rates">
            <h3>{{ $text('method_subscription') }}</h3>
            <div class="payment-method-rate-grid">
            @foreach($groups as $key=>$group)
                @if($group['level'] === 'method')
                    @php $rate = $summary['subscription_rate'][$key]; @endphp
                    <div class="payment-method-rate" data-payment-method="{{ $group['method'] }}">
                        <span><b>{{ $methodLabel($group) }}</b></span>
                        <strong>{{ number_format($rate['rate'],2) }}%</strong>
                        <small>{{ $text('subscription_orders') }} {{ number_format($rate['subscription_orders']) }} / {{ $text('qualified_orders') }} {{ number_format($rate['total_orders']) }}</small>
                        <small class="payment-inline-formula">{{ $inlineRatio($rate['subscription_orders'],$rate['total_orders']) }}</small>
                    </div>
                @endif
            @endforeach
            </div>
        </div>
        <p class="payment-renewal-scope-note">{{ $text('method_rule') }}</p>
        <p class="payment-renewal-scope-note">{{ $text('first_scope') }}</p>
        <p class="payment-renewal-scope-note">{{ $text('delivery_note') }}</p>
        <div class="table-responsive"><table class="table payment-data-table payment-subscription-refunds"><caption>{{ $text('first_refunds') }}</caption><thead><tr>
            <th>{{ $text('payment_method') }}</th><th>{{ $text('purchase_type') }}</th><th>{{ $text('refund_base') }}<small class="payment-inline-formula">{{ $text('refund_base_hint') }}</small></th><th>{{ $text('revenue') }}</th><th>{{ $text('refund_orders') }}</th><th>{{ $text('refund_amount') }}</th><th>{{ $text('refund_count_rate') }}</th><th>{{ $text('refund_amount_rate') }}</th><th>{{ $text('delivery_distribution') }}</th><th>{{ $text('delivered_refund_count_rate') }}</th><th>{{ $text('delivered_refund_amount_rate') }}</th><th>{{ __('payment.renewal_details.actions') }}</th>
        </tr></thead><tbody>
        @foreach($groups as $channel=>$group)
            @foreach(['all','subscription','non_subscription'] as $type)
                @php
                    $refund = $dashboard['initial_refunds_by_type'][$channel][$type];
                    $delivery = $dashboard['initial_delivery_by_type'][$channel][$type];
                    $signed = $delivery['delivered'];
                @endphp
                <tr class="payment-refund-row payment-level-{{ $group['level'] }} {{ $type === 'all' ? 'payment-group-start' : 'payment-type-detail' }}"><td><span class="payment-method-badge">{{ $methodLabel($group) }}</span></td><td>{{ $text('purchase_'.$type) }}</td><td>{{ number_format($refund['orders']) }}</td><td>{{ $money($refund['revenue']) }}</td><td>{{ number_format($refund['refund_order_count']) }}</td><td>{{ $money($refund['refund_amount']) }}</td><td>{{ number_format($refund['refund_count_rate'],2) }}%<small class="payment-inline-formula">{{ $inlineRatio($refund['refund_order_count'],$refund['orders']) }}</small></td><td>{{ number_format($refund['refund_amount_rate'],2) }}%<small class="payment-inline-formula">{{ $inlineRatio($refund['refund_amount'],$refund['revenue'],true) }}</small></td><td class="payment-delivery-distribution">
                    @foreach(['delivered','undelivered'] as $state)
                        <div class="payment-delivery-{{ $state }}"><span>{{ $text('delivery_'.$state) }}</span> <b>{{ number_format($delivery[$state]['orders']) }}</b> / {{ number_format($delivery[$state]['order_share'],2) }}%
                            <small class="payment-inline-formula">{{ $inlineRatio($delivery[$state]['orders'],$refund['orders']) }}</small>
                        </div>
                    @endforeach
                </td>
                <td><strong>{{ number_format($signed['refund_count_rate'],2) }}%</strong><small class="payment-inline-formula">{{ $inlineRatio($signed['refund_order_count'],$signed['orders']) }}</small></td>
                <td><strong>{{ number_format($signed['refund_amount_rate'],2) }}%</strong><small class="payment-inline-formula">{{ $inlineRatio($signed['refund_amount'],$signed['revenue'],true) }}</small></td>
                <td class="payment-renewal-detail-actions">
                    @foreach(['orders','refunds'] as $kind)
                        <button type="button" class="btn btn-default btn-sm" data-renewal-detail-open data-group="{{ $channel }}" data-purchase="{{ $type }}" data-kind="{{ $kind }}" data-title="{{ $methodLabel($group).' / '.$text('purchase_'.$type).' / '.__('payment.renewal_details.'.$kind) }}">{{ __('payment.renewal_details.'.$kind) }}</button>
                    @endforeach
                </td></tr>
            @endforeach
        @endforeach
        </tbody></table></div>
        <details class="payment-calculation-details"><summary>{{ $text('view_calculation') }}</summary>
            {{ $notes([$audit('first_period',['from'=>$firstCalc['period_from'],'to'=>$firstCalc['period_to']]),$audit('utc_period',['from'=>$firstCalc['utc_period_from'],'to'=>$firstCalc['utc_period_to']]),$audit('subscription_scope'),$audit('fixed_scope'),__('payment-renewal.first_source_scope'),$text('channel_rule')]) }}
            @foreach($groups as $channel=>$group)
                @php $label = $methodLabel($group); @endphp
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
@else
    @php
        $calc = $dashboard['calculation'];
        $period = $audit('first_period',['from'=>$calc['period_from'],'to'=>$calc['period_to']]);
        $comparison = $audit('cohort_period',['from'=>$calc['comparison_from'],'to'=>$calc['comparison_to']]);
        $utcPeriod = $audit('utc_period',['from'=>$calc['utc_period_from'],'to'=>$calc['utc_period_to']]);
    @endphp
    <article class="payment-panel payment-renewal-funnel" data-payment-analysis-section="renewals" data-renewal-section="renewal" data-renewal-applied="{{ $appliedFilters }}">
        <h2><span class="payment-section-number">02</span>{{ $text('renewal_title') }}</h2>
        <p class="payment-section-hint">{{ $text('renewal_hint') }}</p>
        @php $moduleReset = array_diff_key($filters, array_flip(['renewal_date_from','renewal_date_to','renewal_timezone','stage','stage_page'])); @endphp
        <form method="get" action="{{ route('payment.renewal-analysis.index') }}#renewal-analysis-renewal" class="payment-renewal-filter" data-renewal-module="renewal">
            <input type="hidden" name="load_renewal" value="1">
            @foreach($filters as $name=>$value)
                @if(str_starts_with($name, 'initial_'))<input type="hidden" name="{{ $name }}" value="{{ $value }}">@endif
            @endforeach
            <label class="payment-renewal-cohort"><span>{{ __($t.'.filters.cohort_period') }}</span><span class="payment-renewal-date-range"><input class="form-control" type="date" name="renewal_date_from" value="{{ $filters['renewal_date_from'] }}" required><b>{{ __('payment.common.range_to') }}</b><input class="form-control" type="date" name="renewal_date_to" value="{{ $filters['renewal_date_to'] }}" required></span></label>
            <label><span>{{ __($t.'.filters.timezone') }}</span><select class="form-control" name="renewal_timezone">@foreach(\App\Portals\Payment\PaymentRenewalAnalysisService::timezones() as $offset=>$label)<option value="{{ $offset }}" @selected($filters['renewal_timezone']===$offset)>{{ $label }}</option>@endforeach</select></label>
            <label><span>{{ $text('renewal_stage') }}</span><select class="form-control" name="stage"><option value="">{{ $text('all_renewals') }}</option>@foreach($dashboard['stage_options'] as $key)<option value="{{ $key }}" @selected($filters['stage']===$key)>{{ $stageName($stageNumber($key)) }}</option>@endforeach</select></label>
            <div class="payment-renewal-filter-actions"><button class="btn btn-primary" type="submit">{{ __('payment.common.query') }}</button><a class="btn btn-default" data-renewal-navigation href="{{ route('payment.renewal-analysis.index', [...$moduleReset, 'load_renewal'=>1]) }}#renewal-analysis-renewal">{{ __('payment.common.reset') }}</a><button class="btn btn-success" type="button" data-renewal-export-url="{{ route('payment.renewal-analysis.export', $exportQuery) }}">{{ __($t.'.export.button') }}</button></div>
            @foreach(['renewal_date_from','renewal_date_to','renewal_timezone'] as $field)@error($field)<p class="text-danger">{{ $message }}</p>@enderror
            @endforeach
        </form>
        <p class="payment-renewal-compact-context">{{ $text('context',['from'=>$filters['renewal_date_from'],'to'=>$filters['renewal_date_to'],'timezone'=>$calc['timezone'],'generated'=>$calc['generated_at']]) }}</p>

        <p class="payment-renewal-scope-note">{{ $text('stage_note') }}</p>
        <div class="payment-renewal-stage-list">
        @forelse($dashboard['stages'] as $stage)
            <section class="payment-renewal-stage-card" aria-labelledby="renewal-stage-{{ $stage['number'] }}">
                <header class="payment-renewal-stage-heading">
                    <h3 id="renewal-stage-{{ $stage['number'] }}">{{ $stageName($stage['number']) }}</h3>
                    <div class="payment-renewal-stage-periods">
                        <span>{{ $text('stage_current_period',['from'=>substr($calc['period_from'],0,10),'to'=>substr($calc['period_to'],0,10)]) }}</span>
                        <span>{{ $text('stage_comparison_period',['from'=>substr($calc['comparison_from'],0,10),'to'=>substr($calc['comparison_to'],0,10),'previous'=>$stage['number'] === 1 ? __($t.'.funnel.initial_stage') : $stageName($stage['number']-1)]) }}</span>
                    </div>
                </header>
                <div class="table-responsive"><table class="table payment-data-table payment-renewal-stage-table">
                    <thead><tr><th>{{ $text('payment_method') }}</th><th>{{ $text('previous_orders') }}</th><th>{{ $text('current_renewal_orders') }}</th><th>{{ __($t.'.funnel.renewal_rate') }}</th><th>{{ $text('revenue') }}</th><th>{{ $text('refund_orders') }}</th><th>{{ $text('refund_amount') }}</th><th>{{ $text('refund_count_rate') }}</th><th>{{ $text('refund_amount_rate') }}</th></tr></thead>
                    <tbody>
            @foreach($groups as $key=>$group)
                @php $part = $key === 'total' ? $stage : $stage['by_channel'][$key]; @endphp
                <tr class="payment-level-{{ $group['level'] }} {{ $key === 'total' ? 'payment-stage-start' : '' }}" @if($key === 'total') data-payment-renewal-number="{{ $stage['number'] }}" @endif data-payment-method="{{ $group['method'] }}">
                    <td><span class="payment-method-badge">{{ $methodLabel($group) }}</span></td>
                    <td>{{ number_format($part['previous_orders']) }}</td>
                    <td>{{ number_format($part['orders']) }}</td>
                    <td class="payment-rate-cell"><strong>{{ $part['rate'] === null ? __($t.'.funnel.not_applicable') : number_format($part['rate'],2).'%' }}</strong><small class="payment-inline-formula">{{ $part['rate'] === null ? $text('inline_no_base') : $inlineRatio($part['orders'],$part['previous_orders']) }}</small></td>
                    <td>{{ $money($part['revenue']) }}</td><td>{{ number_format($part['refund_order_count']) }}</td><td>{{ $money($part['refund_amount']) }}</td>
                    <td class="payment-refund-rate">{{ number_format($part['refund_count_rate'],2) }}%<small class="payment-inline-formula">{{ $inlineRatio($part['refund_order_count'],$part['orders']) }}</small></td>
                    <td class="payment-refund-rate">{{ number_format($part['refund_amount_rate'],2) }}%<small class="payment-inline-formula">{{ $inlineRatio($part['refund_amount'],$part['revenue'],true) }}</small></td>
                </tr>
            @endforeach
                    </tbody></table></div>
            <div class="payment-renewal-stage-calculation">
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
            </div>
            </section>
        @empty<div class="payment-product-empty">{{ __($t.'.funnel.empty') }}</div>@endforelse
        </div>
        @php
            $p = $dashboard['stage_pagination'];
            $stageQuery = array_diff_key($filters, ['stage_page'=>true, 'risk_date_mode'=>true]);
            $stageUrl = static fn (int $number) => route('payment.renewal-analysis.index', array_merge($stageQuery, ['stage_page'=>$number, 'load_renewal'=>1])).'#renewal-analysis-renewal';
        @endphp
        <div class="payment-unified-pagination">
            <span>{{ __($t.'.funnel.pagination_summary',['from'=>$p['from'],'to'=>$p['to'],'total'=>$p['total']]) }}</span>
            <form method="get" action="{{ route('payment.renewal-analysis.index') }}#renewal-analysis-renewal" class="payment-pagination-form" data-renewal-module="renewal">
                <input type="hidden" name="load_renewal" value="1">
                @foreach($stageQuery as $name=>$value)<input type="hidden" name="{{ $name }}" value="{{ $value }}">@endforeach
                <span>{{ __($t.'.funnel.per_page') }}</span>
                <a class="btn btn-default btn-sm {{ $p['current_page']<=1 ? 'disabled' : '' }}" data-renewal-navigation href="{{ $stageUrl(max(1,$p['current_page']-1)) }}">{{ __('payment.common.pagination.previous') }}</a>
                <strong>{{ __('payment.common.pagination.page_status',['current'=>$p['current_page'],'last'=>$p['last_page']]) }}</strong>
                <a class="btn btn-default btn-sm {{ $p['current_page']>=$p['last_page'] ? 'disabled' : '' }}" data-renewal-navigation href="{{ $stageUrl(min($p['last_page'],$p['current_page']+1)) }}">{{ __('payment.common.pagination.next') }}</a>
                <label><span>{{ __('payment.common.pagination.jump_to') }}</span><input class="form-control input-sm payment-page-jump-input" type="number" name="stage_page" min="1" max="{{ $p['last_page'] }}" placeholder="{{ __('payment.common.pagination.page_placeholder') }}" required></label>
                <button class="btn btn-primary btn-sm" type="submit">{{ __('payment.common.pagination.jump') }}</button>
            </form>
        </div>

        <details class="payment-calculation-details"><summary>{{ $text('view_calculation') }}</summary>
            {{ $notes([$period,$comparison,$utcPeriod,$audit('utc_comparison',['from'=>$calc['utc_comparison_from'],'to'=>$calc['utc_comparison_to']]),
                $audit('time',['timezone'=>$calc['timezone'],'generated'=>$calc['generated_at'],'expires'=>$calc['expires_at']]),
                $audit('source_scope'),$text('channel_rule'),$audit('refund_rule'),$riskTime,$audit('rounding')]) }}
        </details>
    </article>
@endif
