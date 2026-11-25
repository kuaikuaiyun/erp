@extends('portal.layout')

@section('page_title', '出单统计')

@php
    $portalOrigin = rtrim(request()->getSchemeAndHttpHost(), '/');
    $conversionRate = $summary['publication_count'] > 0
        ? $summary['converted_count'] / $summary['publication_count'] * 100
        : 0;
@endphp

@push('styles')
    <link rel="stylesheet" href="{{ $portalOrigin }}/css/frontend-list-template.css?v=20261125-1">
    <style>
        .ai-order-statistics{padding:16px;color:#27475c}.ai-order-statistics .stats-page-head{display:flex;align-items:center;justify-content:space-between;gap:18px;margin:0 0 14px;padding:2px 2px 0}.stats-title-wrap{display:flex;align-items:center;gap:12px}.stats-title-icon{display:inline-flex;align-items:center;justify-content:center;width:42px;height:42px;border-radius:12px;background:linear-gradient(145deg,#e2f4fe,#f4fbff);color:#1884bf;font-size:20px;box-shadow:inset 0 0 0 1px #d4eaf6}.stats-title-wrap h2{margin:0;color:#183f58;font-size:22px;font-weight:700}.stats-title-wrap p{margin:4px 0 0;color:#8395a3;font-size:12px}.stats-range-chip{display:inline-flex;align-items:center;gap:7px;padding:8px 12px;border:1px solid #dce9f1;border-radius:18px;background:#fff;color:#557187;font-size:12px;white-space:nowrap}
        .ai-order-statistics .flt-filters{margin-bottom:14px;padding:16px 18px 14px}.ai-order-statistics .statistics-filter-grid{display:grid;grid-template-columns:minmax(260px,1.7fr) minmax(150px,.8fr) minmax(210px,1.15fr) minmax(145px,.72fr) minmax(145px,.72fr);gap:12px 14px;align-items:end}.statistics-filter-field{min-width:0}.statistics-filter-field .flt-field-label{margin-bottom:6px;color:#46667a;font-size:12px}.ai-order-statistics .statistics-filter-grid input,.ai-order-statistics .statistics-filter-grid select{height:36px;min-height:36px}.ai-order-statistics .flt-filter-actions{margin-top:12px;padding-top:11px;border-top:1px dashed #e2ebf1}.ai-order-statistics .flt-filter-actions .flt-btn{min-width:84px}
        .statistics-summary{display:grid;grid-template-columns:repeat(5,minmax(150px,1fr));gap:12px;margin-bottom:14px}.statistics-card{position:relative;display:flex;align-items:center;min-height:84px;padding:14px 16px;overflow:hidden}.statistics-card:after{content:"";position:absolute;right:-20px;bottom:-28px;width:76px;height:76px;border-radius:50%;background:currentColor;opacity:.055}.statistics-card-icon{display:inline-flex;align-items:center;justify-content:center;flex:0 0 38px;width:38px;height:38px;margin-right:12px;border-radius:10px;background:#edf7fc;color:#2188be;font-size:16px}.statistics-card-copy{min-width:0}.statistics-card-copy span{display:block;color:#7b8f9e;font-size:12px;white-space:nowrap}.statistics-card-copy strong{display:block;margin-top:4px;color:#173f59;font-size:21px;line-height:1.15;white-space:nowrap}.statistics-card-copy small{display:block;margin-top:3px;color:#8b9ba7;font-size:11px}.statistics-card.is-converted .statistics-card-icon{background:#eaf8f2;color:#149263}.statistics-card.is-orders .statistics-card-icon{background:#eef3ff;color:#5174c8}.statistics-card.is-items .statistics-card-icon{background:#fff6e9;color:#dc891d}.statistics-card.is-revenue .statistics-card-icon{background:#fff0eb;color:#dc6c3d}.statistics-card.is-revenue strong{color:#d86628}
        .statistics-records{overflow:hidden}.statistics-toolbar{display:flex;align-items:center;justify-content:space-between;gap:15px;padding:13px 16px;border-bottom:1px solid #dfe9f0;background:linear-gradient(180deg,#fff,#fbfdfe)}.statistics-toolbar h3{display:flex;align-items:center;gap:8px;color:#264e67}.statistics-toolbar-meta{display:flex;align-items:center;gap:12px;color:#80929f;font-size:12px}.statistics-toolbar-meta strong{color:#3e657c}.statistics-match-tip{display:inline-flex;align-items:center;gap:5px;padding-left:12px;border-left:1px solid #dfe8ee}
        .statistics-table-wrap{overflow-x:auto}.statistics-table{width:100%;min-width:1120px;margin:0;border-collapse:separate;border-spacing:0;table-layout:fixed}.statistics-table th{position:sticky;top:0;z-index:2;height:44px;padding:9px 8px;border-right:1px solid #d8e6ee;border-bottom:1px solid #d5e3eb;background:#eaf4fa;color:#345c75;font-size:12px;font-weight:600;text-align:center;white-space:nowrap}.statistics-table td{height:64px;padding:9px 10px;border-right:1px solid #e5edf2;border-bottom:1px solid #e5edf2;text-align:center;vertical-align:middle}.statistics-table th:last-child,.statistics-table td:last-child{border-right:0}.statistics-table tbody tr:nth-child(odd) td{background:#fff}.statistics-table tbody tr:nth-child(even) td{background:#f5fafe}.statistics-table tbody tr:hover td{background:#eaf6fc}.statistics-table .col-user{width:142px}.statistics-table .col-product{width:235px}.statistics-table .col-store{width:165px}.statistics-table .col-sku{width:135px}.statistics-table .col-time{width:126px}.statistics-table .col-number{width:82px}.statistics-table .col-money{width:108px}.statistics-table .col-order-time{width:126px}
        .publisher-cell{display:flex;align-items:center;gap:9px;text-align:left}.publisher-avatar{display:inline-flex;align-items:center;justify-content:center;flex:0 0 30px;width:30px;height:30px;border-radius:50%;background:#ddf1fb;color:#187caf;font-weight:700}.publisher-name{display:block;max-width:88px;overflow:hidden;color:#244e67;font-weight:600;text-overflow:ellipsis;white-space:nowrap}.product-cell{text-align:left!important;overflow-wrap:anywhere}.product-name{display:block;color:#21485f;font-weight:600;line-height:1.45;white-space:normal;word-break:break-word}.product-meta{display:flex;align-items:flex-start;flex-wrap:wrap;gap:5px;margin-top:5px;color:#8093a1;font-size:11px;line-height:1.45}.product-code{display:inline-block;max-width:100%;padding:2px 6px;border:1px solid #d9e7ef;border-radius:9px;background:#f7fbfd;color:#5d7d90;overflow-wrap:anywhere;white-space:normal}.store-domain{display:block;max-width:145px;overflow:hidden;color:#287aa7;font-weight:600;text-overflow:ellipsis;white-space:nowrap}.store-name{display:block;max-width:145px;margin-top:3px;overflow:hidden;color:#8b9ca8;font-size:11px;text-overflow:ellipsis;white-space:nowrap}.sku-code{display:block;width:100%;padding:4px 6px;border-radius:4px;background:#f2f6f9;color:#526f81;font-family:Consolas,monospace;font-size:11px;line-height:1.45;overflow-wrap:anywhere;word-break:break-all;white-space:normal}.date-stack strong,.date-stack small{display:block;white-space:nowrap}.date-stack strong{color:#49687a;font-size:12px}.date-stack small{margin-top:3px;color:#93a2ad;font-size:11px}.order-badge{display:inline-flex;align-items:center;justify-content:center;min-width:34px;height:24px;padding:0 8px;border:0;border-radius:12px;background:#e8f7f0;color:#07844f;font-weight:700}.order-detail-trigger{cursor:pointer;box-shadow:inset 0 0 0 1px #bfe8d5;transition:all .18s}.order-detail-trigger:hover,.order-detail-trigger:focus{background:#d9f3e8;color:#056f43;box-shadow:0 2px 7px rgba(9,121,74,.18);outline:0}.order-badge.is-zero{background:#f1f4f6;color:#9aa8b1;font-weight:500}.quantity-value{color:#486778;font-weight:600}.money-value{color:#d66726;font-weight:700}.empty-cell{padding:46px 20px!important;color:#8b9ca8}.empty-cell i{display:block;margin-bottom:8px;color:#bad0dc;font-size:27px}
        .order-detail-modal .modal-dialog{width:min(1060px,calc(100% - 30px))}.order-detail-modal .modal-content{overflow:hidden;border:0;border-radius:10px;box-shadow:0 12px 38px rgba(22,60,82,.24)}.order-detail-modal .modal-header{padding:15px 18px;border-bottom:1px solid #dce8ef;background:#f5fafc}.order-detail-modal .modal-title{color:#244d66;font-weight:700}.order-detail-modal .modal-body{max-height:70vh;padding:0;overflow:auto}.order-detail-summary{display:flex;gap:22px;padding:12px 18px;border-bottom:1px solid #e3edf2;background:#fff;color:#728895;font-size:12px}.order-detail-summary strong{margin-left:4px;color:#24536f;font-size:14px}.order-detail-table{width:100%;min-width:920px;margin:0;border-collapse:collapse}.order-detail-table th{position:sticky;top:0;z-index:2;padding:10px 9px;border-bottom:1px solid #d5e4ec;background:#eaf4fa;color:#355e76;font-size:12px;text-align:center;white-space:nowrap}.order-detail-table td{padding:10px 9px;border-right:1px solid #e6eef3;border-bottom:1px solid #e6eef3;color:#486779;font-size:12px;text-align:center;vertical-align:top}.order-detail-table tbody tr:nth-child(even) td{background:#f6fafc}.order-detail-product{text-align:left!important}.order-detail-product strong,.order-detail-product small{display:block}.order-detail-product small{margin-top:3px;color:#8599a6}.order-detail-sku{font-family:Consolas,monospace;word-break:break-all}.order-detail-loading,.order-detail-empty{padding:50px 20px;color:#8497a4;text-align:center}.order-detail-loading i{margin-right:6px}.order-detail-error{padding:18px;color:#c04b46;text-align:center}
        .statistics-pagination{border-top:1px solid #dfe9f0}.statistics-pagination .is-disabled{pointer-events:none;opacity:.42}.statistics-pagination .statistics-page-label{min-width:68px;text-align:center}
        @media(max-width:1280px){.ai-order-statistics .statistics-filter-grid{grid-template-columns:repeat(3,minmax(180px,1fr))}.statistics-summary{grid-template-columns:repeat(3,1fr)}}@media(max-width:820px){.ai-order-statistics{padding:10px}.ai-order-statistics .stats-page-head{align-items:flex-start;flex-direction:column}.ai-order-statistics .statistics-filter-grid,.statistics-summary{grid-template-columns:1fr}.statistics-toolbar{align-items:flex-start;flex-direction:column}.statistics-toolbar-meta{align-items:flex-start;flex-direction:column;gap:5px}.statistics-match-tip{padding-left:0;border-left:0}}
    </style>
@endpush

@section('content')
<section class="content flt ai-order-statistics">
    <header class="stats-page-head">
        <div class="stats-title-wrap">
            <span class="stats-title-icon"><i class="fa fa-line-chart"></i></span>
            <div><h2>出单统计</h2><p>统计产品资料库商品上架后的 Shopify 出单表现</p></div>
        </div>
        <span class="stats-range-chip"><i class="fa fa-calendar"></i> {{ $filters['date_from'] }} 至 {{ $filters['date_to'] }}</span>
    </header>

    <form class="flt-card flt-filters" method="get" action="{{ route('aikaipin.product-order-statistics.index') }}">
        <div class="statistics-filter-grid">
            <div class="statistics-filter-field"><label class="flt-field-label" for="stats-keyword">关键词</label><input id="stats-keyword" type="search" name="keyword" value="{{ $filters['keyword'] }}" placeholder="产品编号、名称、SKU、域名或 Shopify 商品 ID"></div>
            <div class="statistics-filter-field"><label class="flt-field-label" for="stats-publisher">上架用户</label><select id="stats-publisher" name="published_by"><option value="">全部用户</option>@foreach($publisherOptions as $id => $name)<option value="{{ $id }}" @selected((string)$filters['published_by'] === (string)$id)>{{ $name }}</option>@endforeach</select></div>
            <div class="statistics-filter-field"><label class="flt-field-label" for="stats-store">上架店铺</label><select id="stats-store" name="store_id"><option value="0">全部店铺</option>@foreach($storeOptions as $id => $name)<option value="{{ $id }}" @selected((int)$filters['store_id'] === (int)$id)>{{ $name }}</option>@endforeach</select></div>
            <div class="statistics-filter-field"><label class="flt-field-label" for="stats-from">订单开始日期</label><input id="stats-from" type="date" name="date_from" value="{{ $filters['date_from'] }}"></div>
            <div class="statistics-filter-field"><label class="flt-field-label" for="stats-to">订单结束日期</label><input id="stats-to" type="date" name="date_to" value="{{ $filters['date_to'] }}"></div>
        </div>
        <div class="flt-filter-actions"><button class="flt-btn flt-primary" type="submit"><i class="fa fa-search"></i> 查询</button><a class="flt-btn" href="{{ route('aikaipin.product-order-statistics.index') }}"><i class="fa fa-refresh"></i> 重置</a></div>
    </form>

    <div class="statistics-summary">
        <div class="flt-card statistics-card"><span class="statistics-card-icon"><i class="fa fa-cloud-upload"></i></span><div class="statistics-card-copy"><span>成功上架记录</span><strong>{{ number_format($summary['publication_count']) }}</strong><small>当前筛选范围</small></div></div>
        <div class="flt-card statistics-card is-converted"><span class="statistics-card-icon"><i class="fa fa-check-circle"></i></span><div class="statistics-card-copy"><span>已出单记录</span><strong>{{ number_format($summary['converted_count']) }}</strong><small>出单率 {{ number_format($conversionRate, 1) }}%</small></div></div>
        <div class="flt-card statistics-card is-orders"><span class="statistics-card-icon"><i class="fa fa-file-text-o"></i></span><div class="statistics-card-copy"><span>订单数</span><strong>{{ number_format($summary['order_count']) }} 单</strong><small>按 Shopify 订单去重</small></div></div>
        <div class="flt-card statistics-card is-items"><span class="statistics-card-icon"><i class="fa fa-cubes"></i></span><div class="statistics-card-copy"><span>商品件数</span><strong>{{ number_format($summary['item_quantity'], 0) }} 件</strong><small>按订单商品数量累计</small></div></div>
        <div class="flt-card statistics-card is-revenue"><span class="statistics-card-icon"><i class="fa fa-usd"></i></span><div class="statistics-card-copy"><span>商品营业额</span><strong>${{ number_format($summary['revenue'], 2) }}</strong><small>匹配商品行营业额</small></div></div>
    </div>

    <div class="flt-card flt-records statistics-records">
        <div class="statistics-toolbar">
            <h3><i class="fa fa-th-list"></i> 产品出单明细</h3>
            <div class="statistics-toolbar-meta"><span>共 <strong>{{ number_format($publications->total()) }}</strong> 条上架记录</span><span class="statistics-match-tip"><i class="fa fa-info-circle"></i> 订单归属下单前最近一次成功上架用户</span></div>
        </div>
        <div class="statistics-table-wrap">
            <table class="statistics-table">
                <thead><tr><th class="col-user">上架用户</th><th class="col-product">产品信息</th><th class="col-store">上架店铺</th><th class="col-sku">SKU</th><th class="col-time">上架时间</th><th class="col-number">订单数</th><th class="col-number">商品件数</th><th class="col-money">营业额($)</th><th class="col-order-time">首次出单</th><th class="col-order-time">最近出单</th></tr></thead>
                <tbody>
                @forelse($publications as $publication)
                    @php
                        $stats = $publication->order_statistics;
                        $publisherName = $publication->published_by_name ?: '未知用户';
                        $publishedAt = optional($publication->published_at)->timezone('Asia/Shanghai');
                        $firstOrderAt = $stats['first_order_at'] ? \Carbon\CarbonImmutable::parse($stats['first_order_at'], 'UTC')->timezone('Asia/Shanghai') : null;
                        $lastOrderAt = $stats['last_order_at'] ? \Carbon\CarbonImmutable::parse($stats['last_order_at'], 'UTC')->timezone('Asia/Shanghai') : null;
                    @endphp
                    <tr>
                        <td><div class="publisher-cell"><span class="publisher-avatar">{{ mb_substr($publisherName, 0, 1) }}</span><span class="publisher-name" title="{{ $publisherName }}">{{ $publisherName }}</span></div></td>
                        <td class="product-cell"><span class="product-name">{{ $publication->product?->name ?: '产品已删除' }}</span><span class="product-meta"><span class="product-code">{{ $publication->product?->product_code ?: '—' }}</span>@if($publication->productPage?->name)<span>· {{ $publication->productPage->name }}</span>@endif</span></td>
                        <td><span class="store-domain" title="{{ $publication->store?->domain ?: '—' }}">{{ $publication->store?->domain ?: '—' }}</span>@if($publication->store?->shop_name && $publication->store->shop_name !== $publication->store->domain)<span class="store-name" title="{{ $publication->store->shop_name }}">{{ $publication->store->shop_name }}</span>@endif</td>
                        <td><span class="sku-code">{{ $publication->sku ?: '—' }}</span></td>
                        <td><span class="date-stack">@if($publishedAt)<strong>{{ $publishedAt->format('Y-m-d') }}</strong><small>{{ $publishedAt->format('H:i') }}</small>@else<strong>—</strong>@endif</span></td>
                        <td>
                            @if($stats['order_count'] > 0)
                                <button class="order-badge order-detail-trigger" type="button"
                                    data-order-detail-url="{{ route('aikaipin.product-order-statistics.orders', ['publication' => $publication->getKey(), 'date_from' => $filters['date_from'], 'date_to' => $filters['date_to']]) }}"
                                    data-order-detail-title="{{ $publication->product?->name ?: $publication->sku ?: '产品订单' }}"
                                    title="点击查看具体订单">{{ number_format($stats['order_count']) }}</button>
                            @else
                                <span class="order-badge is-zero">0</span>
                            @endif
                        </td>
                        <td><span class="quantity-value">{{ number_format($stats['item_quantity'], 0) }}</span></td>
                        <td><span class="money-value">${{ number_format($stats['revenue'], 2) }}</span></td>
                        <td><span class="date-stack">@if($firstOrderAt)<strong>{{ $firstOrderAt->format('Y-m-d') }}</strong><small>{{ $firstOrderAt->format('H:i') }}</small>@else<strong>—</strong>@endif</span></td>
                        <td><span class="date-stack">@if($lastOrderAt)<strong>{{ $lastOrderAt->format('Y-m-d') }}</strong><small>{{ $lastOrderAt->format('H:i') }}</small>@else<strong>—</strong>@endif</span></td>
                    </tr>
                @empty
                    <tr><td colspan="10" class="empty-cell"><i class="fa fa-inbox"></i>暂无符合筛选条件的成功上架记录</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @php($lastPage = max(1, $publications->lastPage()))
        <nav class="flt-pagination statistics-pagination" aria-label="出单统计分页">
            <form method="get" action="{{ request()->url() }}">
                @foreach(request()->query() as $key => $value)
                    @if(!in_array($key, ['page', 'per_page'], true) && !is_array($value))<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endif
                @endforeach
                <label>每页显示
                    <select name="per_page" aria-label="每页显示条数" onchange="this.form.submit()">
                        @foreach([20, 50, 100] as $size)<option value="{{ $size }}" @selected((int)$filters['per_page'] === $size)>{{ $size }}</option>@endforeach
                    </select> 条
                </label>
            </form>
            <a class="flt-btn {{ $publications->onFirstPage() ? 'is-disabled' : '' }}" href="{{ $publications->previousPageUrl() ?: '#' }}" @if($publications->onFirstPage()) aria-disabled="true" tabindex="-1" @endif>上一页</a>
            <strong class="statistics-page-label">第 {{ $publications->currentPage() }} / {{ $lastPage }} 页</strong>
            <a class="flt-btn {{ $publications->hasMorePages() ? '' : 'is-disabled' }}" href="{{ $publications->nextPageUrl() ?: '#' }}" @unless($publications->hasMorePages()) aria-disabled="true" tabindex="-1" @endunless>下一页</a>
            <form method="get" action="{{ request()->url() }}" novalidate>
                @foreach(request()->query() as $key => $value)
                    @if($key !== 'page' && !is_array($value))<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endif
                @endforeach
                <label>跳至 <input type="number" name="page" min="1" max="{{ $lastPage }}" step="1" required placeholder="页码" aria-label="跳转页码"> 页</label>
                <button class="flt-btn flt-primary" type="submit">跳转</button>
            </form>
        </nav>
    </div>
</section>

<div id="product-order-detail-modal" class="modal fade order-detail-modal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="关闭"><span aria-hidden="true">&times;</span></button>
                <h4 class="modal-title"><i class="fa fa-file-text-o"></i> <span data-order-detail-title>订单明细</span></h4>
            </div>
            <div class="modal-body" data-order-detail-body><div class="order-detail-loading"><i class="fa fa-spinner fa-spin"></i> 正在加载订单明细...</div></div>
            <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">关闭</button></div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    var modal = $('#product-order-detail-modal');
    var body = modal.find('[data-order-detail-body]');
    var title = modal.find('[data-order-detail-title]');

    function escapeHtml(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (character) {
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[character];
        });
    }

    function money(value) {
        return '$' + Number(value || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    }

    function dateTime(value) {
        if (!value) return '—';
        return escapeHtml(String(value).replace('T', ' ').replace(/\.000000Z$/, '').replace(/Z$/, ''));
    }

    function render(payload) {
        var orders = Array.isArray(payload.orders) ? payload.orders : [];
        var summary = payload.summary || {};
        if (!orders.length) {
            body.html('<div class="order-detail-empty"><i class="fa fa-inbox"></i> 当前时间范围内暂无订单</div>');
            return;
        }

        var rows = orders.map(function (order) {
            var items = (order.items || []).map(function (item) {
                var variant = item.variant_title ? '<small>' + escapeHtml(item.variant_title) + '</small>' : '';
                return '<div class="order-detail-product"><strong>' + escapeHtml(item.product_title || '—') + '</strong>' + variant + '</div>';
            }).join('');
            var skus = (order.items || []).map(function (item) { return escapeHtml(item.sku || '—'); }).join('<br>');
            return '<tr>' +
                '<td><strong>' + escapeHtml(order.order_number || '—') + '</strong><br><small>' + escapeHtml(order.shopify_order_id || '') + '</small></td>' +
                '<td>' + dateTime(order.ordered_at) + '</td>' +
                '<td>' + escapeHtml(order.store_domain || order.store_name || '—') + '</td>' +
                '<td class="order-detail-product">' + items + '</td>' +
                '<td class="order-detail-sku">' + skus + '</td>' +
                '<td>' + Number(order.item_quantity || 0).toLocaleString('en-US', {maximumFractionDigits: 4}) + '</td>' +
                '<td><strong>' + money(order.revenue) + '</strong></td>' +
            '</tr>';
        }).join('');

        body.html(
            '<div class="order-detail-summary"><span>订单数 <strong>' + Number(summary.order_count || 0).toLocaleString() + '</strong></span>' +
            '<span>商品件数 <strong>' + Number(summary.item_quantity || 0).toLocaleString('en-US', {maximumFractionDigits: 4}) + '</strong></span>' +
            '<span>营业额 <strong>' + money(summary.revenue) + '</strong></span></div>' +
            '<table class="order-detail-table"><thead><tr><th>订单号</th><th>下单时间</th><th>店铺</th><th>商品信息</th><th>SKU</th><th>数量</th><th>营业额</th></tr></thead><tbody>' + rows + '</tbody></table>'
        );
    }

    $(document).on('click', '[data-order-detail-url]', function () {
        var button = $(this);
        title.text(button.data('order-detail-title') + ' · 订单明细');
        body.html('<div class="order-detail-loading"><i class="fa fa-spinner fa-spin"></i> 正在加载订单明细...</div>');
        modal.modal('show');

        fetch(button.attr('data-order-detail-url'), {headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}, credentials: 'same-origin'})
            .then(function (response) {
                if (!response.ok) throw new Error('订单明细加载失败');
                return response.json();
            })
            .then(render)
            .catch(function (error) {
                body.html('<div class="order-detail-error"><i class="fa fa-exclamation-circle"></i> ' + escapeHtml(error.message || '订单明细加载失败') + '</div>');
            });
    });
})();
</script>
@endpush
