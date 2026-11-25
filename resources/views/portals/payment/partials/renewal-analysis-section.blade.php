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
        <h2><span class="payment-section-number">01</span>{{ __('payment-renewal.initial_data_title') }}</h2>
        <p class="payment-section-hint">{{ $text('subscription_hint') }}</p>
        @php $moduleReset = [...array_diff_key($filters, array_flip(['initial_date_from','initial_date_to','initial_timezone'])), 'load_initial'=>1]; @endphp
        <form method="get" action="{{ route('payment.renewal-analysis.index') }}" class="flt flt-card flt-filters payment-renewal-filter" data-renewal-module="initial"
            data-logistics-filters data-search-options="{{ __('frontend_list_template.search_options') }}" data-no-options="{{ __('frontend_list_template.no_options') }}">
            <input type="hidden" name="load_initial" value="1">
            @foreach($filters as $name=>$value)
                @if(str_starts_with($name, 'renewal_') || in_array($name,['stage','stage_page'],true))<input type="hidden" name="{{ $name }}" value="{{ $value }}">@endif
            @endforeach
            <div class="flt-filter-grid payment-renewal-fields">
                <div class="payment-renewal-cohort">
                    <label class="flt-field-label" for="renewal-initial-from">{{ __($t.'.filters.cohort_period') }}</label>
                    <div class="flt-time-range">
                        <input id="renewal-initial-from" type="date" name="initial_date_from" aria-label="{{ $text('start_date') }}" value="{{ $filters['initial_date_from'] }}" required>
                        <input type="date" name="initial_date_to" aria-label="{{ $text('end_date') }}" value="{{ $filters['initial_date_to'] }}" required>
                    </div>
                </div>
                <div>
                    <label class="flt-field-label" for="renewal-initial-timezone">{{ __($t.'.filters.timezone') }}</label>
                    <select id="renewal-initial-timezone" name="initial_timezone" data-filter-fixed-choice data-filter-searchable>@foreach(\App\Portals\Payment\PaymentRenewalAnalysisService::timezones() as $offset=>$label)<option value="{{ $offset }}" @selected($filters['initial_timezone']===$offset)>{{ $label }}</option>@endforeach</select>
                </div>
            </div>
            <div class="flt-filter-actions payment-renewal-filter-actions">
                @include('portals.payment.partials.renewal-month-shortcuts')
                <div class="payment-renewal-action-buttons">
                    <button class="flt-btn flt-primary" type="submit"><i class="fa fa-search" aria-hidden="true"></i>{{ __('payment.common.query') }}</button>
                    <a class="flt-btn" data-renewal-navigation href="{{ route('payment.renewal-analysis.index',$moduleReset) }}">{{ __('payment.common.reset') }}</a>
                    <button class="flt-btn" type="button" data-renewal-export-url="{{ route('payment.renewal-analysis.export', $exportQuery) }}"><i class="fa fa-download" aria-hidden="true"></i>{{ __($t.'.export.button') }}</button>
                </div>
            </div>
            @foreach(['initial_date_from','initial_date_to','initial_timezone'] as $field)@error($field)<p class="text-danger">{{ $message }}</p>@enderror
            @endforeach
        </form>
        @include('portals.payment.partials.renewal-section-status')
        <div class="payment-renewal-section-results">
        @include('portals.payment.partials.renewal-table-skeleton')
        <div class="payment-renewal-section-data">
        <p class="payment-renewal-compact-context">{{ $text('context',['from'=>$filters['initial_date_from'],'to'=>$filters['initial_date_to'],'timezone'=>$firstCalc['timezone'],'generated'=>$firstCalc['generated_at']]) }}</p>

        <div class="payment-method-rates">
            <h3>{{ $text('method_subscription') }}</h3>
            <div class="payment-method-rate-grid">
            @foreach($groups as $key=>$group)
                @php $rate = $summary['subscription_rate'][$key]; @endphp
                <div class="payment-method-rate" data-payment-method="{{ $group['method'] }}">
                    <span><b>{{ $methodLabel($group) }}</b></span>
                    <strong>{{ number_format($rate['rate'],2) }}%</strong>
                    <small>{{ $text('subscription_orders') }} {{ number_format($rate['subscription_orders']) }} / {{ $text('qualified_orders') }} {{ number_format($rate['total_orders']) }}</small>
                    <small class="payment-inline-formula">{{ $inlineRatio($rate['subscription_orders'],$rate['total_orders']) }}</small>
                </div>
            @endforeach
            </div>
        </div>
        <div class="payment-method-rates payment-order-values">
            <h3>{{ __('payment-renewal.order_values') }}</h3>
            <div class="payment-method-rate-grid">
                @foreach ($dashboard['initial_order_values'] as $type => $value)
                    <div class="payment-method-rate">
                        <span><b>{{ __('payment-renewal.order_value_'.$type) }}</b></span>
                        <strong>{{ $value['average'] === null ? '—' : $money($value['average']) }}</strong>
                        <small class="payment-inline-formula">{{ $value['orders'] === 0
                            ? __('payment-renewal.order_value_empty')
                            : __('payment-renewal.order_value_formula', ['amount'=>$money($value['revenue']), 'orders'=>number_format($value['orders'])]) }}</small>
                    </div>
                @endforeach
            </div>
        </div>
        <p class="payment-renewal-scope-note">{{ $text('method_rule') }}</p>
        <p class="payment-renewal-scope-note">{{ $text('first_scope') }}</p>
        <p class="payment-renewal-scope-note">{{ $text('delivery_note') }}</p>
        <div class="flt flt-card payment-subscription-refund-table">
        <h3 id="payment-subscription-refund-heading">{{ $text('first_refunds') }}</h3>
        <div class="flt-table-scroll" tabindex="0" role="region" aria-labelledby="payment-subscription-refund-heading">
        <table class="payment-subscription-refunds" aria-labelledby="payment-subscription-refund-heading"><thead><tr>
            <th>{{ $text('payment_method') }}</th><th>{{ $text('purchase_type') }}</th><th>{{ $text('refund_base') }}<small class="payment-inline-formula">{{ $text('refund_base_hint') }}</small></th><th>{{ $text('revenue') }}</th><th>{{ $text('refund_orders') }}</th><th>{{ $text('refund_amount') }}</th><th>{{ $text('refund_count_rate') }}</th><th>{{ $text('refund_amount_rate') }}</th><th>{{ $text('delivery_distribution') }}</th><th>{{ $text('delivered_refund_count_rate') }}</th><th>{{ $text('delivered_refund_amount_rate') }}</th><th>{{ __('payment.renewal_details.actions') }}</th>
        </tr></thead><tbody>
        @foreach($groups as $channel=>$group)
            @foreach(['all','subscription','non_subscription'] as $type)
                @php
                    $refund = $dashboard['initial_refunds_by_type'][$channel][$type];
                    $delivery = $dashboard['initial_delivery_by_type'][$channel][$type];
                    $signed = $delivery['delivered'];
                @endphp
                <tr class="payment-refund-row payment-level-{{ $group['level'] }} {{ $type === 'all' ? 'payment-group-start' : 'payment-type-detail' }}"><td><span class="payment-method-badge">{{ $methodLabel($group) }}</span></td><td>{{ $text('purchase_'.$type) }}</td><td>{{ number_format($refund['orders']) }}</td><td>{{ $money($refund['revenue']) }}</td><td>{{ number_format($refund['refund_order_count']) }}</td><td>{{ $money($refund['refund_amount']) }}</td><td><strong>{{ number_format($refund['refund_count_rate'],2) }}%</strong><small class="payment-inline-formula">{{ $inlineRatio($refund['refund_order_count'],$refund['orders']) }}</small></td><td><strong>{{ number_format($refund['refund_amount_rate'],2) }}%</strong><small class="payment-inline-formula">{{ $inlineRatio($refund['refund_amount'],$refund['revenue'],true) }}</small></td><td class="payment-delivery-distribution">
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
                        <button type="button" class="flt-btn payment-renewal-detail-button" data-renewal-detail-open data-group="{{ $channel }}" data-purchase="{{ $type }}" data-kind="{{ $kind }}" data-title="{{ $methodLabel($group).' / '.$text('purchase_'.$type).' / '.__('payment.renewal_details.'.$kind) }}">{{ __('payment.renewal_details.'.$kind) }}</button>
                    @endforeach
                </td></tr>
            @endforeach
        @endforeach
        </tbody></table>
        </div></div>
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
        </div>
        </div>
    </article>
@else
    @php
        $calc = $dashboard['calculation'];
        $mode = $filters['renewal_mode'] ?? 'normal';
        $linked = $mode === 'linked';
        $renewalText = static fn (string $key, array $values = []) => __('payment-renewal.'.$key, $values);
        $dueThrough = $linked ? $renewalText(
            substr($calc['generated_at'], 0, 10) === $calc['as_of'] ? 'linked_due_through' : 'linked_due_through_date',
            ['datetime'=>$calc['generated_at'], 'date'=>$calc['as_of']]
        ) : null;
        $period = $linked ? $renewalText('linked_period',['from'=>$calc['period_from'],'to'=>$calc['period_to']]) : $audit('first_period',['from'=>$calc['period_from'],'to'=>$calc['period_to']]);
        $comparison = $audit('cohort_period',['from'=>$calc['comparison_from'],'to'=>$calc['comparison_to']]);
        $utcPeriod = $audit('utc_period',['from'=>$calc['utc_period_from'],'to'=>$calc['utc_period_to']]);
        $stageOptions = $dashboard['stage_options'];
        if ($filters['stage'] !== '' && !in_array($filters['stage'], $stageOptions, true)) $stageOptions[] = $filters['stage'];
    @endphp
    <article class="payment-panel payment-renewal-funnel" data-payment-analysis-section="renewals" data-renewal-section="renewal" data-renewal-applied="{{ $appliedFilters }}">
        <h2><span class="payment-section-number">02</span>{{ $text('renewal_title') }}</h2>
        <p class="payment-section-hint">{{ $text('renewal_hint') }}</p>
        @php $moduleReset = array_diff_key($filters, array_flip(['renewal_date_from','renewal_date_to','renewal_timezone','stage','stage_page'])); @endphp
        <form method="get" action="{{ route('payment.renewal-analysis.index') }}#renewal-analysis-renewal" class="flt flt-card flt-filters payment-renewal-filter" data-renewal-module="renewal"
            data-logistics-filters data-search-options="{{ __('frontend_list_template.search_options') }}" data-no-options="{{ __('frontend_list_template.no_options') }}">
            <input type="hidden" name="load_renewal" value="1">
            <fieldset class="payment-renewal-mode-picker">
                <legend class="sr-only">{{ $renewalText('mode_label') }}</legend>
                @foreach (['normal', 'linked'] as $option)
                    <div class="payment-renewal-mode-option">
                        <label>
                            <input class="sr-only" type="radio" name="renewal_mode" value="{{ $option }}" @checked($mode === $option)>
                            <span>{{ $renewalText('mode_'.$option) }}</span>
                        </label>
                        <button type="button" data-renewal-mode-info aria-label="{{ $renewalText('mode_info', ['mode'=>$renewalText('mode_'.$option)]) }}" aria-describedby="renewal-mode-help-{{ $option }}"><i class="fa fa-info-circle" aria-hidden="true"></i></button>
                        <span class="payment-renewal-mode-help" id="renewal-mode-help-{{ $option }}" role="tooltip">{{ $renewalText('mode_'.$option.'_help') }}</span>
                    </div>
                @endforeach
            </fieldset>
            @foreach($filters as $name=>$value)
                @if(str_starts_with($name, 'initial_'))<input type="hidden" name="{{ $name }}" value="{{ $value }}">@endif
            @endforeach
            <div class="flt-filter-grid payment-renewal-fields">
                <div class="payment-renewal-cohort">
                    <label class="flt-field-label" for="renewal-period-from">{{ $linked ? $renewalText('linked_date_label') : __($t.'.filters.cohort_period') }}</label>
                    <div class="flt-time-range">
                        <input id="renewal-period-from" type="date" name="renewal_date_from" aria-label="{{ $text('start_date') }}" value="{{ $filters['renewal_date_from'] }}" required>
                        <input type="date" name="renewal_date_to" aria-label="{{ $text('end_date') }}" value="{{ $filters['renewal_date_to'] }}" required>
                    </div>
                </div>
                <div>
                    <label class="flt-field-label" for="renewal-period-timezone">{{ __($t.'.filters.timezone') }}</label>
                    <select id="renewal-period-timezone" name="renewal_timezone" data-filter-fixed-choice data-filter-searchable>@foreach(\App\Portals\Payment\PaymentRenewalAnalysisService::timezones() as $offset=>$label)<option value="{{ $offset }}" @selected($filters['renewal_timezone']===$offset)>{{ $label }}</option>@endforeach</select>
                </div>
                <div>
                    <label class="flt-field-label" for="renewal-period-stage">{{ $text('renewal_stage') }}</label>
                    <select id="renewal-period-stage" name="stage" data-filter-fixed-choice><option value="">{{ $text('all_renewals') }}</option>@foreach($stageOptions as $key)<option value="{{ $key }}" @selected($filters['stage']===$key)>{{ $stageName($stageNumber($key)) }}</option>@endforeach</select>
                </div>
            </div>
            <div class="flt-filter-actions payment-renewal-filter-actions">
                @include('portals.payment.partials.renewal-month-shortcuts')
                <div class="payment-renewal-action-buttons">
                    <button class="flt-btn flt-primary" type="submit"><i class="fa fa-search" aria-hidden="true"></i>{{ __('payment.common.query') }}</button>
                    <a class="flt-btn" data-renewal-navigation href="{{ route('payment.renewal-analysis.index', [...$moduleReset, 'load_renewal'=>1]) }}#renewal-analysis-renewal">{{ __('payment.common.reset') }}</a>
                    <button class="flt-btn" type="button" data-renewal-export-url="{{ route('payment.renewal-analysis.export', $exportQuery) }}"><i class="fa fa-download" aria-hidden="true"></i>{{ __($t.'.export.button') }}</button>
                </div>
            </div>
            @foreach(['renewal_date_from','renewal_date_to','renewal_timezone'] as $field)@error($field)<p class="text-danger">{{ $message }}</p>@enderror
            @endforeach
        </form>
        @include('portals.payment.partials.renewal-section-status')
        <div class="payment-renewal-section-results">
        @include('portals.payment.partials.renewal-table-skeleton')
        <div class="payment-renewal-section-data">
        <p class="payment-renewal-compact-context">
            @if($linked)
                {{ $renewalText('linked_context',['from'=>$filters['renewal_date_from'],'to'=>$filters['renewal_date_to'],'timezone'=>$calc['timezone'],'generated'=>$calc['generated_at']]) }}
            @else
                {{ $text('context',['from'=>$filters['renewal_date_from'],'to'=>$filters['renewal_date_to'],'timezone'=>$calc['timezone'],'generated'=>$calc['generated_at']]) }}
            @endif
        </p>

        <p class="payment-renewal-scope-note">{{ $linked ? $renewalText('linked_cutoff_note').' '.$renewalText('linked_risk') : $text('stage_note') }}</p>
        <div class="payment-renewal-stage-list">
        @forelse($dashboard['stages'] as $stage)
            @php
                $previousName = $stage['number'] === 1 ? __($t.'.funnel.initial_stage') : $stageName($stage['number']-1);
                $baseLabel = $linked ? $renewalText($stage['number'] === 1 ? 'linked_first_orders' : 'linked_previous_orders') : $text('previous_orders');
            @endphp
            <section class="payment-renewal-stage-card" aria-labelledby="renewal-stage-{{ $stage['number'] }}">
                <header class="payment-renewal-stage-heading">
                    <h3 id="renewal-stage-{{ $stage['number'] }}">{{ $stageName($stage['number']) }}</h3>
                    <div class="payment-renewal-stage-periods">
                        <span>{{ $linked ? $renewalText('linked_period',['from'=>substr($calc['period_from'],0,10),'to'=>substr($calc['period_to'],0,10)]) : $text('stage_current_period',['from'=>substr($calc['period_from'],0,10),'to'=>substr($calc['period_to'],0,10)]) }}</span>
                        <span>{{ $linked ? $renewalText('linked_previous',['previous'=>$previousName]) : $text('stage_comparison_period',['from'=>substr($calc['comparison_from'],0,10),'to'=>substr($calc['comparison_to'],0,10),'previous'=>$previousName]) }}</span>
                        @if($linked)<span>{{ $dueThrough }}</span>@endif
                    </div>
                </header>
                <div class="table-responsive"><table @class(['table payment-data-table payment-renewal-stage-table', 'payment-renewal-stage-table-linked'=>$linked])>
                    <thead><tr><th>{{ $text('payment_method') }}</th><th>{{ $baseLabel }}</th><th>{{ $linked ? $renewalText('linked_current_orders') : $text('current_renewal_orders') }}</th><th>{{ __($t.'.funnel.renewal_rate') }}</th><th>{{ $text('revenue') }}</th><th>{{ $text('refund_orders') }}</th><th>{{ $text('refund_amount') }}</th><th>{{ $text('refund_count_rate') }}</th><th>{{ $text('refund_amount_rate') }}</th></tr></thead>
                    <tbody>
            @foreach($groups as $key=>$group)
                @php $part = $key === 'total' ? $stage : $stage['by_channel'][$key]; @endphp
                <tr class="payment-level-{{ $group['level'] }} {{ $key === 'total' ? 'payment-stage-start' : '' }}" @if($key === 'total') data-payment-renewal-number="{{ $stage['number'] }}" @endif data-payment-method="{{ $group['method'] }}">
                    <td><span class="payment-method-badge">{{ $methodLabel($group) }}</span></td>
                    <td class="payment-renewal-stage-count">
                        @if($linked)
                            <strong>{{ number_format($part['previous_orders']) }}</strong>
                            @if($part['previous_orders']>0)
                                <small>@if($part['base_order_created_to'] === null){{ $renewalText('linked_no_order_time') }}@else{{ $renewalText('linked_last_order_label') }}<strong>{{ $part['base_order_created_to'] }}</strong>@endif</small>
                            @endif
                            @if($part['pending_orders']>0)
                                <small>{{ $renewalText('linked_pending_prefix') }} <strong>{{ number_format($part['pending_orders']) }}</strong> {{ $renewalText('linked_pending_suffix') }}</small>
                            @endif
                        @else
                            {{ number_format($part['previous_orders']) }}
                        @endif
                    </td>
                    <td class="payment-renewal-stage-count">
                        @if($linked)
                            <strong>{{ number_format($part['rate_orders']) }}</strong>
                            @if($part['rate_orders']>0)
                                <small>@if($part['rate_order_created_to'] === null){{ $renewalText('linked_no_order_time') }}@else{{ $renewalText('linked_last_order_label') }}<strong>{{ $part['rate_order_created_to'] }}</strong>@endif</small>
                            @endif
                            @if($part['missing_previous_orders']>0)
                                <small>{{ $renewalText('linked_missing_previous',['count'=>number_format($part['missing_previous_orders'])]) }}</small>
                            @endif
                        @else
                            {{ number_format($part['orders']) }}
                        @endif
                    </td>
                    <td class="payment-rate-cell"><strong>{{ $part['rate'] === null ? __($t.'.funnel.not_applicable') : number_format($part['rate'],2).'%' }}</strong><small class="payment-inline-formula">{{ $part['rate'] === null ? $text('inline_no_base') : $inlineRatio($part['rate_orders'] ?? $part['orders'],$part['previous_orders']) }}</small></td>
                    <td>{{ $money($part['revenue']) }}</td><td>{{ number_format($part['refund_order_count']) }}</td><td>{{ $money($part['refund_amount']) }}</td>
                    <td class="payment-refund-rate">{{ number_format($part['refund_count_rate'],2) }}%<small class="payment-inline-formula">{{ $inlineRatio($part['refund_order_count'],$part['orders']) }}</small></td>
                    <td class="payment-refund-rate">{{ number_format($part['refund_amount_rate'],2) }}%<small class="payment-inline-formula">{{ $inlineRatio($part['refund_amount'],$part['revenue'],true) }}</small></td>
                </tr>
            @endforeach
                    </tbody></table></div>
            <div class="payment-renewal-stage-calculation">
                <details class="payment-calculation-details"><summary>{{ $text('stage_calculation',['number'=>$stage['number']]) }}</summary>
                    @if ($linked)
                        {{ $notes([$renewalText('linked_base',['previous'=>$previousName,'count'=>$stage['previous_orders']]),$renewalText('linked_count',['number'=>$stage['number'],'count'=>$stage['rate_orders']]),$period]) }}
                    @else
                        {{ $notes([$audit('base',['number'=>$stage['number'],'previous'=>$previousName,'count'=>$stage['previous_orders']]),
                            $audit('completed',['number'=>$stage['number'],'count'=>$stage['orders']]),$period,$comparison]) }}
                    @endif
                    @if($stage['rate'] === null)
                        {{ $notes([$text('no_base')]) }}
                    @else
                        {{ $notes($ratio($stage['rate_orders'] ?? $stage['orders'],$stage['previous_orders'],$stage['rate'])) }}
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
        <nav class="flt flt-pagination payment-renewal-pagination" aria-label="{{ __('frontend_list_template.pagination') }}">
            <span class="payment-renewal-page-count">{{ __($t.'.funnel.pagination_summary',['from'=>$p['from'],'to'=>$p['to'],'total'=>$p['total']]) }}</span>
            <form method="get" action="{{ route('payment.renewal-analysis.index') }}#renewal-analysis-renewal" class="payment-pagination-form" data-renewal-module="renewal">
                <input type="hidden" name="load_renewal" value="1">
                @foreach($stageQuery as $name=>$value)<input type="hidden" name="{{ $name }}" value="{{ $value }}">@endforeach
                <span>{{ __($t.'.funnel.per_page') }}</span>
                <a class="flt-btn {{ $p['current_page']<=1 ? 'disabled' : '' }}" @if($p['current_page']<=1) aria-disabled="true" tabindex="-1" @endif data-renewal-navigation href="{{ $stageUrl(max(1,$p['current_page']-1)) }}">{{ __('payment.common.pagination.previous') }}</a>
                <strong>{{ __('payment.common.pagination.page_status',['current'=>$p['current_page'],'last'=>$p['last_page']]) }}</strong>
                <a class="flt-btn {{ $p['current_page']>=$p['last_page'] ? 'disabled' : '' }}" @if($p['current_page']>=$p['last_page']) aria-disabled="true" tabindex="-1" @endif data-renewal-navigation href="{{ $stageUrl(min($p['last_page'],$p['current_page']+1)) }}">{{ __('payment.common.pagination.next') }}</a>
                <label><span>{{ __('payment.common.pagination.jump_to') }}</span><input class="payment-page-jump-input" type="number" name="stage_page" min="1" max="{{ $p['last_page'] }}" placeholder="{{ __('payment.common.pagination.page_placeholder') }}" required></label>
                <button class="flt-btn flt-primary" type="submit">{{ __('payment.common.pagination.jump') }}</button>
            </form>
        </nav>

        <details class="payment-calculation-details"><summary>{{ $text('view_calculation') }}</summary>
            @if ($linked)
                {{ $notes([$period,$utcPeriod,$renewalText('mode_linked_help'),$renewalText('linked_risk'),
                    $audit('time',['timezone'=>$calc['timezone'],'generated'=>$calc['generated_at'],'expires'=>$calc['expires_at']]),$audit('refund_rule'),$audit('rounding')]) }}
            @else
            {{ $notes([$period,$comparison,$utcPeriod,$audit('utc_comparison',['from'=>$calc['utc_comparison_from'],'to'=>$calc['utc_comparison_to']]),
                $audit('time',['timezone'=>$calc['timezone'],'generated'=>$calc['generated_at'],'expires'=>$calc['expires_at']]),
                $audit('source_scope'),$text('channel_rule'),$audit('refund_rule'),$riskTime,$audit('rounding')]) }}
            @endif
        </details>
        </div>
        </div>
    </article>
@endif
