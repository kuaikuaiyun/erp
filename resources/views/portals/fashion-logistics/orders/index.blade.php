@extends('portal.layout')

@section('page_title', 'XShoppy 订单')

@push('styles')
<style>
.xsl-page{--ink:#16324a;--muted:#71869a;--line:#dce7ef;--blue:#2189bd}.xsl-page .box{border:0;border-radius:14px;box-shadow:0 5px 22px rgba(28,69,96,.08);overflow:hidden}.xsl-head{margin:2px 0 18px}.xsl-head h1{margin:0;color:var(--ink);font-size:23px;font-weight:700}.xsl-head p{margin:6px 0 0;color:var(--muted);font-size:13px}.xsl-filter{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));align-items:end;gap:12px 14px;padding:17px 23px;background:linear-gradient(110deg,#fff,#f4faff)}.xsl-filter .form-group{min-width:0;margin:0}.xsl-filter label{display:block;margin:0 0 6px;color:#637d91;font-size:12px;font-weight:600}.xsl-filter .form-control{width:100%;height:38px;min-width:0;border-color:#d5e3ec;border-radius:7px;box-shadow:none}.xsl-filter .xsl-filter-actions{grid-column:3 / span 2;display:flex;justify-content:flex-end;gap:8px}.xsl-filter .btn{display:inline-flex;align-items:center;justify-content:center;height:38px;border-radius:7px;padding:0 14px;font-weight:600;line-height:1}.xsl-filter .btn .fa{margin-right:4px}.xsl-toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:15px 19px;border-bottom:1px solid var(--line);background:#fff}.xsl-toolbar h3{margin:0;color:var(--ink);font-size:16px;font-weight:700}.xsl-toolbar small{margin-left:7px;color:var(--muted);font-weight:400}.xsl-sync-button{border-color:#b7dcca;color:#167650;background:#f2fbf7;border-radius:7px;font-weight:600}.xsl-sync-button:hover{background:#e1f6ec;color:#116443}.xsl-table{width:100%;margin:0;border:1px solid #cbdce8;border-collapse:separate;border-spacing:0;background:#fff}.xsl-table th{height:43px;padding:10px 12px!important;border-top:0!important;border-right:1px solid #cbdce8!important;border-bottom:1px solid #cbdce8!important;background:#e9f2f8!important;color:#24506d;font-size:13px;font-weight:700;text-align:center;vertical-align:middle!important;white-space:nowrap}.xsl-table th:last-child{border-right:0!important}.xsl-table td{min-height:88px;padding:14px 12px!important;border-top:0!important;border-right:1px solid #d9e5ed!important;border-bottom:1px solid #d9e5ed!important;background:#fff;color:#36556a;vertical-align:middle!important}.xsl-table td:last-child{border-right:0!important;text-align:center}.xsl-table tbody tr:last-child td{border-bottom:0!important}.xsl-table tbody tr:hover td{background:#f4f9fc!important}.xsl-order-no,.xsl-store,.xsl-customer strong,.xsl-money{color:#175d89}.xsl-order-no{font-size:14px;font-weight:700}.xsl-store{font-size:13px;font-weight:700}.xsl-sub,.xsl-handle{margin-top:5px;color:#7890a1;font-size:11px;line-height:1.45}.xsl-field-line{display:flex;align-items:baseline;gap:5px;max-width:100%;line-height:1.55}.xsl-field-line+.xsl-field-line{margin-top:3px}.xsl-field-label{flex:none;color:#6f8ca2;font-size:11px;font-weight:600;white-space:nowrap}.xsl-field-value{min-width:0;overflow:hidden;color:#265e84;font-size:12px;font-weight:700;text-overflow:ellipsis;white-space:nowrap}.xsl-field-value.muted{color:#7890a1;font-size:11px;font-weight:400}.xsl-status{color:#265e84;font-size:12px;font-weight:700}.xsl-products{min-width:300px;padding-top:10px!important;padding-bottom:10px!important}.xsl-product{display:flex;min-height:45px;gap:9px;align-items:center;padding:6px 0;border-bottom:1px dashed #d7e4ec}.xsl-product:last-child{border-bottom:0}.xsl-product img{width:37px;height:37px;flex:none;border:1px solid #dbe6ed;border-radius:4px;object-fit:cover}.xsl-product-name{max-width:235px;overflow:hidden;color:#35617d;font-size:12px;font-weight:600;text-overflow:ellipsis;white-space:nowrap}.xsl-sku{margin-top:3px;color:#6d93ad;font-size:11px}.xsl-money{font-size:13px;font-weight:700;white-space:nowrap}.xsl-detail-button{min-width:56px;border-color:#a9cee4;background:#fff;color:#216c98;font-weight:600}.xsl-detail-button:hover{border-color:#2189bd;background:#2189bd;color:#fff}.xsl-empty{padding:65px 20px!important;text-align:center;color:#8a9ca9}.xsl-pagination{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;padding:14px 19px;background:#fbfdff;border-top:1px solid var(--line)}.xsl-page-summary{color:#71869a;font-size:12px}.xsl-page-summary strong{color:#217ba8}.xsl-page-list{display:flex;align-items:center;gap:5px}.xsl-page-link,.xsl-page-active,.xsl-page-gap{display:inline-flex;align-items:center;justify-content:center;height:31px;min-width:31px;padding:0 9px;border-radius:6px;font-size:12px}.xsl-page-link{border:1px solid #d4e2ea;color:#35657e;background:#fff}.xsl-page-link:hover{border-color:#2189bd;color:#fff;background:#2189bd}.xsl-page-link.is-disabled{pointer-events:none;color:#a7b5bf;background:#f4f6f8}.xsl-page-active{border:1px solid #177aaa;background:#2189bd;color:#fff;font-weight:700}.xsl-page-gap{color:#8a9aa5}.xsl-modal .modal-dialog{width:92%;max-width:1320px;margin:24px auto}.xsl-modal .modal-content{border:1px solid #d9e7ef;border-radius:9px;overflow:hidden;box-shadow:0 20px 60px rgba(23,58,82,.28)}.xsl-modal .modal-header{padding:16px 20px;border-bottom:1px solid #d9e7ef;background:#fff;color:#16324a}.xsl-modal .header .close{margin-top:0;color:#486b80}.xsl-modal .modal-title{font-size:18px}.xsl-modal .modal-header p{margin:5px 0 0;color:#7690a2;font-size:12px}.xsl-modal .modal-body{padding:14px 18px 16px;background:#f7fafc}.xsl-sync-table{margin:0;background:#fff;border:1px solid #e0eaf0;border-radius:7px;overflow:hidden}.xsl-sync-table th{padding:10px!important;background:#eef5f9;color:#55748a;text-align:left}.xsl-sync-table td{padding:11px 10px!important;background:#fff;border-color:#e6eef3!important;font-size:12px;vertical-align:middle!important}.xsl-sync-status{display:inline-block;padding:3px 8px;border-radius:99px;font-size:11px;font-weight:700}.xsl-sync-status.success{background:#e8f8f0;color:#16885a}.xsl-sync-status.failed{background:#fff0ef;color:#cc4b42}.xsl-sync-status.syncing{background:#fff6e8;color:#b97715}.xsl-sync-status.pending{background:#eef3f6;color:#667f90}.xsl-sync-error{max-width:280px;color:#c34d43;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.xsl-detail-dialog{max-width:900px}.xsl-detail-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}.xsl-detail-card{min-height:72px;padding:11px 12px;border:1px solid #e1eaf0;border-radius:8px;background:#fff}.xsl-detail-card span{display:block;color:#8093a2;font-size:11px}.xsl-detail-card strong{display:block;margin-top:5px;color:#264c66;font-size:13px;word-break:break-word}@media(max-width:960px){.xsl-filter{grid-template-columns:repeat(2,minmax(0,1fr))}.xsl-filter .xsl-filter-actions{grid-column:auto;justify-content:flex-start}}@media(max-width:760px){.xsl-head,.xsl-toolbar{align-items:flex-start;flex-direction:column}.xsl-filter{grid-template-columns:1fr}.xsl-filter .xsl-filter-actions{grid-column:auto}.xsl-pagination{justify-content:center}.xsl-page-summary{width:100%;text-align:center}.xsl-detail-grid{grid-template-columns:1fr}.xsl-modal .modal-dialog{width:96%}}
/* Keep modal close buttons aligned after the filter button alignment override. */
.xsl-modal .modal-header .close{margin-top:0;color:#486b80}
.xsl-order-modal .modal-dialog{width:92%;max-width:1020px}.xsl-order-modal .modal-content{border:0;border-radius:12px;overflow:hidden;box-shadow:0 16px 55px rgba(20,58,82,.24)}.xsl-order-modal .modal-header{padding:15px 18px;border:0;background:linear-gradient(120deg,#1b81b5,#286d99);color:#fff}.xsl-order-modal .modal-header .close{color:#fff;opacity:.85}.xsl-order-modal .modal-title{font-size:16px;font-weight:700}.xsl-order-modal .modal-body{padding:18px;background:#f8fbfd}.xsl-order-modal .xsl-detail-grid{margin-bottom:16px}.xsl-order-modal .xsl-detail-card{min-height:77px}.xsl-detail-section{margin-top:16px}.xsl-detail-section h4{margin:0 0 8px;color:#315e79;font-size:13px;font-weight:700}.xsl-item-table{margin:0;background:#fff}.xsl-item-table th{background:#edf5f9;color:#4c6e82;font-size:11px}.xsl-item-table th,.xsl-item-table td{padding:8px!important;border-color:#e3ebf1!important;font-size:12px}.xsl-raw{max-height:350px;margin:0;padding:13px;overflow:auto;border:1px solid #1a354a;border-radius:8px;background:#152b3c;color:#cde6f4;font:12px/1.5 Consolas,"Courier New",monospace;white-space:pre-wrap;word-break:break-word}
</style>
@endpush

@section('content')
@php
    $statusLabels = ['pending' => '待付款', 'processing' => '处理中', 'on-hold' => '保留', 'completed' => '已完成', 'cancelled' => '已取消', 'refunded' => '已退款', 'failed' => '支付失败'];
    $syncLabels = ['success' => '同步成功', 'failed' => '同步失败', 'syncing' => '同步中', 'pending' => '等待同步'];
@endphp
<section class="content xsl-page">
    <div class="xsl-head"><h1>服装订单列表</h1><p>XShoppy 订单独立同步，每 10 分钟自动更新</p></div>
    <div class="box"><form method="get" action="{{ route('fashion-logistics.orders.index') }}" class="xsl-filter">
        <div class="form-group"><label>关键词</label><input class="form-control" name="keyword" value="{{ $filters['keyword'] ?? '' }}" placeholder="订单号、客户、邮箱、SKU、商品名称"></div>
        <div class="form-group"><label>店铺</label><select class="form-control" name="store_id"><option value="">全部 XShoppy 店铺</option>@foreach($stores as $store)<option value="{{ $store->id }}" @selected((string)($filters['store_id'] ?? '') === (string)$store->id)>{{ $store->shop_name ?: $store->domain }}</option>@endforeach</select></div>
        <div class="form-group"><label>运营人员</label><select class="form-control" name="operator_id"><option value="">全部运营人员</option>@foreach($operators as $operator)<option value="{{ $operator->id }}" @selected((string)($filters['operator_id'] ?? '') === (string)$operator->id)>{{ $operator->name ?: $operator->username }}</option>@endforeach</select></div>
        <div class="form-group"><label>订单状态</label><select class="form-control" name="status"><option value="" @selected(($filters['status'] ?? '') === '')>全部订单状态</option><option value="completed" @selected(($filters['status'] ?? '') === 'completed')>已完成</option><option value="processing" @selected(($filters['status'] ?? '') === 'processing')>处理中</option></select></div>
        <div class="form-group"><label>店铺搜索</label><input class="form-control" name="store_keyword" value="{{ $filters['store_keyword'] ?? '' }}" placeholder="店铺名称或域名"></div>
        <div class="form-group"><label>下单开始</label><input class="form-control" type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}"></div>
        <div class="form-group"><label>下单结束</label><input class="form-control" type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}"></div>
        <div class="form-group"><label>每页</label><select class="form-control" name="per_page">@foreach([20,50,100] as $size)<option value="{{ $size }}" @selected((int)($filters['per_page'] ?? 20) === $size)>{{ $size }} 条</option>@endforeach</select></div>
        <div class="xsl-filter-actions"><button class="btn btn-primary" type="submit"><i class="fa fa-search"></i> 查询</button><a class="btn btn-default" href="{{ route('fashion-logistics.orders.index') }}"><i class="fa fa-refresh"></i> 重置</a></div>
    </form></div>
    <div class="box" style="margin-top:16px"><div class="xsl-toolbar"><h3>订单列表 <small>共 {{ $orders->total() }} 笔 XShoppy 订单</small></h3><button class="btn xsl-sync-button" type="button" data-toggle="modal" data-target="#xshoppy-sync-modal"><i class="fa fa-refresh"></i> 店铺同步情况</button></div>
        <div class="table-responsive"><table class="table table-bordered table-hover xsl-table"><thead><tr><th>订单信息</th><th>物流信息</th><th>客户信息</th><th>订单来源</th><th>商品摘要</th><th>订单金额</th><th>操作</th></tr></thead><tbody>
        @forelse($orders as $order)
            <tr>
                <td><div class="xsl-field-line"><span class="xsl-field-label">订单号：</span><span class="xsl-field-value xsl-order-no">#{{ $order->order_number ?: $order->external_order_id }}</span></div><div class="xsl-field-line"><span class="xsl-field-label">平台 ID：</span><span class="xsl-field-value muted">{{ $order->external_order_id }}</span></div><div class="xsl-field-line"><span class="xsl-field-label">原始下单：</span><span class="xsl-field-value muted">{{ $order->source_order_created_at?->format('Y-m-d H:i:s') ?: '—' }}{{ $order->source_order_timezone ? ' · '.$order->source_order_timezone : '' }}</span></div><div class="xsl-field-line"><span class="xsl-field-label">入库下单：</span><span class="xsl-field-value muted">{{ $order->order_created_at?->format('Y-m-d H:i:s') ?: '—' }}（中国）</span></div></td>
                <td><div class="xsl-field-line"><span class="xsl-field-label">店铺：</span><span class="xsl-field-value xsl-store">{{ $order->store_name ?: '未命名店铺' }}</span></div><div class="xsl-field-line"><span class="xsl-field-label">域名：</span><span class="xsl-field-value muted">{{ $order->source_domain ?: '—' }}</span></div><div class="xsl-field-line"><span class="xsl-field-label">订单状态：</span><span class="xsl-status">{{ $statusLabels[$order->status] ?? ($order->status ?: '未知') }}</span></div></td>
                <td><div class="xsl-field-line"><span class="xsl-field-label">客户：</span><span class="xsl-field-value">{{ $order->customer_name ?: '—' }}</span></div><div class="xsl-field-line"><span class="xsl-field-label">联系方式：</span><span class="xsl-field-value muted">{{ $order->customer_email ?: $order->customer_phone ?: '—' }}</span></div></td>
                <td><div class="xsl-field-line"><span class="xsl-field-label">建站系统：</span><span class="xsl-field-value">XShoppy</span></div><div class="xsl-field-line"><span class="xsl-field-label">支付方式：</span><span class="xsl-field-value muted">{{ $order->payment_method_title ?: $order->payment_method ?: '—' }}</span></div></td>
                <td class="xsl-products">
                    @forelse($order->items->take(2) as $item)
                        <div class="xsl-product">
                            @if($item->image_url)
                                <img src="{{ $item->image_url }}" alt="">
                            @endif
                            <div><div class="xsl-product-name" title="{{ $item->product_name }}"><span class="xsl-field-label">商品：</span>{{ $item->product_name ?: '未命名商品' }}</div><div class="xsl-sku"><span class="xsl-field-label">SKU：</span>{{ $item->sku ?: '无 SKU' }} · ×{{ rtrim(rtrim(number_format((float)$item->quantity,4,'.',''),'0'),'.') }}</div></div>
                        </div>
                    @empty
                        <span class="text-muted">无商品明细</span>
                    @endforelse
                    @if($order->items->count() > 2)
                        <div class="xsl-sub">另有 {{ $order->items->count() - 2 }} 件商品</div>
                    @endif
                </td>
                <td><div class="xsl-field-line"><span class="xsl-field-label">金额：</span><span class="xsl-field-value xsl-money">{{ $order->currency ?: '—' }} {{ number_format((float)$order->total_amount,2) }}</span></div>
                    @if((float)$order->discount_amount > 0)
                        <div class="xsl-field-line"><span class="xsl-field-label">优惠：</span><span class="xsl-field-value muted">{{ number_format((float)$order->discount_amount,2) }}</span></div>
                    @endif
                </td>
                <td><button class="btn btn-info btn-xs xsl-detail-button" type="button" data-toggle="modal" data-target="#xshoppy-detail-{{ $order->id }}"><i class="fa fa-eye"></i> 详情</button></td>
            </tr>
        @empty
            <tr><td colspan="7" class="xsl-empty"><i class="fa fa-inbox fa-2x"></i><br>当前筛选条件下没有 XShoppy 订单。完成首次同步后会自动显示。</td></tr>
        @endforelse
        </tbody></table></div>
        @if($orders->total() > 0)
            <div class="xsl-pagination">
                <div class="xsl-page-summary">显示 <strong>{{ $orders->firstItem() }}-{{ $orders->lastItem() }}</strong> 条，共 <strong>{{ $orders->total() }}</strong> 条 · 第 {{ $orders->currentPage() }} / {{ $orders->lastPage() }} 页</div>
                @if($orders->hasPages())
                    @php
                        $pages = collect([1, $orders->currentPage() - 2, $orders->currentPage() - 1, $orders->currentPage(), $orders->currentPage() + 1, $orders->currentPage() + 2, $orders->lastPage()])
                            ->filter(fn ($page) => $page >= 1 && $page <= $orders->lastPage())->unique()->sort()->values();
                    @endphp
                    <nav class="xsl-page-list">
                        <a class="xsl-page-link {{ $orders->onFirstPage() ? 'is-disabled' : '' }}" href="{{ $orders->previousPageUrl() ?: '#' }}"><i class="fa fa-angle-left"></i></a>
                        @foreach($pages as $page)
                            @if($page === $orders->currentPage())
                                <span class="xsl-page-active">{{ $page }}</span>
                            @else
                                <a class="xsl-page-link" href="{{ $orders->url($page) }}">{{ $page }}</a>
                            @endif
                        @endforeach
                        <a class="xsl-page-link {{ $orders->hasMorePages() ? '' : 'is-disabled' }}" href="{{ $orders->nextPageUrl() ?: '#' }}"><i class="fa fa-angle-right"></i></a>
                    </nav>
                @endif
            </div>
        @endif
    </div>

    <div class="modal fade xsl-modal" id="xshoppy-sync-modal" tabindex="-1">
        <div class="modal-dialog"><div class="modal-content">
            <div class="modal-header"><button type="button" class="close" data-dismiss="modal">×</button><h4 class="modal-title">XShoppy 店铺同步情况</h4><p>查看每个已同步店铺的最近执行结果；同步任务本身仍按每 5 分钟自动运行。</p></div>
            <div class="modal-body"><div class="table-responsive"><table class="table xsl-sync-table"><thead><tr><th>店铺 / 域名</th><th>同步状态</th><th>当前订单数</th><th>本次新增 / 更新</th><th>最近成功</th><th>最近错误</th></tr></thead><tbody>
                @forelse($syncStates as $state)
                    <tr><td><strong>{{ $state->store?->shop_name ?: $state->store?->domain ?: '店铺 #'.$state->assistant_domain_store_id }}</strong><div class="xsl-sub">{{ $state->store?->domain ?: '—' }}</div></td><td><span class="xsl-sync-status {{ $state->status }}">{{ $syncLabels[$state->status] ?? $state->status }}</span></td><td>{{ $state->stored_orders_count }}</td><td>{{ $state->last_created_count }} / {{ $state->last_updated_count }}</td><td>{{ $state->last_succeeded_at?->format('Y-m-d H:i:s') ?: '尚未成功同步' }}</td><td><div class="xsl-sync-error" title="{{ $state->last_error }}">{{ $state->last_error ?: '—' }}</div></td></tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted">暂无同步记录</td></tr>
                @endforelse
            </tbody></table></div></div>
        </div></div>
    </div>

    @foreach($orders as $order)
        <div class="modal fade xsl-modal xsl-order-modal" id="xshoppy-detail-{{ $order->id }}" tabindex="-1">
            <div class="modal-dialog modal-lg"><div class="modal-content">
                <div class="modal-header"><button type="button" class="close" data-dismiss="modal">×</button><h4 class="modal-title"><i class="fa fa-file-text-o"></i> XShoppy 订单详情</h4></div>
                <div class="modal-body">
                    <div class="xsl-detail-grid">
                        <div class="xsl-detail-card"><span>订单号</span><strong>{{ $order->order_number ?: $order->external_order_id }}</strong></div>
                        <div class="xsl-detail-card"><span>店铺 / 域名</span><strong>{{ $order->store_name ?: '—' }} / {{ $order->source_domain ?: '—' }}</strong></div>
                        <div class="xsl-detail-card"><span>订单金额</span><strong>{{ $order->currency ?: '—' }} {{ number_format((float) $order->total_amount, 4) }}</strong></div>
                        <div class="xsl-detail-card"><span>订单状态</span><strong>{{ $statusLabels[$order->status] ?? ($order->status ?: '—') }}</strong></div>
                        <div class="xsl-detail-card"><span>支付方式</span><strong>{{ $order->payment_method_title ?: $order->payment_method ?: '—' }}</strong></div>
                        <div class="xsl-detail-card"><span>交易号</span><strong>{{ $order->transaction_id ?: '—' }}</strong></div>
                        <div class="xsl-detail-card"><span>客户</span><strong>{{ $order->customer_name ?: '—' }}</strong></div>
                        <div class="xsl-detail-card"><span>邮箱 / 电话</span><strong>{{ collect([$order->customer_email, $order->customer_phone])->filter()->implode(' / ') ?: '—' }}</strong></div>
                        <div class="xsl-detail-card"><span>收货地址</span><strong>{{ $order->shipping_address ?: '—' }}</strong></div>
                        <div class="xsl-detail-card"><span>原始下单时间</span><strong>{{ $order->source_order_created_at?->format('Y-m-d H:i:s') ?: '—' }}{{ $order->source_order_timezone ? ' · '.$order->source_order_timezone : '' }}</strong></div>
                        <div class="xsl-detail-card"><span>入库下单时间（中国）</span><strong>{{ $order->order_created_at?->format('Y-m-d H:i:s') ?: '—' }}</strong></div>
                        <div class="xsl-detail-card"><span>更新时间</span><strong>{{ $order->order_modified_at?->format('Y-m-d H:i:s') ?: '—' }}</strong></div>
                        <div class="xsl-detail-card"><span>付款时间</span><strong>{{ $order->paid_at?->format('Y-m-d H:i:s') ?: '—' }}</strong></div>
                        <div class="xsl-detail-card"><span>完成时间</span><strong>{{ $order->completed_at?->format('Y-m-d H:i:s') ?: '—' }}</strong></div>
                        <div class="xsl-detail-card"><span>同步时间</span><strong>{{ $order->source_synced_at?->format('Y-m-d H:i:s') ?: '—' }}</strong></div>
                        <div class="xsl-detail-card"><span>运费 / 优惠 / 税费</span><strong>{{ number_format((float) $order->shipping_amount, 4) }} / {{ number_format((float) $order->discount_amount, 4) }} / {{ number_format((float) $order->tax_amount, 4) }}</strong></div>
                    </div>
                    <div class="xsl-detail-section"><h4>商品明细（{{ $order->items->count() }}）</h4><div class="table-responsive"><table class="table table-bordered xsl-item-table"><thead><tr><th>商品</th><th>SKU</th><th>数量</th><th>小计</th><th>金额</th></tr></thead><tbody>
                        @forelse($order->items as $item)
                            <tr><td>{{ $item->product_name ?: '—' }}</td><td>{{ $item->sku ?: '—' }}</td><td>{{ $item->quantity }}</td><td>{{ $item->subtotal }}</td><td>{{ $item->total }}</td></tr>
                        @empty
                            <tr><td colspan="5" class="text-center text-muted">无商品明细</td></tr>
                        @endforelse
                    </tbody></table></div></div>
                    <div class="xsl-detail-section"><h4>XShoppy 原始 JSON</h4><pre class="xsl-raw">{{ json_encode($order->source_payload ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre></div>
                </div>
            </div></div>
        </div>
    @endforeach
</section>
@endsection
