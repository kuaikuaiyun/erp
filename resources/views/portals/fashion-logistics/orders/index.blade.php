@extends('portal.layout')

@section('page_title', '服装订单列表')

@push('styles')
<style>
.fashion-box{border:0;border-radius:12px;box-shadow:0 4px 16px rgba(24,61,89,.09);overflow:hidden}.fashion-filter{display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap;padding:16px 18px}.fashion-filter .form-group{margin:0}.fashion-filter label{display:block;margin-bottom:6px;font-size:12px;color:#647d90}.fashion-filter .form-control{height:38px;border:1px solid #d7e3ec;border-radius:6px;min-width:150px}.fashion-filter .keyword{min-width:260px}.sync-strip{display:flex;gap:10px;overflow-x:auto;padding:0 0 14px}.sync-card{min-width:245px;padding:11px 13px;border-radius:9px;background:#fff;border-left:4px solid #2f91c3;box-shadow:0 2px 10px rgba(26,66,94,.08)}.sync-card.failed{border-color:#e74c3c}.sync-card.syncing{border-color:#f39c12}.sync-name{font-weight:700;color:#214f6e}.sync-meta{margin-top:5px;color:#778c9b;font-size:12px}.order-table{margin:0}.order-table th{background:#d9eaf5!important;color:#214e6c;text-align:center;white-space:nowrap;padding:11px 8px!important}.order-table td{padding:10px 8px!important;vertical-align:middle!important;border-color:#e1eaf0!important}.order-table tbody tr.order-row:nth-of-type(4n+1)>td{background:#f4f9fc}.order-table tbody tr.order-row:nth-of-type(4n+3)>td{background:#fff8ef}.order-no{font-weight:700;color:#176f9f}.store-name{font-weight:600;color:#245675}.store-domain{font-size:12px;color:#7b8d9a}.product-list{min-width:260px}.product{display:flex;gap:8px;padding:4px 0;border-bottom:1px dashed #e0e8ee}.product:last-child{border:0}.product img{width:36px;height:36px;object-fit:cover;border-radius:5px;border:1px solid #dce5eb}.product-name{max-width:230px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.sku{font-size:12px;color:#2880aa}.status-badge{display:inline-block;padding:4px 9px;border-radius:12px;background:#e5f3fb;color:#2377a0;font-weight:600}.order-detail{display:none}.order-detail.open{display:table-row}.order-detail td{padding:14px 18px!important;background:#f7fafc!important}.detail-grid{display:grid;grid-template-columns:repeat(4,minmax(170px,1fr));gap:10px}.detail-item{padding:10px;border-radius:7px;background:#fff;border:1px solid #e0e8ee}.detail-item span{display:block;font-size:12px;color:#758a99}.detail-item strong{display:block;margin-top:4px;color:#244f6a;word-break:break-all}.empty-state{text-align:center;padding:50px!important;color:#8295a2}.fashion-pagination{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;padding:14px 18px;background:linear-gradient(90deg,#f7fafc,#edf5fa);border-top:1px solid #dce7ee}.pagination-summary{display:flex;align-items:center;gap:10px;color:#61798a}.pagination-summary strong{color:#1e6389}.page-buttons{display:flex;align-items:center;gap:5px}.page-button,.page-current,.page-dots{display:inline-flex;align-items:center;justify-content:center;height:32px;min-width:32px;padding:0 10px;border-radius:6px}.page-button{color:#366b8b;background:#fff;border:1px solid #cfdee8;transition:.15s}.page-button:hover{color:#fff;background:#2f8fbe;border-color:#2f8fbe}.page-current{color:#fff;background:linear-gradient(135deg,#3b9aca,#217dad);border:1px solid #217dad;font-weight:700;box-shadow:0 2px 6px rgba(35,125,173,.2)}.page-button.disabled{color:#a7b4bd;background:#f4f6f8;border-color:#e1e6ea;pointer-events:none}.page-dots{color:#8799a6;padding:0 3px;min-width:22px}.page-caption{padding:0 6px;color:#78909f;font-size:12px}@media(max-width:900px){.detail-grid{grid-template-columns:1fr 1fr}.fashion-filter .form-control,.fashion-filter .keyword{min-width:100%;width:100%}.fashion-pagination{justify-content:center}.pagination-summary{width:100%;justify-content:center}}
</style>
@endpush

@section('content')
@php
    $statusLabels = [
        'pending' => '待付款',
        'processing' => '处理中',
        'on-hold' => '保留',
        'completed' => '已完成',
        'cancelled' => '已取消',
        'refunded' => '已退款',
        'failed' => '支付失败',
        'trash' => '已删除',
    ];
@endphp
<section class="content-header"><h1>服装订单列表 <small>XShoppy 订单独立同步，每 5 分钟自动更新</small></h1></section>
<section class="content">
    <div class="box fashion-box"><form method="get" action="{{ route('fashion-logistics.orders.index') }}" class="fashion-filter">
        <div class="form-group"><label>关键词</label><input class="form-control keyword" name="keyword" value="{{ $filters['keyword'] ?? '' }}" placeholder="订单号、客户、邮箱、SKU、商品名"></div>
        <div class="form-group"><label>运营人员</label><select class="form-control" name="operator_id"><option value="">全部运营人员</option>@foreach($operators as $operator)<option value="{{ $operator->id }}" @selected((string)($filters['operator_id'] ?? '') === (string)$operator->id)>{{ $operator->name ?: $operator->username }}</option>@endforeach</select></div>
        <div class="form-group"><label>店铺搜索</label><input class="form-control" name="store_keyword" value="{{ $filters['store_keyword'] ?? '' }}" placeholder="输入店铺名称或域名"></div>
        <div class="form-group"><label>订单状态</label><select class="form-control" name="status"><option value="completed" @selected(($filters['status'] ?? 'completed') === 'completed')>已完成</option><option value="processing" @selected(($filters['status'] ?? '') === 'processing')>处理中</option></select></div>
        <div class="form-group"><label>开始日期</label><input class="form-control" type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}"></div>
        <div class="form-group"><label>结束日期</label><input class="form-control" type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}"></div>
        <div class="form-group"><label>每页</label><select class="form-control" name="per_page">@foreach([20,50,100] as $size)<option value="{{ $size }}" @selected((int)($filters['per_page'] ?? 20) === $size)>{{ $size }} 条</option>@endforeach</select></div>
        <button class="btn btn-primary" type="submit"><i class="fa fa-search"></i> 查询</button><a class="btn btn-default" href="{{ route('fashion-logistics.orders.index') }}"><i class="fa fa-refresh"></i> 重置</a>
    </form></div>

    @if($syncStates->isNotEmpty())<div class="sync-strip">@foreach($syncStates as $state)<div class="sync-card {{ $state->status }}"><div class="sync-name">{{ $state->store?->shop_name ?: $state->store?->domain ?: '店铺 #'.$state->assistant_domain_store_id }}</div><div class="sync-meta">状态：{{ ['success'=>'同步成功','failed'=>'同步失败','syncing'=>'同步中','pending'=>'等待同步'][$state->status] ?? $state->status }} · 当前订单 {{ $state->stored_orders_count }} 条</div><div class="sync-meta">本次新增 {{ $state->last_created_count }} / 更新 {{ $state->last_updated_count }}</div><div class="sync-meta">最近成功：{{ $state->last_succeeded_at?->format('Y-m-d H:i:s') ?: '尚未同步' }}</div>@if($state->last_error)<div class="sync-meta text-danger" title="{{ $state->last_error }}">{{ Illuminate\Support\Str::limit($state->last_error, 60) }}</div>@endif</div>@endforeach</div>@endif

    <div class="box fashion-box"><div class="box-header with-border"><h3 class="box-title">订单数据 <small>共 {{ $orders->total() }} 条</small></h3></div><div class="box-body no-padding table-responsive">
        <table class="table table-bordered table-hover order-table"><thead><tr><th>下单时间</th><th>店铺 / 域名</th><th>订单号</th><th>状态</th><th>客户</th><th>订单商品</th><th>支付方式</th><th>订单金额</th><th>操作</th></tr></thead><tbody>
        @forelse($orders as $order)
            <tr class="order-row"><td>{{ $order->order_created_at?->format('Y-m-d H:i:s') ?: '—' }}</td><td><div class="store-name">{{ $order->store_name ?: '未命名店铺' }}</div><div class="store-domain">{{ $order->source_domain }}</div></td><td><div class="order-no">#{{ $order->order_number ?: $order->external_order_id }}</div><small>ID: {{ $order->external_order_id }}</small></td><td><span class="status-badge">{{ $statusLabels[$order->status] ?? ($order->status ?: '未知') }}</span></td><td><strong>{{ $order->customer_name ?: '—' }}</strong><br><small>{{ $order->customer_email ?: $order->customer_phone }}</small></td><td class="product-list">@forelse($order->items as $item)<div class="product">@if($item->image_url)<img src="{{ $item->image_url }}" alt="">@endif<div><div class="product-name" title="{{ $item->product_name }}">{{ $item->product_name ?: '未命名商品' }} × {{ rtrim(rtrim(number_format((float)$item->quantity,4,'.',''),'0'),'.') }}</div><div class="sku">SKU：{{ $item->sku ?: '未填写' }}</div></div></div>@empty<span class="text-muted">无商品明细</span>@endforelse</td><td>{{ $order->payment_method_title ?: $order->payment_method ?: '—' }}</td><td><strong>{{ $order->currency ?: '$' }} {{ number_format((float)$order->total_amount,2) }}</strong></td><td><button class="btn btn-xs btn-info js-detail" type="button" data-target="detail-{{ $order->id }}"><i class="fa fa-eye"></i> 详情</button></td></tr>
            <tr class="order-detail" id="detail-{{ $order->id }}"><td colspan="9"><div class="detail-grid"><div class="detail-item"><span>交易号</span><strong>{{ $order->transaction_id ?: '—' }}</strong></div><div class="detail-item"><span>联系电话</span><strong>{{ $order->customer_phone ?: '—' }}</strong></div><div class="detail-item"><span>收货地址</span><strong>{{ $order->shipping_address ?: '—' }}</strong></div><div class="detail-item"><span>运费 / 优惠 / 税费</span><strong>{{ number_format((float)$order->shipping_amount,2) }} / {{ number_format((float)$order->discount_amount,2) }} / {{ number_format((float)$order->tax_amount,2) }}</strong></div><div class="detail-item"><span>支付时间</span><strong>{{ $order->paid_at?->format('Y-m-d H:i:s') ?: '—' }}</strong></div><div class="detail-item"><span>完成时间</span><strong>{{ $order->completed_at?->format('Y-m-d H:i:s') ?: '—' }}</strong></div><div class="detail-item"><span>最近同步</span><strong>{{ $order->source_synced_at?->format('Y-m-d H:i:s') ?: '—' }}</strong></div><div class="detail-item"><span>客户备注</span><strong>{{ $order->customer_note ?: '—' }}</strong></div></div></td></tr>
        @empty<tr><td colspan="9" class="empty-state"><i class="fa fa-inbox fa-2x"></i><br>当前筛选条件暂无服装订单；定时任务完成首次同步后会自动显示。</td></tr>@endforelse
        </tbody></table>
    </div>
    @if($orders->total() > 0)
    <div class="fashion-pagination">
        <div class="pagination-summary"><i class="fa fa-list-alt"></i><span>显示 <strong>{{ $orders->firstItem() }}-{{ $orders->lastItem() }}</strong> 条，共 <strong>{{ $orders->total() }}</strong> 条</span><span class="page-caption">第 {{ $orders->currentPage() }} / {{ $orders->lastPage() }} 页</span></div>
        @if($orders->hasPages())
        <nav class="page-buttons" aria-label="订单分页">
            <a class="page-button {{ $orders->onFirstPage() ? 'disabled' : '' }}" href="{{ $orders->previousPageUrl() ?: '#' }}" title="上一页"><i class="fa fa-angle-left"></i><span>&nbsp;上一页</span></a>
            @php
                $currentPage = $orders->currentPage();
                $lastPage = $orders->lastPage();
                $pageNumbers = collect([1, $currentPage - 2, $currentPage - 1, $currentPage, $currentPage + 1, $currentPage + 2, $lastPage])->filter(fn($page) => $page >= 1 && $page <= $lastPage)->unique()->sort()->values();
                $previousNumber = null;
            @endphp
            @foreach($pageNumbers as $page)
                @if($previousNumber !== null && $page > $previousNumber + 1)<span class="page-dots">…</span>@endif
                @if($page === $currentPage)<span class="page-current">{{ $page }}</span>@else<a class="page-button" href="{{ $orders->url($page) }}">{{ $page }}</a>@endif
                @php($previousNumber = $page)
            @endforeach
            <a class="page-button {{ $orders->hasMorePages() ? '' : 'disabled' }}" href="{{ $orders->nextPageUrl() ?: '#' }}" title="下一页"><span>下一页&nbsp;</span><i class="fa fa-angle-right"></i></a>
        </nav>
        @endif
    </div>
    @endif
    </div>
</section>
@endsection

@push('scripts')
<script>document.addEventListener('click',function(e){var b=e.target.closest('.js-detail');if(!b)return;var row=document.getElementById(b.dataset.target);if(row)row.classList.toggle('open');});</script>
@endpush
