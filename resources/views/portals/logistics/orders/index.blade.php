@extends('portal.layout')

@section('page_title', '物流订单')

@php
    $portalOrigin = rtrim(request()->getSchemeAndHttpHost(), '/');
    $hasFilters = collect($filters)->except(['per_page'])->contains(fn ($value) => $value !== '');
    $display = static function ($value): string {
        if ($value === null || $value === '') return '—';
        if (is_string($value) && preg_match('/^-?\d+\.\d{1,4}$/', $value)) return rtrim(rtrim($value, '0'), '.');
        return (string) $value;
    };
    $weight = static function ($value) use ($display): string {
        if ($value === null || $value === '' || !is_numeric($value)) return $display($value);
        return $display($value).' '.__('logistics.orders.sale_product_detail_labels.weight_unit');
    };
    $date = static fn ($value): string => $value ? $value->timezone('Asia/Shanghai')->format('Y-m-d H:i:s') : '—';
    $lines = static function ($value) use ($display): array {
        if ($value === null || $value === '') return ['—'];
        return array_map($display, preg_split('/\r?\n/', (string) $value) ?: []);
    };
@endphp

@push('styles')
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/logistics.css?v=20261024-8">
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/logistics-order-detail.css?v=20261018-1">
@endpush

@section('content')
    <section class="content-header">
        <h1>物流订单 <small>Shopify 订单与通途人工匹配</small></h1>
    </section>

    <section class="content logistics-page">
        @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
        @if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif
        @if($errors->has('scope'))<div class="alert alert-danger">{{ $errors->first('scope') }}</div>@endif
        @if($errors->has('refund_file'))<div class="alert alert-danger">{{ $errors->first('refund_file') }}</div>@endif
        @if($activeTrackingBatch)
            @php
                $trackingProgress = $activeTrackingBatch->progressPayload();
            @endphp
            <div id="tracking-sync-progress" class="logistics-match-progress" data-url="{{ route('logistics.orders.tracking-progress', $activeTrackingBatch) }}" data-completed="{{ $trackingProgress['completed'] ? '1' : '0' }}" data-title-queued="{{ __('logistics.tracking.queued') }}" data-title-processing="{{ __('logistics.tracking.processing') }}" data-title-completed="{{ __('logistics.tracking.completed') }}" data-summary-template="{{ __('logistics.tracking.summary', ['processed' => '{processed}', 'total' => '{total}', 'success' => '{success}', 'skipped' => '{skipped}', 'failed' => '{failed}']) }}">
                <div class="logistics-match-progress-heading"><span><i class="fa fa-refresh fa-spin"></i> <strong data-tracking-title>{{ $trackingProgress['completed'] ? __('logistics.tracking.completed') : ($trackingProgress['status'] === 'queued' ? __('logistics.tracking.queued') : __('logistics.tracking.processing')) }}</strong></span><span data-tracking-percent>{{ $trackingProgress['percent'] }}%</span></div>
                <div class="progress progress-sm active"><div class="progress-bar progress-bar-aqua progress-bar-striped" role="progressbar" data-tracking-bar aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $trackingProgress['percent'] }}" style="width: {{ $trackingProgress['percent'] }}%"></div></div>
                <div class="logistics-match-progress-summary" data-tracking-summary>{{ __('logistics.tracking.summary', $trackingProgress) }}</div>
                <div class="logistics-match-progress-note"><i class="fa fa-clock-o"></i> {{ __('logistics.tracking.note') }}</div>
                <div class="logistics-match-failures" data-tracking-failures @if($trackingFailureDetails === []) hidden @endif>
                    <details open><summary>{{ __('logistics.tracking.failure_details', ['count' => count($trackingFailureDetails)]) }}</summary><ul data-tracking-failure-list>@foreach($trackingFailureDetails as $failure)<li><strong>{{ __('logistics.tracking.order', ['number' => $failure['shopify_order_number']]) }}</strong><span>{{ __('logistics.tracking.tracking_number', ['number' => $failure['tracking_number']]) }}</span><em>{{ $failure['reason'] }}</em></li>@endforeach</ul></details>
                </div>
            </div>
        @endif

        <div class="box box-solid logistics-filter-box">
            <div class="box-header with-border"><h3 class="box-title"><i class="fa fa-filter"></i> 筛选条件</h3></div>
            <div class="box-body">
                <form method="get" action="{{ route('logistics.orders.index') }}" class="logistics-filter-form" data-loading="region" data-loading-target=".logistics-orders-scroll">
                    <input type="hidden" name="per_page" value="{{ $filters['per_page'] }}">
                    <div class="logistics-filter-grid">
                        <div class="logistics-filter-field"><label>编号</label><input class="form-control" name="id" value="{{ $filters['id'] }}" placeholder="请输入编号"></div>
                        <div class="logistics-filter-field"><label>Shopify订单号</label><input class="form-control" name="shopify_order_number" value="{{ $filters['shopify_order_number'] }}" placeholder="请输入 Shopify订单号"></div>
                        <div class="logistics-filter-field"><label>通途订单号</label><input class="form-control" name="tongtu_order_number" value="{{ $filters['tongtu_order_number'] }}" placeholder="请输入通途订单号"></div>
                        <div class="logistics-filter-field"><label>订单ID</label><input class="form-control" name="shopify_order_id" value="{{ $filters['shopify_order_id'] }}" placeholder="请输入订单ID"></div>
                        <div class="logistics-filter-field"><label>原始SKU</label><input class="form-control" name="shopify_sku" value="{{ $filters['shopify_sku'] }}" placeholder="请输入 Shopify 原始SKU"></div>
                        <div class="logistics-filter-field"><label>店铺名称</label><select class="form-control" name="store_name"><option value="">请选择店铺</option>@foreach($filterOptions['stores'] as $value)<option value="{{ $value }}" @selected($filters['store_name'] === $value)>{{ $value }}</option>@endforeach</select></div>
                        <div class="logistics-filter-field"><label>运营人员</label><select class="form-control" name="operator_name"><option value="">请选择运营人员</option>@foreach($filterOptions['operators'] as $value)<option value="{{ $value }}" @selected($filters['operator_name'] === $value)>{{ $value }}</option>@endforeach</select></div>
                        <div class="logistics-filter-field"><label>所属组</label><select class="form-control" name="group_names"><option value="">请选择所属组</option>@foreach($filterOptions['groups'] as $value)<option value="{{ $value }}" @selected($filters['group_names'] === $value)>{{ $value }}</option>@endforeach</select></div>
                        <div class="logistics-filter-field"><label>人员状态</label><select class="form-control" name="staff_status"><option value="">请选择人员状态</option><option value="正常" @selected($filters['staff_status'] === '正常')>正常</option></select></div>
                        <div class="logistics-filter-field"><label>买家名称</label><input class="form-control" name="buyer_name" value="{{ $filters['buyer_name'] }}" placeholder="请输入买家名称"></div>
                        <div class="logistics-filter-field"><label>Email</label><input class="form-control" name="buyer_email" value="{{ $filters['buyer_email'] }}" placeholder="请输入 Email"></div>
                        <div class="logistics-filter-field"><label>电话</label><input class="form-control" name="buyer_phone" value="{{ $filters['buyer_phone'] }}" placeholder="请输入电话"></div>
                        <div class="logistics-filter-field"><label>来源Shopify域名</label><select class="form-control" name="source_shop_domain"><option value="">请选择来源域名</option>@foreach($filterOptions['sourceDomains'] as $value)<option value="{{ $value }}" @selected($filters['source_shop_domain'] === $value)>{{ $value }}</option>@endforeach</select></div>
                        <div class="logistics-filter-field"><label>店铺归属状态</label><select class="form-control" name="store_assignment_status"><option value="">全部</option><option value="assigned" @selected($filters['store_assignment_status'] === 'assigned')>已归属</option><option value="unmatched" @selected($filters['store_assignment_status'] === 'unmatched')>店铺匹配失败</option></select></div>
                        <div class="logistics-filter-field"><label>是否加购</label><select class="form-control" name="is_add_on"><option value="">请选择是否加购</option><option value="已加购" @selected($filters['is_add_on'] === '已加购')>已加购</option><option value="未加购" @selected($filters['is_add_on'] === '未加购')>未加购</option></select></div>
                        <div class="logistics-filter-field"><label>订阅类型</label><select class="form-control" name="subscription_type"><option value="">请选择订阅类型</option>@foreach($filterOptions['subscriptionTypes'] as $value)<option value="{{ $value }}" @selected($filters['subscription_type'] === $value)>{{ $value }}</option>@endforeach</select></div>
                        <div class="logistics-filter-field"><label>支付方式</label><select class="form-control" name="payment_method"><option value="">请选择支付方式</option>@foreach($filterOptions['paymentMethods'] as $value)<option value="{{ $value }}" @selected($filters['payment_method'] === $value)>{{ $value }}</option>@endforeach</select></div>
                        <div class="logistics-filter-field"><label>支付渠道</label><select class="form-control" name="payment_gateway"><option value="">请选择支付渠道</option>@foreach($filterOptions['paymentGateways'] as $value)<option value="{{ $value }}" @selected($filters['payment_gateway'] === $value)>{{ $value }}</option>@endforeach</select></div>
                        <div class="logistics-filter-field"><label>收款账号邮箱</label><input class="form-control" name="collection_account_email" value="{{ $filters['collection_account_email'] }}" placeholder="请输入收款账号邮箱"></div>
                        <div class="logistics-filter-field"><label>交易号</label><input class="form-control" name="transaction_id" value="{{ $filters['transaction_id'] }}" placeholder="请输入交易号"></div>
                        <div class="logistics-filter-field"><label>账单号</label><input class="form-control" name="bill_number" value="{{ $filters['bill_number'] }}" placeholder="请输入账单号"></div>
                        <div class="logistics-filter-field"><label>物流状态</label><select class="form-control" name="logistics_status"><option value="">请选择状态</option>@foreach($trackingOptions as $value)<option value="{{ $value }}" @selected($filters['logistics_status'] === $value)>{{ $value }}</option>@endforeach</select></div>
                        <div class="logistics-filter-field"><label>实际退款编号</label><input class="form-control" name="refund_number" value="{{ $filters['refund_number'] }}" placeholder="PayPal 退款交易号或信用卡退款流水号"></div>
                        <div class="logistics-filter-field"><label>实际退款原因</label><input class="form-control" name="refund_reason" value="{{ $filters['refund_reason'] }}" placeholder="请输入 PayPal 或信用卡退款原因"></div>
                        <div class="logistics-filter-field"><label>数据状态</label><select class="form-control" name="data_status"><option value="">请选择数据状态</option>@foreach($statusOptions as $value)<option value="{{ $value }}" @selected($filters['data_status'] === $value)>{{ $value }}</option>@endforeach</select></div>
                    </div>
                    <div class="logistics-filter-date-row">
                        <div class="logistics-filter-field logistics-date-range"><label>实际退款时间</label><div><input type="date" class="form-control" name="refunded_from" value="{{ $filters['refunded_from'] }}"><span>—</span><input type="date" class="form-control" name="refunded_to" value="{{ $filters['refunded_to'] }}"></div></div>
                        <div class="logistics-filter-field logistics-date-range"><label>下单时间</label><div><input type="date" class="form-control" name="ordered_from" value="{{ $filters['ordered_from'] }}"><span>—</span><input type="date" class="form-control" name="ordered_to" value="{{ $filters['ordered_to'] }}"></div></div>
                        <div class="logistics-filter-field logistics-date-range"><label>付款时间</label><div><input type="date" class="form-control" name="paid_from" value="{{ $filters['paid_from'] }}"><span>—</span><input type="date" class="form-control" name="paid_to" value="{{ $filters['paid_to'] }}"></div></div>
                    </div>
                    <div class="logistics-filter-actions">
                        <button class="btn btn-primary" type="submit"><i class="fa fa-search"></i> 查询</button>
                        @if($hasFilters)<a class="btn btn-default" href="{{ route('logistics.orders.index', ['per_page' => $filters['per_page']]) }}" data-loading="region" data-loading-target=".logistics-orders-scroll">重置</a>@endif
                    </div>
                </form>
            </div>
        </div>

        <form id="tracking-sync-form" method="post" action="{{ route('logistics.orders.sync-tracking') }}">@csrf<input type="hidden" name="return_to" value="{{ request()->fullUrl() }}"></form>
        <form id="tongtu-sku-retry-form" method="post" action="{{ route('logistics.orders.tongtu-skus.retry') }}">@csrf<input type="hidden" name="return_to" value="{{ request()->fullUrl() }}"></form>
        <form id="tongtu-sku-refresh-form" method="post" action="{{ route('logistics.orders.tongtu-skus.refresh') }}">@csrf<input type="hidden" name="return_to" value="{{ request()->fullUrl() }}"></form>
        <form id="assign-store-form" method="post" action="{{ route('logistics.orders.assign-store') }}" class="logistics-batch-form">
            @csrf
            <input type="hidden" name="return_to" value="{{ request()->fullUrl() }}">
            <select class="form-control input-sm" name="assistant_domain_store_id" required>
                <option value="">选择归属 Shopify 店铺</option>
                @foreach($shopifyStores as $store)<option value="{{ $store->id }}">{{ $store->shop_name }}（{{ $store->user_name ?: '未设置运营人员' }}）</option>@endforeach
            </select>
        </form>

        <div class="box box-solid logistics-table-box">
            <div class="box-header with-border logistics-list-toolbar">
                <h3 class="box-title">订单列表</h3>
                <button class="btn btn-default btn-sm" type="button" data-toggle="modal" data-target="#logistics-order-export-modal"><i class="fa fa-download"></i> 导出</button>
                <button class="btn btn-info btn-sm" type="button" data-toggle="modal" data-target="#logistics-refund-import-modal"><i class="fa fa-upload"></i> {{ __('logistics.refund_import.open_button') }}</button>
                <button class="btn btn-warning btn-sm" type="submit" form="tongtu-sku-retry-form" id="tongtu-sku-retry-button" disabled><i class="fa fa-repeat"></i> {{ __('logistics.orders.sku_mapping_actions.retry') }}</button>
                <button class="btn btn-default btn-sm" type="submit" form="tongtu-sku-refresh-form" id="tongtu-sku-refresh-button" disabled><i class="fa fa-refresh"></i> {{ __('logistics.orders.sku_mapping_actions.refresh') }}</button>
                <button class="btn btn-info btn-sm" type="submit" form="tracking-sync-form" id="tracking-sync-button" disabled><i class="fa fa-truck"></i> {{ __('logistics.tracking.batch_sync') }}</button>
                <button class="btn btn-warning btn-sm" type="submit" form="assign-store-form" id="assign-store-button" disabled><i class="fa fa-link"></i> 批量归属店铺</button>
                @include('portals.logistics.partials.pagination', ['paginator' => $orders, 'filters' => $filters, 'routeName' => 'logistics.orders.index', 'loadingTarget' => '.logistics-orders-scroll'])
            </div>
            <div class="box-body table-responsive no-padding logistics-orders-scroll">
                <table class="table table-hover logistics-table logistics-orders-table">
                    <thead><tr>
                        <th class="logistics-sticky-left"><input id="order-check-all" type="checkbox" aria-label="全选"></th>
                        <th>编号</th><th class="logistics-order-identifiers-heading">{{ __('logistics.orders.identifier_information') }}</th><th class="logistics-store-information-heading">{{ __('logistics.orders.store_information') }}</th><th class="logistics-subscription-information-heading">{{ __('logistics.orders.subscription_information') }}</th><th>订阅复购原订单ID</th><th class="logistics-sale-product-details-heading">{{ __('logistics.orders.sale_product_details') }}</th><th class="logistics-payment-information-heading">{{ __('logistics.orders.payment_information') }}</th><th class="logistics-amount-information-heading">{{ __('logistics.orders.amount_information') }}</th><th class="logistics-order-time-information-heading">{{ __('logistics.orders.order_time_information') }}</th><th>{{ __('logistics.orders.refunds.paypal_actual') }}</th><th>{{ __('logistics.orders.refunds.credit_card_actual') }}</th><th>交易号</th><th>账单号</th><th class="logistics-buyer-information-heading">{{ __('logistics.orders.buyer_information') }}</th><th class="logistics-shipping-information-heading">{{ __('logistics.orders.shipping_information') }}</th><th>数据状态</th><th>操作</th>
                    </tr></thead>
                    <tbody>
                    @forelse($orders as $order)
                        @php
                            $paypalRefunds = $order->paypalRefunds;
                            $paypalRefundCount = $paypalRefunds->count();
                            $paypalRefundDetails = $paypalRefunds->map(function ($refund) use ($date): array {
                                $reason = trim((string) ($refund->manual_reason ?: $refund->refund_reason ?: $refund->eventLabel()));
                                $reason = $reason !== '' ? $reason : __('logistics.orders.refunds.reason_not_provided');
                                $amount = number_format((float) $refund->refund_amount_usd, 2);

                                return [
                                    'reason' => $reason,
                                    'amount' => $amount,
                                    'title' => __('logistics.orders.refunds.paypal_item', [
                                    'number' => $refund->refund_transaction_id ?: __('logistics.common.not_available'),
                                    'amount' => $amount,
                                    'reason' => $reason,
                                    'time' => $date($refund->refunded_at ?: $refund->created_at),
                                    ]),
                                ];
                            });
                            $paypalRefundTitle = $paypalRefundDetails->pluck('title')->implode("\n");
                            $creditCardRefunds = $order->creditCardRefunds;
                            $creditCardRefundCount = $creditCardRefunds->count();
                            $creditCardRefundDetails = $creditCardRefunds->map(function ($refund) use ($date): array {
                                $reason = trim((string) $refund->refund_reason);
                                $reason = $reason !== '' ? $reason : __('logistics.orders.refunds.reason_not_provided');
                                $amount = number_format((float) $refund->refund_amount_usd, 2);

                                return [
                                    'reason' => $reason,
                                    'amount' => $amount,
                                    'title' => __('logistics.orders.refunds.credit_card_item', [
                                    'bill' => $refund->bill_number ?: __('logistics.common.not_available'),
                                    'reference' => $refund->refund_reference ?: __('logistics.common.not_available'),
                                    'amount' => $amount,
                                    'reason' => $reason,
                                    'time' => $date($refund->last_imported_at ?: $refund->created_at),
                                    ]),
                                ];
                            });
                            $creditCardRefundTitle = $creditCardRefundDetails->pluck('title')->implode("\n");
                            $paymentReferences = $order->paymentReferences
                                ->filter(fn ($reference) => filled($reference->reference_number))
                                ->values();
                            $primaryPaymentReference = $paymentReferences->firstWhere('reference_type', \App\Models\LogisticsOrderPaymentReference::TYPE_PRIMARY)?->reference_number
                                ?: $order->bill_number;
                            $upsellReferenceCount = $paymentReferences
                                ->where('reference_type', \App\Models\LogisticsOrderPaymentReference::TYPE_UPSELL)
                                ->count();
                            $paymentReferenceTitle = $paymentReferences
                                ->pluck('reference_number')
                                ->filter()
                                ->implode("\n");
                            if ($paymentReferenceTitle === '' && filled($primaryPaymentReference)) {
                                $paymentReferenceTitle = (string) $primaryPaymentReference;
                            }
                            $subscriptionType = (string) $order->subscription_type;
                            $hasAddOn = $order->is_add_on === '已加购';
                            $hasSubscription = filled($subscriptionType) && $subscriptionType !== '无订阅';
                            $subscriptionStatus = trim((string) $order->subscription_status);
                            $subscriptionStatusTone = str_contains($subscriptionStatus, '已')
                                ? 'is-positive'
                                : ((str_contains($subscriptionStatus, '未') || str_contains($subscriptionStatus, '无')) ? 'is-negative' : 'is-neutral');
                            $collectionAccountEmailMatched = filled($order->collection_account_email);
                            $collectionAccountEmail = $collectionAccountEmailMatched
                                ? $order->collection_account_email
                                : __('logistics.orders.payment_identifiers.unmatched');
                            $saleProductDetails = $order->items->map(function ($item) use ($display, $weight): array {
                                $hasTongtuName = filled($item->tongtu_product_name);

                                return [
                                    'product_code' => $display($item->tongtu_product_code),
                                    'shopify_sku' => $display($item->shopify_sku),
                                    'tongtu_sku' => $display($item->tongtu_sku),
                                    'name' => $display($hasTongtuName ? $item->tongtu_product_name : $item->product_title),
                                    'name_label' => $hasTongtuName ? 'name' : 'shopify_product',
                                    'quantity' => $display($item->quantity),
                                    'goods_weight' => $weight($item->tongtu_goods_weight_gram),
                                    'package_weight' => $weight($item->tongtu_package_weight_gram),
                                ];
                            })->all();
                            if ($saleProductDetails === []) {
                                $productCodeLines = $lines($order->tongtu_product_code);
                                $skuLines = $lines($order->tongtu_sku);
                                $productNameLines = $lines($order->tongtu_product_name);
                                $quantityLines = $lines($order->sale_quantity);
                                $weightLines = $lines($order->sale_product_weight);
                                $saleProductDetailCount = max(count($productCodeLines), count($skuLines), count($productNameLines), count($quantityLines), count($weightLines));
                                for ($detailIndex = 0; $detailIndex < $saleProductDetailCount; $detailIndex++) {
                                    $saleProductDetails[] = [
                                        'product_code' => $productCodeLines[$detailIndex] ?? '—',
                                        'shopify_sku' => '—',
                                        'tongtu_sku' => $skuLines[$detailIndex] ?? '—',
                                        'name' => $productNameLines[$detailIndex] ?? '—',
                                        'name_label' => 'name',
                                        'quantity' => $quantityLines[$detailIndex] ?? '—',
                                        'goods_weight' => $weight($weightLines[$detailIndex] ?? null),
                                        'package_weight' => '—',
                                    ];
                                }
                            }
                        @endphp
                        <tr data-data-status="{{ trim((string) $order->data_status) }}"
                            data-logistics-status="{{ trim((string) $order->logistics_status) }}"
                            data-tracking-status="{{ trim((string) $order->tracking_sync_status) }}">
                            <td class="logistics-sticky-left"><input class="logistics-order-check" type="checkbox" value="{{ $order->id }}" data-unmatched="{{ $order->assistant_domain_store_id ? '0' : '1' }}" aria-label="选择订单 {{ $order->id }}"></td>
                            <td>{{ $order->id }}</td><td class="logistics-order-identifiers"><div class="logistics-order-identifier-line"><span class="logistics-order-identifier-label">{{ __('logistics.orders.identifiers.shopify_order_number') }}</span><span class="logistics-order-identifier-value">#{{ $display($order->shopify_order_number) }}</span></div><div class="logistics-order-identifier-line"><span class="logistics-order-identifier-label">{{ __('logistics.orders.identifiers.shopify_order_id') }}</span><span class="logistics-order-identifier-value">{{ $display($order->shopify_order_id) }}</span></div><div class="logistics-order-identifier-line"><span class="logistics-order-identifier-label">{{ __('logistics.orders.identifiers.tongtu_order_number') }}</span><span class="logistics-order-identifier-value">{{ $display($order->tongtu_order_number) }}</span></div></td>
                            <td class="logistics-store-information"><div class="logistics-store-information-line"><span class="logistics-store-information-label">{{ __('logistics.orders.store_identifiers.store_name') }}</span><span class="logistics-store-information-value">{{ $display($order->store_name) }}</span></div><div class="logistics-store-information-line"><span class="logistics-store-information-label">{{ __('logistics.orders.store_identifiers.login_address') }}</span><span class="logistics-store-information-value">{{ $display($order->source_shop_domain) }}</span></div><div class="logistics-store-information-line"><span class="logistics-store-information-label">{{ __('logistics.orders.store_identifiers.operator_name') }}</span><span class="logistics-store-information-value">{{ $display($order->operator_name) }}</span></div><div class="logistics-store-information-line"><span class="logistics-store-information-label">{{ __('logistics.orders.store_identifiers.group_names') }}</span><span class="logistics-store-information-value">{{ $display($order->group_names) }}</span></div></td>
                            <td class="logistics-subscription-information"><div class="logistics-subscription-information-line"><span class="logistics-subscription-information-label">{{ __('logistics.orders.subscription_identifiers.add_on') }}</span><span class="logistics-subscription-information-value {{ $hasAddOn ? 'is-positive' : 'is-negative' }}">{{ $hasAddOn ? __('logistics.orders.subscription_identifiers.added') : __('logistics.orders.subscription_identifiers.not_added') }}</span></div><div class="logistics-subscription-information-line"><span class="logistics-subscription-information-label">{{ __('logistics.orders.subscription_identifiers.subscription_type') }}</span><span class="logistics-subscription-information-value {{ $hasSubscription ? 'is-subscription' : 'is-negative' }}">{{ $display($order->subscription_type) }}</span></div><div class="logistics-subscription-information-line"><span class="logistics-subscription-information-label">{{ __('logistics.orders.subscription_identifiers.subscription_status') }}</span><span class="logistics-subscription-information-value {{ $subscriptionStatusTone }}">{{ $display($order->subscription_status) }}</span></div></td><td>{{ $display($order->subscription_origin_order_id) }}</td>
                            <td class="logistics-sale-product-details">@foreach($saleProductDetails as $saleProductDetail)<div class="logistics-sale-product-detail-item"><div class="logistics-sale-product-detail-code {{ $saleProductDetail['product_code'] === '—' ? 'is-empty' : '' }}"><b>{{ __('logistics.orders.sale_product_detail_labels.product_code') }}</b><span title="{{ $saleProductDetail['product_code'] === '—' ? __('logistics.orders.sale_product_detail_labels.unmatched') : $saleProductDetail['product_code'] }}">{{ $saleProductDetail['product_code'] === '—' ? __('logistics.orders.sale_product_detail_labels.unmatched') : $saleProductDetail['product_code'] }}</span></div><div class="logistics-sale-product-detail-skus"><span class="logistics-sale-product-detail-sku is-original"><b>{{ __('logistics.orders.sale_product_detail_labels.shopify_sku') }}</b><span title="{{ $saleProductDetail['shopify_sku'] }}">{{ $saleProductDetail['shopify_sku'] }}</span></span><span class="logistics-sale-product-detail-sku {{ $saleProductDetail['tongtu_sku'] === '—' ? 'is-empty' : 'is-matched' }}"><b>{{ __('logistics.orders.sale_product_detail_labels.tongtu_sku') }}</b><span title="{{ $saleProductDetail['tongtu_sku'] === '—' ? __('logistics.orders.sale_product_detail_labels.unmatched') : $saleProductDetail['tongtu_sku'] }}">{{ $saleProductDetail['tongtu_sku'] === '—' ? __('logistics.orders.sale_product_detail_labels.unmatched') : $saleProductDetail['tongtu_sku'] }}</span></span></div><div class="logistics-sale-product-detail-metrics"><span><b>{{ __('logistics.orders.sale_product_detail_labels.quantity') }}</b>{{ $saleProductDetail['quantity'] }}</span><span><b>{{ __('logistics.orders.sale_product_detail_labels.goods_weight') }}</b>{{ $saleProductDetail['goods_weight'] }}</span><span><b>{{ __('logistics.orders.sale_product_detail_labels.package_weight') }}</b>{{ $saleProductDetail['package_weight'] }}</span></div><div class="logistics-sale-product-detail-name"><b>{{ __('logistics.orders.sale_product_detail_labels.'.$saleProductDetail['name_label']) }}</b><span title="{{ $saleProductDetail['name'] }}">{{ $saleProductDetail['name'] }}</span></div></div>@endforeach</td>
                            <td class="logistics-payment-information">
                                <div class="logistics-payment-information-line"><span class="logistics-payment-information-label">{{ __('logistics.orders.payment_identifiers.status') }}</span><span class="logistics-payment-information-value {{ $order->payment_status === '已付款' ? 'is-paid' : 'is-pending' }}">{{ $display($order->payment_status) }}</span></div>
                                <div class="logistics-payment-information-line"><span class="logistics-payment-information-label">{{ __('logistics.orders.payment_identifiers.method') }}</span><span class="logistics-payment-information-value">{{ $display($order->payment_method) }}</span></div>
                                <div class="logistics-payment-information-line"><span class="logistics-payment-information-label">{{ __('logistics.orders.payment_identifiers.gateway') }}</span><span class="logistics-payment-information-value">{{ $display($order->payment_gateway) }}</span></div>
                                <div class="logistics-payment-information-line"><span class="logistics-payment-information-label">{{ __('logistics.orders.payment_identifiers.collection_account') }}</span><span class="logistics-payment-information-value">{{ $display($order->collection_account) }}</span></div>
                                <div class="logistics-payment-information-line"><span class="logistics-payment-information-label">{{ __('logistics.orders.payment_identifiers.collection_account_email') }}</span><span class="logistics-payment-information-value {{ $collectionAccountEmailMatched ? '' : 'is-unmatched' }}" title="{{ $collectionAccountEmail }}">{{ $collectionAccountEmail }}</span></div>
                            </td>
                            <td class="logistics-amount-information"><div class="logistics-amount-information-line"><span class="logistics-amount-information-label">{{ __('logistics.orders.amount_identifiers.paid_amount') }}</span><span class="logistics-amount-information-value js-money-cell">{{ $display($order->paid_amount_usd) }}</span></div><div class="logistics-amount-information-line"><span class="logistics-amount-information-label">{{ __('logistics.orders.amount_identifiers.total_shipping') }}</span><span class="logistics-amount-information-value js-money-cell">{{ $display($order->total_shipping_usd) }}</span></div></td>
                            <td class="logistics-order-time-information"><div class="logistics-order-time-information-line"><span class="logistics-order-time-information-label">{{ __('logistics.orders.order_time_identifiers.stored') }}</span><span class="logistics-order-time-information-value">{{ $date($order->created_at) }}</span></div><div class="logistics-order-time-information-line"><span class="logistics-order-time-information-label">{{ __('logistics.orders.order_time_identifiers.ordered') }}</span><span class="logistics-order-time-information-value">{{ $date($order->order_created_at) }}</span></div><div class="logistics-order-time-information-line"><span class="logistics-order-time-information-label">{{ __('logistics.orders.order_time_identifiers.paid') }}</span><span class="logistics-order-time-information-value">{{ $date($order->paid_at) }}</span></div></td>
                            <td class="logistics-refund-summary" @if($paypalRefundCount > 0) title="{{ $paypalRefundTitle }}" @endif>
                                @if($paypalRefundCount > 0)
                                    <div class="logistics-refund-list">
                                        @foreach($paypalRefundDetails as $refundDetail)
                                            <div class="logistics-refund-item" title="{{ $refundDetail['title'] }}">
                                                <span class="logistics-refund-reason" title="{{ $refundDetail['reason'] }}">{{ $refundDetail['reason'] }}</span>
                                                <span class="logistics-refund-amount">US${{ $refundDetail['amount'] }}</span>
                                            </div>
                                        @endforeach
                                    </div>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td class="logistics-refund-summary" @if($creditCardRefundCount > 0) title="{{ $creditCardRefundTitle }}" @endif>
                                @if($creditCardRefundCount > 0)
                                    <div class="logistics-refund-list">
                                        @foreach($creditCardRefundDetails as $refundDetail)
                                            <div class="logistics-refund-item" title="{{ $refundDetail['title'] }}">
                                                <span class="logistics-refund-reason" title="{{ $refundDetail['reason'] }}">{{ $refundDetail['reason'] }}</span>
                                                <span class="logistics-refund-amount">US${{ $refundDetail['amount'] }}</span>
                                            </div>
                                        @endforeach
                                    </div>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td>{{ $display($order->transaction_id) }}</td><td class="logistics-payment-reference-cell" title="{{ $paymentReferenceTitle }}"><span>{{ $display($primaryPaymentReference) }}</span>@if($upsellReferenceCount > 0)<small>{{ __('logistics.payment_references.upsell_count', ['count' => $upsellReferenceCount]) }}</small>@endif</td>
                            <td class="logistics-buyer-information"><div class="logistics-buyer-information-line"><span class="logistics-buyer-information-label">{{ __('logistics.orders.buyer_identifiers.name') }}</span><span class="logistics-buyer-information-value" title="{{ $display($order->buyer_name) }}">{{ $display($order->buyer_name) }}</span></div><div class="logistics-buyer-information-line"><span class="logistics-buyer-information-label">{{ __('logistics.orders.buyer_identifiers.email') }}</span><span class="logistics-buyer-information-value" title="{{ $display($order->buyer_email) }}">{{ $display($order->buyer_email) }}</span></div><div class="logistics-buyer-information-line"><span class="logistics-buyer-information-label">{{ __('logistics.orders.buyer_identifiers.destination') }}</span><span class="logistics-buyer-information-value" title="{{ $display($order->destination) }}">{{ $display($order->destination) }}</span></div></td>
                            <td class="logistics-shipping-information"><div class="logistics-shipping-information-line"><span class="logistics-shipping-information-label">{{ __('logistics.orders.shipping_identifiers.company') }}</span><span class="logistics-shipping-information-value" title="{{ $display($order->shipping_company) }}">{{ $display($order->shipping_company) }}</span></div><div class="logistics-shipping-information-line"><span class="logistics-shipping-information-label">{{ __('logistics.orders.shipping_identifiers.tracking_number') }}</span><span class="logistics-shipping-information-value" title="{{ $display($order->tracking_number) }}">{{ $display($order->tracking_number) }}</span></div><div class="logistics-shipping-information-line"><span class="logistics-shipping-information-label">{{ __('logistics.orders.shipping_identifiers.status') }}</span><span class="logistics-shipping-information-value"><span class="label {{ $order->logisticsStatusClass() }} logistics-tag">{{ $order->logistics_status }}</span></span></div></td>
                            <td><span class="label {{ $order->statusClass() }} logistics-tag" title="{{ $order->sku_exception_reason }}">{{ $order->data_status }}</span></td><td class="text-nowrap"><button class="btn btn-info btn-xs js-logistics-order-detail" type="button" data-order-detail-url="{{ route('logistics.orders.detail', $order) }}"><i class="fa fa-eye"></i> 查看</button> <a class="btn btn-primary btn-xs" href="{{ route('logistics.orders.edit', $order) }}"><i class="fa fa-pencil"></i> 编辑</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="18" class="text-center text-muted">暂无订单。请先在“Shopify 店铺同步”中执行同步。</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            <div class="box-footer clearfix">
                @include('portals.logistics.partials.pagination', ['paginator' => $orders, 'filters' => $filters, 'routeName' => 'logistics.orders.index', 'loadingTarget' => '.logistics-orders-scroll'])
            </div>
        </div>

        <div class="modal fade" id="logistics-order-export-modal" tabindex="-1" role="dialog" aria-labelledby="logistics-order-export-title">
            <div class="modal-dialog" role="document">
                <form method="post" action="{{ route('logistics.orders.export') }}" class="modal-content" id="logistics-order-export-form" data-loading="download">
                    @csrf
                    @foreach ($filters as $filterName => $filterValue)
                        <input type="hidden" name="{{ $filterName }}" value="{{ $filterValue }}">
                    @endforeach
                    <div class="modal-header">
                        <button type="button" class="close" data-dismiss="modal" aria-label="关闭"><span aria-hidden="true">&times;</span></button>
                        <h4 class="modal-title" id="logistics-order-export-title"><i class="fa fa-download"></i> 导出物流订单</h4>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-info logistics-export-notice"><i class="fa fa-info-circle"></i> 导出文件包含全部订单业务字段，请仅在受控环境中使用和保管。</div>
                        <p class="logistics-modal-section-title">导出范围</p>
                        <label class="logistics-export-scope">
                            <input type="radio" name="scope" value="selected" checked>
                            <span><strong>导出当前页已选记录</strong><small id="logistics-order-export-selected-count">当前已选择 0 条记录。</small></span>
                        </label>
                        <label class="logistics-export-scope">
                            <input type="radio" name="scope" value="filtered">
                            <span><strong>导出当前筛选结果全部记录</strong><small>当前筛选结果共 {{ $orders->total() }} 条记录。</small></span>
                        </label>
                        <div id="logistics-order-export-selected-inputs"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-default" data-dismiss="modal">取消</button>
                        <button type="submit" class="btn btn-primary"><i class="fa fa-download"></i> 确认导出</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="modal fade" id="logistics-refund-import-modal" tabindex="-1" role="dialog" aria-labelledby="logistics-refund-import-title">
            <div class="modal-dialog" role="document">
                <form method="post" action="{{ route('logistics.orders.import-refunds') }}" enctype="multipart/form-data" class="modal-content" id="logistics-refund-import-form" data-loading="button">
                    @csrf
                    <input type="hidden" name="return_to" value="{{ request()->fullUrl() }}">
                    <div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="{{ __('logistics.common.close') }}"><span aria-hidden="true">&times;</span></button><h4 class="modal-title" id="logistics-refund-import-title"><i class="fa fa-upload"></i> {{ __('logistics.refund_import.title') }}</h4></div>
                    <div class="modal-body logistics-refund-import-body">
                        <p class="text-muted">{{ __('logistics.refund_import.modal.notice') }}</p>
                        <div class="logistics-import-header-rule"><i class="fa fa-list-alt"></i><span>{{ __('logistics.refund_import.modal.headers') }}</span></div>
                        @if(session('logistics_refund_import_error_report'))
                            <div class="alert alert-warning">
                                <i class="fa fa-exclamation-circle"></i> {{ __('logistics.refund_import.messages.error_report_ready') }}
                                <a class="btn btn-warning btn-xs pull-right" href="{{ session('logistics_refund_import_error_report') }}" data-loading="download"><i class="fa fa-download"></i> {{ __('logistics.refund_import.messages.download_error_report') }}</a>
                            </div>
                        @endif
                        <label class="logistics-import-dropzone" for="logistics-refund-import-file" data-file-selected-template="{{ __('logistics.refund_import.modal.file_selected', ['name' => '__name__']) }}">
                            <input id="logistics-refund-import-file" type="file" name="refund_file" accept=".csv,.xls,.xlsx" required>
                            <i class="fa fa-cloud-upload"></i>
                            <strong>{{ __('logistics.refund_import.modal.dropzone') }}</strong>
                            <span>{{ __('logistics.refund_import.modal.file_hint') }}</span>
                            <em id="logistics-refund-import-file-name"></em>
                        </label>
                        <div class="logistics-import-footer-note">
                            <span><i class="fa fa-info-circle"></i> {{ __('logistics.refund_import.modal.rule_note') }}</span>
                            <a href="{{ route('logistics.orders.refund-import-template') }}" data-loading="download"><i class="fa fa-file-excel-o"></i> {{ __('logistics.refund_import.modal.template_download') }}</a>
                        </div>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">{{ __('logistics.common.cancel') }}</button><button type="submit" class="btn btn-success"><i class="fa fa-upload"></i> {{ __('logistics.refund_import.modal.confirm') }}</button></div>
                </form>
            </div>
        </div>
        @include('portals.logistics.partials.order-detail-modal')
    </section>
@endsection

@push('scripts')
        <script>
        (function () {
            var formatFinancialCells = function () {
                var fixed = function (value) {
                    var normalized = String(value || '').trim().replace(/,/g, '');
                    return /^-?\d+(?:\.\d+)?$/.test(normalized) ? Number(normalized).toFixed(2) : null;
                };
                var headings = document.querySelectorAll('.logistics-orders-table thead th');

                document.querySelectorAll('.logistics-orders-table tbody tr').forEach(function (row) {
                    row.querySelectorAll('.js-money-cell, .js-rate-cell').forEach(function (cell) {
                        var value = fixed(cell.textContent);
                        var isRate = cell.classList.contains('js-rate-cell');
                        cell.classList.add('logistics-number-cell');
                        if (headings[cell.cellIndex]) headings[cell.cellIndex].classList.add('logistics-number-heading');
                        if (value !== null) {
                            cell.textContent = isRate ? value + '%' : '$' + value;
                        } else {
                            cell.classList.add('logistics-empty-value');
                        }
                    });
                    Array.prototype.forEach.call(row.children, function (cell) {
                        if (cell.textContent.trim() === '—') cell.classList.add('logistics-empty-value');
                    });
                });
            };

            formatFinancialCells();
            var refundImportZone = document.querySelector('.logistics-import-dropzone');
            var refundImportInput = document.getElementById('logistics-refund-import-file');
                var refundImportFilename = document.getElementById('logistics-refund-import-file-name');
            if (refundImportZone && refundImportInput && refundImportFilename) {
                var showRefundImportFilename = function (file) {
                    var template = refundImportZone.dataset.fileSelectedTemplate || '__name__';
                    refundImportFilename.textContent = file ? template.replace('__name__', file.name) : '';
                    refundImportZone.classList.toggle('is-filled', Boolean(file));
                };
                refundImportInput.addEventListener('change', function () {
                    showRefundImportFilename(this.files && this.files[0]);
                });
                ['dragenter', 'dragover'].forEach(function (eventName) {
                    refundImportZone.addEventListener(eventName, function (event) {
                        event.preventDefault();
                        event.stopPropagation();
                        refundImportZone.classList.add('is-dragover');
                    });
                });
                ['dragleave', 'dragend'].forEach(function (eventName) {
                    refundImportZone.addEventListener(eventName, function (event) {
                        event.preventDefault();
                        event.stopPropagation();
                        refundImportZone.classList.remove('is-dragover');
                    });
                });
                refundImportZone.addEventListener('drop', function (event) {
                    event.preventDefault();
                    event.stopPropagation();
                    refundImportZone.classList.remove('is-dragover');
                    var files = event.dataTransfer && event.dataTransfer.files;
                    if (!files || !files.length) return;
                    refundImportInput.files = files;
                    showRefundImportFilename(files[0]);
                });
            }
            var trackingProgressBox = document.getElementById('tracking-sync-progress');
            if (trackingProgressBox && trackingProgressBox.dataset.completed !== '1' && window.fetch) {
                var trackingProgressUrl = trackingProgressBox.dataset.url;
                var trackingProgressTimer = null;
                var trackingTitle = trackingProgressBox.querySelector('[data-tracking-title]');
                var trackingPercent = trackingProgressBox.querySelector('[data-tracking-percent]');
                var trackingBar = trackingProgressBox.querySelector('[data-tracking-bar]');
                var trackingSummary = trackingProgressBox.querySelector('[data-tracking-summary]');
                var trackingSummaryText = function (progress) {
                    return String(trackingProgressBox.dataset.summaryTemplate || '')
                        .replace('{processed}', progress.processed)
                        .replace('{total}', progress.total)
                        .replace('{success}', progress.success)
                        .replace('{skipped}', progress.skipped)
                        .replace('{failed}', progress.failed);
                };
                var updateTrackingProgress = function () {
                    window.fetch(trackingProgressUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
                        .then(function (response) { return response.ok ? response.json() : Promise.reject(response); })
                        .then(function (progress) {
                            trackingTitle.textContent = progress.completed ? trackingProgressBox.dataset.titleCompleted : (progress.status === 'queued' ? trackingProgressBox.dataset.titleQueued : trackingProgressBox.dataset.titleProcessing);
                            trackingPercent.textContent = progress.percent + '%';
                            trackingBar.style.width = progress.percent + '%';
                            trackingBar.setAttribute('aria-valuenow', progress.percent);
                            trackingSummary.textContent = trackingSummaryText(progress);
                            if (progress.completed) {
                                window.clearInterval(trackingProgressTimer);
                                window.setTimeout(function () { window.location.reload(); }, 1200);
                            }
                        })
                        .catch(function () { /* 下一次轮询会自动重试。 */ });
                };
                updateTrackingProgress();
                trackingProgressTimer = window.setInterval(updateTrackingProgress, 3000);
            }
            var all = document.getElementById('order-check-all');
            var skuRetryButton = document.getElementById('tongtu-sku-retry-button');
            var skuRefreshButton = document.getElementById('tongtu-sku-refresh-button');
            var trackingButton = document.getElementById('tracking-sync-button');
            var assignButton = document.getElementById('assign-store-button');
            var exportForm = document.getElementById('logistics-order-export-form');
            var exportSelectedScope = exportForm.querySelector('input[name="scope"][value="selected"]');
            var exportFilteredScope = exportForm.querySelector('input[name="scope"][value="filtered"]');
            var exportSelectedCount = document.getElementById('logistics-order-export-selected-count');
            var exportSelectedInputs = document.getElementById('logistics-order-export-selected-inputs');
            var checks = function () { return Array.prototype.slice.call(document.querySelectorAll('.logistics-order-check')); };
            var selected = function () { return checks().filter(function (item) { return item.checked; }); };
            var populate = function (form) {
                form.querySelectorAll('input[data-selection]').forEach(function (input) { input.remove(); });
                selected().forEach(function (item) {
                    var input = document.createElement('input');
                    input.type = 'hidden'; input.name = 'selected_ids[]'; input.value = item.value; input.setAttribute('data-selection', '1');
                    form.appendChild(input);
                });
            };
            var refresh = function () {
                var selectedItems = selected();
                if (skuRetryButton) skuRetryButton.disabled = selectedItems.length === 0;
                if (skuRefreshButton) skuRefreshButton.disabled = selectedItems.length === 0;
                if (trackingButton) trackingButton.disabled = selectedItems.length === 0;
                assignButton.disabled = selectedItems.length === 0 || !selectedItems.some(function (item) { return item.dataset.unmatched === '1'; });
                all.checked = checks().length > 0 && selectedItems.length === checks().length;
                all.indeterminate = selectedItems.length > 0 && !all.checked;
                exportSelectedCount.textContent = '当前已选择 ' + selectedItems.length + ' 条记录。';
                exportSelectedScope.disabled = selectedItems.length === 0;
                if (selectedItems.length === 0 && exportSelectedScope.checked) exportFilteredScope.checked = true;
            };
            all.addEventListener('change', function () { checks().forEach(function (item) { item.checked = all.checked; }); refresh(); });
            checks().forEach(function (item) { item.addEventListener('change', refresh); });
            var skuRetryForm = document.getElementById('tongtu-sku-retry-form');
            if (skuRetryForm) skuRetryForm.addEventListener('submit', function () { populate(this); });
            var skuRefreshForm = document.getElementById('tongtu-sku-refresh-form');
            if (skuRefreshForm) skuRefreshForm.addEventListener('submit', function () { populate(this); });
            var trackingForm = document.getElementById('tracking-sync-form');
            if (trackingForm) trackingForm.addEventListener('submit', function () { populate(this); });
            document.getElementById('assign-store-form').addEventListener('submit', function () { populate(this); });
            exportForm.addEventListener('submit', function (event) {
                var scope = exportForm.querySelector('input[name="scope"]:checked').value;
                var selectedItems = selected();
                exportSelectedInputs.innerHTML = '';

                if (scope === 'selected' && selectedItems.length === 0) {
                    event.preventDefault();
                    window.alert('请先选择当前页需要导出的订单记录。');
                    return;
                }

                if (scope === 'selected') {
                    selectedItems.forEach(function (item) {
                        var input = document.createElement('input');
                        input.type = 'hidden'; input.name = 'selected_ids[]'; input.value = item.value;
                        exportSelectedInputs.appendChild(input);
                    });
                }
            });
            if (window.jQuery) window.jQuery('#logistics-order-export-modal').on('show.bs.modal', refresh);
            @if($errors->has('scope'))
            if (window.jQuery) window.jQuery('#logistics-order-export-modal').modal('show');
            @endif
            @if($errors->has('refund_file') || session('logistics_refund_import_failed') || session('logistics_refund_import_has_errors'))
            if (window.jQuery) window.jQuery('#logistics-refund-import-modal').modal('show');
            @endif
            refresh();
        }());
    </script>
    <script src="{{ $portalOrigin }}/assets/erp/logistics-order-detail.js?v=20261014-1"></script>
@endpush
