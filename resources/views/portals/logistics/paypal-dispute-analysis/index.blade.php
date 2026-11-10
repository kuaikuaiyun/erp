@extends('portal.layout')

@section('page_title', 'Paypal争议分析')

@php
    $origin = rtrim(request()->getSchemeAndHttpHost(), '/');
    $text = static fn ($value): string => filled($value) ? (string) $value : '—';
    $date = static fn ($value): string => $value ? $value->timezone('Asia/Shanghai')->format('Y-m-d H:i:s') : '';
    $flag = static fn ($value): string => $value === null ? '未处理' : ($value ? '是' : '否');
    $amount = static fn ($value, $currency): string => $value === null || $value === '' ? '—' : number_format((float) $value, 2).' '.($currency ?: '');
    $hasFilters = collect($filters)->except('per_page')->contains(fn ($value) => $value !== '');
    $operationChoice = static function ($value): string {
        if ($value === true || $value === 'yes') return '是';
        if ($value === false || $value === 'no') return '否';

        return '未修改';
    };
@endphp

@push('styles')
    <link rel="stylesheet" href="{{ $origin }}/assets/erp/logistics.css?v=20261109-1">
    <link rel="stylesheet" href="{{ $origin }}/assets/erp/paypal-dispute-analysis.css?v=20261110-4">
@endpush

@section('content')
    <section class="content-header"><h1>Paypal争议分析 <small>PayPal 争议、订单与物流关联明细</small></h1></section>
    <section class="content paypal-dispute-analysis-page">
        @if(session('success'))<div class="alert alert-success"><i class="fa fa-check-circle"></i> {{ session('success') }}</div>@endif
        <div class="box box-solid logistics-filter-box">
            <div class="box-header with-border"><h3 class="box-title"><i class="fa fa-filter"></i> 筛选条件</h3></div>
            <div class="box-body">
                <form method="get" action="{{ route('logistics.paypal-dispute-analysis.index') }}" class="logistics-filter-form">
                    <input type="hidden" name="per_page" value="{{ $filters['per_page'] }}">
                    <div class="logistics-filter-grid">
                        <div class="logistics-filter-field"><label>PP账户</label><select class="form-control" name="account_id"><option value="">全部账户</option>@foreach($accounts as $account)<option value="{{ $account->id }}" @selected((string) $filters['account_id'] === (string) $account->id)>{{ $account->account_name }}</option>@endforeach</select></div>
                        <div class="logistics-filter-field"><label>关键词</label><input class="form-control" name="keyword" value="{{ $filters['keyword'] }}" placeholder="事件ID、交易ID、账户"></div>
                        <div class="logistics-filter-field"><label>状态</label><select class="form-control" name="status"><option value="">全部状态</option>@foreach(['OPEN' => '处理中', 'WAITING_FOR_BUYER_RESPONSE' => '等待买家回复', 'WAITING_FOR_SELLER_RESPONSE' => '等待卖家回复', 'UNDER_REVIEW' => '审核中', 'RESOLVED' => '已解决'] as $key => $label)<option value="{{ $key }}" @selected($filters['status'] === $key)>{{ $label }}</option>@endforeach</select></div>
                        <div class="logistics-filter-field"><label>订单关联</label><select class="form-control" name="matched"><option value="">全部</option><option value="yes" @selected($filters['matched'] === 'yes')>已匹配</option><option value="no" @selected($filters['matched'] === 'no')>未匹配</option></select></div>
                        <div class="logistics-filter-field"><label>人工处理</label><select class="form-control" name="handled"><option value="">全部</option><option value="yes" @selected($filters['handled'] === 'yes')>已处理</option><option value="no" @selected($filters['handled'] === 'no')>未处理</option></select></div>
                    </div>
                    <div class="logistics-filter-date-row"><div class="logistics-filter-field logistics-date-range"><label>发起日期</label><div><input type="date" class="form-control" name="date_from" value="{{ $filters['date_from'] }}"><span>至</span><input type="date" class="form-control" name="date_to" value="{{ $filters['date_to'] }}"></div></div></div>
                    <div class="logistics-filter-actions"><button class="btn btn-primary" type="submit"><i class="fa fa-search"></i> 查询</button>@if($hasFilters)<a class="btn btn-default" href="{{ route('logistics.paypal-dispute-analysis.index', ['per_page' => $filters['per_page']]) }}">重置</a>@endif</div>
                </form>
            </div>
        </div>

        <div class="box box-solid logistics-table-box">
            <div class="box-header with-border logistics-list-toolbar"><h3 class="box-title">争议列表</h3><span class="paypal-dispute-count">共 {{ $disputes->total() }} 条</span>@include('portals.logistics.partials.pagination', ['paginator' => $disputes, 'filters' => $filters, 'routeName' => 'logistics.paypal-dispute-analysis.index', 'loadingTarget' => '.paypal-dispute-scroll'])</div>
            <div class="box-body table-responsive no-padding paypal-dispute-scroll">
                <table class="table table-hover logistics-table paypal-dispute-table">
                    <thead><tr><th>基本信息</th><th>订单信息</th><th>金额信息</th><th>订阅信息</th><th>纠纷信息</th><th>物流信息</th><th>商品信息</th><th>处理结果</th><th>操作</th></tr></thead>
                    <tbody>@forelse($disputes as $dispute)
                        @php
                            $order = $dispute->order;
                            $payment = $transactions->get((int) $dispute->logistics_paypal_account_id.'|'.trim((string) $dispute->disputed_transaction_id));
                            $orderAmount = $payment?->original_amount ?? $order?->paid_amount_usd;
                            $orderCurrency = $payment?->currency_code ?? ($orderAmount !== null ? 'USD' : '');
                            $refunds = $dispute->refundLinks->map(fn ($link) => $link->refund)->filter();
                            $refundCurrency = $refunds->pluck('currency_code')->filter()->unique()->count() === 1 ? $refunds->pluck('currency_code')->filter()->first() : '';
                            $refundAmount = $refunds->sum(fn ($refund) => (float) ($refund->original_amount ?? $refund->refund_amount_usd ?? 0));
                            $items = $order?->items ?? collect();
                        @endphp
                        <tr>
                            <td><div class="pd-block"><b>PP账户：</b>{{ $text($dispute->account?->account_name) }}<br><b>事件ID：</b><span class="pd-mono">{{ $text($dispute->dispute_id) }}</span><br><b>交易ID：</b><span class="pd-mono">{{ $text($dispute->disputed_transaction_id) }}</span></div></td>
                            <td><div class="pd-block"><b>客户邮箱：</b>{{ $text($order?->buyer_email ?: '未匹配') }}<br><b>发起日期：</b>{{ $date($dispute->dispute_created_at) ?: '—' }}<br><b>首次处理：</b>{{ $date($dispute->first_handled_at) ?: '无' }}<br><b>下单日期：</b>{{ $date($order?->order_created_at) ?: '未匹配' }}</div></td>
                            <td><div class="pd-block"><b>申诉金额：</b>{{ $amount($dispute->original_amount ?? $dispute->dispute_amount_usd, $dispute->currency_code) }}<br><b>订单金额：</b>{{ $amount($orderAmount, $orderCurrency) }}<br><b>退款金额：</b>{{ $refunds->isEmpty() ? '—' : $amount($refundAmount, $refundCurrency) }}<br><b>纠纷费：</b>—</div></td>
                            <td><div class="pd-block"><b>订阅/定期：</b>{{ $text($order?->subscription_type ?: '未匹配') }}<br><b>PostUpsell：</b>{{ $order ? ($order->is_add_on === '已加购' ? '是' : '否') : '未匹配' }}<br><b>是否复购单：</b>{{ $order ? (filled($order->subscription_origin_order_id) ? '是' : '否') : '未匹配' }}<br><b>取消订阅：</b><span class="pd-manual">{{ $flag($dispute->subscription_cancelled) }}</span><br><b>取消加购：</b><span class="pd-manual">{{ $flag($dispute->add_on_cancelled) }}</span></div></td>
                            <td><div class="pd-block"><b>类型：</b>{{ $dispute->typeLabel() }}<br><b>纠纷理由：</b>{{ $dispute->reasonLabel() }}</div></td>
                            <td><div class="pd-block"><b>是否发货：</b>{{ $order ? (in_array($order->logistics_status, [\App\Models\LogisticsOrder::LOGISTICS_SHIPPED, \App\Models\LogisticsOrder::LOGISTICS_DELIVERED, \App\Models\LogisticsOrder::LOGISTICS_IN_TRANSIT], true) ? '是' : '否') : '未匹配' }}<br><b>物流单号：</b><span class="pd-mono">{{ $text($order?->tracking_number ?: '未匹配') }}</span></div></td>
                            <td><div class="pd-block"><b>通途订单号：</b>{{ $text($order?->tongtu_order_number ?: '未匹配') }}@forelse($items as $item)<hr><b>通途SKU：</b>{{ $text($item->tongtu_sku) }}<br><b>中文品名：</b>{{ $text($item->tongtu_product_name ?: $item->product_title) }}@empty<hr><b>通途SKU：</b>未匹配<br><b>中文品名：</b>未匹配@endforelse</div></td>
                            <td><div class="pd-block"><b>解决方案：</b>{{ $dispute->outcomeLabel() }}<br><b>状态：</b>{{ $dispute->statusLabel() }}@if($dispute->first_handled_by_name)<hr><b>首次操作人：</b>{{ $dispute->first_handled_by_name }}@endif</div></td>
                            <td><button class="btn btn-primary btn-sm" type="button" data-toggle="modal" data-target="#handle-dispute-{{ $dispute->id }}"><i class="fa fa-pencil"></i> 处理 / 查看</button><button class="pd-operation-history-link" type="button" data-toggle="modal" data-target="#dispute-history-{{ $dispute->id }}">{{ $dispute->operations->count() }} 条处理记录</button></td>
                        </tr>
                    @empty<tr><td colspan="9" class="text-center text-muted">暂无 PayPal 争议记录</td></tr>@endforelse</tbody>
                </table>
            </div>
        </div>

        @foreach($disputes as $dispute)
            <div class="modal fade" id="handle-dispute-{{ $dispute->id }}" tabindex="-1"><div class="modal-dialog"><form class="modal-content" method="post" action="{{ route('logistics.paypal-dispute-analysis.handle', $dispute) }}">@csrf
                <div class="modal-header"><button type="button" class="close" data-dismiss="modal">×</button><h4 class="modal-title">处理争议：{{ $dispute->dispute_id }}</h4></div>
                <div class="modal-body"><input type="hidden" name="return_to" value="{{ request()->fullUrl() }}"><p class="text-muted">首次保存人工处理信息时，系统自动写入首次处理日期和操作人，之后不会覆盖。</p><div class="form-group"><label>是否取消订阅</label><select class="form-control" name="subscription_cancelled"><option value="">不修改</option><option value="yes" @selected($dispute->subscription_cancelled === true)>是</option><option value="no" @selected($dispute->subscription_cancelled === false)>否</option></select></div><div class="form-group"><label>是否取消加购</label><select class="form-control" name="add_on_cancelled"><option value="">不修改</option><option value="yes" @selected($dispute->add_on_cancelled === true)>是</option><option value="no" @selected($dispute->add_on_cancelled === false)>否</option></select></div><div class="form-group"><label>处理备注</label><textarea class="form-control" rows="3" name="note" maxlength="1000" placeholder="可选，保存后写入处理记录"></textarea></div></div>
                <div class="modal-footer"><button class="btn btn-default" type="button" data-dismiss="modal">关闭</button><button class="btn btn-primary" type="submit">保存处理信息</button></div>
            </form></div></div>
            <div class="modal fade" id="dispute-history-{{ $dispute->id }}" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content">
                <div class="modal-header"><button type="button" class="close" data-dismiss="modal">×</button><h4 class="modal-title">处理历史 <small>事件 ID：{{ $dispute->dispute_id }}</small></h4></div>
                <div class="modal-body pd-history-modal-body"><div class="table-responsive"><table class="table pd-history-table"><thead><tr><th>处理时间</th><th>操作人</th><th>是否取消订阅</th><th>是否取消加购</th><th>处理备注</th></tr></thead><tbody>@forelse($dispute->operations as $operation)<tr><td>{{ $date($operation->created_at) }}</td><td>{{ $operation->operator_name ?: '系统' }}</td><td>{{ $operationChoice($operation->payload['subscription_cancelled'] ?? null) }}</td><td>{{ $operationChoice($operation->payload['add_on_cancelled'] ?? null) }}</td><td class="pd-history-note">{{ filled($operation->payload['note'] ?? null) ? $operation->payload['note'] : '未填写备注' }}</td></tr>@empty<tr><td colspan="5" class="text-center text-muted">暂无处理历史</td></tr>@endforelse</tbody></table></div></div>
                <div class="modal-footer"><button class="btn btn-default" type="button" data-dismiss="modal">关闭</button></div>
            </div></div></div>
        @endforeach
    </section>
@endsection
