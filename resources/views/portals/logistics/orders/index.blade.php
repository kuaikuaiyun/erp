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
    $date = static fn ($value): string => $value ? $value->timezone('Asia/Shanghai')->format('Y-m-d H:i:s') : '—';
    $lines = static function ($value) use ($display): array {
        if ($value === null || $value === '') return ['—'];
        return array_map($display, preg_split('/\r?\n/', (string) $value) ?: []);
    };
@endphp

@push('styles')
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/logistics.css?v=20261018-1">
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
        @if($activeMatchBatch)
            @php
                $matchProgress = $activeMatchBatch->progressPayload();
            @endphp
            <div id="tongtu-match-progress" class="logistics-match-progress" data-url="{{ route('logistics.orders.match-progress', $activeMatchBatch) }}" data-completed="{{ $matchProgress['completed'] ? '1' : '0' }}">
                <div class="logistics-match-progress-heading"><span><i class="fa fa-refresh fa-spin"></i> <strong data-match-title>{{ $matchProgress['completed'] ? '通途匹配完成' : ($matchProgress['status'] === 'queued' ? '通途任务排队中' : '通途后台匹配中') }}</strong></span><span data-match-percent>{{ $matchProgress['percent'] }}%</span></div>
                <div class="progress progress-sm active"><div class="progress-bar progress-bar-info progress-bar-striped" role="progressbar" data-match-bar aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $matchProgress['percent'] }}" style="width: {{ $matchProgress['percent'] }}%"></div></div>
                <div class="logistics-match-progress-summary" data-match-summary>已处理 {{ $matchProgress['processed'] }} / {{ $matchProgress['total'] }} 条；成功 {{ $matchProgress['matched'] }} 条，SKU 异常 {{ $matchProgress['sku_exception'] }} 条，通途未找到 {{ $matchProgress['not_found'] }} 条，失败 {{ $matchProgress['failed'] }} 条。</div>
                <div class="logistics-match-progress-note"><i class="fa fa-clock-o"></i> 通途接口正在按每 13 秒 1 次的安全频率处理；可离开本页，返回后仍会显示进度。</div>
                <div class="logistics-match-failures" data-match-failures @if($matchFailureDetails === []) hidden @endif>
                    <details open><summary>失败详情（<span data-match-failure-count>{{ count($matchFailureDetails) }}</span>）</summary><ul data-match-failure-list>@foreach($matchFailureDetails as $failure)<li><strong>Shopify 订单：{{ $failure['shopify_order_number'] }}</strong><span>通途订单：{{ $failure['tongtu_order_number'] }}</span><em>{{ $failure['reason'] }}</em></li>@endforeach</ul></details>
                </div>
            </div>
        @endif
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
                        <div class="logistics-filter-field"><label>退款单号</label><input class="form-control" name="refund_number" value="{{ $filters['refund_number'] }}" placeholder="请输入退款单号"></div>
                        <div class="logistics-filter-field"><label>退款类型</label><input class="form-control" name="refund_type" value="{{ $filters['refund_type'] }}" placeholder="请输入退款类型"></div>
                        <div class="logistics-filter-field"><label>退款备注</label><input class="form-control" name="refund_remark" value="{{ $filters['refund_remark'] }}" placeholder="请输入退款备注"></div>
                        <div class="logistics-filter-field logistics-date-range"><label>退款时间</label><div><input type="date" class="form-control" name="refunded_from" value="{{ $filters['refunded_from'] }}"><span>—</span><input type="date" class="form-control" name="refunded_to" value="{{ $filters['refunded_to'] }}"></div></div>
                        <div class="logistics-filter-field logistics-date-range"><label>下单时间</label><div><input type="date" class="form-control" name="ordered_from" value="{{ $filters['ordered_from'] }}"><span>—</span><input type="date" class="form-control" name="ordered_to" value="{{ $filters['ordered_to'] }}"></div></div>
                        <div class="logistics-filter-field"><label>退款原因</label><input class="form-control" name="refund_reason" value="{{ $filters['refund_reason'] }}" placeholder="请输入退款原因"></div>
                        <div class="logistics-filter-field logistics-date-range"><label>创建时间</label><div><input type="date" class="form-control" name="created_from" value="{{ $filters['created_from'] }}"><span>—</span><input type="date" class="form-control" name="created_to" value="{{ $filters['created_to'] }}"></div></div>
                        <div class="logistics-filter-field"><label>数据状态</label><select class="form-control" name="data_status"><option value="">请选择数据状态</option>@foreach($statusOptions as $value)<option value="{{ $value }}" @selected($filters['data_status'] === $value)>{{ $value }}</option>@endforeach</select></div>
                    </div>
                    <div class="logistics-filter-actions">
                        <button class="btn btn-primary" type="submit"><i class="fa fa-search"></i> 查询</button>
                        @if($hasFilters)<a class="btn btn-default" href="{{ route('logistics.orders.index', ['per_page' => $filters['per_page']]) }}" data-loading="region" data-loading-target=".logistics-orders-scroll">重置</a>@endif
                    </div>
                </form>
            </div>
        </div>

        <form id="tongtu-match-form" method="post" action="{{ route('logistics.orders.match-tongtu') }}">@csrf<input type="hidden" name="return_to" value="{{ request()->fullUrl() }}"></form>
        <form id="tracking-sync-form" method="post" action="{{ route('logistics.orders.sync-tracking') }}">@csrf<input type="hidden" name="return_to" value="{{ request()->fullUrl() }}"></form>
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
                <button class="btn btn-success btn-sm" type="submit" form="tongtu-match-form" id="tongtu-match-button" disabled><i class="fa fa-exchange"></i> 批量匹配通途订单</button>
                <button class="btn btn-info btn-sm" type="submit" form="tracking-sync-form" id="tracking-sync-button" disabled><i class="fa fa-truck"></i> {{ __('logistics.tracking.batch_sync') }}</button>
                <button class="btn btn-warning btn-sm" type="submit" form="assign-store-form" id="assign-store-button" disabled><i class="fa fa-link"></i> 批量归属店铺</button>
                @include('portals.logistics.partials.pagination', ['paginator' => $orders, 'filters' => $filters, 'routeName' => 'logistics.orders.index', 'loadingTarget' => '.logistics-orders-scroll'])
            </div>
            <div class="box-body table-responsive no-padding logistics-orders-scroll">
                <table class="table table-hover logistics-table logistics-orders-table">
                    <thead><tr>
                        <th class="logistics-sticky-left"><input id="order-check-all" type="checkbox" aria-label="全选"></th>
                        <th>编号</th><th>Shopify订单号</th><th>通途订单号</th><th>本订单ID</th><th>店铺名称</th><th>来源Shopify域名</th><th>运营人员</th><th>所属组</th><th>是否加购</th><th>订阅类型</th><th>订阅复购原订单ID</th><th>订阅状态</th><th>通途SKU</th><th>通途配货名称</th><th>出单数量</th><th>出单产品重量</th><th>支付状态</th><th>支付方式</th><th>支付渠道</th><th>实付金额（USD）</th><th>付款时间</th><th>货品成本（USD）</th><th>手续费（USD）</th><th>总运费（USD）</th><th>比例退款（USD）</th><th>比例利润（USD）</th><th>比例利润率</th><th>财务利润（USD）</th><th>财务利润率</th><th>运营利润（USD）</th><th>运营利润率</th><th>渠道退款额（USD）</th><th>Shopify店铺退款额（USD）</th><th>退款单号</th><th>退款金额（USD）</th><th>退款类型</th><th>退款时间</th><th>退款原因</th><th>退款备注</th><th>收款账号</th><th>收款账号邮箱</th><th>收款账号实收金额（USD）</th><th>平台费用（USD）</th><th>净额（USD）</th><th>交易号</th><th>账单号</th><th>买家名称</th><th>Email</th><th>目的地</th><th>物流公司</th><th>跟踪单号</th><th>物流状态</th><th>订单创建时间</th><th>数据状态</th><th>操作</th>
                    </tr></thead>
                    <tbody>
                    @forelse($orders as $order)
                        @php
                            $importRefunds = $order->activeRefundDetails;
                            $importRefundNumbers = $importRefunds->pluck('refund_number')->filter()->implode("\n");
                            $importRefundTypes = $importRefunds->pluck('refund_type')->filter()->implode("\n");
                            $importRefundReasons = $importRefunds->pluck('refund_reason')->filter()->implode("\n");
                            $importRefundRemarks = $importRefunds->pluck('remark')->filter()->implode("\n");
                            $importRefundAmount = $importRefunds->isEmpty() ? null : $importRefunds->sum(fn ($refund) => (float) $refund->refund_amount_usd);
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
                            $subscriptionTypeClass = $subscriptionType === '无订阅'
                                ? 'label-default'
                                : (str_starts_with($subscriptionType, '订阅复购') ? 'logistics-tag-purple' : 'label-primary');
                            $subscriptionStatusClass = $order->subscription_status === '已订阅' ? 'label-success' : 'label-default';
                        @endphp
                        <tr class="{{ $order->hasFailureRow() ? 'logistics-row-failed' : '' }}"
                            data-data-status="{{ trim((string) $order->data_status) }}"
                            data-logistics-status="{{ trim((string) $order->logistics_status) }}"
                            data-tracking-status="{{ trim((string) $order->tracking_sync_status) }}">
                            <td class="logistics-sticky-left"><input class="logistics-order-check" type="checkbox" value="{{ $order->id }}" data-unmatched="{{ $order->assistant_domain_store_id ? '0' : '1' }}" aria-label="选择订单 {{ $order->id }}"></td>
                            <td>{{ $order->id }}</td><td><button class="btn btn-link btn-xs js-logistics-order-detail" type="button" data-order-detail-url="{{ route('logistics.orders.detail', $order) }}">{{ $display($order->shopify_order_number) }}</button></td><td>{{ $display($order->tongtu_order_number) }}</td><td>{{ $display($order->shopify_order_id) }}</td>
                            <td><span class="label {{ $order->assistant_domain_store_id ? 'label-primary' : 'label-danger' }} logistics-tag">{{ $display($order->store_name) }}</span></td><td>{{ $display($order->source_shop_domain) }}</td><td><span class="label label-primary logistics-tag">{{ $display($order->operator_name) }}</span></td><td><span class="label label-info logistics-tag">{{ $display($order->group_names) }}</span></td>
                            <td><span class="label {{ $order->is_add_on === '未加购' ? 'label-default' : 'label-warning' }} logistics-tag">{{ $display($order->is_add_on) }}</span></td><td><span class="label {{ $subscriptionTypeClass }} logistics-tag">{{ $display($order->subscription_type) }}</span></td><td>{{ $display($order->subscription_origin_order_id) }}</td><td><span class="label {{ $subscriptionStatusClass }} logistics-tag">{{ $display($order->subscription_status) }}</span></td>
                            <td class="logistics-multiline {{ $order->data_status === \App\Models\LogisticsOrder::STATUS_SKU_EXCEPTION ? 'text-red' : '' }}">{{ $display($order->tongtu_sku) }}</td>
                            <td class="logistics-multiline">@foreach($lines($order->tongtu_product_name) as $line)<span class="logistics-product-line" title="{{ $line }}">{{ $line }}</span>@endforeach</td>
                            <td class="logistics-multiline">{{ $display($order->sale_quantity) }}</td><td class="logistics-multiline">{{ $display($order->sale_product_weight) }}</td><td><span class="label {{ $order->payment_status === '已付款' ? 'label-success' : 'label-warning' }} logistics-tag">{{ $display($order->payment_status) }}</span></td><td><span class="label label-info logistics-tag">{{ $display($order->payment_method) }}</span></td><td><span class="label label-primary logistics-tag">{{ $display($order->payment_gateway) }}</span></td>
                            <td class="js-money-cell">{{ $display($order->paid_amount_usd) }}</td><td>{{ $date($order->paid_at) }}</td><td class="js-money-cell">{{ $display($order->product_cost_usd) }}</td><td class="js-money-cell">{{ $display($order->handling_fee_usd) }}</td><td class="js-money-cell">{{ $display($order->total_shipping_usd) }}</td><td class="js-money-cell">{{ $display($order->proportional_refund_usd) }}</td><td class="js-money-cell js-profit-cell">{{ $display($order->proportional_profit_usd) }}</td><td class="js-rate-cell js-profit-cell">{{ $display($order->proportional_profit_rate) }}</td><td class="js-money-cell js-profit-cell">{{ $display($order->financial_profit_usd) }}</td><td class="js-rate-cell js-profit-cell">{{ $display($order->financial_profit_rate) }}</td><td class="js-money-cell js-profit-cell">{{ $display($order->operation_profit_usd) }}</td><td class="js-rate-cell js-profit-cell">{{ $display($order->operation_profit_rate) }}</td><td class="js-money-cell">{{ $display($order->channel_refund_amount_usd) }}</td><td class="js-money-cell">{{ $display($order->shopify_refund_amount_usd) }}</td><td class="logistics-multiline">{{ $display($importRefundNumbers) }}</td><td class="js-money-cell">{{ $display($importRefundAmount) }}</td><td class="logistics-multiline">{{ $display($importRefundTypes) }}</td><td>{{ $date($order->refunded_at) }}</td><td class="logistics-ellipsis" title="{{ $importRefundReasons ?: $order->refund_reason }}">{{ $display($importRefundReasons ?: $order->refund_reason) }}</td><td class="logistics-ellipsis" title="{{ $importRefundRemarks }}">{{ $display($importRefundRemarks) }}</td><td><span class="label label-primary logistics-tag logistics-tag-account">{{ $display($order->collection_account) }}</span></td><td>{{ $display($order->collection_account_email) }}</td><td class="js-money-cell">{{ $display($order->received_amount_usd) }}</td><td class="js-money-cell">{{ $display($order->platform_fee_usd) }}</td><td class="js-money-cell js-profit-cell">{{ $display($order->net_amount_usd) }}</td><td>{{ $display($order->transaction_id) }}</td><td class="logistics-payment-reference-cell" title="{{ $paymentReferenceTitle }}"><span>{{ $display($primaryPaymentReference) }}</span>@if($upsellReferenceCount > 0)<small>{{ __('logistics.payment_references.upsell_count', ['count' => $upsellReferenceCount]) }}</small>@endif</td><td>{{ $display($order->buyer_name) }}</td><td>{{ $display($order->buyer_email) }}</td><td>{{ $display($order->destination) }}</td><td class="logistics-multiline">{{ $display($order->shipping_company) }}</td><td class="logistics-multiline">{{ $display($order->tracking_number) }}</td><td><span class="label {{ $order->logisticsStatusClass() }} logistics-tag">{{ $order->logistics_status }}</span></td><td>{{ $date($order->order_created_at) }}</td><td><span class="label {{ $order->statusClass() }} logistics-tag" title="{{ $order->sku_exception_reason }}">{{ $order->data_status }}</span></td><td class="text-nowrap"><button class="btn btn-info btn-xs js-logistics-order-detail" type="button" data-order-detail-url="{{ route('logistics.orders.detail', $order) }}"><i class="fa fa-eye"></i> 查看</button> <a class="btn btn-primary btn-xs" href="{{ route('logistics.orders.edit', $order) }}"><i class="fa fa-pencil"></i> 编辑</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="57" class="text-center text-muted">暂无订单。请先在“Shopify 店铺同步”中执行同步。</td></tr>
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
                            if (cell.classList.contains('js-profit-cell') && Number(value) !== 0) {
                                cell.classList.add(Number(value) < 0 ? 'is-negative' : 'is-positive');
                            }
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
            var matchProgressBox = document.getElementById('tongtu-match-progress');
            if (matchProgressBox && matchProgressBox.dataset.completed !== '1' && window.fetch) {
                var progressUrl = matchProgressBox.dataset.url;
                var progressTitle = matchProgressBox.querySelector('[data-match-title]');
                var progressPercent = matchProgressBox.querySelector('[data-match-percent]');
                var progressBar = matchProgressBox.querySelector('[data-match-bar]');
                var progressSummary = matchProgressBox.querySelector('[data-match-summary]');
                var progressFailures = matchProgressBox.querySelector('[data-match-failures]');
                var progressFailureCount = matchProgressBox.querySelector('[data-match-failure-count]');
                var progressFailureList = matchProgressBox.querySelector('[data-match-failure-list]');
                var progressTimer = null;
                var escapeHtml = function (value) { return String(value || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;'); };
                var renderFailureDetails = function (failures) {
                    if (!progressFailures || !progressFailureCount || !progressFailureList) return;
                    if (!failures || failures.length === 0) { progressFailures.hidden = true; return; }
                    progressFailureCount.textContent = failures.length;
                    progressFailureList.innerHTML = failures.map(function (failure) {
                        return '<li><strong>Shopify 订单：' + escapeHtml(failure.shopify_order_number) + '</strong><span>通途订单：' + escapeHtml(failure.tongtu_order_number) + '</span><em>' + escapeHtml(failure.reason) + '</em></li>';
                    }).join('');
                    progressFailures.hidden = false;
                };
                var updateMatchProgress = function () {
                    window.fetch(progressUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
                        .then(function (response) { return response.ok ? response.json() : Promise.reject(response); })
                        .then(function (progress) {
                            progressTitle.textContent = progress.completed ? '通途匹配完成' : (progress.status === 'queued' ? '通途任务排队中' : '通途后台匹配中');
                            progressPercent.textContent = progress.percent + '%';
                            progressBar.style.width = progress.percent + '%';
                            progressBar.setAttribute('aria-valuenow', progress.percent);
                            progressSummary.textContent = '已处理 ' + progress.processed + ' / ' + progress.total + ' 条；成功 ' + progress.matched + ' 条，SKU 异常 ' + progress.sku_exception + ' 条，通途未找到 ' + progress.not_found + ' 条，失败 ' + progress.failed + ' 条。';
                            renderFailureDetails(progress.failures || []);
                            if (progress.completed) {
                                window.clearInterval(progressTimer);
                                window.setTimeout(function () { window.location.reload(); }, 1200);
                            }
                        })
                        .catch(function () { /* 下一次轮询会自动重试。 */ });
                };
                updateMatchProgress();
                progressTimer = window.setInterval(updateMatchProgress, 3000);
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
            var matchButton = document.getElementById('tongtu-match-button');
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
                matchButton.disabled = selectedItems.length === 0;
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
            document.getElementById('tongtu-match-form').addEventListener('submit', function () { populate(this); });
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
