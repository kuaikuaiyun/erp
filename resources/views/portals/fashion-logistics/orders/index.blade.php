@extends('portal.layout')

@section('page_title', __('fashion_logistics_order_list.xshoppy'))

@php
    $portalOrigin = rtrim(request()->getSchemeAndHttpHost(), '/');
@endphp

@push('styles')
<link rel="stylesheet" href="{{ $portalOrigin }}/css/frontend-list-template.css?v=20261123-19">
<style>
.xsl-page{--ink:#16324a;--muted:#71869a;--line:#dce7ef;--blue:#2189bd}
.xsl-page .box{border:0;border-radius:14px;box-shadow:0 5px 22px rgba(28,69,96,.08);overflow:hidden}
.xsl-order-no,.xsl-store,.xsl-customer strong,.xsl-money{color:#175d89}
.xsl-order-no{font-size:14px;font-weight:700}
.xsl-store{font-size:13px;font-weight:700}
.xsl-sub,.xsl-handle{margin-top:5px;color:#7890a1;font-size:11px;line-height:1.45}
.xsl-field-line{display:flex;align-items:baseline;gap:5px;max-width:100%;line-height:1.55}
.xsl-field-line+.xsl-field-line{margin-top:3px}
.xsl-field-label{flex:none;color:#6f8ca2;font-size:11px;font-weight:600;white-space:nowrap}
.xsl-field-value{min-width:0;overflow:hidden;color:#265e84;font-size:12px;font-weight:700;text-overflow:ellipsis;white-space:nowrap}
.xsl-field-value.muted{color:#7890a1;font-size:11px;font-weight:400}
.xsl-status{color:#265e84;font-size:12px;font-weight:700}
.xsl-products{min-width:300px;padding-top:10px!important;padding-bottom:10px!important}
.xsl-product{display:flex;min-height:45px;gap:9px;align-items:center;padding:6px 0;border-bottom:1px dashed #d7e4ec}
.xsl-product:last-child{border-bottom:0}
.xsl-product-name{max-width:235px;overflow:hidden;color:#35617d;font-size:12px;font-weight:600;text-overflow:ellipsis;white-space:nowrap}
.xsl-sku{margin-top:3px;color:#6d93ad;font-size:11px}
.xsl-money{font-size:13px;font-weight:700;white-space:nowrap}
.xsl-page-gap{display:inline-flex;align-items:center;justify-content:center;height:31px;min-width:31px;padding:0 9px;border-radius:6px;font-size:12px}
.xsl-page-gap{color:#8a9aa5}
</style>
<link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/fashion-logistics-order-list.css?v=5">
@endpush

@section('content')
@php
    $listText = fn ($key, $values = []) => __('fashion_logistics_order_list.'.$key, $values);
    $statusLabels = $listText('xshoppy_statuses');
    $syncLabels = ['pending' => $listText('waiting_sync')] + $listText('sync_states');
@endphp
<section class="content xsl-page">
    <div class="flt fashion-order-list fashion-order-list--xsl" data-search-options="{{ $listText('search_options') }}" data-no-options="{{ $listText('no_options') }}" data-selected-orders="{{ $listText('selected_orders') }}">
    <h1 class="fashion-order-heading">{{ $listText('xshoppy') }}</h1>
    <form method="get" action="{{ route('fashion-logistics.orders.index') }}" class="flt-card flt-filters fashion-order-filter" data-loading="region" data-loading-target="#fashion-order-region" data-loading-text="{{ $listText('loading') }}">
        <input type="hidden" name="per_page" value="{{ $orders->perPage() }}">
        <div class="flt-filter-grid">
        <div class="form-group"><label for="xsl-list-keyword">{{ $listText('keyword') }}</label><input class="form-control" id="xsl-list-keyword" name="keyword" value="{{ $filters['keyword'] ?? '' }}" placeholder="{{ $listText('keyword_hint') }}"></div>
        <div class="form-group"><label for="xsl-list-store_id">{{ $listText('store') }}</label><select data-fashion-searchable class="form-control" id="xsl-list-store_id" name="store_id"><option value="">{{ $listText('all_xshoppy_stores') }}</option>@foreach($stores as $store)<option value="{{ $store->id }}" @selected((string)($filters['store_id'] ?? '') === (string)$store->id)>{{ $store->shop_name ?: $store->domain }}</option>@endforeach</select></div>
        <div class="form-group"><label for="xsl-list-operator_id">{{ $listText('operator') }}</label><select data-fashion-searchable class="form-control" id="xsl-list-operator_id" name="operator_id"><option value="">{{ $listText('all_operators') }}</option>@foreach($operators as $operator)<option value="{{ $operator->id }}" @selected((string)($filters['operator_id'] ?? '') === (string)$operator->id)>{{ $operator->name ?: $operator->username }}</option>@endforeach</select></div>
        <div class="form-group"><label for="xsl-list-status">{{ $listText('order_status') }}</label><select class="form-control" id="xsl-list-status" name="status"><option value="" @selected(($filters['status'] ?? '') === '')>{{ $listText('all_order_statuses') }}</option><option value="completed" @selected(($filters['status'] ?? '') === 'completed')>{{ $statusLabels['completed'] }}</option><option value="processing" @selected(($filters['status'] ?? '') === 'processing')>{{ $statusLabels['processing'] }}</option></select></div>
        <div class="form-group"><label for="xsl-list-store_keyword">{{ $listText('store_keyword') }}</label><input class="form-control" id="xsl-list-store_keyword" name="store_keyword" value="{{ $filters['store_keyword'] ?? '' }}" placeholder="{{ $listText('store_keyword_hint') }}"></div>
        <div class="flt-time-range" role="group" aria-label="{{ $listText('date_range') }}">
        <div class="form-group"><label for="xsl-list-date_from">{{ $listText('date_from') }}</label><input class="form-control" type="date" id="xsl-list-date_from" name="date_from" value="{{ $filters['date_from'] ?? '' }}"></div>
        <div class="form-group"><label for="xsl-list-date_to">{{ $listText('date_to') }}</label><input class="form-control" type="date" id="xsl-list-date_to" name="date_to" value="{{ $filters['date_to'] ?? '' }}"></div>
        </div>
        </div>
        <div class="flt-filter-actions">
            <button class="flt-btn flt-primary" type="submit"><i class="fa fa-search" aria-hidden="true"></i> {{ $listText('query') }}</button>
            <a class="flt-btn" href="{{ route('fashion-logistics.orders.index') }}">{{ $listText('reset') }}</a>
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
            <div class="flt-toolbar-actions"><button class="flt-btn flt-primary" type="button" data-toggle="modal" data-target="#xshoppy-sync-modal"><i class="fa fa-refresh" aria-hidden="true"></i> {{ $listText('sync_title') }}</button></div>
        </div>
        <div class="flt-table-region" id="fashion-order-region">
        <div class="flt-table-scroll fashion-order-scroll{{ $orders->count() === 0 ? ' is-empty' : '' }}"><table class="fashion-order-table"><thead><tr><th class="flt-check" scope="col"><input type="checkbox" data-fashion-select-all aria-label="{{ $listText('check_page') }}" @disabled($orders->count() === 0)></th><th scope="col">{{ $listText('order_info') }}</th><th scope="col">{{ $listText('logistics_info') }}</th><th scope="col">{{ $listText('customer_info') }}</th><th scope="col">{{ $listText('marketing_source') }}</th><th scope="col">{{ $listText('products') }}</th><th scope="col">{{ $listText('amount') }}</th><th scope="col">{{ $listText('actions') }}</th></tr></thead><tbody>
        @foreach($orders as $order)
            <tr>
                <td class="flt-check"><input type="checkbox" data-fashion-select-order aria-label="{{ $listText('check_order', ['order' => $order->order_number ?: $order->external_order_id]) }}"></td>
                <td><div class="xsl-field-line"><span class="xsl-field-label">{{ $listText('order_number') }}：</span><span class="xsl-field-value xsl-order-no">#{{ $order->order_number ?: $order->external_order_id }}</span></div><div class="xsl-field-line"><span class="xsl-field-label">{{ $listText('external_id') }}：</span><span class="xsl-field-value muted">{{ $order->external_order_id }}</span></div><div class="xsl-field-line"><span class="xsl-field-label">{{ $listText('source_created_at') }}：</span><span class="xsl-field-value muted">{{ $order->source_order_created_at?->format('Y-m-d H:i:s') ?: '—' }}{{ $order->source_order_timezone ? ' · '.$order->source_order_timezone : '' }}</span></div><div class="xsl-field-line"><span class="xsl-field-label">{{ $listText('stored_created_at') }}：</span><span class="xsl-field-value muted">{{ $order->order_created_at?->format('Y-m-d H:i:s') ?: '—' }}{{ $listText('china_time') }}</span></div></td>
                <td><div class="xsl-field-line"><span class="xsl-field-label">{{ $listText('store') }}：</span><span class="xsl-field-value xsl-store">{{ $order->store_name ?: $listText('unnamed_store') }}</span></div><div class="xsl-field-line"><span class="xsl-field-label">{{ $listText('domain') }}：</span><span class="xsl-field-value muted">{{ $order->source_domain ?: '—' }}</span></div><div class="xsl-field-line"><span class="xsl-field-label">{{ $listText('order_status') }}：</span><span class="xsl-status">{{ $statusLabels[$order->status] ?? ($order->status ?: $listText('unknown')) }}</span></div></td>
                <td><div class="xsl-field-line"><span class="xsl-field-label">{{ $listText('customer') }}：</span><span class="xsl-field-value">{{ $order->customer_name ?: '—' }}</span></div><div class="xsl-field-line"><span class="xsl-field-label">{{ $listText('contact') }}：</span><span class="xsl-field-value muted">{{ $order->customer_email ?: $order->customer_phone ?: '—' }}</span></div></td>
                <td><div class="xsl-field-line"><span class="xsl-field-label">{{ $listText('platform') }}：</span><span class="xsl-field-value">XShoppy</span></div><div class="xsl-field-line"><span class="xsl-field-label">{{ $listText('payment_method') }}：</span><span class="xsl-field-value muted">{{ $order->payment_method_title ?: $order->payment_method ?: '—' }}</span></div></td>
                <td class="xsl-products">
                    @forelse($order->items as $item)
                        <div class="xsl-product">
                                                        <div><div class="xsl-product-name" title="{{ $item->product_name }}"><span class="xsl-field-label">{{ $listText('product') }}：</span>{{ $item->product_name ?: $listText('unnamed_product') }}</div><div class="xsl-sku"><span class="xsl-field-label">{{ $listText('sku') }}：</span>{{ $item->sku ?: $listText('no_sku') }} · ×{{ rtrim(rtrim(number_format((float)$item->quantity,4,'.',''),'0'),'.') }}</div></div>
                        </div>
                    @empty
                        <span class="text-muted">{{ $listText('no_items') }}</span>
                    @endforelse
                    @if($order->items_count > 2)
                        <div class="xsl-sub">{{ $listText('more_items', ['count' => $order->items_count - 2]) }}</div>
                    @endif
                </td>
                <td><div class="xsl-field-line"><span class="xsl-field-label">{{ $listText('total') }}：</span><span class="xsl-field-value xsl-money">{{ $order->currency ?: '—' }} {{ number_format((float)$order->total_amount,2) }}</span></div>
                    @if((float)$order->discount_amount > 0)
                        <div class="xsl-field-line"><span class="xsl-field-label">{{ $listText('discount') }}：</span><span class="xsl-field-value muted">{{ number_format((float)$order->discount_amount,2) }}</span></div>
                    @endif
                </td>
                <td><button class="flt-row-action" type="button" data-fashion-order-detail data-url="{{ route('fashion-logistics.orders.show', $order) }}">{{ $listText('detail') }}</button></td>
            </tr>
        @endforeach
        </tbody></table>
            @if($orders->count() === 0)
                <div class="flt-empty-host">
                    <div class="flt-empty" role="status">
                        <i class="fa fa-search" aria-hidden="true"></i>
                        <h4>{{ $listText('empty_title') }}</h4>
                        <p>{{ $listText('empty_xshoppy') }}</p>
                    </div>
                </div>
            @endif
        </div>
        </div>
        @include('portals.fashion-logistics.partials.order-list-pagination')
    </section>
    </div>

    <div class="modal fashion-order-modal fashion-sync-modal" id="xshoppy-sync-modal" tabindex="-1" role="dialog" aria-labelledby="xshoppy-sync-title">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-label="{{ $listText('close') }}"><span aria-hidden="true">&times;</span></button>
                    <h4 class="modal-title" id="xshoppy-sync-title"><i class="fa fa-refresh" aria-hidden="true"></i> {{ $listText('platform_sync_title', ['platform' => 'XShoppy']) }}</h4>
                    <p>{{ $listText('xshoppy_sync_hint') }}</p>
                </div>
                <div class="modal-body">
                    <div class="fashion-sync-table-scroll" tabindex="0" aria-label="{{ $listText('sync_title') }}">
                        <table class="fashion-sync-table">
                            <thead><tr>
                                @foreach(['store_domain', 'sync_status', 'stored_orders', 'created_updated', 'last_success', 'last_error'] as $key)
                                    <th scope="col">{{ $listText($key) }}</th>
                                @endforeach
                            </tr></thead>
                            <tbody>
                            @forelse($syncStates as $state)
                                <tr>
                                    <td><strong>{{ $state->store?->shop_name ?: $state->store?->domain ?: $listText('store_number', ['id' => $state->assistant_domain_store_id]) }}</strong><span class="fashion-sync-subtitle">{{ $state->store?->domain ?: '—' }}</span></td>
                                    <td><span class="fashion-sync-badge" data-state="{{ $state->status }}">{{ $syncLabels[$state->status] ?? $state->status }}</span></td>
                                    <td>{{ $state->stored_orders_count }}</td>
                                    <td>{{ $state->last_created_count }} / {{ $state->last_updated_count }}</td>
                                    <td>{{ $state->last_succeeded_at?->format('Y-m-d H:i:s') ?: $listText('never_synced') }}</td>
                                    <td>
                                        @if(filled($state->last_error))
                                            <details class="fashion-sync-error">
                                                <summary><span>{{ $state->last_error }}</span></summary>
                                                <div>{{ $state->last_error }}</div>
                                            </details>
                                        @else
                                            —
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="fashion-sync-message">{{ $listText('no_sync_records') }}</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="flt-btn" data-dismiss="modal">{{ $listText('close') }}</button>
                </div>
            </div>
        </div>
    </div>

    @include('portals.fashion-logistics.partials.order-detail-modal', ['detailPlatform' => 'xshoppy', 'detailTitle' => $listText('detail_title', ['platform' => 'XShoppy'])])
</section>
@endsection

@push('scripts')
<script src="{{ $portalOrigin }}/assets/erp/fashion-logistics-order-list.js?v=5"></script>
@endpush
