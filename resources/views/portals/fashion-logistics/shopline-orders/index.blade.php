@extends('portal.layout')

@section('page_title', __('fashion_logistics_order_list.shopline'))

@php
    $portalOrigin = rtrim(request()->getSchemeAndHttpHost(), '/');
@endphp

@push('styles')
<link rel="stylesheet" href="{{ $portalOrigin }}/css/frontend-list-template.css?v=20261123-19">
<style>
.shopline-orders-page{--sl-ink:#16324a;--sl-muted:#71869a;--sl-line:#dce7ef;--sl-blue:#2189bd;--sl-blue-soft:#eef8fc;--sl-green:#17865c;--sl-amber:#b87510}
.shopline-orders-page .box{border:0;border-radius:14px;box-shadow:0 5px 22px rgba(28,69,96,.08);overflow:hidden}
.sl-order-no{font-weight:700;color:#176f9f}
.sl-sub{margin-top:3px;color:#8092a0;font-size:11px}
.sl-store{font-weight:700;color:#254f6b}
.sl-handle{margin-top:3px;color:#788c9d;font-size:11px}
.sl-customer{max-width:145px}
.sl-customer strong{display:block;color:#36576d;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.sl-products{min-width:245px}
.sl-product{display:flex;gap:7px;align-items:center;padding:4px 0;border-bottom:1px dashed #e2ebf0}
.sl-product:last-child{border-bottom:0}
.sl-product-name{max-width:180px;overflow:hidden;color:#405e71;font-size:12px;text-overflow:ellipsis;white-space:nowrap}
.sl-sku{margin-top:1px;color:#7892a4;font-size:11px}
.sl-pill{display:inline-flex;align-items:center;padding:3px 8px;border-radius:99px;font-size:11px;font-weight:700;white-space:nowrap}
.sl-pill.order{background:#eaf5fb;color:#1b759d}
.sl-pill.payment{margin-top:4px;background:#f4eefc;color:#7953ac}
.sl-pill.fulfillment{margin-top:4px;background:#fdf5e9;color:#a36912}
.sl-money{color:#153e59;font-size:13px;font-weight:700;white-space:nowrap}
</style>
<style>
.shopline-orders-page .sl-field-line{display:flex;align-items:baseline;gap:5px;max-width:100%;line-height:1.55}
.shopline-orders-page .sl-field-line+.sl-field-line{margin-top:3px}
.shopline-orders-page .sl-field-label{flex:none;color:#6f8ca2;font-size:11px;font-weight:600;white-space:nowrap}
.shopline-orders-page .sl-field-value{min-width:0;overflow:hidden;color:#265e84;font-size:12px;font-weight:700;text-overflow:ellipsis;white-space:nowrap}
.shopline-orders-page .sl-field-value.muted{color:#7890a1;font-size:11px;font-weight:400}
.shopline-orders-page .sl-order-information{min-width:315px}
.shopline-orders-page .sl-logistics-information{min-width:150px}
.shopline-orders-page .sl-customer-information{min-width:205px}
.shopline-orders-page .sl-info-block+.sl-info-block{margin-top:8px;padding-top:8px;border-top:1px dashed #d9e5ed}
.shopline-orders-page .sl-status-stack{min-width:108px}
.shopline-orders-page .sl-status-line{display:flex;align-items:baseline;gap:5px;line-height:1.7;white-space:nowrap}
.shopline-orders-page .sl-status-value{font-size:12px;font-weight:700}
.shopline-orders-page .sl-status-value.order{color:#24739a}
.shopline-orders-page .sl-status-value.payment{color:#7351a4}
.shopline-orders-page .sl-status-value.fulfillment{color:#a16a16}
.shopline-orders-page .sl-logistics-body{display:block}
</style>
<link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/fashion-logistics-order-list.css?v=5">
@endpush

@section('content')
@php
    $listText = fn ($key, $values = []) => __('fashion_logistics_order_list.'.$key, $values);
    $orderLabels = $listText('shopline_statuses');
    $paymentLabels = $listText('shopline_payments');
    $fulfillmentLabels = $listText('shopline_fulfillments');
    $fulfillmentFilterLabels = array_intersect_key($fulfillmentLabels, array_flip(['unshipped', 'partial', 'shipped']));
    $marketingSourceLabels = $listText('shopline_sources');
@endphp
<section class="content shopline-orders-page">
    <div class="flt fashion-order-list fashion-order-list--sl" data-search-options="{{ $listText('search_options') }}" data-no-options="{{ $listText('no_options') }}" data-selected-orders="{{ $listText('selected_orders') }}">
    <h1 class="fashion-order-heading">{{ $listText('shopline') }}</h1>

    <form method="get" action="{{ route('fashion-logistics.shopline-orders.index') }}" class="flt-card flt-filters fashion-order-filter" data-loading="region" data-loading-target="#fashion-order-region" data-loading-text="{{ $listText('loading') }}">
        <input type="hidden" name="per_page" value="{{ $orders->perPage() }}">
        <div class="flt-filter-grid">
        <div class="form-group"><label for="sl-list-keyword">{{ $listText('keyword') }}</label><input class="form-control sl-keyword" id="sl-list-keyword" name="keyword" value="{{ $filters['keyword'] ?? '' }}" placeholder="{{ $listText('keyword_hint') }}"></div>
        <div class="form-group"><label for="sl-list-store_handle">{{ $listText('store') }}</label><select data-fashion-searchable class="form-control" id="sl-list-store_handle" name="store_handle"><option value="">{{ $listText('all_shopline_stores') }}</option>@foreach($storeHandles as $store)<option value="{{ $store->shop_name }}" @selected(strtolower(trim((string) ($filters['store_handle'] ?? ''))) === strtolower(trim((string) $store->shop_name)))>{{ $store->shop_name }}</option>@endforeach</select></div>
        <div class="form-group"><label for="sl-list-operator_id">{{ $listText('operator') }}</label><select data-fashion-searchable class="form-control" id="sl-list-operator_id" name="operator_id"><option value="">{{ $listText('all_operators') }}</option>@foreach($operators as $operator)<option value="{{ $operator->id }}" @selected((string)($filters['operator_id'] ?? '') === (string)$operator->id)>{{ $operator->name ?: $operator->username }}</option>@endforeach</select></div>
        <div class="form-group"><label for="sl-list-status">{{ $listText('order_status') }}</label><select class="form-control" id="sl-list-status" name="status"><option value="">{{ $listText('all_order_statuses') }}</option><option value="open" @selected(($filters['status'] ?? '') === 'open')>{{ $orderLabels['open'] }}</option><option value="cancelled" @selected(($filters['status'] ?? '') === 'cancelled')>{{ $orderLabels['cancelled'] }}</option></select></div>
        <div class="form-group"><label for="sl-list-payment_status">{{ $listText('payment_status') }}</label><select class="form-control" id="sl-list-payment_status" name="payment_status"><option value="">{{ $listText('all_payment_statuses') }}</option>@foreach($paymentLabels as $key => $label)<option value="{{ $key }}" @selected(($filters['payment_status'] ?? '') === $key)>{{ $label }}</option>@endforeach</select></div>
        <div class="form-group"><label for="sl-list-fulfillment_status">{{ $listText('fulfillment_status') }}</label><select class="form-control" id="sl-list-fulfillment_status" name="fulfillment_status"><option value="">{{ $listText('all_fulfillment_statuses') }}</option>@foreach($fulfillmentFilterLabels as $key => $label)<option value="{{ $key }}" @selected(($filters['fulfillment_status'] ?? '') === $key)>{{ $label }}</option>@endforeach</select></div>
        <div class="form-group"><label for="sl-list-marketing_source">{{ $listText('marketing_source') }}</label><select class="form-control" id="sl-list-marketing_source" name="marketing_source"><option value="">{{ $listText('all_marketing_sources') }}</option>@foreach($marketingSourceLabels as $key => $label)<option value="{{ $key }}" @selected(($filters['marketing_source'] ?? '') === $key)>{{ $label }}</option>@endforeach</select></div>
        <div class="flt-time-range" role="group" aria-label="{{ $listText('date_range') }}">
        <div class="form-group"><label for="sl-list-date_from">{{ $listText('date_from') }}</label><input class="form-control" type="date" id="sl-list-date_from" name="date_from" value="{{ $filters['date_from'] ?? '' }}"></div>
        <div class="form-group"><label for="sl-list-date_to">{{ $listText('date_to') }}</label><input class="form-control" type="date" id="sl-list-date_to" name="date_to" value="{{ $filters['date_to'] ?? '' }}"></div>
        </div>
        </div>
        <div class="flt-filter-actions">
            <button class="flt-btn flt-primary" type="submit"><i class="fa fa-search" aria-hidden="true"></i> {{ $listText('query') }}</button>
            <a class="flt-btn" href="{{ route('fashion-logistics.shopline-orders.index') }}">{{ $listText('reset') }}</a>
        </div>
    </form>

    <section class="flt-card flt-records">
        <div class="flt-toolbar">
            <div class="flt-selection">
                <span>{{ $listText('record_total', ['count' => number_format($orders->total())]) }}</span>
                <span class="flt-toolbar-divider" aria-hidden="true"></span>
                <span data-fashion-selection-count aria-live="polite">{{ $listText('selected_orders', ['count' => 0]) }}</span>
                <button type="button" data-fashion-clear-selection disabled>{{ $listText('clear_selection') }}</button>
            </div>
            <div class="flt-toolbar-actions"><button class="flt-btn flt-primary" type="button" data-toggle="modal" data-target="#shopline-sync-modal"><i class="fa fa-refresh" aria-hidden="true"></i> {{ $listText('sync_title') }}</button></div>
        </div>
        <div class="flt-table-region" id="fashion-order-region">
        <div class="flt-table-scroll fashion-order-scroll{{ $orders->count() === 0 ? ' is-empty' : '' }}"><table class="fashion-order-table"><thead><tr><th class="flt-check" scope="col"><input type="checkbox" data-fashion-select-all aria-label="{{ $listText('check_page') }}" @disabled($orders->count() === 0)></th><th scope="col">{{ $listText('order_info') }}</th><th scope="col">{{ $listText('logistics_info') }}</th><th scope="col">{{ $listText('customer_info') }}</th><th scope="col">{{ $listText('marketing_source') }}</th><th scope="col">{{ $listText('products') }}</th><th scope="col">{{ $listText('amount') }}</th><th scope="col">{{ $listText('actions') }}</th></tr></thead><tbody>
        @foreach($orders as $order)
            @php
                $fulfillmentStatus = strtolower(trim((string) $order->fulfillment_status));
                $storeOwner = trim((string) ($order->store?->ownerUser?->name ?: $order->store?->ownerUser?->username));
                $storeUser = trim((string) $order->store?->user_name);
                $storeTeams = collect($order->store?->team ?? [])->filter()->implode(' / ');
            @endphp
            <tr class="sl-order-row">
                <td class="flt-check"><input type="checkbox" data-fashion-select-order aria-label="{{ $listText('check_order', ['order' => $order->order_number ?: $order->external_order_id]) }}"></td>
                <td class="sl-order-information"><div class="sl-info-block"><div class="sl-field-line"><span class="sl-field-label">{{ $listText('date') }}：</span><span class="sl-field-value">{{ $order->order_created_at?->format('Y-m-d') ?: '—' }}</span></div><div class="sl-field-line"><span class="sl-field-label">{{ $listText('time') }}：</span><span class="sl-field-value muted">{{ $order->order_created_at?->format('H:i:s') ?: '—' }}</span></div></div><div class="sl-info-block"><div class="sl-field-line"><span class="sl-field-label">{{ $listText('store') }}：</span><span class="sl-field-value fashion-order-emphasis">{{ $order->store_name ?: $listText('unnamed_store') }}</span></div><div class="sl-field-line"><span class="sl-field-label">{{ $listText('domain') }}：</span><span class="sl-field-value muted">{{ $order->source_domain ?: '—' }}</span></div><div class="sl-field-line"><span class="sl-field-label">{{ $listText('owner') }}：</span><span class="sl-field-value muted">{{ $storeOwner ?: '—' }}</span></div><div class="sl-field-line"><span class="sl-field-label">{{ $listText('user') }}：</span><span class="sl-field-value muted">{{ $storeUser ?: '—' }}</span></div><div class="sl-field-line"><span class="sl-field-label">{{ $listText('team') }}：</span><span class="sl-field-value muted">{{ $storeTeams ?: '—' }}</span></div></div><div class="sl-info-block"><div class="sl-field-line"><span class="sl-field-label">{{ $listText('order_number') }}：</span><span class="sl-field-value sl-order-no">{{ $order->order_number ?: $order->external_order_id }}</span></div><div class="sl-field-line"><span class="sl-field-label">{{ $listText('id') }}：</span><span class="sl-field-value muted">{{ $order->external_order_id }}</span></div></div></td>
                <td class="sl-logistics-information"><div class="sl-logistics-body"><div class="sl-status-stack"><div class="sl-status-line"><span class="sl-field-label">{{ $listText('order_status') }}：</span><span class="sl-status-value order">{{ $orderLabels[$order->status] ?? ($order->status ?: $listText('unknown')) }}</span></div><div class="sl-status-line"><span class="sl-field-label">{{ $listText('payment_status') }}：</span><span class="sl-status-value payment">{{ $paymentLabels[$order->payment_status] ?? ($order->payment_status ?: $listText('unknown_payment')) }}</span></div><div class="sl-status-line"><span class="sl-field-label">{{ $listText('fulfillment_status') }}：</span><span class="sl-status-value fulfillment">{{ ($fulfillmentStatus === '' || $fulfillmentStatus === 'null') ? $fulfillmentLabels['unshipped'] : ($fulfillmentLabels[$fulfillmentStatus] ?? $order->fulfillment_status) }}</span></div></div></div></td>
                <td class="sl-customer-information"><div class="sl-field-line"><span class="sl-field-label">{{ $listText('customer') }}：</span><span class="sl-field-value" title="{{ $order->customer_name }}">{{ $order->customer_name ?: '—' }}</span></div><div class="sl-field-line"><span class="sl-field-label">{{ $listText('contact') }}：</span><span class="sl-field-value muted" title="{{ $order->customer_email ?: $order->customer_phone }}">{{ $order->customer_email ?: $order->customer_phone ?: '—' }}</span></div></td>
                <td><div class="sl-field-line"><span class="sl-field-label">{{ $listText('source') }}：</span><span class="sl-field-value">{{ $marketingSourceLabels[$order->marketing_source] ?? $listText('unknown_source') }}</span></div></td>
                <td class="sl-products">
                    @forelse($order->items as $item)
                        <div class="sl-product">
                                                        <div><div class="sl-product-name" title="{{ $item->product_name }}"><span class="sl-field-label">{{ $listText('product') }}：</span>{{ $item->product_name ?: $listText('unnamed_product') }}</div><div class="sl-sku"><span class="sl-field-label">{{ $listText('sku') }}：</span>{{ $item->sku ?: $listText('no_sku') }} · ×{{ rtrim(rtrim(number_format((float)$item->quantity, 4, '.', ''), '0'), '.') }}</div></div>
                        </div>
                    @empty
                        <span class="text-muted">{{ $listText('no_items') }}</span>
                    @endforelse
                    @if($order->items_count > 2)<div class="sl-sub">{{ $listText('more_items', ['count' => $order->items_count - 2]) }}</div>@endif
                </td>
                <td><div class="sl-field-line"><span class="sl-field-label">{{ $listText('total') }}：</span><span class="sl-field-value sl-money">{{ $order->currency ?: '—' }} {{ number_format((float)$order->total_amount, 2) }}</span></div>@if((float)$order->discount_amount > 0)<div class="sl-field-line"><span class="sl-field-label">{{ $listText('discount') }}：</span><span class="sl-field-value muted">{{ number_format((float)$order->discount_amount, 2) }}</span></div>@endif</td>
                <td><button class="flt-row-action" data-fashion-order-detail type="button" data-url="{{ route('fashion-logistics.shopline-orders.show', $order) }}">{{ $listText('detail') }}</button></td>
            </tr>
        @endforeach
        </tbody></table>
            @if($orders->count() === 0)
                <div class="flt-empty-host">
                    <div class="flt-empty" role="status">
                        <i class="fa fa-search" aria-hidden="true"></i>
                        <h4>{{ $listText('empty_title') }}</h4>
                        <p>{{ $listText('empty_shopline') }}</p>
                    </div>
                </div>
            @endif
        </div>
        </div>
        @include('portals.fashion-logistics.partials.order-list-pagination')
    </section>
    </div>

    <div class="modal fashion-order-modal fashion-sync-modal" id="shopline-sync-modal" tabindex="-1" role="dialog" aria-labelledby="shopline-sync-title">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-label="{{ $listText('close') }}"><span aria-hidden="true">&times;</span></button>
                    <h4 class="modal-title" id="shopline-sync-title"><i class="fa fa-refresh" aria-hidden="true"></i> {{ $listText('platform_sync_title', ['platform' => 'Shopline']) }}</h4>
                    <p>{{ $listText('shopline_sync_hint') }}</p>
                </div>
                <div class="modal-body">
                    <form id="shopline-sync-form" class="fashion-sync-filter" data-fashion-sync-form data-loading="off" data-url="{{ route('fashion-logistics.shopline-orders.sync-status') }}">
                        <div class="form-group">
                            <label for="shopline-sync-keyword">{{ $listText('store_operator') }}</label>
                            <input class="form-control" id="shopline-sync-keyword" name="keyword" placeholder="{{ $listText('handle_operator_hint') }}">
                        </div>
                        <div class="form-group">
                            <label for="shopline-sync-configuration">{{ $listText('sync_configuration') }}</label>
                            <select class="form-control" id="shopline-sync-configuration" name="configuration">
                                <option value="">{{ $listText('all') }}</option>
                                <option value="ready">{{ $listText('ready') }}</option>
                                <option value="missing">{{ $listText('missing') }}</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="shopline-sync-status">{{ $listText('sync_status') }}</label>
                            <select class="form-control" id="shopline-sync-status" name="sync_status">
                                <option value="">{{ $listText('all') }}</option>
                                @foreach($listText('sync_states') as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="shopline-sync-per-page">{{ $listText('per_page') }}</label>
                            <select class="form-control" id="shopline-sync-per-page" name="per_page">
                                @foreach([20, 50, 100] as $size)
                                    <option value="{{ $size }}">{{ $size }}</option>
                                @endforeach
                            </select>
                        </div>
                        <button class="flt-btn flt-primary" type="submit"><i class="fa fa-search" aria-hidden="true"></i> {{ $listText('query') }}</button>
                    </form>
                    <dl class="fashion-sync-summary" data-fashion-sync-summary aria-live="polite" hidden></dl>
                    <div class="fashion-sync-table-scroll" tabindex="0" aria-label="{{ $listText('platform_sync_title', ['platform' => 'Shopline']) }}">
                        <table class="fashion-sync-table">
                            <thead><tr>
                                @foreach(['handle', 'sync_configuration', 'last_sync', 'status', 'store_orders', 'today_orders', 'last_error'] as $key)
                                    <th scope="col">{{ $listText($key) }}</th>
                                @endforeach
                            </tr></thead>
                            <tbody data-fashion-sync-rows></tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <div class="fashion-sync-navigation">
                        <span data-fashion-sync-range></span>
                        <nav data-fashion-sync-pages aria-label="{{ $listText('pagination') }}"></nav>
                    </div>
                    <button type="button" class="flt-btn" data-dismiss="modal">{{ $listText('close') }}</button>
                </div>
            </div>
        </div>
    </div>

@include('portals.fashion-logistics.partials.order-detail-modal', ['detailPlatform' => 'shopline', 'detailTitle' => $listText('detail_title', ['platform' => 'Shopline'])])
</section>
@endsection

@push('scripts')
<script src="{{ $portalOrigin }}/assets/erp/fashion-logistics-order-list.js?v=5"></script>
@endpush
