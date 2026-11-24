@extends('portal.layout')
@section('page_title', '异常订单')

@push('styles')
<link rel="stylesheet" href="{{ asset('css/frontend-list-template.css') }}?v=20261124-21">
<style>
.abnormal-order-page{padding:15px}.abnormal-order-filters{margin-bottom:15px;padding:18px 20px 15px}.abnormal-order-filter-grid{display:grid;grid-template-columns:minmax(280px,2fr) repeat(2,minmax(190px,1fr));gap:14px 18px;align-items:start}.abnormal-order-filter-grid>label>input{margin-top:8px}.abnormal-order-picker-field{min-width:0}.abnormal-order-picker-field>.flt-field-label{margin-bottom:8px}.abnormal-order-picker[open] summary:after{transform:translateY(-20%) rotate(225deg)}.abnormal-order-picker .flt-picker-panel{z-index:108}.abnormal-order-picker-search{min-height:34px!important;margin-bottom:5px;padding:7px 10px!important}.abnormal-order-picker .flt-picker-options{margin-top:0}.abnormal-order-picker .flt-picker-options label{font-weight:400}.abnormal-order-filter-actions{display:flex;justify-content:flex-end;gap:9px;margin-top:13px}.abnormal-order-records{overflow:hidden}.abnormal-order-records .flt-toolbar{padding:12px 18px}.abnormal-order-table-scroll{max-height:calc(100vh - 285px);overflow:auto}.abnormal-order-table{width:100%!important;min-width:1320px!important;margin:0!important;border-collapse:separate!important;border-spacing:0!important;table-layout:fixed!important}.abnormal-order-table th:nth-child(1){width:205px}.abnormal-order-table th:nth-child(2){width:220px}.abnormal-order-table th:nth-child(3){width:115px}.abnormal-order-table th:nth-child(4){width:140px}.abnormal-order-table th:nth-child(5){width:390px}.abnormal-order-table th:nth-child(6){width:145px}.abnormal-order-table th:nth-child(7){width:160px}.abnormal-order-table thead th{position:sticky;top:0;z-index:4;padding:11px 10px!important;background:#eaf4fa!important;color:#345c75!important;border-right:1px solid #dce8ef!important}.abnormal-order-table tbody td{padding:11px 10px!important;border-right:1px solid #e7eef3!important;border-bottom:1px solid #e7eef3!important;vertical-align:middle!important}.abnormal-order-table tbody tr:nth-child(odd)>td{background:#fff}.abnormal-order-table tbody tr:nth-child(even)>td{background:#f3f9fd}.abnormal-order-table tbody tr:hover>td{background:#eaf5fb!important}.abnormal-order-meta{text-align:left;line-height:1.65}.abnormal-order-meta b{color:#285b7e;font-weight:600}.abnormal-order-types,.abnormal-product-types{display:flex;gap:5px;flex-wrap:wrap}.abnormal-product-types{margin-bottom:5px}.abnormal-order-type{display:inline-flex;align-items:center;gap:4px;padding:4px 9px;border-radius:13px;font-size:12px;font-weight:700;white-space:nowrap}.abnormal-product-types .abnormal-order-type{padding:2px 7px;font-size:11px}.abnormal-order-type.non-runnable{background:#fde5e1;color:#b34335}.abnormal-order-type.missing-sku{background:#fff0c7;color:#916300}.abnormal-products{display:grid;gap:7px;text-align:left}.abnormal-product{padding:8px 10px;border:1px solid #f1ddb0;border-left:4px solid #f39c12;background:#fffaf0;border-radius:6px}.abnormal-product strong{display:block;color:#704a00;margin-bottom:3px}.abnormal-product small{display:block;color:#62798a;line-height:1.6;word-break:break-all}.abnormal-notify-meta{display:block;margin-top:6px;color:#7890a1;font-size:12px;line-height:1.55}.abnormal-order-actions{display:flex;align-items:center;justify-content:center;flex-wrap:wrap;gap:3px}.abnormal-order-actions form{display:inline-flex;margin:0}.abnormal-order-actions .flt-row-action{display:inline-flex;align-items:center;gap:4px;white-space:nowrap}.abnormal-order-actions button.flt-row-action{border-left:0}.abnormal-order-pagination{border-top:1px solid #e3edf4}.abnormal-order-pagination .pagination-total{margin-right:auto}.abnormal-order-pagination .page-size{margin-right:auto}.abnormal-order-pagination form{display:flex;align-items:center;gap:8px;margin:0}.abnormal-order-pagination form input[type=hidden]{display:none}.abnormal-order-pagination .page-jump input{width:64px!important}.abnormal-order-pagination .is-disabled{pointer-events:none;opacity:.45}@media(max-width:900px){.abnormal-order-filter-grid{grid-template-columns:1fr}.abnormal-order-pagination{justify-content:center}.abnormal-order-pagination .pagination-total,.abnormal-order-pagination .page-size{width:100%;margin:0;justify-content:center}.abnormal-order-picker .flt-picker-panel{position:relative;top:5px}}
</style>
@endpush

@section('content')
<section class="content-header"><h1>异常订单 <small>{{ $isReviewPortal ? '查看异常订单状态并通知对应运营' : '统计 2026-10-20 起首单或无订阅且包含违规产品、SKU 为空商品的订单' }}</small></h1></section>
<section class="content abnormal-order-page flt">
    @if(session('success'))<div class="alert alert-success"><i class="fa fa-check-circle"></i> {{ session('success') }}</div>@endif
    @if(session('warning'))<div class="alert alert-warning"><i class="fa fa-exclamation-triangle"></i> {{ session('warning') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger"><i class="fa fa-exclamation-circle"></i> {{ $errors->first() }}</div>@endif

    <form class="flt-card flt-filters abnormal-order-filters" method="get" action="{{ route('abnormal-orders.index') }}">
        <div class="abnormal-order-filter-grid">
            <label>关键词<input type="search" name="keyword" value="{{ $keyword }}" placeholder="订单号、店铺、运营或 SKU" autocomplete="off"></label>
            @include('portal.abnormal-orders.partials.single-picker', [
                'label' => '处理状态', 'name' => 'status', 'selected' => $status,
                'options' => ['' => '全部状态', 'pending' => '未处理', 'processed' => '已处理'],
            ])
            @include('portal.abnormal-orders.partials.single-picker', [
                'label' => '异常订单类型', 'name' => 'abnormal_type', 'selected' => $abnormalType,
                'options' => ['' => '全部类型', 'non_runnable' => '违规产品', 'missing_sku' => 'SKU 为空'],
            ])
            <input type="hidden" name="per_page" value="{{ $orders->perPage() }}">
        </div>
        <div class="abnormal-order-filter-actions"><button class="flt-btn flt-primary" type="submit"><i class="fa fa-search"></i> 查询</button><a class="flt-btn" href="{{ route('abnormal-orders.index') }}"><i class="fa fa-refresh"></i> 重置</a></div>
    </form>

    <div class="flt-card flt-records abnormal-order-records">
        <div class="flt-toolbar"><div class="flt-selection"><i class="fa fa-exclamation-circle text-orange"></i><strong>异常订单列表</strong><span class="flt-toolbar-divider"></span><span>共 {{ $orders->total() }} 条</span></div></div>
        <div class="flt-table-scroll abnormal-order-table-scroll">
            <table class="abnormal-order-table">
                <thead><tr><th>订单信息</th><th>店铺与运营</th><th>订单类型</th><th>异常订单类型</th><th>异常商品</th><th>处理状态</th><th>操作</th></tr></thead>
                <tbody>
                @forelse($orders as $order)
                    @php($record = $order->abnormalRecord)
                    @php($processed = $record?->status === 'processed')
                    <tr>
                        <td class="abnormal-order-meta"><div><b>系统 ID：</b>{{ $order->id }}</div><div><b>Shopify：</b>#{{ $order->shopify_order_number ?: '—' }}</div><div><b>订单 ID：</b>{{ $order->shopify_order_id ?: '—' }}</div><div><b>下单时间：</b><span class="flt-time">{{ $order->order_created_at?->timezone('Asia/Shanghai')->format('Y-m-d H:i:s') ?: '—' }}</span></div></td>
                        <td class="abnormal-order-meta"><div><b>店铺：</b>{{ $order->store_name ?: '—' }}</div><div><b>域名：</b>{{ $order->store?->domain ?: ($order->source_shop_domain ?: '—') }}</div><div><b>归属运营：</b>{{ $order->effective_owner_name ?: '未匹配' }} @if($order->effective_owner_source === 'store_user')<small class="text-muted">（使用人）</small>@endif</div><div><b>团队：</b>{{ $order->effective_team_name ?: '—' }}</div></td>
                        <td><span class="label {{ $order->subscription_type === '订阅首次购买' ? 'label-info' : 'label-default' }}">{{ $order->subscription_type }}</span></td>
                        <td><div class="abnormal-order-types">@foreach($order->abnormal_types as $type)<span class="abnormal-order-type {{ $type === 'missing_sku' ? 'missing-sku' : 'non-runnable' }}"><i class="fa {{ $type === 'missing_sku' ? 'fa-exclamation-circle' : 'fa-ban' }}"></i>{{ $type === 'missing_sku' ? 'SKU 为空' : '违规产品' }}</span>@endforeach</div></td>
                        <td><div class="abnormal-products">@foreach($order->abnormal_items as $item)<div class="abnormal-product"><div class="abnormal-product-types">@foreach($item['types'] as $type)<span class="abnormal-order-type {{ $type === 'missing_sku' ? 'missing-sku' : 'non-runnable' }}">{{ $type === 'missing_sku' ? 'SKU 为空' : '违规产品' }}</span>@endforeach</div><strong>{{ $item['product_name'] }}</strong><small>原始 SKU：{{ $item['shopify_sku'] }}</small><small>通途 SKU：{{ $item['tongtu_sku'] }}　PCL：{{ $item['product_code'] }}</small></div>@endforeach</div></td>
                        <td><span class="flt-status" data-status="{{ $processed ? 'enabled' : 'pending' }}">{{ $processed ? '已处理' : '未处理' }}</span>@if($processed)<span class="abnormal-notify-meta">{{ $record->processed_by_name }}<br>{{ $record->processed_at?->timezone('Asia/Shanghai')->format('Y-m-d H:i') }}</span>@elseif($record?->last_notified_at)<span class="abnormal-notify-meta">最近通知：{{ $record->last_notified_at->timezone('Asia/Shanghai')->format('Y-m-d H:i') }}</span>@endif</td>
                        <td><div class="abnormal-order-actions">
                            @if($isReviewPortal)
                                @if(!$processed && $order->effective_owner_user_id)<form method="post" action="{{ route('abnormal-orders.notify', $order) }}">@csrf<button class="flt-row-action" type="submit"><i class="fa fa-paper-plane"></i> 发送异常订单</button></form>@elseif(!$processed)<button class="flt-row-action" type="button" disabled title="订单未匹配真实归属人或店铺使用人账号"><i class="fa fa-ban"></i> 无法通知</button>@else<span class="text-muted">已处理，无需通知</span>@endif
                            @else
                                <form method="post" action="{{ route('abnormal-orders.status', $order) }}">@csrf @method('PATCH')<input type="hidden" name="status" value="{{ $processed ? 'pending' : 'processed' }}"><button class="flt-row-action" type="submit"><i class="fa {{ $processed ? 'fa-undo' : 'fa-check' }}"></i> {{ $processed ? '恢复未处理' : '标记已处理' }}</button></form>
                            @endif
                        </div></td>
                    </tr>
                @empty
                    <tr><td colspan="7"><div class="flt-empty"><i class="fa fa-inbox"></i><h4>暂无符合条件的异常订单</h4><p>可调整筛选条件后重新查询</p></div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @include('portal.abnormal-orders.partials.pagination', ['paginator' => $orders])
    </div>
</section>
@endsection

@push('scripts')
<script>
(function(){
    var pickers=[].slice.call(document.querySelectorAll('[data-abnormal-order-picker]'));
    pickers.forEach(function(picker){
        var summary=picker.querySelector('[data-picker-summary]');
        var search=picker.querySelector('[data-picker-search]');
        var options=[].slice.call(picker.querySelectorAll('[data-picker-option]'));
        var empty=picker.querySelector('[data-picker-empty]');
        options.forEach(function(option){
            var radio=option.querySelector('input[type="radio"]');
            radio.addEventListener('change',function(){summary.textContent=option.querySelector('span').textContent.trim();picker.removeAttribute('open');});
        });
        if(search){
            search.addEventListener('input',function(){
                var keyword=search.value.trim().toLowerCase(),visible=0;
                options.forEach(function(option){var matched=option.textContent.toLowerCase().indexOf(keyword)!==-1;option.hidden=!matched;if(matched)visible++;});
                if(empty)empty.hidden=visible!==0;
            });
            picker.addEventListener('toggle',function(){if(picker.open){search.value='';options.forEach(function(option){option.hidden=false;});if(empty)empty.hidden=true;setTimeout(function(){search.focus();},0);}});
        }
    });
    document.addEventListener('click',function(event){pickers.forEach(function(picker){if(picker.open&&!picker.contains(event.target))picker.removeAttribute('open');});});
})();
</script>
@endpush
