@extends('portal.layout')

@section('page_title', 'Paypal争议分析')

@php
    $origin = rtrim(request()->getSchemeAndHttpHost(), '/');
    $text = static fn ($value): string => filled($value) ? (string) $value : '—';
    $date = static fn ($value): string => $value ? $value->timezone('Asia/Shanghai')->format('Y-m-d H:i:s') : '';
    $flag = static fn ($value): string => $value === null ? '未处理' : ($value ? '是' : '否');
    $amount = static fn ($value, $currency): string => $value === null || $value === '' ? '—' : number_format((float) $value, 2).' '.($currency ?: '');
    $hasFilters = collect($filters)->except('per_page')->contains(fn ($value) => $value !== '');
    $disputeStatuses = ['OPEN' => '处理中', 'WAITING_FOR_BUYER_RESPONSE' => '等待买家回复', 'WAITING_FOR_SELLER_RESPONSE' => '等待卖家回复', 'UNDER_REVIEW' => '审核中', 'RESOLVED' => '已解决'];
    $disputeTypes = ['INTERNAL' => '索偿', 'EXTERNAL' => '撤销付款', 'ALERT' => '预警', 'INQUIRY' => '查询'];
    $reasonOptions = __('logistics.paypal_dispute.reasons');
    $outcomeOptions = __('logistics.paypal_dispute.outcomes');
    $logisticsStatuses = \App\Models\LogisticsOrder::logisticsStatusOptions();
    $operationChoice = static function ($value): string {
        if ($value === true || $value === 'yes') return '是';
        if ($value === false || $value === 'no') return '否';

        return '未修改';
    };
@endphp

@push('styles')
    <link rel="stylesheet" href="{{ $origin }}/assets/erp/logistics.css?v=20261109-1">
    <link rel="stylesheet" href="{{ $origin }}/assets/erp/paypal-dispute-analysis.css?v=20261110-9">
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
                    <div class="pd-filter-sections">
                        <div class="pd-filter-section"><h4>基本信息</h4><div class="logistics-filter-grid"><div class="logistics-filter-field"><label>PP账户</label><select class="form-control" name="account_id"><option value="">全部账户</option>@foreach($accounts as $account)<option value="{{ $account->id }}" @selected((string) $filters['account_id'] === (string) $account->id)>{{ $account->account_name }}</option>@endforeach</select></div><div class="logistics-filter-field"><label>事件 / 交易 ID</label><input class="form-control" name="keyword" value="{{ $filters['keyword'] }}" placeholder="事件ID、交易ID、账户"></div><div class="logistics-filter-field"><label>订单关联</label><select class="form-control" name="matched"><option value="">全部</option><option value="yes" @selected($filters['matched'] === 'yes')>已匹配</option><option value="no" @selected($filters['matched'] === 'no')>未匹配</option></select></div></div></div>
                        <div class="pd-filter-section"><div class="logistics-filter-grid"><div class="logistics-filter-field"><label>客户邮箱</label><input class="form-control" name="customer_email" value="{{ $filters['customer_email'] }}" placeholder="输入客户邮箱"></div></div></div>
                        <div class="pd-filter-section"><h4>金额信息</h4><div class="logistics-filter-grid"><div class="logistics-filter-field pd-amount-range"><label>申诉金额</label><div><input class="form-control" type="number" step="0.01" min="0" name="dispute_amount_min" value="{{ $filters['dispute_amount_min'] }}" placeholder="最低"><span>至</span><input class="form-control" type="number" step="0.01" min="0" name="dispute_amount_max" value="{{ $filters['dispute_amount_max'] }}" placeholder="最高"></div></div><div class="logistics-filter-field pd-amount-range"><label>订单金额</label><div><input class="form-control" type="number" step="0.01" min="0" name="order_amount_min" value="{{ $filters['order_amount_min'] }}" placeholder="最低"><span>至</span><input class="form-control" type="number" step="0.01" min="0" name="order_amount_max" value="{{ $filters['order_amount_max'] }}" placeholder="最高"></div></div><div class="logistics-filter-field pd-amount-range"><label>退款金额（单笔）</label><div><input class="form-control" type="number" step="0.01" min="0" name="refund_amount_min" value="{{ $filters['refund_amount_min'] }}" placeholder="最低"><span>至</span><input class="form-control" type="number" step="0.01" min="0" name="refund_amount_max" value="{{ $filters['refund_amount_max'] }}" placeholder="最高"></div></div><div class="logistics-filter-field"><label>币种</label><select class="form-control" name="currency"><option value="">全部币种</option>@foreach($currencies as $currency)<option value="{{ $currency }}" @selected($filters['currency'] === $currency)>{{ $currency }}</option>@endforeach</select></div></div></div>
                        <div class="pd-filter-section"><h4>订阅、纠纷与处理</h4><div class="logistics-filter-grid"><div class="logistics-filter-field"><label>订阅/定期</label><select class="form-control" name="subscription_type"><option value="">全部</option>@foreach($subscriptionTypes as $subscriptionType)<option value="{{ $subscriptionType }}" @selected($filters['subscription_type'] === $subscriptionType)>{{ $subscriptionType }}</option>@endforeach</select></div><div class="logistics-filter-field"><label>PostUpsell</label><select class="form-control" name="post_upsell"><option value="">全部</option><option value="yes" @selected($filters['post_upsell'] === 'yes')>是</option><option value="no" @selected($filters['post_upsell'] === 'no')>否</option></select></div><div class="logistics-filter-field"><label>是否复购单</label><select class="form-control" name="repurchase"><option value="">全部</option><option value="yes" @selected($filters['repurchase'] === 'yes')>是</option><option value="no" @selected($filters['repurchase'] === 'no')>否</option></select></div><div class="logistics-filter-field"><label>取消订阅</label><select class="form-control" name="subscription_cancelled"><option value="">全部</option><option value="yes" @selected($filters['subscription_cancelled'] === 'yes')>是</option><option value="no" @selected($filters['subscription_cancelled'] === 'no')>否</option></select></div><div class="logistics-filter-field"><label>取消加购</label><select class="form-control" name="add_on_cancelled"><option value="">全部</option><option value="yes" @selected($filters['add_on_cancelled'] === 'yes')>是</option><option value="no" @selected($filters['add_on_cancelled'] === 'no')>否</option></select></div><div class="logistics-filter-field"><label>类型</label><select class="form-control" name="type"><option value="">全部类型</option>@foreach($disputeTypes as $key => $label)<option value="{{ $key }}" @selected($filters['type'] === $key)>{{ $label }}</option>@endforeach</select></div><div class="logistics-filter-field"><label>纠纷理由</label><select class="form-control" name="reason"><option value="">全部理由</option>@foreach($reasonOptions as $key => $label)<option value="{{ $key }}" @selected($filters['reason'] === $key)>{{ $label }}</option>@endforeach</select></div><div class="logistics-filter-field"><label>解决方案</label><select class="form-control" name="outcome"><option value="">全部方案</option>@foreach($outcomeOptions as $key => $label)<option value="{{ $key }}" @selected($filters['outcome'] === $key)>{{ $label }}</option>@endforeach</select></div><div class="logistics-filter-field"><label>状态</label><select class="form-control" name="status"><option value="">全部状态</option>@foreach($disputeStatuses as $key => $label)<option value="{{ $key }}" @selected($filters['status'] === $key)>{{ $label }}</option>@endforeach</select></div><div class="logistics-filter-field"><label>人工处理</label><select class="form-control" name="handled"><option value="">全部</option><option value="yes" @selected($filters['handled'] === 'yes')>已处理</option><option value="no" @selected($filters['handled'] === 'no')>未处理</option></select></div></div></div>
                        <div class="pd-filter-section"><h4>物流与商品</h4><div class="logistics-filter-grid"><div class="logistics-filter-field"><label>是否发货</label><select class="form-control" name="shipped"><option value="">全部</option><option value="yes" @selected($filters['shipped'] === 'yes')>是</option><option value="no" @selected($filters['shipped'] === 'no')>否</option></select></div><div class="logistics-filter-field"><label>物流状态</label><select class="form-control" name="logistics_status"><option value="">全部状态</option>@foreach($logisticsStatuses as $key => $label)<option value="{{ $key }}" @selected($filters['logistics_status'] === $key)>{{ $label }}</option>@endforeach</select></div><div class="logistics-filter-field"><label>物流单号</label><input class="form-control" name="tracking_number" value="{{ $filters['tracking_number'] }}" placeholder="输入物流单号"></div><div class="logistics-filter-field"><label>通途订单号</label><input class="form-control" name="tongtu_order_number" value="{{ $filters['tongtu_order_number'] }}" placeholder="输入通途订单号"></div><div class="logistics-filter-field"><label>通途 SKU / 中文品名</label><input class="form-control" name="item_keyword" value="{{ $filters['item_keyword'] }}" placeholder="输入SKU或中文品名"></div></div></div>
                        <div class="pd-filter-date-row"><div class="logistics-filter-field logistics-date-range"><label>发起日期</label><div><input type="date" class="form-control" name="date_from" value="{{ $filters['date_from'] }}"><span>至</span><input type="date" class="form-control" name="date_to" value="{{ $filters['date_to'] }}"></div></div><div class="logistics-filter-field logistics-date-range"><label>首次处理日期</label><div><input type="date" class="form-control" name="handled_from" value="{{ $filters['handled_from'] }}"><span>至</span><input type="date" class="form-control" name="handled_to" value="{{ $filters['handled_to'] }}"></div></div><div class="logistics-filter-field logistics-date-range"><label>下单日期</label><div><input type="date" class="form-control" name="order_from" value="{{ $filters['order_from'] }}"><span>至</span><input type="date" class="form-control" name="order_to" value="{{ $filters['order_to'] }}"></div></div></div>
                    </div>
                    <div class="logistics-filter-actions"><button class="btn btn-primary" type="submit"><i class="fa fa-search"></i> 查询</button>@if($hasFilters)<a class="btn btn-default" href="{{ route('logistics.paypal-dispute-analysis.index', ['per_page' => $filters['per_page']]) }}">重置</a>@endif</div>
                </form>
            </div>
        </div>

        <div class="box box-solid logistics-table-box">
            <div class="box-header with-border logistics-list-toolbar"><button class="btn btn-default btn-sm" type="button" data-toggle="modal" data-target="#paypal-dispute-export-modal"><i class="fa fa-download"></i> 导出</button><span class="paypal-dispute-count">共 {{ $disputes->total() }} 条</span>@include('portals.logistics.partials.pagination', ['paginator' => $disputes, 'filters' => $filters, 'routeName' => 'logistics.paypal-dispute-analysis.index', 'loadingTarget' => '.paypal-dispute-scroll'])</div>
            <div class="box-body table-responsive no-padding paypal-dispute-scroll">
                <table class="table table-hover logistics-table paypal-dispute-table">
                    <thead><tr><th class="pd-dispute-select-cell"><input id="paypal-dispute-check-all" type="checkbox" aria-label="全选当前页"></th><th>基本信息</th><th>订单信息</th><th>金额信息</th><th>订阅信息</th><th>纠纷信息</th><th>物流信息</th><th>商品信息</th><th>处理结果</th><th>操作</th></tr></thead>
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
                            $latestOperation = $dispute->operations->first(fn ($operation) => filled($operation->payload['note'] ?? null));
                            $latestNote = $latestOperation ? ($latestOperation->payload['note'] ?? null) : null;
                        @endphp
                        <tr>
                            <td class="pd-dispute-select-cell"><input class="paypal-dispute-check" type="checkbox" value="{{ $dispute->id }}" aria-label="选择事件 {{ $dispute->dispute_id }}"></td>
                            <td><div class="pd-block"><b>PP账户：</b>{{ $text($dispute->account?->account_name) }}<br><b>事件ID：</b><span class="pd-mono">{{ $text($dispute->dispute_id) }}</span><br><b>交易ID：</b><span class="pd-mono">{{ $text($dispute->disputed_transaction_id) }}</span></div></td>
                            <td><div class="pd-block"><b>客户邮箱：</b>{{ $text($order?->buyer_email ?: '未匹配') }}<br><b>发起日期：</b>{{ $date($dispute->dispute_created_at) ?: '—' }}<br><b>首次处理：</b>{{ $date($dispute->first_handled_at) ?: '无' }}<br><b>下单日期：</b>{{ $date($order?->order_created_at) ?: '未匹配' }}</div></td>
                            <td><div class="pd-block"><b>申诉金额：</b>{{ $amount($dispute->original_amount ?? $dispute->dispute_amount_usd, $dispute->currency_code) }}<br><b>订单金额：</b>{{ $amount($orderAmount, $orderCurrency) }}<br><b>退款金额：</b>{{ $refunds->isEmpty() ? '—' : $amount($refundAmount, $refundCurrency) }}<br><b>纠纷费：</b>—</div></td>
                            <td><div class="pd-block"><b>订阅/定期：</b>{{ $text($order?->subscription_type ?: '未匹配') }}<br><b>PostUpsell：</b>{{ $order ? ($order->is_add_on === '已加购' ? '是' : '否') : '未匹配' }}<br><b>是否复购单：</b>{{ $order ? (filled($order->subscription_origin_order_id) ? '是' : '否') : '未匹配' }}<br><b>取消订阅：</b><span class="pd-manual">{{ $flag($dispute->subscription_cancelled) }}</span><br><b>取消加购：</b><span class="pd-manual">{{ $flag($dispute->add_on_cancelled) }}</span></div></td>
                            <td><div class="pd-block"><b>类型：</b>{{ $dispute->typeLabel() }}<br><b>纠纷理由：</b>{{ $dispute->reasonLabel() }}</div></td>
                            <td><div class="pd-block"><b>事件时状态：</b>{{ $dispute->event_logistics_captured_at ? $text($dispute->event_logistics_status ?: '待发货') : '未留存' }}<br><b>事件时是否发货：</b>{{ $dispute->event_logistics_captured_at ? ($dispute->event_shipped ? '是' : '否') : '未留存' }}<br><b>事件时单号：</b><span class="pd-mono">{{ $dispute->event_logistics_captured_at ? $text($dispute->event_tracking_number ?: '无') : '未留存' }}</span><hr><b>当前物流状态：</b>{{ $text($order?->logistics_status ?: '未匹配') }}<br><b>当前是否发货：</b>{{ $order ? (in_array($order->logistics_status, [\App\Models\LogisticsOrder::LOGISTICS_SHIPPED, \App\Models\LogisticsOrder::LOGISTICS_DELIVERED, \App\Models\LogisticsOrder::LOGISTICS_IN_TRANSIT], true) ? '是' : '否') : '未匹配' }}<br><b>当前物流单号：</b><span class="pd-mono">{{ $text($order?->tracking_number ?: '未匹配') }}</span></div></td>
                            <td><div class="pd-block"><b>通途订单号：</b>{{ $text($order?->tongtu_order_number ?: '未匹配') }}@forelse($items as $item)<hr><b>通途SKU：</b>{{ $text($item->tongtu_sku) }}<br><b>中文品名：</b>{{ $text($item->tongtu_product_name ?: $item->product_title) }}@empty<hr><b>通途SKU：</b>未匹配<br><b>中文品名：</b>未匹配@endforelse</div></td>
                            <td><div class="pd-block">
                                <b>解决方案：</b>{{ $dispute->outcomeLabel() }}<br>
                                <b>状态：</b>{{ $dispute->statusLabel() }}
                                @if($dispute->first_handled_by_name)
                                    <br><b>首次操作人：</b>{{ $dispute->first_handled_by_name }}
                                @endif
                                @if(filled($latestNote))
                                    <hr><b>最新处理备注：</b>{{ $latestNote }}
                                @endif
                            </div></td>
                            <td><button class="btn btn-primary btn-sm" type="button" data-toggle="modal" data-target="#handle-dispute-{{ $dispute->id }}"><i class="fa fa-pencil"></i> 处理 / 查看</button><button class="pd-operation-history-link" type="button" data-toggle="modal" data-target="#dispute-history-{{ $dispute->id }}">{{ $dispute->operations->count() }} 条处理记录</button></td>
                        </tr>
                    @empty<tr><td colspan="10" class="text-center text-muted">暂无 PayPal 争议记录</td></tr>@endforelse</tbody>
                </table>
            </div>
        </div>

        <div class="modal fade" id="paypal-dispute-export-modal" tabindex="-1" role="dialog" aria-labelledby="paypal-dispute-export-title">
            <div class="modal-dialog" role="document"><form id="paypal-dispute-export-form" class="modal-content" method="post" action="{{ route('logistics.paypal-dispute-analysis.export') }}" data-loading="download">
                @csrf
                @foreach($filters as $name => $value)<input type="hidden" name="{{ $name }}" value="{{ $value }}">@endforeach
                <div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="关闭"><span aria-hidden="true">&times;</span></button><h4 class="modal-title" id="paypal-dispute-export-title"><i class="fa fa-download"></i> 导出 Paypal 争议分析</h4></div>
                <div class="modal-body">
                    <div class="alert alert-info logistics-export-notice"><i class="fa fa-info-circle"></i> 导出文件包含当前争议分析展示字段，请仅在受控环境中使用和保管。</div>
                    <h5 class="logistics-modal-section-title">导出范围</h5>
                    <label class="logistics-export-scope"><input type="radio" name="scope" value="selected" checked> <strong>导出当前页已选记录</strong><small id="paypal-dispute-selected-count">当前已选择 0 条记录。</small></label>
                    <label class="logistics-export-scope"><input type="radio" name="scope" value="filtered"> <strong>导出当前筛选结果全部记录</strong><small>当前筛选结果共 {{ $disputes->total() }} 条记录。</small></label>
                    <div id="paypal-dispute-selected-inputs"></div>
                    @error('scope')<p class="text-danger m-t-sm"><i class="fa fa-exclamation-circle"></i> {{ $message }}</p>@enderror
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">取消</button><button type="submit" class="btn btn-primary"><i class="fa fa-download"></i> 确认导出</button></div>
            </form></div>
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

@push('scripts')
<script>
    (function () {
        var form = document.getElementById('paypal-dispute-export-form');
        var selectAll = document.getElementById('paypal-dispute-check-all');
        var selectedInputs = document.getElementById('paypal-dispute-selected-inputs');
        var selectedCount = document.getElementById('paypal-dispute-selected-count');

        if (!form || !selectAll || !selectedInputs || !selectedCount) return;

        function checks() { return Array.prototype.slice.call(document.querySelectorAll('.paypal-dispute-check')); }
        function selected() { return checks().filter(function (item) { return item.checked; }); }
        function refresh() {
            var selectedItems = selected();
            selectedCount.textContent = '当前已选择 ' + selectedItems.length + ' 条记录。';
            selectAll.checked = checks().length > 0 && selectedItems.length === checks().length;
            selectAll.indeterminate = selectedItems.length > 0 && selectedItems.length < checks().length;
            form.querySelector('input[name="scope"][value="selected"]').disabled = selectedItems.length === 0;
            if (selectedItems.length === 0) form.querySelector('input[name="scope"][value="filtered"]').checked = true;
        }
        selectAll.addEventListener('change', function () { checks().forEach(function (item) { item.checked = selectAll.checked; }); refresh(); });
        document.addEventListener('change', function (event) { if (event.target.classList.contains('paypal-dispute-check')) refresh(); });
        form.addEventListener('submit', function (event) {
            selectedInputs.innerHTML = '';
            if (form.querySelector('input[name="scope"]:checked').value !== 'selected') return;
            var selectedItems = selected();
            if (selectedItems.length === 0) { event.preventDefault(); refresh(); return; }
            selectedItems.forEach(function (item) { var input = document.createElement('input'); input.type = 'hidden'; input.name = 'selected_ids[]'; input.value = item.value; selectedInputs.appendChild(input); });
        });
        if (window.jQuery) $('#paypal-dispute-export-modal').on('show.bs.modal', refresh);
        refresh();
        @if($errors->has('scope'))
        if (window.jQuery) $('#paypal-dispute-export-modal').modal('show');
        @endif
    }());
</script>
@endpush
