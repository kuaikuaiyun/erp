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
    $sortColumns = [
        'product_info' => ['name' => 'sort.product_name', 'product_code' => 'compact.pcl'],
        'basic_info' => ['order_count' => 'compact.order_count', 'revenue' => 'compact.sales_amount'],
        'paypal_fee_info' => ['paypal_dispute_rate' => 'compact.dispute_rate', 'paypal_refund_rate' => 'compact.refund_rate', 'paypal_delivered_dispute_rate' => 'compact.delivered_dispute_rate'],
        'card_fee_info' => ['credit_card_dispute_rate' => 'compact.dispute_rate', 'credit_card_refund_rate' => 'compact.refund_rate', 'credit_card_delivered_dispute_rate' => 'compact.delivered_dispute_rate'],
        'latest_paid_at' => [],
        'combined_refund' => ['combined_refund_rate' => 'table.combined_refund'],
    ];
@endphp

@if(($tab ?? 'risk_metrics') === 'refund_reason')
    @include('portals.payment.partials.refund-reason-report', ['report' => $dashboard['refund_reason_report'] ?? []])
@else

<div class="payment-real-product-table-wrap payment-product-scroll-table"><table class="table payment-real-product-table payment-product-compact-table"><thead><tr>
        @foreach($sortColumns as $column => $metrics)
            <th scope="col" @if($column === 'combined_refund' && $activeSort === 'combined_refund_rate') aria-sort="{{ $activeDirection === 'asc' ? 'ascending' : 'descending' }}" @endif>
                @if($column !== 'combined_refund')
                    <span>{{ __($translationPrefix.'.table.'.$column) }}</span>
                @endif
                @if($metrics !== [])
                    <span class="{{ $column === 'combined_refund' ? 'payment-product-single-sort' : 'payment-product-sort-group' }}">
                        @foreach($metrics as $metric => $labelKey)
                            @php
                                $isActive = $activeSort === $metric;
                                $nextDirection = $isActive && $activeDirection === 'desc' ? 'asc' : 'desc';
                                $label = __($translationPrefix.'.'.$labelKey);
                                $sortLabel = __($translationPrefix.'.metric_filters.sort_'.$nextDirection, [
                                    'metric' => $metricLabels[$metric] ?? $label,
                                ]);
                            @endphp
                            <button type="button" @class(['payment-product-metric-sort', 'payment-product-header-sort' => $column === 'combined_refund', 'active' => $isActive])
                                data-payment-product-sort="{{ $metric }}" aria-pressed="{{ $isActive ? 'true' : 'false' }}" aria-label="{{ $sortLabel }}" title="{{ $sortLabel }}">
                                {{ $label }} <i class="fa {{ $isActive ? ($activeDirection === 'asc' ? 'fa-sort-asc' : 'fa-sort-desc') : 'fa-sort' }}" aria-hidden="true"></i>
                            </button>
                        @endforeach
                    </span>
                @endif
            </th>
        @endforeach
    </tr></thead><tbody>
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
                <td class="payment-product-info-cell"><div class="payment-real-product-identity">@if($product['image_url'])<button type="button" class="payment-product-image-button" data-payment-product-image data-image-url="{{ $product['image_url'] }}" data-image-name="{{ $displayName }}"><img src="{{ $product['image_url'] }}" alt="{{ $displayName }}" loading="lazy"></button>@else<span><i class="fa fa-cube"></i></span>@endif<div><strong title="{{ $displayName }}">{{ $displayName }}</strong><small><b>{{ __($translationPrefix.'.compact.pcl') }}：</b>{{ $product['product_code'] ?: $product['sku'] }}</small><small><b>{{ __($translationPrefix.'.compact.match_status') }}：</b>{{ $product['match_status_label'] }}</small></div></div></td>
                <td class="payment-product-basic-cell"><dl class="payment-product-compact-metrics"><div><dt>{{ __($translationPrefix.'.compact.order_count') }}</dt><dd>{{ $number($product['order_count'] ?? 0) }}{{ __($translationPrefix.'.compact.order_unit') }}</dd></div><div><dt>{{ __($translationPrefix.'.compact.sales_amount') }}</dt><dd>{{ $money($product['revenue']) }}</dd></div></dl></td>
                <td class="payment-product-fee-cell"><dl class="payment-product-compact-metrics">
                    <div><dt>{{ __($translationPrefix.'.compact.refund_rate') }}</dt><dd>{{ $rate($product['paypal_refund_rate']) }} <small>{{ __($translationPrefix.'.metric_filters.order_ratio_formula', ['numerator' => $number($paypalRefundOrderCount), 'denominator' => $number($paypalOrderCount)]) }}</small></dd></div>
                    <div><dt>{{ __($translationPrefix.'.compact.dispute_rate') }}</dt><dd>{{ $rate($product['paypal_dispute_rate']) }} <small>{{ __($translationPrefix.'.metric_filters.order_ratio_formula', ['numerator' => $number($paypalDisputeOrderCount), 'denominator' => $number($paypalOrderCount)]) }}</small></dd></div>
                    <div><dt>{{ __($translationPrefix.'.compact.description_mismatch_rate') }}</dt><dd>{{ $rate($product['paypal_description_mismatch_rate']) }} <small>物品与描述不符争议金额 ÷ PayPal 总争议金额 × 100%</small></dd></div>
                    <div><dt>{{ __($translationPrefix.'.compact.initial_dispute_rate') }}</dt><dd>{{ $rate($product['paypal_initial_dispute_rate']) }} <small>{{ $number($product['paypal_initial_dispute_count']) }}笔 ÷ {{ $number($product['paypal_initial_order_count']) }}笔 × 100%</small></dd></div>
                    <div><dt>{{ __($translationPrefix.'.compact.delivered_dispute_rate') }}</dt><dd>{{ $rate($product['paypal_delivered_dispute_rate']) }} <small>{{ $number($product['paypal_delivered_dispute_count']) }}笔 ÷ {{ $number($product['paypal_delivered_order_count']) }}笔 × 100%</small></dd></div>
                    <div><dt>{{ __($translationPrefix.'.compact.initial_refund_amount_rate') }}</dt><dd>{{ $rate($product['paypal_initial_refund_amount_rate']) }} <small>{{ $money($product['paypal_initial_refund_amount']) }} ÷ {{ $money($product['paypal_initial_received_amount']) }} × 100%</small></dd></div>
                </dl></td>
                <td class="payment-product-fee-cell"><dl class="payment-product-compact-metrics">
                    <div><dt>{{ __($translationPrefix.'.compact.refund_rate') }}</dt><dd>{{ $rate($product['credit_card_refund_rate']) }} <small>{{ __($translationPrefix.'.metric_filters.order_ratio_formula', ['numerator' => $number($creditCardRefundOrderCount), 'denominator' => $number($creditCardOrderCount)]) }}</small></dd></div>
                    <div><dt>{{ __($translationPrefix.'.compact.dispute_rate') }}</dt><dd>{{ $rate($product['credit_card_dispute_rate']) }} <small>{{ $number($product['credit_card_dispute_count']) }}笔 ÷ {{ $number($creditCardOrderCount) }}笔 × 100%</small></dd></div>
                    <div><dt>{{ __($translationPrefix.'.compact.delivered_dispute_rate') }}</dt><dd>{{ $rate($product['credit_card_delivered_dispute_rate']) }} <small>{{ $number($product['credit_card_delivered_dispute_count']) }}笔 ÷ {{ $number($product['credit_card_delivered_order_count']) }}笔 × 100%</small></dd></div>
                    <div><dt>{{ __($translationPrefix.'.compact.initial_refund_amount_rate') }}</dt><dd>{{ $rate($product['credit_card_initial_refund_amount_rate']) }} <small>{{ $money($product['credit_card_initial_refund_amount']) }} ÷ {{ $money($product['credit_card_initial_received_amount']) }} × 100%</small></dd></div>
                </dl></td>
                <td class="payment-product-latest-cell"><strong>{{ $product['latest_paid_at'] }}</strong></td>
                <td><span class="payment-product-rate-card {{ $refundTone }}">{{ $rate($product['combined_refund_rate']) }} <i class="fa fa-info-circle payment-formula-icon" tabindex="0" data-payment-formula-tooltip data-toggle="tooltip" data-container="body" title="{{ __($translationPrefix.'.formulas.combined_refund_rate') }}"></i><small>{{ $number($combinedRefundOrderCount) }}笔 ÷ {{ $number($paidOrderCount) }}笔 × 100%</small></span></td>
            </tr>
        @empty<tr><td colspan="6" class="payment-product-empty">{{ __($translationPrefix.'.empty_results') }}</td></tr>@endforelse
</tbody></table></div>

@include('portals.payment.partials.pagination', ['pagination' => $pagination, 'routeName' => 'payment.product-risk-profit.index'])
@endif
