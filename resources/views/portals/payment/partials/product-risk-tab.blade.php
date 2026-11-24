@php
    $translationPrefix = 'payment.product_risk_profit';
    $pagination = $dashboard['pagination'];
    $filters = $dashboard['filters'];
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
@endphp

@if(($tab ?? 'risk_metrics') === 'refund_reason')
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
            @php($abnormal = (float)$product['combined_refund_rate'] > 0 || (int)$product['paypal_dispute_count'] > 0)
            @php($refundTone = (float)$product['combined_refund_rate'] >= 5 ? 'is-danger' : ((float)$product['combined_refund_rate'] > 0 ? 'is-warning' : 'is-success'))
            @php($displayName = trim((string)$product['tongtu_product_name']) !== '' ? $product['tongtu_product_name'] : (trim((string)$product['source_product_name']) !== '' ? $product['source_product_name'] : $product['name']))
            @php($paypalRefundOrderCount = $product['paypal_refund_order_count'] ?? $product['paypal_refund_count'] ?? 0)
            @php($paypalOrderCount = $product['paypal_order_count'] ?? 0)
            @php($paypalDisputeOrderCount = $product['paypal_dispute_order_count'] ?? $product['paypal_dispute_count'] ?? 0)
            @php($creditCardRefundOrderCount = $product['credit_card_refund_order_count'] ?? $product['credit_card_refund_count'] ?? 0)
            @php($creditCardOrderCount = $product['credit_card_order_count'] ?? 0)
            @php($combinedRefundOrderCount = $product['combined_refund_order_count'] ?? 0)
            @php($paidOrderCount = $product['paid_order_count'] ?? $product['order_count'] ?? 0)
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
                            <small>{{ $product['latest_paid_at'] }}</small>
                        </div>
                    </div>
                </td>
                <td class="payment-product-value">{{ $number($product['order_count'] ?? 0) }}{{ __($translationPrefix.'.compact.order_unit') }}</td>
                <td class="payment-product-value">{{ $money($product['revenue']) }}</td>
                <td class="payment-product-value">
                    <span class="payment-product-rate-card {{ $refundTone }}">{{ $rate($product['combined_refund_rate']) }} <i class="fa fa-info-circle payment-formula-icon" tabindex="0" data-payment-formula-tooltip data-toggle="tooltip" data-container="body" title="{{ __($translationPrefix.'.formulas.combined_refund_rate') }}"></i></span>
                    <small>{{ __($translationPrefix.'.metric_filters.order_ratio_formula', ['numerator' => $number($combinedRefundOrderCount), 'denominator' => $number($paidOrderCount)]) }}</small>
                </td>
                <td class="payment-product-value">
                    <strong>{{ $rate($product['paypal_refund_rate']) }}</strong>
                    <small>{{ __($translationPrefix.'.metric_filters.order_ratio_formula', ['numerator' => $number($paypalRefundOrderCount), 'denominator' => $number($paypalOrderCount)]) }}</small>
                </td>
                <td class="payment-product-value">
                    <strong>{{ $rate($product['paypal_dispute_rate']) }}</strong>
                    <small>{{ __($translationPrefix.'.metric_filters.order_ratio_formula', ['numerator' => $number($paypalDisputeOrderCount), 'denominator' => $number($paypalOrderCount)]) }}</small>
                </td>
                <td class="payment-product-value">
                    <strong>{{ $rate($product['paypal_description_mismatch_rate']) }}</strong>
                    <small>{{ __($translationPrefix.'.layout.mismatch_formula') }}</small>
                </td>
                <td class="payment-product-value">
                    <strong>{{ $rate($product['paypal_initial_dispute_rate']) }}</strong>
                    <small>{{ __($translationPrefix.'.metric_filters.order_ratio_formula', ['numerator' => $number($product['paypal_initial_dispute_count']), 'denominator' => $number($product['paypal_initial_order_count'])]) }}</small>
                </td>
                <td class="payment-product-value">
                    <strong>{{ $rate($product['paypal_delivered_dispute_rate']) }}</strong>
                    <small>{{ __($translationPrefix.'.metric_filters.order_ratio_formula', ['numerator' => $number($product['paypal_delivered_dispute_count']), 'denominator' => $number($product['paypal_delivered_order_count'])]) }}</small>
                </td>
                <td class="payment-product-value">
                    <strong>{{ $rate($product['paypal_initial_refund_amount_rate']) }}</strong>
                    <small>{{ $money($product['paypal_initial_refund_amount']) }} ÷ {{ $money($product['paypal_initial_received_amount']) }} × 100%</small>
                </td>
                <td class="payment-product-value">
                    <strong>{{ $rate($product['credit_card_refund_rate']) }}</strong>
                    <small>{{ __($translationPrefix.'.metric_filters.order_ratio_formula', ['numerator' => $number($creditCardRefundOrderCount), 'denominator' => $number($creditCardOrderCount)]) }}</small>
                </td>
                <td class="payment-product-value">
                    <strong>{{ $rate($product['credit_card_dispute_rate']) }}</strong>
                    <small>{{ __($translationPrefix.'.metric_filters.order_ratio_formula', ['numerator' => $number($product['credit_card_dispute_count']), 'denominator' => $number($creditCardOrderCount)]) }}</small>
                </td>
                <td class="payment-product-value">
                    <strong>{{ $rate($product['credit_card_delivered_dispute_rate']) }}</strong>
                    <small>{{ __($translationPrefix.'.metric_filters.order_ratio_formula', ['numerator' => $number($product['credit_card_delivered_dispute_count']), 'denominator' => $number($product['credit_card_delivered_order_count'])]) }}</small>
                </td>
                <td class="payment-product-value">
                    <strong>{{ $rate($product['credit_card_initial_refund_amount_rate']) }}</strong>
                    <small>{{ $money($product['credit_card_initial_refund_amount']) }} ÷ {{ $money($product['credit_card_initial_received_amount']) }} × 100%</small>
                </td>
            </tr>
        @empty<tr><td colspan="{{ $columnCount + 1 }}" class="payment-product-empty">{{ __($translationPrefix.'.empty_results') }}</td></tr>@endforelse
</tbody></table></div>

@include('portals.payment.partials.pagination', ['pagination' => $pagination, 'routeName' => 'payment.product-risk-profit.index', 'controlsOnly' => true])
@endif
