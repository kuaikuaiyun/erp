@extends('portal.layout')
@section('page_title', __('nbc_orders.menu'))
@php
    $portalOrigin = rtrim(request()->getSchemeAndHttpHost(), '/');
@endphp

@push('styles')
<link rel="stylesheet" href="{{ $portalOrigin }}/css/frontend-list-template.css?v=20261123-19">
<style>
.nbc-page{min-width:0;max-width:100%;color:#31536b}
.nbc-page .box{min-width:0;max-width:100%;border:1px solid #d5e3ed;border-radius:8px;overflow:hidden}
.nbc-page .table-responsive{width:100%;max-width:100%;min-width:0;margin-bottom:0;border:0;overflow-x:auto;-webkit-overflow-scrolling:touch}
.nbc-money strong{color:#175a83}
.nbc-field{display:grid;grid-template-columns:max-content minmax(0,1fr);align-items:baseline;gap:6px;line-height:1.75}
.nbc-field+.nbc-field{margin-top:3px}
.nbc-field small{color:#7890a2;white-space:nowrap;font-size:11px}
.nbc-field-value{min-width:0;overflow-wrap:anywhere}
.nbc-status .nbc-field-value{white-space:nowrap}
.nbc-product{display:flex;align-items:flex-start;gap:9px;min-width:0;padding:6px 0;border-bottom:1px dashed #e2eaf0}
.nbc-product:last-child{border-bottom:0}
.nbc-product-copy{flex:1;min-width:0}
.nbc-product-title{display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:2;max-height:3.4em;overflow:hidden;overflow-wrap:anywhere;line-height:1.7}
.nbc-product-meta{display:flex;gap:7px;min-width:0;margin-top:3px}
.nbc-product-sku{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.nbc-product-quantity{flex:none;white-space:nowrap}
.nbc-muted{color:#7890a2;font-size:11px}
.nbc-money .nbc-muted{margin-top:6px}
</style>
<link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/fashion-logistics-order-list.css?v=5">
@endpush
@section('content')
@php
    $listText = fn ($key, $values = []) => __('fashion_logistics_order_list.'.$key, $values);
    $t = fn ($key, $replace = []) => __('nbc_orders.'.$key, $replace);
    $orderLabels = $t('orders'); $fulfillmentLabels = $t('fulfillments'); $sourceLabels = $t('sources');
@endphp
<section class="content nbc-page">
    <div class="flt fashion-order-list fashion-order-list--nbc" data-search-options="{{ $listText('search_options') }}" data-no-options="{{ $listText('no_options') }}" data-selected-orders="{{ $listText('selected_orders') }}">
    <h1 class="fashion-order-heading">{{ $t('menu') }}</h1>
    @if($errors->any())<div class="alert alert-danger"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <form method="get" action="{{ route('fashion-logistics.nbc-orders.index') }}" class="flt-card flt-filters fashion-order-filter" data-loading="region" data-loading-target="#fashion-order-region" data-loading-text="{{ $listText('loading') }}">
        <input type="hidden" name="per_page" value="{{ $orders->perPage() }}">
        <div class="flt-filter-grid">
        <div class="form-group"><label for="nbc-keyword">{{ $t('keyword') }}</label><input id="nbc-keyword" class="form-control" name="keyword" value="{{ $filters['keyword'] ?? '' }}" placeholder="{{ $t('keyword_hint') }}"></div>
        <div class="form-group"><label for="nbc-store">{{ $t('store') }}</label><select data-fashion-searchable id="nbc-store" class="form-control" name="store_id"><option value="">{{ $t('all_stores') }}</option>@foreach($stores as $store)<option value="{{ $store->id }}" @selected((string)($filters['store_id'] ?? '') === (string)$store->id)>{{ $store->shop_name ?: '#'.$store->id }} · {{ $t('platforms.'.strtolower(trim((string)$store->site_builder))) }}</option>@endforeach</select></div>
        @if($canSelectOperator)<div class="form-group"><label for="nbc-operator">{{ $t('operator') }}</label><select data-fashion-searchable id="nbc-operator" class="form-control" name="operator_id"><option value="">{{ $t('all_operators') }}</option>@foreach($operators as $operator)<option value="{{ $operator->id }}" @selected((string)($filters['operator_id'] ?? '') === (string)$operator->id)>{{ $operator->name ?: $operator->username }}</option>@endforeach</select></div>@endif
        @foreach(['status' => ['order_status', $orderLabels], 'fulfillment_status' => ['fulfillment_status', $fulfillmentLabels], 'marketing_source' => ['marketing_source', $sourceLabels]] as $name => [$label, $options])
            <div class="form-group"><label for="nbc-{{ $name }}">{{ $t($label) }}</label><select id="nbc-{{ $name }}" class="form-control" name="{{ $name }}"><option value="">{{ $t('all') }}</option>@foreach($options as $value => $text)<option value="{{ $value }}" @selected((string)($filters[$name] ?? '') === (string)$value)>{{ $text }}</option>@endforeach</select></div>
        @endforeach
        <div class="flt-time-range" role="group" aria-label="{{ $listText('date_range') }}">
        @foreach(['date_from', 'date_to'] as $name)<div class="form-group"><label for="nbc-{{ $name }}">{{ $t($name) }}</label><input id="nbc-{{ $name }}" class="form-control" type="date" name="{{ $name }}" value="{{ $filters[$name] ?? '' }}"></div>@endforeach
        </div>
        </div>
        <div class="flt-filter-actions">
            <button class="flt-btn flt-primary" type="submit"><i class="fa fa-search" aria-hidden="true"></i> {{ $listText('query') }}</button>
            <a class="flt-btn" href="{{ route('fashion-logistics.nbc-orders.index') }}">{{ $listText('reset') }}</a>
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
            <div class="flt-toolbar-actions"><button type="button" class="flt-btn flt-primary" data-toggle="modal" data-target="#nbc-sync-modal"><i class="fa fa-refresh" aria-hidden="true"></i> {{ $t('sync_title') }}</button></div>
        </div>
        <div class="flt-table-region" id="fashion-order-region">
        <div class="flt-table-scroll fashion-order-scroll{{ $orders->count() === 0 ? ' is-empty' : '' }}"><table class="fashion-order-table"><thead><tr><th class="flt-check" scope="col"><input type="checkbox" data-fashion-select-all aria-label="{{ $listText('check_page') }}" @disabled($orders->count() === 0)></th>@foreach(['order_info','logistics_info','customer_info','marketing_source','products','amount','actions'] as $key)<th scope="col">{{ $t($key) }}</th>@endforeach</tr></thead><tbody>
        @foreach($orders as $order)
            <tr>
                <td class="flt-check"><input type="checkbox" data-fashion-select-order aria-label="{{ $listText('check_order', ['order' => $order->order_number ?: $order->external_order_id]) }}"></td>
                <td>@foreach(['date' => $order->order_created_at?->format('Y-m-d H:i:s'), 'platform' => $t('platforms.'.$order->source_platform), 'store' => $order->store_name, 'domain' => $order->source_domain, 'owner' => ($order->store?->ownerUser?->name ?: $order->store?->ownerUser?->username), 'user' => $order->store?->user_name, 'team' => implode(' / ', $order->store?->team ?? []), 'order_number' => $order->order_number, 'external_id' => $order->external_order_id] as $key => $value)<div class="nbc-field"><small>{{ $t($key) }}:</small><span class="nbc-field-value{{ in_array($key, ['order_number', 'store'], true) ? ' fashion-order-emphasis' : '' }}" title="{{ $value ?: '—' }}">{{ $value ?: '—' }}</span></div>@endforeach</td>
                <td class="nbc-status"><div class="nbc-field"><small>{{ $t('order_status') }}:</small><span class="nbc-field-value">{{ $orderLabels[$order->status] ?? $t('unknown') }}</span></div><div class="nbc-field"><small>{{ $t('payment_status') }}:</small><span class="nbc-field-value">{{ $t('payments.230') }}</span></div><div class="nbc-field"><small>{{ $t('fulfillment_status') }}:</small><span class="nbc-field-value">{{ $fulfillmentLabels[$order->fulfillment_status] ?? $t('unknown') }}</span></div></td>
                <td>@foreach(['customer' => $order->customer_name, 'email' => $order->customer_email, 'phone' => $order->customer_phone] as $key => $value)<div class="nbc-field"><small>{{ $t($key) }}:</small><span class="nbc-field-value" title="{{ $value ?: '—' }}">{{ $value ?: '—' }}</span></div>@endforeach</td>
                <td>{{ $sourceLabels[$order->marketing_source] ?? $t('unknown') }}</td>
                <td class="nbc-products">@forelse($order->items as $item)<div class="nbc-product"><div class="nbc-product-copy"><div class="nbc-product-title" title="{{ $item->product_name ?: $t('unnamed') }}">{{ $item->product_name ?: $t('unnamed') }}</div><div class="nbc-product-meta nbc-muted"><span class="nbc-product-sku" title="{{ $item->sku }}">{{ $t('sku') }}: {{ $item->sku ?: '—' }}</span><span class="nbc-product-quantity">×{{ rtrim(rtrim($item->quantity, '0'), '.') }}</span></div></div></div>@empty{{ $t('no_items') }}@endforelse @if($order->items_count > 2)<div class="nbc-muted">{{ $t('more_items', ['count' => $order->items_count - 2]) }}</div>@endif</td>
                <td class="nbc-money"><strong>{{ $order->currency }} {{ number_format((float)$order->total_amount, 2) }}</strong>@if((float)$order->discount_amount > 0)<div class="nbc-muted">{{ $t('discount') }}: {{ $order->discount_amount }}</div>@endif</td>
                <td class="nbc-operations"><button type="button" class="flt-row-action" data-fashion-order-detail data-url="{{ route('fashion-logistics.nbc-orders.show', $order) }}">{{ $t('detail') }}</button></td>
            </tr>
        @endforeach
        </tbody></table>
            @if($orders->count() === 0)
                <div class="flt-empty-host">
                    <div class="flt-empty" role="status">
                        <i class="fa fa-search" aria-hidden="true"></i>
                        <h4>{{ $listText('empty_title') }}</h4>
                        <p>{{ $listText('empty_hint') }}</p>
                    </div>
                </div>
            @endif
        </div>
        </div>
        @include('portals.fashion-logistics.partials.order-list-pagination')
    </section>
    </div>

    @include('portals.fashion-logistics.partials.order-detail-modal', ['detailPlatform' => 'nbc', 'detailTitle' => $t('detail_title')])
    <div class="modal fashion-order-modal fashion-sync-modal" id="nbc-sync-modal" tabindex="-1" role="dialog" aria-labelledby="nbc-sync-title">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-label="{{ $listText('close') }}"><span aria-hidden="true">&times;</span></button>
                    <h4 class="modal-title" id="nbc-sync-title"><i class="fa fa-refresh" aria-hidden="true"></i> {{ $t('sync_title') }}</h4>
                    <p>{{ $t('sync_hint') }}</p>
                </div>
                <div class="modal-body">
                    <form id="nbc-sync-form" class="fashion-sync-filter" data-fashion-sync-form data-loading="off" data-url="{{ route('fashion-logistics.nbc-orders.sync-status') }}">
                        <div class="form-group">
                            <label for="nbc-sync-keyword">{{ $t('sync_keyword') }}</label>
                            <input class="form-control" id="nbc-sync-keyword" name="keyword" >
                        </div>
                        <div class="form-group">
                            <label for="nbc-sync-configuration">{{ $t('configuration') }}</label>
                            <select class="form-control" id="nbc-sync-configuration" name="configuration">
                                <option value="">{{ $listText('all') }}</option>
                                <option value="ready">{{ $t('ready') }}</option>
                                <option value="missing">{{ $t('missing') }}</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="nbc-sync-status">{{ $listText('sync_status') }}</label>
                            <select class="form-control" id="nbc-sync-status" name="sync_status">
                                <option value="">{{ $listText('all') }}</option>
                                @foreach($t('sync_states') as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="nbc-sync-per-page">{{ $listText('per_page') }}</label>
                            <select class="form-control" id="nbc-sync-per-page" name="per_page">
                                @foreach([20, 50, 100] as $size)
                                    <option value="{{ $size }}">{{ $size }}</option>
                                @endforeach
                            </select>
                        </div>
                        <button class="flt-btn flt-primary" type="submit"><i class="fa fa-search" aria-hidden="true"></i> {{ $listText('query') }}</button>
                    </form>
                    <dl class="fashion-sync-summary" data-fashion-sync-summary aria-live="polite" hidden></dl>
                    <div class="fashion-sync-table-scroll" tabindex="0" aria-label="{{ $t('sync_title') }}">
                        <table class="fashion-sync-table">
                            <thead><tr>
                                @foreach(['store', 'platform', 'operator', 'configuration', 'last_success', 'sync_status', 'total_count', 'today_count', 'last_error'] as $key)
                                    <th scope="col">{{ $t($key) }}</th>
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
</section>
@endsection
@push('scripts')
<script src="{{ $portalOrigin }}/assets/erp/fashion-logistics-order-list.js?v=5"></script>
@endpush
