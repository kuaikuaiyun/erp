@extends('portal.layout')

@section('page_title', '出单统计')

@php
    $portalOrigin = rtrim(request()->getSchemeAndHttpHost(), '/');
    $conversionRate = $summary['product_count'] > 0
        ? $summary['converted_count'] / $summary['product_count'] * 100
        : 0;
@endphp

@push('styles')
    <link rel="stylesheet" href="{{ $portalOrigin }}/css/frontend-list-template.css?v=20261125-1">
    <style>
        .ai-order-statistics{padding:16px;color:#27475c}.ai-order-statistics .stats-page-head{display:flex;align-items:center;justify-content:space-between;gap:18px;margin:0 0 14px;padding:2px 2px 0}.stats-title-wrap{display:flex;align-items:center;gap:12px}.stats-title-icon{display:inline-flex;align-items:center;justify-content:center;width:42px;height:42px;border-radius:12px;background:linear-gradient(145deg,#e2f4fe,#f4fbff);color:#1884bf;font-size:20px;box-shadow:inset 0 0 0 1px #d4eaf6}.stats-title-wrap h2{margin:0;color:#183f58;font-size:22px;font-weight:700}.stats-title-wrap p{margin:4px 0 0;color:#8395a3;font-size:12px}.stats-range-chip{display:inline-flex;align-items:center;gap:7px;padding:8px 12px;border:1px solid #dce9f1;border-radius:18px;background:#fff;color:#557187;font-size:12px;white-space:nowrap}
        .ai-order-statistics .flt-filters{margin-bottom:14px;padding:16px 18px 14px}.ai-order-statistics .statistics-filter-grid{display:grid;grid-template-columns:minmax(260px,1.7fr) minmax(150px,.8fr) minmax(210px,1.15fr) minmax(145px,.72fr) minmax(145px,.72fr);gap:12px 14px;align-items:end}.statistics-filter-field,.statistics-filter-grid .operation-ai-template-picker-field{min-width:0}.statistics-filter-field .flt-field-label,.statistics-filter-grid .operation-ai-template-picker-field .flt-field-label{margin-bottom:6px;color:#46667a;font-size:12px}.ai-order-statistics .statistics-filter-grid input,.ai-order-statistics .statistics-filter-grid select{height:36px;min-height:36px}.statistics-filter-grid .operation-ai-template-picker{width:100%}.statistics-filter-grid .operation-ai-template-picker>summary{height:36px;min-height:36px;padding:8px 34px 8px 11px}.statistics-filter-grid .flt-picker-panel{z-index:108}.statistics-filter-grid .operation-ai-template-picker-search{min-height:34px!important;margin-bottom:5px;padding:7px 10px!important}.statistics-filter-grid .flt-picker-options{margin-top:0}.statistics-filter-grid .flt-picker-options label{font-weight:400}.ai-order-statistics .flt-filter-actions{margin-top:12px;padding-top:11px;border-top:1px dashed #e2ebf1}.ai-order-statistics .flt-filter-actions .flt-btn{min-width:84px}
        .statistics-summary{display:grid;grid-template-columns:repeat(5,minmax(150px,1fr));gap:12px;margin-bottom:14px}.statistics-card{position:relative;display:flex;align-items:center;min-height:84px;padding:14px 16px;overflow:hidden}.statistics-card:after{content:"";position:absolute;right:-20px;bottom:-28px;width:76px;height:76px;border-radius:50%;background:currentColor;opacity:.055}.statistics-card-icon{display:inline-flex;align-items:center;justify-content:center;flex:0 0 38px;width:38px;height:38px;margin-right:12px;border-radius:10px;background:#edf7fc;color:#2188be;font-size:16px}.statistics-card-copy{min-width:0}.statistics-card-copy span{display:block;color:#7b8f9e;font-size:12px;white-space:nowrap}.statistics-card-copy strong{display:block;margin-top:4px;color:#173f59;font-size:21px;line-height:1.15;white-space:nowrap}.statistics-card-copy small{display:block;margin-top:3px;color:#8b9ba7;font-size:11px}.statistics-card.is-converted .statistics-card-icon{background:#eaf8f2;color:#149263}.statistics-card.is-orders .statistics-card-icon{background:#eef3ff;color:#5174c8}.statistics-card.is-items .statistics-card-icon{background:#fff6e9;color:#dc891d}.statistics-card.is-revenue .statistics-card-icon{background:#fff0eb;color:#dc6c3d}.statistics-card.is-revenue strong{color:#d86628}
        .statistics-records{overflow:hidden}.statistics-toolbar{display:flex;align-items:center;justify-content:space-between;gap:15px;padding:13px 16px;border-bottom:1px solid #dfe9f0;background:linear-gradient(180deg,#fff,#fbfdfe)}.statistics-toolbar h3{display:flex;align-items:center;gap:8px;color:#264e67}.statistics-toolbar-meta{display:flex;align-items:center;gap:12px;color:#80929f;font-size:12px}.statistics-toolbar-meta strong{color:#3e657c}.statistics-match-tip{display:inline-flex;align-items:center;gap:5px;padding-left:12px;border-left:1px solid #dfe8ee}
        .statistics-table-wrap{overflow-x:auto}.statistics-table{width:100%;min-width:960px;margin:0;border-collapse:separate;border-spacing:0;table-layout:fixed}.statistics-table th{position:sticky;top:0;z-index:2;height:44px;padding:9px 8px;border-right:1px solid #d8e6ee;border-bottom:1px solid #d5e3eb;background:#eaf4fa;color:#345c75;font-size:12px;font-weight:600;text-align:center;white-space:nowrap}.statistics-table td{height:76px;padding:8px 10px;border-right:1px solid #e5edf2;border-bottom:1px solid #e5edf2;text-align:center;vertical-align:middle}.statistics-table th:last-child,.statistics-table td:last-child{border-right:0}.statistics-table tbody tr:nth-child(odd) td{background:#fff}.statistics-table tbody tr:nth-child(even) td{background:#f5fafe}.statistics-table tbody tr:hover td{background:#eaf6fc}.statistics-table .col-image{width:90px}.statistics-table .col-product{width:auto}.statistics-table .col-creator{width:120px}.statistics-table .col-number{width:125px}.statistics-table .col-money{width:150px}.statistics-table .col-action{width:110px}.statistics-detail-button{white-space:nowrap}
        .publisher-cell{display:flex;align-items:center;gap:9px;text-align:left}.publisher-avatar{display:inline-flex;align-items:center;justify-content:center;flex:0 0 30px;width:30px;height:30px;border-radius:50%;background:#ddf1fb;color:#187caf;font-weight:700}.publisher-name{display:block;max-width:88px;overflow:hidden;color:#244e67;font-weight:600;text-overflow:ellipsis;white-space:nowrap}.statistics-product-image{display:inline-flex;align-items:center;justify-content:center;width:56px;height:56px;overflow:hidden;border:1px solid #d7e5ed;border-radius:8px;background:#f4f8fa;color:#a7bac6;text-decoration:none;box-shadow:0 1px 3px rgba(31,72,96,.08)}.statistics-product-image:hover,.statistics-product-image:focus{border-color:#69a9cf;box-shadow:0 3px 9px rgba(34,117,162,.18);outline:0}.statistics-product-image img{display:block;width:100%;height:100%;object-fit:cover}.statistics-product-image i{font-size:20px}.product-cell{text-align:left!important;overflow-wrap:anywhere}.product-name{display:block;color:#21485f;font-weight:600;line-height:1.45;white-space:normal;word-break:break-word}.product-meta{display:flex;align-items:flex-start;flex-wrap:wrap;gap:5px;margin-top:5px;color:#8093a1;font-size:11px;line-height:1.45}.product-code{display:inline-block;max-width:100%;padding:2px 6px;border:1px solid #d9e7ef;border-radius:9px;background:#f7fbfd;color:#5d7d90;overflow-wrap:anywhere;white-space:normal}.store-domain{display:block;max-width:145px;overflow:hidden;color:#287aa7;font-weight:600;text-overflow:ellipsis;white-space:nowrap}.store-name{display:block;max-width:145px;margin-top:3px;overflow:hidden;color:#8b9ca8;font-size:11px;text-overflow:ellipsis;white-space:nowrap}.sku-code{display:block;width:100%;padding:4px 6px;border-radius:4px;background:#f2f6f9;color:#526f81;font-family:Consolas,monospace;font-size:11px;line-height:1.45;overflow-wrap:anywhere;word-break:break-all;white-space:normal}.date-stack strong,.date-stack small{display:block;white-space:nowrap}.date-stack strong{color:#49687a;font-size:12px}.date-stack small{margin-top:3px;color:#93a2ad;font-size:11px}.order-badge{display:inline-flex;align-items:center;justify-content:center;min-width:34px;height:24px;padding:0 8px;border:0;border-radius:12px;background:#e8f7f0;color:#07844f;font-weight:700}.order-detail-trigger{cursor:pointer}.order-badge.is-zero{background:#f1f4f6;color:#9aa8b1;font-weight:500}.quantity-value{color:#486778;font-weight:600}.money-value{color:#d66726;font-weight:700}.empty-cell{padding:46px 20px!important;color:#8b9ca8}.empty-cell i{display:block;margin-bottom:8px;color:#bad0dc;font-size:27px}
        .order-detail-modal .modal-dialog{width:min(1060px,calc(100% - 30px))}.order-detail-modal .modal-content{overflow:hidden;border:0;border-radius:10px;box-shadow:0 12px 38px rgba(22,60,82,.24)}.order-detail-modal .modal-header{padding:15px 18px;border-bottom:1px solid #dce8ef;background:#f5fafc}.order-detail-modal .modal-title{color:#244d66;font-weight:700}.order-detail-modal .modal-body{max-height:70vh;padding:0;overflow:auto}.order-detail-filter{display:flex;align-items:center;gap:10px;padding:12px 18px;border-bottom:1px solid #e3edf2;background:#f8fbfd}.order-detail-filter label{margin:0;color:#42657a;font-size:12px;font-weight:600;white-space:nowrap}.order-detail-filter select{width:min(360px,100%);height:34px;padding:6px 30px 6px 10px;border:1px solid #cbdde8;border-radius:5px;background:#fff;color:#34586e}.order-detail-summary{display:flex;gap:22px;padding:12px 18px;border-bottom:1px solid #e3edf2;background:#fff;color:#728895;font-size:12px}.order-detail-summary strong{margin-left:4px;color:#24536f;font-size:14px}.order-detail-table{width:100%;min-width:920px;margin:0;border-collapse:collapse}.order-detail-table th{position:sticky;top:0;z-index:2;padding:10px 9px;border-bottom:1px solid #d5e4ec;background:#eaf4fa;color:#355e76;font-size:12px;text-align:center;white-space:nowrap}.order-detail-table td{padding:10px 9px;border-right:1px solid #e6eef3;border-bottom:1px solid #e6eef3;color:#486779;font-size:12px;text-align:center;vertical-align:top}.order-detail-table tbody tr:nth-child(even) td{background:#f6fafc}.order-detail-product{text-align:left!important}.order-detail-product strong,.order-detail-product small{display:block}.order-detail-product small{margin-top:3px;color:#8599a6}.order-detail-sku{font-family:Consolas,monospace;word-break:break-all}.order-detail-loading,.order-detail-empty{padding:50px 20px;color:#8497a4;text-align:center}.order-detail-loading i{margin-right:6px}.order-detail-error{padding:18px;color:#c04b46;text-align:center}
        .publication-detail-section{padding:14px 18px;border-bottom:1px solid #e1ebf1;background:#fbfdfe}.publication-detail-section h5{margin:0 0 9px;color:#355e76;font-weight:700}.publication-detail-table{width:100%;border:1px solid #dce8ef;border-radius:6px;border-collapse:separate;border-spacing:0;overflow:hidden;background:#fff}.publication-detail-table th,.publication-detail-table td{padding:8px 9px;border-right:1px solid #e4edf2;border-bottom:1px solid #e4edf2;font-size:12px;text-align:center}.publication-detail-table th{background:#edf6fb;color:#42677c;font-weight:600}.publication-detail-table tr:last-child td{border-bottom:0}.publication-detail-table th:last-child,.publication-detail-table td:last-child{border-right:0}.publication-detail-table .is-money{color:#d66726;font-weight:700}.publication-detail-table .is-sku{font-family:Consolas,monospace;word-break:break-all}
        .statistics-pagination{border-top:1px solid #dfe9f0}.statistics-pagination .is-disabled{pointer-events:none;opacity:.42}.statistics-pagination .statistics-page-label{min-width:68px;text-align:center}
        @media(min-width:1281px){.ai-order-statistics .statistics-filter-grid{grid-template-columns:minmax(240px,1.5fr) minmax(150px,.8fr) minmax(150px,.8fr) minmax(200px,1.1fr) minmax(140px,.7fr) minmax(140px,.7fr)}}
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
            @include('portals.operation.ai-product-library.partials.filter-single-picker', ['label' => '产品创建人', 'name' => 'created_by', 'selected' => $filters['created_by'], 'options' => ['' => '全部创建人'] + $productCreatorOptions->all(), 'searchable' => true])
            @include('portals.operation.ai-product-library.partials.filter-single-picker', ['label' => '上架用户', 'name' => 'published_by', 'selected' => $filters['published_by'], 'options' => ['' => '全部用户'] + $publisherOptions->all(), 'searchable' => true])
            @include('portals.operation.ai-product-library.partials.filter-single-picker', ['label' => '上架店铺', 'name' => 'store_id', 'selected' => $filters['store_id'], 'options' => [0 => '全部店铺'] + $storeOptions->all(), 'searchable' => true])
            <div class="statistics-filter-field"><label class="flt-field-label" for="stats-from">订单开始日期</label><input id="stats-from" type="date" name="date_from" value="{{ $filters['date_from'] }}"></div>
            <div class="statistics-filter-field"><label class="flt-field-label" for="stats-to">订单结束日期</label><input id="stats-to" type="date" name="date_to" value="{{ $filters['date_to'] }}"></div>
        </div>
        <div class="flt-filter-actions"><button class="flt-btn flt-primary" type="submit"><i class="fa fa-search"></i> 查询</button><a class="flt-btn" href="{{ route('aikaipin.product-order-statistics.index') }}"><i class="fa fa-refresh"></i> 重置</a></div>
    </form>

    <div class="statistics-summary">
        <div class="flt-card statistics-card"><span class="statistics-card-icon"><i class="fa fa-cloud-upload"></i></span><div class="statistics-card-copy"><span>成功上架产品</span><strong>{{ number_format($summary['product_count']) }}</strong><small>{{ number_format($summary['publication_count']) }} 条上架记录</small></div></div>
        <div class="flt-card statistics-card is-converted"><span class="statistics-card-icon"><i class="fa fa-check-circle"></i></span><div class="statistics-card-copy"><span>已出单产品</span><strong>{{ number_format($summary['converted_count']) }}</strong><small>出单率 {{ number_format($conversionRate, 1) }}%</small></div></div>
        <div class="flt-card statistics-card is-orders"><span class="statistics-card-icon"><i class="fa fa-file-text-o"></i></span><div class="statistics-card-copy"><span>订单数</span><strong>{{ number_format($summary['order_count']) }} 单</strong><small>按 Shopify 订单去重</small></div></div>
        <div class="flt-card statistics-card is-items"><span class="statistics-card-icon"><i class="fa fa-cubes"></i></span><div class="statistics-card-copy"><span>商品件数</span><strong>{{ number_format($summary['item_quantity'], 0) }} 件</strong><small>按订单商品数量累计</small></div></div>
        <div class="flt-card statistics-card is-revenue"><span class="statistics-card-icon"><i class="fa fa-usd"></i></span><div class="statistics-card-copy"><span>商品营业额</span><strong>${{ number_format($summary['revenue'], 2) }}</strong><small>匹配商品行营业额</small></div></div>
    </div>

    <div class="flt-card flt-records statistics-records">
        <div class="statistics-toolbar">
            <h3><i class="fa fa-th-list"></i> 产品出单明细</h3>
            <div class="statistics-toolbar-meta"><span>共 <strong>{{ number_format($products->total()) }}</strong> 个产品</span><span class="statistics-match-tip"><i class="fa fa-info-circle"></i> 同一产品的上架店铺与订单已合并统计</span></div>
        </div>
        <div class="statistics-table-wrap">
            <table class="statistics-table">
                <thead><tr><th class="col-image">产品图片</th><th class="col-product">产品信息</th><th class="col-creator">产品创建人</th><th class="col-number">订单数</th><th class="col-number">商品件数</th><th class="col-money">营业额($)</th><th class="col-action">操作</th></tr></thead>
                <tbody>
                @forelse($products as $publication)
                    @php
                        $stats = $publication->order_statistics;
                        $productImages = $publication->product?->imageFiles() ?? [];
                        $productImageUrl = $productImages !== [] ? route('aikaipin.product-library.images.show', [
                            'product' => $publication->product,
                            'imageIndex' => 0,
                            'v' => $publication->product?->updated_at?->getTimestamp() ?? time(),
                        ]) : null;
                        $productThumbnailUrl = $productImageUrl ? $productImageUrl.'&thumb=1' : null;
                    @endphp
                    <tr>
                        <td>@if($productThumbnailUrl)<a class="statistics-product-image" href="{{ $productImageUrl }}" target="_blank" rel="noopener noreferrer" title="查看产品图片"><img src="{{ $productThumbnailUrl }}" alt="{{ $publication->product?->name ?: '产品图片' }}" loading="lazy" decoding="async" width="56" height="56"></a>@else<span class="statistics-product-image" title="暂无产品图片"><i class="fa fa-picture-o" aria-hidden="true"></i></span>@endif</td>
                        <td class="product-cell"><span class="product-name">{{ $publication->product?->name ?: '产品已删除' }}</span><span class="product-meta"><span class="product-code">{{ $publication->product?->product_code ?: '—' }}</span><span>· {{ number_format($publication->publication_count) }} 条上架记录</span></span></td>
                        <td>{{ $publication->product?->created_by_name ?: '—' }}</td>
                        <td><span class="order-badge {{ $stats['order_count'] > 0 ? '' : 'is-zero' }}">{{ number_format($stats['order_count']) }}</span></td>
                        <td><span class="quantity-value">{{ number_format($stats['item_quantity'], 0) }}</span></td>
                        <td><span class="money-value">${{ number_format($stats['revenue'], 2) }}</span></td>
                        <td><button class="flt-btn flt-primary statistics-detail-button order-detail-trigger" type="button" data-order-detail-url="{{ route('aikaipin.product-order-statistics.products.details', ['product' => $publication->aikaipin_product_id, 'date_from' => $filters['date_from'], 'date_to' => $filters['date_to'], 'keyword' => $filters['keyword'], 'created_by' => $filters['created_by'], 'published_by' => $filters['published_by'], 'store_id' => $filters['store_id']]) }}" data-order-detail-title="{{ $publication->product?->name ?: $publication->sku ?: '产品详情' }}"><i class="fa fa-eye" aria-hidden="true"></i> 查看详情</button></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="empty-cell"><i class="fa fa-inbox"></i>暂无符合筛选条件的成功上架产品</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @php($lastPage = max(1, $products->lastPage()))
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
            <a class="flt-btn {{ $products->onFirstPage() ? 'is-disabled' : '' }}" href="{{ $products->previousPageUrl() ?: '#' }}" @if($products->onFirstPage()) aria-disabled="true" tabindex="-1" @endif>上一页</a>
            <strong class="statistics-page-label">第 {{ $products->currentPage() }} / {{ $lastPage }} 页</strong>
            <a class="flt-btn {{ $products->hasMorePages() ? '' : 'is-disabled' }}" href="{{ $products->nextPageUrl() ?: '#' }}" @unless($products->hasMorePages()) aria-disabled="true" tabindex="-1" @endunless>下一页</a>
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
                <h4 class="modal-title"><i class="fa fa-file-text-o"></i> <span data-order-detail-title>产品详情</span></h4>
            </div>
            <div class="modal-body" data-order-detail-body><div class="order-detail-loading"><i class="fa fa-spinner fa-spin"></i> 正在加载产品详情...</div></div>
            <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">关闭</button></div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    var filterPickers = Array.prototype.slice.call(document.querySelectorAll('[data-operation-ai-template-picker]'));
    filterPickers.forEach(function (picker) {
        var summary = picker.querySelector('[data-picker-summary]');
        var search = picker.querySelector('[data-picker-search]');
        var options = Array.prototype.slice.call(picker.querySelectorAll('[data-picker-option]'));
        var empty = picker.querySelector('[data-picker-empty]');

        picker.querySelectorAll('input[type="radio"]').forEach(function (input) {
            input.addEventListener('change', function () {
                summary.textContent = input.closest('label').querySelector('span').textContent.trim();
                picker.removeAttribute('open');
            });
        });
        if (search) {
            search.addEventListener('input', function () {
                var keyword = search.value.trim().toLowerCase();
                var visible = 0;
                options.forEach(function (option) {
                    var matched = !keyword || option.textContent.toLowerCase().indexOf(keyword) !== -1;
                    option.hidden = !matched;
                    if (matched) visible++;
                });
                empty.hidden = visible !== 0;
            });
            picker.addEventListener('toggle', function () {
                if (!picker.open) return;
                search.value = '';
                options.forEach(function (option) { option.hidden = false; });
                empty.hidden = true;
                window.setTimeout(function () { search.focus(); }, 0);
            });
        }
    });
    document.addEventListener('click', function (event) {
        filterPickers.forEach(function (picker) {
            if (picker.open && !picker.contains(event.target)) picker.removeAttribute('open');
        });
    });

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
        var publications = Array.isArray(payload.publications) ? payload.publications : [];
        var storeLabels = {};
        publications.forEach(function (publication) {
            var storeId = String(publication.store_id || '');
            if (storeId) storeLabels[storeId] = publication.store || '未知店铺';
        });

        function renderStore(selectedStoreId) {
            selectedStoreId = String(selectedStoreId || '');
            var visiblePublications = publications.filter(function (publication) {
                return !selectedStoreId || String(publication.store_id || '') === selectedStoreId;
            });
            var visibleOrders = orders.filter(function (order) {
                return !selectedStoreId || String(order.store_id || '') === selectedStoreId;
            });
            var itemQuantity = visibleOrders.reduce(function (total, order) { return total + Number(order.item_quantity || 0); }, 0);
            var revenue = visibleOrders.reduce(function (total, order) { return total + Number(order.revenue || 0); }, 0);
            var visibleStoreCount = {};
            visiblePublications.forEach(function (publication) { visibleStoreCount[String(publication.store_id || '')] = true; });

            var storeOptions = Object.keys(storeLabels).sort(function (left, right) {
                return String(storeLabels[left]).localeCompare(String(storeLabels[right]), 'zh-CN');
            }).map(function (storeId) {
                return '<option value="' + escapeHtml(storeId) + '"' + (selectedStoreId === storeId ? ' selected' : '') + '>' + escapeHtml(storeLabels[storeId]) + '</option>';
            }).join('');
            var filterHtml = '<div class="order-detail-filter"><label for="product-order-store-filter"><i class="fa fa-filter"></i> 按店铺筛选订单</label>' +
                '<select id="product-order-store-filter" data-order-store-filter><option value="">全部店铺</option>' + storeOptions + '</select></div>';

            var summaryHtml = '<div class="order-detail-summary"><span>订单数 <strong>' + visibleOrders.length.toLocaleString() + '</strong></span>' +
                '<span>商品件数 <strong>' + itemQuantity.toLocaleString('en-US', {maximumFractionDigits: 4}) + '</strong></span>' +
                '<span>营业额 <strong>' + money(revenue) + '</strong></span></div>';

            var publicationRows = visiblePublications.map(function (publication) {
                var store = '<strong>' + escapeHtml(publication.store || '—') + '</strong>';
                if (publication.page && publication.page !== '—') store += '<br><small>' + escapeHtml(publication.page) + '</small>';

                return '<tr>' +
                    '<td><strong>' + escapeHtml(publication.publisher || '—') + '</strong></td>' +
                    '<td>' + store + '</td>' +
                    '<td class="is-sku">' + escapeHtml(publication.sku || '—') + '</td>' +
                    '<td>' + escapeHtml(publication.published_at || '—') + '</td>' +
                    '<td><strong>' + Number(publication.order_count || 0).toLocaleString() + '</strong></td>' +
                    '<td>' + Number(publication.item_quantity || 0).toLocaleString('en-US', {maximumFractionDigits: 4}) + '</td>' +
                    '<td class="is-money">' + money(publication.revenue) + '</td>' +
                '</tr>';
            }).join('');
            var publicationSection = '<section class="publication-detail-section">' +
                '<h5>运营与上架店铺出单明细（' + visiblePublications.length.toLocaleString() + ' 条上架记录 / ' + Object.keys(visibleStoreCount).filter(Boolean).length.toLocaleString() + ' 家店铺）</h5>' +
                '<div class="table-responsive"><table class="publication-detail-table"><thead><tr><th>运营人员</th><th>上架店铺</th><th>SKU</th><th>上架时间</th><th>订单数</th><th>商品件数</th><th>营业额</th></tr></thead><tbody>' + publicationRows + '</tbody></table></div>' +
            '</section>';

            var rows = visibleOrders.map(function (order) {
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
            var ordersHtml = visibleOrders.length
                ? '<table class="order-detail-table"><thead><tr><th>订单号</th><th>下单时间</th><th>店铺</th><th>商品信息</th><th>SKU</th><th>数量</th><th>营业额</th></tr></thead><tbody>' + rows + '</tbody></table>'
                : '<div class="order-detail-empty"><i class="fa fa-inbox"></i> 当前店铺在所选时间范围内暂无订单</div>';

            body.html(filterHtml + summaryHtml + publicationSection + ordersHtml);
            body.find('[data-order-store-filter]').on('change', function () { renderStore(this.value); });
        }

        renderStore('');
    }

    $(document).on('click', '[data-order-detail-url]', function () {
        var button = $(this);
        title.text(button.data('order-detail-title') + ' · 产品详情');
        body.html('<div class="order-detail-loading"><i class="fa fa-spinner fa-spin"></i> 正在加载产品详情...</div>');
        modal.modal('show');

        fetch(button.attr('data-order-detail-url'), {headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}, credentials: 'same-origin'})
            .then(function (response) {
                if (!response.ok) throw new Error('产品详情加载失败');
                return response.json();
            })
            .then(render)
            .catch(function (error) {
                body.html('<div class="order-detail-error"><i class="fa fa-exclamation-circle"></i> ' + escapeHtml(error.message || '产品详情加载失败') + '</div>');
            });
    });
})();
</script>
@endpush
