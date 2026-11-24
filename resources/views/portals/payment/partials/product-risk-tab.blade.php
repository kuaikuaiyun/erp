@php
    $translationPrefix = 'payment.product_risk_profit';
    $pagination = $dashboard['pagination'] ?? [];
    $filters = $dashboard['filters'] ?? request()->only(['date_from', 'date_to', 'store']);
    $money = static fn ($value) => '$'.number_format((float) $value, 2);
    $number = static fn ($value) => number_format((float) $value, ((float) $value == (int) $value) ? 0 : 2);
    $rate = static fn ($value) => number_format((float) $value, 2).'%';
    $activeSort = (string) ($filters['sort'] ?? '');
    $activeDirection = (string) ($filters['direction'] ?? 'desc');
    $metricLabels = __($translationPrefix.'.metric_filters.metrics');
    $columnGroups = [
        'product_info' => [
            ['layout.product_detail', []],
            ['compact.order_count', ['order_count' => 'compact.order_count']],
            ['compact.sales_amount', ['revenue' => 'compact.sales_amount']],
            ['table.combined_refund', ['combined_refund_rate' => 'table.combined_refund']],
            ['layout.combined_initial_refund_amount_rate', []],
        ],
        'paypal_fee_info' => [
            ['compact.refund_rate', ['paypal_refund_rate' => 'compact.refund_rate']],
            ['compact.dispute_rate', ['paypal_dispute_rate' => 'compact.dispute_rate']],
            ['compact.description_mismatch_rate', []],
            ['compact.initial_dispute_rate', []],
            ['compact.delivered_dispute_rate', ['paypal_delivered_dispute_rate' => 'compact.delivered_dispute_rate']],
            ['compact.initial_refund_amount_rate', []],
        ],
        'card_fee_info' => [
            ['compact.refund_rate', ['credit_card_refund_rate' => 'compact.refund_rate']],
            ['compact.dispute_rate', ['credit_card_dispute_rate' => 'compact.dispute_rate']],
            ['compact.delivered_dispute_rate', ['credit_card_delivered_dispute_rate' => 'compact.delivered_dispute_rate']],
            ['compact.initial_refund_amount_rate', []],
        ],
    ];
    $columnCount = array_sum(array_map('count', $columnGroups));
    $formulaText = $translationPrefix.'.formula_details';
    $formulaScopes = [
        'selected' => empty($filters['date_from']) && empty($filters['date_to'])
            ? __($formulaText.'.all_dates')
            : __($formulaText.'.selected_dates', [
                'from' => ($filters['date_from'] ?? '') ?: __($formulaText.'.open_date'),
                'to' => ($filters['date_to'] ?? '') ?: __($formulaText.'.open_date'),
            ]),
        'history' => __($formulaText.'.historical'),
    ];
    $formulaLabels = [
        'combined_refund_rate' => 'table.combined_refund',
        'combined_initial_refund_amount_rate' => 'layout.combined_initial_refund_amount_rate',
        'paypal_refund_rate' => 'compact.refund_rate',
        'paypal_dispute_rate' => 'compact.dispute_rate',
        'paypal_description_mismatch_rate' => 'compact.description_mismatch_rate',
        'paypal_initial_dispute_rate' => 'compact.initial_dispute_rate',
        'paypal_delivered_dispute_rate' => 'compact.delivered_dispute_rate',
        'paypal_initial_refund_amount_rate' => 'compact.initial_refund_amount_rate',
        'credit_card_refund_rate' => 'compact.refund_rate',
        'credit_card_dispute_rate' => 'compact.dispute_rate',
        'credit_card_delivered_dispute_rate' => 'compact.delivered_dispute_rate',
        'credit_card_initial_refund_amount_rate' => 'compact.initial_refund_amount_rate',
    ];
    $refundOrderRates = ['combined_refund_rate', 'paypal_refund_rate', 'credit_card_refund_rate'];
    $formulaDetails = [];
    if (\Illuminate\Support\Facades\Lang::has($formulaText.'.expression') && \Illuminate\Support\Facades\Lang::has($formulaText.'.substitution')) {
        foreach ($formulaScopes as $scope => $description) {
            $definitions = __($formulaText.'.'.$scope);
            if (!is_array($definitions)) {
                continue;
            }
            foreach ($definitions as $metric => $terms) {
                if (isset($formulaLabels[$metric]) && is_array($terms) && is_string($terms[0] ?? null) && is_string($terms[1] ?? null)) {
                    $formulaDetails[$metric] = [$scope, $description, $terms[0], $terms[1]];
                }
            }
        }
    }
@endphp

@if(($tab ?? 'risk_metrics') === 'all_products_summary')
    <div class="payment-risk-summary-scroll" tabindex="0" role="region" aria-label="{{ __($translationPrefix.'.layout.all_products_summary') }}">
        <table class="table payment-risk-summary-table">
            <thead>
                <tr>
                    <th colspan="4" scope="colgroup">{{ __($translationPrefix.'.layout.summary_overall') }}</th>
                    <th colspan="6" scope="colgroup">{{ __($translationPrefix.'.table.paypal_fee_info') }}</th>
                    <th colspan="4" scope="colgroup">{{ __($translationPrefix.'.table.card_fee_info') }}</th>
                </tr>
                <tr>
                    @foreach($dashboard['all_products_summary'] as $metric => $value)
                        <th scope="col">{{ __($translationPrefix.'.'.($formulaLabels[$metric] ?? match ($metric) {
                            'order_count' => 'compact.order_count',
                            'revenue' => 'compact.sales_amount',
                        })) }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody><tr>
                @foreach($dashboard['all_products_summary'] as $metric => $data)
                    <td>
                        <strong>{{ match ($metric) {
                            'order_count' => $number($data['value']),
                            'revenue' => $money($data['value']),
                            default => $rate($data['value']),
                        } }}</strong>
                        @if(isset($data['denominator']))
                            <small>
                                @if((float) $data['denominator'] === 0.0)
                                    {{ __($translationPrefix.'.layout.summary_zero_denominator') }}
                                @elseif($data['amount'])
                                    {{ __($translationPrefix.'.layout.summary_amount_formula', ['numerator' => $money($data['numerator']), 'denominator' => $money($data['denominator'])]) }}
                                @else
                                    {{ __($translationPrefix.'.metric_filters.order_ratio_formula', ['numerator' => $number($data['numerator']), 'denominator' => $number($data['denominator'])]) }}
                                @endif
                            </small>
                        @endif
                        @if(isset($formulaDetails[$metric], $data['denominator']))
                            @php
                                [$scope, $description, $numeratorLabel, $denominatorLabel] = $formulaDetails[$metric];
                                $format = $data['amount'] ? $money : $number;
                                $values = ['numerator' => $format($data['numerator']), 'denominator' => $format($data['denominator']), 'rate' => $rate($data['value'])];
                                $group = $loop->index < 4 ? 'layout.summary_overall' : ($loop->index < 10 ? 'table.paypal_fee_info' : 'table.card_fee_info');
                                $heading = __($formulaText.'.heading', ['metric' => __($translationPrefix.'.'.$group).' · '.__($translationPrefix.'.'.$formulaLabels[$metric])]);
                            @endphp
                            <button type="button" class="btn btn-link btn-xs" data-payment-risk-formula data-formula-title="{{ $heading }}" aria-haspopup="dialog" aria-controls="payment-risk-formula-modal">{{ __($formulaText.'.open') }}</button>
                            <template data-payment-risk-formula-content>
                                <p>{{ __($formulaText.'.expression', ['numerator' => $numeratorLabel, 'denominator' => $denominatorLabel]) }}</p>
                                <p><strong>{{ (float) $data['denominator'] === 0.0 ? __($formulaText.'.zero') : __($formulaText.'.substitution', $values) }}</strong></p>
                                <p>{{ __($formulaText.'.scope', ['value' => $description]) }}</p>
                                @if($scope === 'selected')
                                    <p>{{ __($formulaText.'.store', ['value' => ($filters['store'] ?? '') ?: __($translationPrefix.'.filters.all_stores')]) }}</p>
                                @endif
                                <p>{{ __($formulaText.'.summary_deduplication') }}</p>
                                @if(in_array($metric, $refundOrderRates, true))
                                    <p>{{ __($formulaText.'.refund_link') }}</p>
                                @endif
                            </template>
                        @endif
                    </td>
                @endforeach
            </tr></tbody>
        </table>
    </div>
@elseif(($tab ?? 'risk_metrics') === 'refund_reason')
    @include('portals.payment.partials.refund-reason-report', ['report' => $dashboard['refund_reason_report'] ?? []])
@else

<div class="payment-real-product-table-wrap payment-product-scroll-table" tabindex="0" role="region" aria-label="{{ __($translationPrefix.'.layout.list_total', ['count' => $number($pagination['total'])]) }}">
<table class="table payment-real-product-table payment-product-grouped-table">
    <caption class="sr-only">{{ __($translationPrefix.'.layout.list_total', ['count' => $number($pagination['total'])]) }}</caption>
    @foreach($columnGroups as $columns)
        <colgroup span="{{ count($columns) }}"></colgroup>
    @endforeach
    <thead>
        <tr class="payment-product-group-headings">
            <th class="payment-risk-check-cell" rowspan="2" scope="col">
                <input type="checkbox" data-payment-risk-check-page aria-label="{{ __('frontend_list_template.check_page') }}" @disabled(empty($dashboard['products']))>
            </th>
            @foreach($columnGroups as $group => $columns)
                <th scope="colgroup" colspan="{{ count($columns) }}">{{ __($translationPrefix.'.table.'.$group) }}</th>
            @endforeach
        </tr>
        <tr class="payment-product-column-headings">
            @foreach($columnGroups as $columns)
                @foreach($columns as [$labelKey, $sorts])
                    <th scope="col" @class(['payment-product-info-cell' => $labelKey === 'layout.product_detail']) @if(count($sorts) === 1 && isset($sorts[$activeSort])) aria-sort="{{ $activeDirection === 'asc' ? 'ascending' : 'descending' }}" @endif>
                        @if(count($sorts) !== 1)
                            <span>{{ __($translationPrefix.'.'.$labelKey) }}</span>
                        @endif
                        @if($sorts !== [])
                            <span class="payment-product-column-sorts">
                                @foreach($sorts as $metric => $sortKey)
                                    @php
                                        $isActive = $activeSort === $metric;
                                        $nextDirection = $isActive && $activeDirection === 'desc' ? 'asc' : 'desc';
                                        $label = __($translationPrefix.'.'.$sortKey);
                                        $sortLabel = __($translationPrefix.'.metric_filters.sort_'.$nextDirection, ['metric' => $metricLabels[$metric] ?? $label]);
                                    @endphp
                                    <button type="button" @class(['payment-product-metric-sort', 'active' => $isActive])
                                        data-payment-product-sort="{{ $metric }}" aria-pressed="{{ $isActive ? 'true' : 'false' }}" aria-label="{{ $sortLabel }}" title="{{ $sortLabel }}">
                                        {{ $label }} <i class="fa {{ $isActive ? ($activeDirection === 'asc' ? 'fa-sort-asc' : 'fa-sort-desc') : 'fa-sort' }}" aria-hidden="true"></i>
                                    </button>
                                @endforeach
                            </span>
                        @endif
                    </th>
                @endforeach
            @endforeach
        </tr>
    </thead>
    <tbody>
        @forelse($dashboard['products'] as $product)
            @php
                $abnormal = (float)$product['combined_refund_rate'] > 0 || (int)$product['paypal_dispute_count'] > 0;
                $refundTone = (float)$product['combined_refund_rate'] >= 5 ? 'is-danger' : ((float)$product['combined_refund_rate'] > 0 ? 'is-warning' : 'is-success');
                $displayName = trim((string)$product['tongtu_product_name']) !== '' ? $product['tongtu_product_name'] : (trim((string)$product['source_product_name']) !== '' ? $product['source_product_name'] : $product['name']);
                $paypalRefundOrderCount = $product['paypal_refund_order_count'] ?? $product['paypal_refund_count'] ?? 0;
                $paypalOrderCount = $product['paypal_order_count'] ?? 0;
                $paypalDisputeOrderCount = $product['paypal_dispute_order_count'] ?? $product['paypal_dispute_count'] ?? 0;
                $creditCardRefundOrderCount = $product['credit_card_refund_order_count'] ?? $product['credit_card_refund_count'] ?? 0;
                $creditCardOrderCount = $product['credit_card_order_count'] ?? 0;
                $combinedRefundOrderCount = $product['combined_refund_order_count'] ?? 0;
                $paidOrderCount = $product['paid_order_count'] ?? $product['order_count'] ?? 0;
            @endphp
            @continue($filters['only_abnormal'] === '1' && !$abnormal)
            <tr class="payment-product-clickable-row" tabindex="0" role="button" data-payment-product-row data-payment-product-detail data-product-id="{{ $product['id'] }}" data-product-name="{{ $displayName }}" data-abnormal="{{ $abnormal ? '1' : '0' }}">
                <td class="payment-risk-check-cell">
                    <input type="checkbox" value="{{ $product['id'] }}" data-payment-risk-check aria-label="{{ __($translationPrefix.'.layout.select_product', ['name' => $displayName]) }}">
                </td>
                <td class="payment-product-info-cell">
                    <div class="payment-real-product-identity">
                        @if($product['image_url'])
                            <button type="button" class="payment-product-image-button" data-payment-product-image data-image-url="{{ $product['image_url'] }}" data-image-name="{{ $displayName }}"><img src="{{ $product['image_url'] }}" alt="{{ $displayName }}" loading="lazy"></button>
                        @else
                            <span><i class="fa fa-cube" aria-hidden="true"></i></span>
                        @endif
                        <div>
                            <strong title="{{ $displayName }}">{{ $displayName }}</strong>
                            <small title="{{ $product['product_code'] ?: $product['sku'] }}">{{ $product['product_code'] ?: $product['sku'] }}</small>
                            <small>{{ __($translationPrefix.'.layout.latest_paid_line', ['value' => $product['latest_paid_at']]) }}</small>
                        </div>
                    </div>
                </td>
                <td class="payment-product-value">{{ $number($product['order_count'] ?? 0) }}{{ __($translationPrefix.'.compact.order_unit') }}</td>
                <td class="payment-product-value">{{ $money($product['revenue']) }}</td>
                @php
                    $operands = [
                        'combined_refund_rate' => [$combinedRefundOrderCount, $paidOrderCount],
                        'combined_initial_refund_amount_rate' => [$product['combined_initial_refund_amount'], $product['combined_initial_received_amount'], true],
                        'paypal_refund_rate' => [$paypalRefundOrderCount, $paypalOrderCount],
                        'paypal_dispute_rate' => [$paypalDisputeOrderCount, $paypalOrderCount],
                        'paypal_description_mismatch_rate' => [$product['paypal_description_mismatch_amount'] ?? 0, $product['paypal_initial_dispute_amount'] ?? 0, true],
                        'paypal_initial_dispute_rate' => [$product['paypal_initial_dispute_count'], $product['paypal_initial_order_count']],
                        'paypal_delivered_dispute_rate' => [$product['paypal_delivered_dispute_count'], $product['paypal_delivered_order_count']],
                        'paypal_initial_refund_amount_rate' => [$product['paypal_initial_refund_amount'], $product['paypal_initial_received_amount'], true],
                        'credit_card_refund_rate' => [$creditCardRefundOrderCount, $creditCardOrderCount],
                        'credit_card_dispute_rate' => [$product['credit_card_dispute_count'], $product['credit_card_initial_order_count'] ?? 0],
                        'credit_card_delivered_dispute_rate' => [$product['credit_card_delivered_dispute_count'], $product['credit_card_delivered_order_count']],
                        'credit_card_initial_refund_amount_rate' => [$product['credit_card_initial_refund_amount'], $product['credit_card_initial_received_amount'], true],
                    ];
                @endphp
                @foreach($operands as $metric => $parts)
                    @php
                        [$numerator, $denominator] = $parts;
                        $isAmount = $parts[2] ?? false;
                        $format = $isAmount ? $money : $number;
                        $values = ['numerator' => $format($numerator), 'denominator' => $format($denominator), 'rate' => $rate($product[$metric])];
                    @endphp
                    <td @class(['payment-product-value', 'payment-product-value-with-formula' => isset($formulaDetails[$metric])])>
                        @if($metric === 'combined_refund_rate')
                            <span class="payment-product-rate-card {{ $refundTone }}">{{ $values['rate'] }}</span>
                        @else
                            <strong>{{ $values['rate'] }}</strong>
                        @endif
                        <small>
                            @if($isAmount)
                                {{ $values['numerator'] }} ÷ {{ $values['denominator'] }} × 100%
                            @else
                                {{ __($translationPrefix.'.metric_filters.order_ratio_formula', $values) }}
                            @endif
                        </small>
                        @if(isset($formulaDetails[$metric]))
                            @php
                                [$scope, $description, $numeratorLabel, $denominatorLabel] = $formulaDetails[$metric];
                                $title = __($translationPrefix.'.'.$formulaLabels[$metric]);
                                $channel = match (true) {
                                    str_starts_with($metric, 'paypal_') => __($translationPrefix.'.table.paypal_fee_info'),
                                    str_starts_with($metric, 'credit_card_') => __($translationPrefix.'.table.card_fee_info'),
                                    default => '',
                                };
                                $heading = __($formulaText.'.heading', ['metric' => trim($channel.' '.$title)]);
                            @endphp
                            <button type="button" class="btn btn-link btn-xs" data-payment-risk-formula data-formula-title="{{ $heading }}" aria-haspopup="dialog" aria-controls="payment-risk-formula-modal">{{ __($formulaText.'.open') }}</button>
                            <template data-payment-risk-formula-content>
                                    <p>{{ __($formulaText.'.expression', ['numerator' => $numeratorLabel, 'denominator' => $denominatorLabel]) }}</p>
                                    <p><strong>{{ (float) $denominator === 0.0 ? __($formulaText.'.zero') : __($formulaText.'.substitution', $values) }}</strong></p>
                                    <p>{{ __($formulaText.'.scope', ['value' => $description]) }}</p>
                                    @if($scope === 'selected')
                                        <p>{{ __($formulaText.'.store', ['value' => ($filters['store'] ?? '') ?: __($translationPrefix.'.filters.all_stores')]) }}</p>
                                    @endif
                                    @if(in_array($metric, $refundOrderRates, true))
                                        <p>{{ __($formulaText.'.refund_link') }}</p>
                                    @endif
                                    @if(str_starts_with($metric, 'paypal_'))
                                        <p>{{ __($formulaText.'.paypal_link') }}</p>
                                    @elseif($metric === 'credit_card_refund_rate')
                                        <p>{{ __($formulaText.'.card_refund_link') }}</p>
                                    @elseif(str_starts_with($metric, 'credit_card_'))
                                        <p>{{ __($formulaText.'.card_link') }}</p>
                                    @endif
                            </template>
                        @endif
                    </td>
                @endforeach
            </tr>
        @empty<tr><td colspan="{{ $columnCount + 1 }}" class="payment-product-empty">{{ __($translationPrefix.'.empty_results') }}</td></tr>@endforelse
</tbody></table></div>

@include('portals.payment.partials.pagination', ['pagination' => $pagination, 'routeName' => 'payment.product-risk-profit.index', 'controlsOnly' => true])
@endif
