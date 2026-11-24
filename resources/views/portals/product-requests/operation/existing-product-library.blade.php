@extends('portal.layout')

@section('page_title', $libraryTitle ?? '已有产品库')

@php($portalOrigin = rtrim(request()->getSchemeAndHttpHost(), '/'))
@push('styles')
<link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/product-requests.css?v=20261022-01">
<link rel="stylesheet" href="{{ $portalOrigin }}/css/frontend-list-template.css?v=20261124-20">
<style>
.existing-library-template{padding:20px}.existing-library-template .content-header{padding:0 0 15px}.existing-library-template .content-header h1{margin:0;font-size:24px}.existing-library-template .content-header p{margin:6px 0 0;color:#718697}.existing-library-template .flt-filters{margin-bottom:16px;padding:17px 20px 15px}.existing-library-template .flt-filter-grid{grid-template-columns:minmax(280px,2fr) repeat(2,minmax(180px,1fr));gap:14px 18px}.existing-library-template .flt-records{overflow:hidden}.existing-library-template .flt-toolbar{padding:12px 18px}.existing-library-template .flt-table-scroll{max-height:calc(100vh - 330px);min-height:320px}.existing-library-template .flt-table-scroll table{min-width:1120px;table-layout:auto}.existing-library-template .flt-table-scroll th{position:sticky;top:0;z-index:4;padding:11px 12px;background:#eaf4fa;color:#345c75;border-right:1px solid #dce8ef}.existing-library-template .flt-table-scroll td{max-width:280px;padding:11px 12px;text-align:left;border-right:1px solid #e7eef3}.existing-library-template .flt-table-scroll th:last-child,.existing-library-template .flt-table-scroll td:last-child{border-right:0}.existing-library-template .flt-table-scroll tbody tr:nth-child(odd)>td{background:#fff}.existing-library-template .flt-table-scroll tbody tr:nth-child(even)>td{background:#f3f9fd}.existing-library-template .flt-table-scroll tbody tr:hover>td{background:#eaf5fb!important}.existing-library-template .product-request-sticky-left{min-width:86px}.existing-library-template .product-request-sticky-right{min-width:110px}.existing-library-template .flt-table-scroll tbody tr:nth-child(even)>.product-request-sticky-left,.existing-library-template .flt-table-scroll tbody tr:nth-child(even)>.product-request-sticky-right{background:#f3f9fd!important}.existing-library-template .flt-table-scroll tbody tr:hover>.product-request-sticky-left,.existing-library-template .flt-table-scroll tbody tr:hover>.product-request-sticky-right{background:#eaf5fb!important}.existing-library-template .product-request-thumbnail{width:48px;height:48px}.existing-library-template .product-request-name-cell{min-width:180px}.existing-library-template .product-request-sku-cell{min-width:220px}.existing-library-template .product-request-link-wrap{max-width:230px}.existing-library-template .product-request-link-wrap a{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.existing-library-template .flt-pagination{border-top:1px solid #e3edf4}.existing-library-template .flt-pagination-total{margin-right:auto}.existing-library-template .flt-pagination .is-disabled{pointer-events:none;opacity:.45}.existing-library-template .flt-pagination form{display:flex;align-items:center;gap:8px}.existing-library-template .flt-pagination .page-size-form{margin:0 auto 0 0}.existing-library-template .flt-pagination .page-jump-form label{margin:0}.existing-library-template .flt-pagination .page-jump-form input{width:64px!important}.existing-library-template .product-request-empty{text-align:center!important}
.existing-library-template .existing-library-single-field{min-width:0}.existing-library-template .existing-library-single-field>.flt-field-label{margin-bottom:8px}.existing-library-template .existing-library-single-picker summary{color:#24455b}.existing-library-template .existing-library-single-picker[open] summary:after{transform:translateY(-20%) rotate(225deg)}.existing-library-template .existing-library-single-picker .flt-picker-panel{z-index:106}.existing-library-template .existing-library-single-picker .flt-picker-options{margin-top:0}.existing-library-template .existing-library-single-picker .flt-picker-options label{font-weight:400}.existing-library-template .existing-library-single-picker .flt-picker-options span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
@media(max-width:900px){.existing-library-template .flt-filter-grid{grid-template-columns:1fr 1fr}.existing-library-template .flt-filter-grid>label:first-child{grid-column:1/-1}}@media(max-width:600px){.existing-library-template{padding:12px}.existing-library-template .flt-filter-grid{grid-template-columns:1fr}.existing-library-template .flt-filter-grid>label:first-child{grid-column:auto}.existing-library-template .flt-pagination{justify-content:center}.existing-library-template .flt-pagination-total,.existing-library-template .flt-pagination .page-size-form{width:100%;margin:0;justify-content:center}}
</style>
@endpush
@push('scripts')
<script src="{{ $portalOrigin }}/assets/erp/product-requests.js?v=20261018-20"></script>
<script>
document.addEventListener('DOMContentLoaded',function(){
    var pickers=Array.prototype.slice.call(document.querySelectorAll('[data-existing-library-single-picker]'));
    pickers.forEach(function(picker){
        var summary=picker.querySelector('[data-picker-summary]');
        picker.querySelectorAll('input[type="radio"]').forEach(function(input){
            input.addEventListener('change',function(){
                var label=input.closest('label');
                summary.textContent=label?label.querySelector('span').textContent:'全部';
                picker.removeAttribute('open');
            });
        });
    });
    document.addEventListener('click',function(event){pickers.forEach(function(picker){if(!picker.contains(event.target))picker.removeAttribute('open');});});
});
</script>
@endpush

@section('content')
<section class="content product-request-page flt existing-library-template">
    <header class="content-header"><h1>{{ $libraryTitle ?? '已有产品库' }}</h1><p>{{ $libraryDescription ?? '查看和搜索已录入的 PCL SKU 产品数据' }}</p></header>
    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif
    <form class="flt-card flt-filters" method="get" action="{{ route($libraryRoute ?? 'product-requests.operation.existing-product-library') }}" data-loading="region" data-loading-target=".product-request-table-box">
        <div class="flt-filter-grid">
            <label>关键词<input name="keyword" type="search" value="{{ $filters['keyword'] }}" placeholder="商品名称 / PCL编号 / SKU / 1688链接"></label>
            @if($showRunnableFilter ?? true)@include('portals.product-requests.operation.partials.single-picker', ['label'=>'是否可跑','name'=>'is_runnable','selected'=>(string)($filters['is_runnable'] ?? ''),'options'=>[''=>'全部','1'=>'可跑','0'=>'不可跑']])@endif
            @include('portals.product-requests.operation.partials.single-picker', ['label'=>'不可跑原因','name'=>'not_runnable_reason','selected'=>(string)($filters['not_runnable_reason'] ?? ''),'options'=>[''=>'全部'] + \App\Models\ProductRequestLinkSku::NOT_RUNNABLE_REASONS])
            <input type="hidden" name="per_page" value="{{ $filters['per_page'] ?? 10 }}">
        </div>
        <div class="flt-filter-actions"><button class="flt-btn flt-primary" type="submit"><i class="fa fa-search"></i> 查询</button><a class="flt-btn" href="{{ route($libraryRoute ?? 'product-requests.operation.existing-product-library') }}"><i class="fa fa-refresh"></i> 重置</a></div>
    </form>
    <div class="flt-card flt-records product-request-table-box">
        <div class="flt-toolbar"><div class="flt-selection"><strong>{{ $libraryTitle ?? '已有产品库' }}</strong><span class="flt-toolbar-divider"></span><span>共 {{ $links->total() }} 条记录</span></div><div class="flt-toolbar-actions"><span class="flt-btn" style="cursor:default"><i class="fa fa-lock"></i> 只读模式</span></div></div>
        <div class="flt-table-region"><div class="flt-table-scroll product-request-table-scroll"><table class="product-request-table product-request-link-sku-table"><thead><tr><th class="product-request-sticky-left product-request-image-cell">商品</th><th>商品名称</th><th>PCL 编号</th><th class="product-request-sku-cell">SKU</th><th>是否可跑</th><th>不可跑原因</th><th>1688 链接</th><th>创建时间</th><th class="product-request-sticky-right">操作</th></tr></thead><tbody>
        @forelse($links as $link)
            @php($linkImages = array_values((array) ($link->images ?? [])))
            <tr><td class="product-request-sticky-left product-request-image-cell">@if($link->product_image)<img class="product-request-thumbnail" src="{{ route('product-requests.link-skus.product-image', $link) }}" alt="商品图片" data-product-request-image-preview data-product-request-image-alt="商品图片">@elseif($linkImages !== [])<img class="product-request-thumbnail" src="{{ route('product-requests.link-skus.image', [$link, 0]) }}" alt="商品图片" data-product-request-image-preview data-product-request-image-alt="商品图片">@else 暂无图片 @endif</td><td class="product-request-name-cell" title="{{ $nameMap[$link->supplier_url_hash] ?? '' }}">{{ $nameMap[$link->supplier_url_hash] ?? '未填写' }}</td><td>{{ $pclMap[$link->supplier_url_hash] ?? '未填写' }}</td><td class="product-request-sku-cell">@include('portals.product-requests.partials.sku-tags', ['skus' => $link->skus, 'empty' => '暂无 SKU'])</td><td>@if($link->is_runnable ?? true)<span class="label label-success">可跑</span>@else<span class="label label-danger">不可跑</span>@endif</td><td>@if(!($link->is_runnable ?? true))<span class="text-danger">{{ $link->not_runnable_reason_label }}</span>@else—@endif</td><td class="product-request-link-wrap">@if($link->supplier_url)<a href="{{ $link->supplier_url }}" target="_blank" rel="noopener" title="{{ $link->supplier_url }}">{{ $link->supplier_url }}</a>@else 未填写 @endif</td><td>{{ optional($link->created_at)->timezone('Asia/Shanghai')->format('Y-m-d H:i') }}</td><td class="product-request-sticky-right product-request-actions"><button type="button" class="btn btn-info btn-xs product-request-action-button" data-product-request-modal-url="{{ route('product-requests.operation.existing-product-library.detail', $link) }}"><i class="fa fa-eye"></i> 查看详情</button></td></tr>
        @empty
            <tr><td colspan="9" class="product-request-empty">暂无数据</td></tr>
        @endforelse
        </tbody></table></div></div>
        @include('portals.product-requests.operation.existing-product-library-pagination', ['paginator' => $links])
    </div>
    @include('portals.product-requests.partials.modal')
</section>
@endsection
