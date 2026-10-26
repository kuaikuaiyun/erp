@extends('portal.layout')

@php
    $translationPrefix = 'payment.product_risk_profit';
    $portalOrigin = rtrim(request()->getSchemeAndHttpHost(), '/');
    $summary = $dashboard['summary'];
    $pagination = $dashboard['pagination'];
    $filters = $dashboard['filters'];
    $money = static fn ($value) => '$'.number_format((float) $value, 2);
    $number = static fn ($value) => number_format((float) $value, ((float) $value == (int) $value) ? 0 : 2);
    $rate = static fn ($value) => number_format((float) $value, 2).'%';
    $sortableHeaders = ['product'=>'name','product_code'=>'product_code','stores'=>'shop_count','sales'=>'sales','revenue'=>'revenue','paypal_refund'=>'paypal_refund_amount','paypal_dispute'=>'paypal_dispute_count','card_refund'=>'credit_card_refund_amount','combined_refund'=>'combined_refund_rate'];
@endphp

@section('page_title', __($translationPrefix.'.page_title'))

@push('styles')
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/payment-portal.css?v=20261026-1">
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/payment-shared.css?v=20261026-1">
@endpush

@section('content')
<section class="content payment-portal-page payment-product-risk-page">
    <div class="box box-solid payment-filter-box"><div class="box-header with-border"><h3 class="box-title"><i class="fa fa-filter" aria-hidden="true"></i> {{ __('payment.common.filter_title') }}</h3></div><div class="box-body">
        <form class="payment-operation-filter-form" method="get" action="{{ route('payment.product-risk-profit.index') }}" data-payment-product-filter>
            <input type="hidden" name="per_page" value="{{ $pagination['per_page'] }}">
            <input type="hidden" name="sort" value="{{ $filters['sort'] }}"><input type="hidden" name="direction" value="{{ $filters['direction'] }}">
            <label class="payment-operation-filter-field"><span>{{ __('payment.common.period') }}</span><span class="payment-operation-date-range"><input class="form-control" type="date" name="date_from" value="{{ $filters['date_from'] }}"><b>{{ __('payment.common.range_to') }}</b><input class="form-control" type="date" name="date_to" value="{{ $filters['date_to'] }}"></span></label>
            <label class="payment-operation-filter-field"><span>{{ __($translationPrefix.'.filters.store') }}</span><select class="form-control" name="store"><option value="">{{ __($translationPrefix.'.filters.all_stores') }}</option>@foreach($dashboard['stores'] as $store)<option value="{{ $store }}" @selected($filters['store'] === $store)>{{ $store }}</option>@endforeach</select></label>
            <label class="payment-operation-filter-field"><span>{{ __($translationPrefix.'.filters.product') }}</span><input class="form-control" type="search" name="keyword" value="{{ $filters['keyword'] }}" placeholder="{{ __($translationPrefix.'.filters.product_placeholder') }}"></label>
            <label class="payment-operation-filter-field"><span>{{ __($translationPrefix.'.filters.only_abnormal') }}</span><select class="form-control" name="only_abnormal"><option value="">{{ __($translationPrefix.'.filters.all_products') }}</option><option value="1" @selected($filters['only_abnormal'] === '1')>{{ __($translationPrefix.'.filters.abnormal') }}</option></select></label>
            <div class="payment-operation-filter-actions"><button class="btn btn-primary" type="submit"><i class="fa fa-search"></i> {{ __('payment.common.query') }}</button><a class="btn btn-default" href="{{ route('payment.product-risk-profit.index') }}">{{ __('payment.common.reset') }}</a></div>
        </form>
    </div></div>

    <div class="payment-kpi-grid payment-kpi-grid-four">
        <article class="payment-kpi-card"><span class="payment-kpi-icon payment-kpi-icon-blue"><i class="fa fa-cubes"></i></span><div><p>{{ __($translationPrefix.'.kpis.products') }}</p><strong>{{ $number($summary['product_count']) }} / {{ $number($summary['sales']) }}</strong><small>{{ __($translationPrefix.'.kpis.products_real_note') }}</small></div></article>
        <article class="payment-kpi-card"><span class="payment-kpi-icon payment-kpi-icon-cyan"><i class="fa fa-usd"></i></span><div><p>{{ __($translationPrefix.'.kpis.receipts') }}</p><strong>{{ $money($summary['revenue']) }}</strong><small>{{ __($translationPrefix.'.kpis.receipts_real_note') }}</small></div></article>
        <article class="payment-kpi-card payment-tone-warning"><span class="payment-kpi-icon payment-kpi-icon-orange"><i class="fa fa-reply"></i></span><div><p>{{ __($translationPrefix.'.kpis.refund') }}</p><strong>{{ $money($summary['actual_refunds']) }} / {{ $rate($dashboard['refund_rate']) }}</strong><small>{{ __($translationPrefix.'.kpis.refund_real_note') }}</small></div></article>
        <article class="payment-kpi-card"><span class="payment-kpi-icon payment-kpi-icon-red"><i class="fa fa-line-chart"></i></span><div><p>{{ __($translationPrefix.'.kpis.profit_pending') }}</p><strong>{{ __($translationPrefix.'.pending_cost') }}</strong><small>{{ __($translationPrefix.'.kpis.profit_pending_note') }}</small></div></article>
    </div>

    <div class="payment-tabs" role="tablist">@foreach(['risk_metrics','loss_profit','refund_reason'] as $tab)<button type="button" class="{{ $loop->first ? 'active' : '' }}" data-payment-tab="{{ $tab }}" role="tab" aria-selected="{{ $loop->first ? 'true' : 'false' }}">{{ __($translationPrefix.'.tabs.'.$tab) }}</button>@endforeach</div>

    <div class="payment-tab-panel" data-payment-tab-panel="risk_metrics">
        <div class="payment-real-product-table-wrap"><table class="table payment-real-product-table"><thead><tr>
            @foreach(['product','product_code','stores','sales','revenue','paypal_refund','paypal_dispute','card_refund','combined_refund','action'] as $header)<th>@if(isset($sortableHeaders[$header]))<button type="button" class="payment-product-sort {{ $filters['sort'] === $sortableHeaders[$header] ? 'active' : '' }}" data-payment-product-sort="{{ $sortableHeaders[$header] }}">{{ __($translationPrefix.'.table.'.$header) }} <i class="fa {{ $filters['sort'] === $sortableHeaders[$header] ? ($filters['direction'] === 'asc' ? 'fa-sort-asc' : 'fa-sort-desc') : 'fa-sort' }}"></i></button>@else{{ __($translationPrefix.'.table.'.$header) }}@endif</th>@endforeach
        </tr></thead><tbody>
            @forelse($dashboard['products'] as $product)
                @php($abnormal = (float)$product['combined_refund_rate'] > 0 || (int)$product['paypal_dispute_count'] > 0)
                @continue($filters['only_abnormal'] === '1' && !$abnormal)
                <tr data-payment-product-row data-abnormal="{{ $abnormal ? '1' : '0' }}">
                    <td><div class="payment-real-product-identity">@if($product['image_url'])<img src="{{ $product['image_url'] }}" alt="" loading="lazy">@else<span><i class="fa fa-cube"></i></span>@endif<div><strong>{{ $product['name'] }}</strong><small>{{ $product['matched_name'] ?: $product['match_status_label'] }}</small></div></div></td>
                    <td><strong>{{ $product['product_code'] ?: $product['sku'] }}</strong><small>{{ $product['match_status_label'] }}</small></td>
                    <td><strong>{{ $number($product['shop_count']) }}</strong><small>{{ implode('、', $product['stores']) }}</small></td>
                    <td>{{ $number($product['sales']) }}</td><td>{{ $money($product['revenue']) }}</td>
                    <td><strong>{{ $money($product['paypal_refund_amount']) }}</strong><small>{{ $rate($product['paypal_refund_rate']) }} · {{ $number($product['paypal_refund_count']) }} {{ __($translationPrefix.'.record_unit') }}</small></td>
                    <td><strong>{{ $number($product['paypal_dispute_count']) }}</strong><small>{{ $rate($product['paypal_dispute_rate']) }}</small></td>
                    <td><strong>{{ $money($product['credit_card_refund_amount']) }}</strong><small>{{ $rate($product['credit_card_refund_rate']) }} · {{ $number($product['credit_card_refund_count']) }} {{ __($translationPrefix.'.record_unit') }}</small></td>
                    <td><span class="payment-product-rate {{ $abnormal ? 'is-risk' : '' }}">{{ $rate($product['combined_refund_rate']) }}</span></td>
                    <td><button type="button" class="btn btn-primary btn-xs" data-payment-product-detail data-product-id="{{ $product['id'] }}" data-product-name="{{ $product['name'] }}"><i class="fa fa-eye"></i> {{ __($translationPrefix.'.view_detail') }}</button></td>
                </tr>
            @empty<tr><td colspan="10" class="payment-product-empty">{{ __($translationPrefix.'.empty_results') }}</td></tr>@endforelse
        </tbody></table></div>
        @include('portals.payment.partials.pagination', ['pagination' => $pagination, 'routeName' => 'payment.product-risk-profit.index'])
    </div>

    <div class="payment-tab-panel" data-payment-tab-panel="loss_profit" hidden><div class="payment-profit-grid">@foreach($dashboard['products'] as $product)<article><h3>{{ $product['name'] }}</h3><dl><div><dt>{{ __($translationPrefix.'.profit.revenue') }}</dt><dd>{{ $money($product['revenue']) }}</dd></div><div><dt>{{ __($translationPrefix.'.profit.refunds') }}</dt><dd>{{ $money($product['actual_refunds']) }}</dd></div><div><dt>{{ __($translationPrefix.'.profit.cost') }}</dt><dd>{{ __($translationPrefix.'.pending_cost') }}</dd></div><div><dt>{{ __($translationPrefix.'.profit.net_profit') }}</dt><dd>{{ __($translationPrefix.'.pending_cost') }}</dd></div></dl></article>@endforeach</div></div>
    <div class="payment-tab-panel" data-payment-tab-panel="refund_reason" hidden><div class="payment-refund-reason-grid">@foreach(['source_reason','manual_reason'] as $reasonType)<article><h3>{{ __($translationPrefix.'.refund_report.'.$reasonType) }}</h3>@forelse(($dashboard['refund_reason_report'][$reasonType] ?? []) as $row)<div><span>{{ $row['reason'] === '__empty__' ? __($translationPrefix.'.refund_report.no_reason') : $row['reason'] }}</span><strong>{{ $money($row['amount']) }} / {{ $rate($row['amount_ratio']) }}</strong></div>@empty<p>{{ __($translationPrefix.'.refund_report.empty') }}</p>@endforelse</article>@endforeach</div></div>

    <div class="modal fade payment-product-detail-modal" id="payment-product-detail-modal" tabindex="-1" role="dialog" aria-labelledby="payment-product-detail-title"><div class="modal-dialog modal-lg" role="document"><div class="modal-content"><div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="{{ __($translationPrefix.'.detail.close') }}"><span>&times;</span></button><h4 id="payment-product-detail-title">{{ __($translationPrefix.'.detail.title') }}</h4><p data-payment-product-detail-subtitle></p></div><div class="modal-body">
        <div class="payment-detail-tabs" role="tablist">@foreach(['overview','first_renewal','paypal_orders','credit_card_orders','refunds','disputes','orders'] as $tab)<button type="button" class="{{ $loop->first ? 'active' : '' }}" data-payment-product-detail-tab="{{ $tab }}">{{ __($translationPrefix.'.detail.tabs.'.$tab) }}</button>@endforeach</div>
        <form class="payment-detail-filter" data-payment-product-detail-filter hidden><input class="form-control" name="detail_keyword" type="search" placeholder="{{ __($translationPrefix.'.detail.search_placeholder') }}"><button class="btn btn-primary" type="submit">{{ __('payment.common.query') }}</button><button class="btn btn-default" type="reset">{{ __('payment.common.reset') }}</button></form>
        <div class="payment-detail-state" data-payment-product-loading><i class="fa fa-spinner fa-spin"></i> {{ __($translationPrefix.'.detail.loading') }}</div><div class="payment-detail-state payment-detail-error" data-payment-product-error hidden></div><div data-payment-product-detail-body></div><div class="payment-detail-pagination" data-payment-product-detail-pagination></div>
    </div><div class="modal-footer"><button class="btn btn-default" type="button" data-dismiss="modal">{{ __($translationPrefix.'.detail.close') }}</button></div></div></div></div>
</section>
@endsection

@push('scripts')
<script>window.PaymentPortal = { productDetailUrl: @json(route('payment.product-risk-profit.detail')), productDetail: @json(__($translationPrefix.'.detail')), pagination: @json(__('payment.common.pagination')) };</script>
<script src="{{ $portalOrigin }}/assets/erp/payment-portal.js?v=20261026-6"></script>
@endpush
