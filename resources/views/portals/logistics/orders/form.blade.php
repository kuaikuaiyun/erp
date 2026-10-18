@extends('portal.layout')

@section('page_title', '编辑物流订单')

@php
    $portalOrigin = rtrim(request()->getSchemeAndHttpHost(), '/');
    $labels = [
        'shopify_order_id' => '本订单ID', 'shopify_order_number' => 'Shopify订单号', 'tongtu_order_number' => '通途订单号',
        'store_name' => '店铺名称', 'operator_name' => '运营人员', 'group_names' => '所属组',
        'staff_status' => '人员状态', 'buyer_name' => '买家名称', 'buyer_email' => 'Email', 'buyer_phone' => '电话',
        'is_add_on' => '是否加购', 'subscription_type' => '订阅类型', 'subscription_origin_order_id' => '订阅复购原订单ID',
        'subscription_status' => '订阅状态', 'payment_status' => '支付状态', 'payment_method' => '支付方式', 'payment_gateway' => '支付渠道',
        'paid_amount_usd' => '实付金额（USD）', 'paid_at' => '付款时间', 'tongtu_product_code' => __('logistics.product_statistics.headers.product_code'), 'tongtu_sku' => __('logistics.product_statistics.headers.tongtu_sku'),
        'tongtu_product_name' => '通途配货名称', 'sale_quantity' => '出单数量', 'sale_product_weight' => '出单产品重量',
        'product_cost_usd' => '货品成本（USD）', 'handling_fee_usd' => '手续费（USD）', 'total_shipping_usd' => '总运费（USD）',
        'proportional_refund_usd' => '比例退款（USD）', 'proportional_profit_usd' => '比例利润（USD）', 'proportional_profit_rate' => '比例利润率',
        'financial_profit_usd' => '财务利润（USD）', 'financial_profit_rate' => '财务利润率',
        'operation_profit_usd' => '运营利润（USD）', 'operation_profit_rate' => '运营利润率',
        'channel_refund_amount_usd' => '渠道退款额（USD）', 'shopify_refund_amount_usd' => 'Shopify店铺退款额（USD）',
        'refunded_at' => '退款时间', 'refund_reason' => '退款原因', 'collection_account' => '收款账号',
        'collection_account_email' => '收款账号邮箱', 'received_amount_usd' => '收款账号实收金额（USD）',
        'platform_fee_usd' => '平台费用（USD）', 'net_amount_usd' => '净额（USD）', 'transaction_id' => '交易号',
        'bill_number' => '账单号', 'destination' => '目的地', 'shipping_company' => '物流公司',
        'tracking_number' => '跟踪单号', 'logistics_status' => '物流状态', 'order_created_at' => '订单创建时间',
        'data_status' => '数据状态', 'sku_exception_reason' => 'SKU异常原因', 'time_zone' => '时区',
    ];
    $groups = [
        '订单基础信息' => ['shopify_order_id', 'shopify_order_number', 'tongtu_order_number', 'store_name', 'operator_name', 'group_names', 'staff_status', 'buyer_name', 'buyer_email', 'buyer_phone', 'is_add_on', 'time_zone', 'destination', 'order_created_at'],
        '订阅与支付' => ['subscription_type', 'subscription_origin_order_id', 'subscription_status', 'payment_status', 'payment_method', 'payment_gateway', 'paid_amount_usd', 'paid_at', 'collection_account', 'collection_account_email', 'transaction_id', 'bill_number'],
        '通途商品与成本' => ['tongtu_product_code', 'tongtu_sku', 'tongtu_product_name', 'sale_quantity', 'sale_product_weight', 'product_cost_usd', 'handling_fee_usd', 'total_shipping_usd', 'proportional_refund_usd', 'proportional_profit_usd', 'proportional_profit_rate', 'financial_profit_usd', 'financial_profit_rate', 'operation_profit_usd', 'operation_profit_rate'],
        '退款与结算' => ['channel_refund_amount_usd', 'shopify_refund_amount_usd', 'refunded_at', 'refund_reason', 'received_amount_usd', 'platform_fee_usd', 'net_amount_usd'],
        '物流与状态' => ['shipping_company', 'tracking_number', 'logistics_status', 'data_status', 'sku_exception_reason'],
    ];
    $textareaFields = ['tongtu_product_code', 'tongtu_sku', 'tongtu_product_name', 'sale_quantity', 'sale_product_weight', 'refund_reason', 'tracking_number', 'sku_exception_reason'];
    $numberFields = ['paid_amount_usd', 'product_cost_usd', 'handling_fee_usd', 'total_shipping_usd', 'proportional_refund_usd', 'proportional_profit_usd', 'proportional_profit_rate', 'financial_profit_usd', 'financial_profit_rate', 'operation_profit_usd', 'operation_profit_rate', 'channel_refund_amount_usd', 'shopify_refund_amount_usd', 'received_amount_usd', 'platform_fee_usd', 'net_amount_usd'];
    $dateFields = ['paid_at', 'refunded_at', 'order_created_at'];
    $value = static function (string $field) use ($order, $dateFields) {
        $old = old($field, $order->{$field});
        if (in_array($field, $dateFields, true) && $old instanceof \Carbon\CarbonInterface) return $old->timezone('Asia/Shanghai')->format('Y-m-d\\TH:i');
        return $old;
    };
@endphp

@push('styles')
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/logistics.css?v=20261009-10">
@endpush

@section('content')
    <section class="content-header"><h1>编辑物流订单 <small>编号：{{ $order->id }}</small></h1></section>
    <section class="content logistics-page">
        <form method="post" action="{{ route('logistics.orders.update', $order) }}">
            @csrf
            @method('PUT')
            @foreach($groups as $groupTitle => $fields)
                <div class="box box-solid logistics-form-box">
                    <div class="box-header with-border"><h3 class="box-title">{{ $groupTitle }}</h3></div>
                    <div class="box-body"><div class="row">
                        @foreach($fields as $field)
                            <div class="form-group col-md-4 @error($field) has-error @enderror">
                                <label for="{{ $field }}">{{ $labels[$field] }}</label>
                                @if($field === 'data_status')
                                    <select id="{{ $field }}" name="{{ $field }}" class="form-control">@foreach($statusOptions as $option)<option value="{{ $option }}" @selected($value($field) === $option)>{{ $option }}</option>@endforeach</select>
                                @elseif($field === 'logistics_status')
                                    <select id="{{ $field }}" name="{{ $field }}" class="form-control">@foreach($trackingOptions as $option)<option value="{{ $option }}" @selected($value($field) === $option)>{{ $option }}</option>@endforeach</select>
                                @elseif(in_array($field, $textareaFields, true))
                                    <textarea id="{{ $field }}" name="{{ $field }}" class="form-control" rows="{{ in_array($field, ['tongtu_product_code', 'tongtu_sku', 'tongtu_product_name', 'sale_quantity', 'sale_product_weight'], true) ? 4 : 2 }}">{{ $value($field) }}</textarea>
                                @else
                                    <input id="{{ $field }}" name="{{ $field }}" type="{{ in_array($field, $dateFields, true) ? 'datetime-local' : (in_array($field, $numberFields, true) ? 'number' : 'text') }}" @if(in_array($field, $numberFields, true)) step="0.0001" @endif class="form-control" value="{{ $value($field) }}">
                                @endif
                                @error($field)<span class="help-block">{{ $message }}</span>@enderror
                            </div>
                        @endforeach
                    </div></div>
                </div>
            @endforeach
            <div class="logistics-form-actions">
                <a class="btn btn-default" href="{{ route('logistics.orders.index') }}">取消</a>
                <button class="btn btn-success" type="submit"><i class="fa fa-save"></i> 保存修改</button>
            </div>
        </form>

        <div class="box box-solid logistics-form-box">
            <div class="box-header with-border"><h3 class="box-title">导入退款明细</h3><span class="pull-right text-muted">作废记录保留审计，但不计入汇总和产品统计</span></div>
            <div class="box-body table-responsive no-padding">
                <table class="table table-bordered table-hover logistics-refund-detail-table">
                    <thead><tr><th>退款单号</th><th>退款金额（USD）</th><th>退款类型</th><th>退款原因</th><th>备注</th><th>状态</th><th>导入时间</th><th>操作</th></tr></thead>
                    <tbody>
                    @forelse($order->refundDetails as $refund)
                        @php
                            $formId = 'refund-update-'.$refund->id;
                        @endphp
                        <tr class="{{ $refund->is_void ? 'text-muted' : '' }}">
                            <td><input form="{{ $formId }}" class="form-control input-sm" name="refund_number" value="{{ $refund->refund_number }}" @disabled($refund->is_void)></td>
                            <td><input form="{{ $formId }}" class="form-control input-sm" type="number" step="0.0001" name="refund_amount_usd" value="{{ $refund->refund_amount_usd }}" @disabled($refund->is_void)></td>
                            <td><input form="{{ $formId }}" class="form-control input-sm" name="refund_type" value="{{ $refund->refund_type }}" @disabled($refund->is_void)></td>
                            <td><input form="{{ $formId }}" class="form-control input-sm" name="refund_reason" value="{{ $refund->refund_reason }}" @disabled($refund->is_void)></td>
                            <td><input form="{{ $formId }}" class="form-control input-sm" name="remark" value="{{ $refund->remark }}" @disabled($refund->is_void)></td>
                            <td><span class="label {{ $refund->is_void ? 'label-default' : 'label-success' }}">{{ $refund->is_void ? '已作废' : '有效' }}</span></td>
                            <td>{{ $refund->created_at?->timezone('Asia/Shanghai')->format('Y-m-d H:i:s') ?: '—' }}</td>
                            <td class="text-nowrap">
                                @unless($refund->is_void)
                                    <form id="{{ $formId }}" method="post" action="{{ route('logistics.orders.refunds.update', [$order, $refund]) }}" class="logistics-inline-form">@csrf @method('PUT')<button class="btn btn-primary btn-xs" type="submit"><i class="fa fa-save"></i> 保存</button></form>
                                    <form method="post" action="{{ route('logistics.orders.refunds.void', [$order, $refund]) }}" class="logistics-inline-form" onsubmit="return confirm('确认作废此退款明细？')">@csrf @method('DELETE')<button class="btn btn-danger btn-xs" type="submit"><i class="fa fa-ban"></i> 作废</button></form>
                                @endunless
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="text-center text-muted">当前订单没有导入退款明细。</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </section>
@endsection
