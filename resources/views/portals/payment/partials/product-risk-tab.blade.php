@php
    $translationPrefix = 'payment.product_risk_profit';
    $pagination = $dashboard['pagination'];
    $filters = $dashboard['filters'];
    $money = static fn ($value) => '$'.number_format((float) $value, 2);
    $number = static fn ($value) => number_format((float) $value, ((float) $value == (int) $value) ? 0 : 2);
    $rate = static fn ($value) => number_format((float) $value, 2).'%';
    $sortableHeaders = ['product'=>'name','product_code'=>'product_code','sales'=>'sales','revenue'=>'revenue','paypal_refund'=>'paypal_refund_amount','paypal_dispute'=>'paypal_dispute_count','card_refund'=>'credit_card_refund_amount','combined_refund'=>'combined_refund_rate'];
@endphp

@if($tab === 'risk_metrics')
    <div class="payment-real-product-table-wrap"><table class="table payment-real-product-table"><thead><tr>
        @foreach(['product','product_code','sales','revenue','paypal_refund','paypal_dispute','card_refund','combined_refund'] as $header)<th>@if(isset($sortableHeaders[$header]))<button type="button" class="payment-product-sort {{ $filters['sort'] === $sortableHeaders[$header] ? 'active' : '' }}" data-payment-product-sort="{{ $sortableHeaders[$header] }}">{{ __($translationPrefix.'.table.'.$header) }} <i class="fa {{ $filters['sort'] === $sortableHeaders[$header] ? ($filters['direction'] === 'asc' ? 'fa-sort-asc' : 'fa-sort-desc') : 'fa-sort' }}"></i></button>@else{{ __($translationPrefix.'.table.'.$header) }}@endif</th>@endforeach
    </tr></thead><tbody>
        @forelse($dashboard['products'] as $product)
            @php($abnormal = (float)$product['combined_refund_rate'] > 0 || (int)$product['paypal_dispute_count'] > 0)
            @php($refundTone = (float)$product['combined_refund_rate'] >= 5 ? 'is-danger' : ((float)$product['combined_refund_rate'] > 0 ? 'is-warning' : 'is-success'))
            @continue($filters['only_abnormal'] === '1' && !$abnormal)
            <tr class="payment-product-clickable-row" tabindex="0" role="button" data-payment-product-row data-payment-product-detail data-product-id="{{ $product['id'] }}" data-product-name="{{ $product['name'] }}" data-abnormal="{{ $abnormal ? '1' : '0' }}">
                <td><div class="payment-real-product-identity">@if($product['image_url'])<button type="button" class="payment-product-image-button" data-payment-product-image data-image-url="{{ $product['image_url'] }}" data-image-name="{{ $product['source_product_name'] }}"><img src="{{ $product['image_url'] }}" alt="{{ $product['source_product_name'] }}" loading="lazy"></button>@else<span><i class="fa fa-cube"></i></span>@endif<div><strong>{{ $product['source_product_name'] }}</strong><small>{{ $product['tongtu_product_name'] }}</small></div></div></td>
                <td><strong>{{ $product['product_code'] ?: $product['sku'] }}</strong><small>{{ $product['match_status_label'] }}</small></td>
                <td>{{ $number($product['sales']) }}</td><td>{{ $money($product['revenue']) }}</td>
                <td><strong>{{ $money($product['paypal_refund_amount']) }}</strong><small>{{ $rate($product['paypal_refund_rate']) }} <i class="fa fa-info-circle payment-formula-icon" tabindex="0" data-payment-formula-tooltip data-toggle="tooltip" data-container="body" title="{{ __($translationPrefix.'.formulas.paypal_refund_rate') }}"></i> · {{ $number($product['paypal_refund_count']) }} {{ __($translationPrefix.'.record_unit') }}</small></td>
                <td><strong>{{ $number($product['paypal_dispute_count']) }}</strong><small>{{ $rate($product['paypal_dispute_rate']) }} <i class="fa fa-info-circle payment-formula-icon" tabindex="0" data-payment-formula-tooltip data-toggle="tooltip" data-container="body" title="{{ __($translationPrefix.'.formulas.paypal_dispute_rate') }}"></i></small></td>
                <td><strong>{{ $money($product['credit_card_refund_amount']) }}</strong><small>{{ $rate($product['credit_card_refund_rate']) }} <i class="fa fa-info-circle payment-formula-icon" tabindex="0" data-payment-formula-tooltip data-toggle="tooltip" data-container="body" title="{{ __($translationPrefix.'.formulas.card_refund_rate') }}"></i> · {{ $number($product['credit_card_refund_count']) }} {{ __($translationPrefix.'.record_unit') }}</small></td>
                <td><span class="payment-product-rate-card {{ $refundTone }}">{{ $rate($product['combined_refund_rate']) }} <i class="fa fa-info-circle payment-formula-icon" tabindex="0" data-payment-formula-tooltip data-toggle="tooltip" data-container="body" title="{{ __($translationPrefix.'.formulas.combined_refund_rate') }}"></i></span></td>
            </tr>
        @empty<tr><td colspan="8" class="payment-product-empty">{{ __($translationPrefix.'.empty_results') }}</td></tr>@endforelse
    </tbody></table></div>
@elseif($tab === 'loss_profit')
    <div class="payment-profit-grid">@forelse($dashboard['products'] as $product)<article><h3>{{ $product['name'] }}</h3><dl><div><dt>{{ __($translationPrefix.'.profit.revenue') }}</dt><dd>{{ $money($product['revenue']) }}</dd></div><div><dt>{{ __($translationPrefix.'.profit.refunds') }}</dt><dd>{{ $money($product['actual_refunds']) }}</dd></div><div><dt>{{ __($translationPrefix.'.profit.cost') }}</dt><dd>{{ __($translationPrefix.'.pending_cost') }}</dd></div><div><dt>{{ __($translationPrefix.'.profit.net_profit') }}</dt><dd>{{ __($translationPrefix.'.pending_cost') }}</dd></div></dl></article>@empty<p class="payment-product-empty">{{ __($translationPrefix.'.empty_results') }}</p>@endforelse</div>
@else
    <div class="payment-refund-reason-grid">@forelse($dashboard['refund_reason_rows'] as $row)<article><h3>{{ __($translationPrefix.'.refund_report.'.$row['reason_type']) }}</h3><div><span>{{ $row['reason'] === '__empty__' ? __($translationPrefix.'.refund_report.no_reason') : $row['reason'] }}</span><strong>{{ $money($row['amount']) }} / {{ $rate($row['amount_ratio']) }} <i class="fa fa-info-circle payment-formula-icon" tabindex="0" data-payment-formula-tooltip data-toggle="tooltip" data-container="body" title="{{ __($translationPrefix.'.formulas.refund_reason_amount_ratio') }}"></i></strong></div></article>@empty<p class="payment-product-empty">{{ __($translationPrefix.'.refund_report.empty') }}</p>@endforelse</div>
@endif

@include('portals.payment.partials.pagination', ['pagination' => $pagination, 'routeName' => 'payment.product-risk-profit.tab', 'extraQuery' => ['tab' => $tab]])
