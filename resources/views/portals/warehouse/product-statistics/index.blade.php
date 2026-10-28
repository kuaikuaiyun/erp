@extends('portal.layout')

@section('page_title', $labels['pageTitle'])

@php
    $portalOrigin = rtrim(request()->getSchemeAndHttpHost(), '/');
    $warehouseProductStatisticsConfig = [
        'products' => $products,
        'pagination' => $productPagination,
        'detailUrl' => route('warehouse.product-statistics.detail'),
        'fillUrl' => route('warehouse.product-statistics.fill-weight-exceptions'),
        'refreshWeightUrl' => route('warehouse.product-statistics.refresh-weights'),
        'linkSkuImagesUrl' => route('warehouse.product-statistics.link-sku-images'),
        'linkSkuImageUploadUrl' => route('warehouse.product-statistics.link-sku-images.upload'),
        'linkSkuImageDeleteUrl' => route('warehouse.product-statistics.link-sku-images.delete'),
        'approveUrl' => route('warehouse.product-statistics.approve'),
        'rejectReasons' => $rejectReasonOptions,
        'sort' => $filters['sort'],
        'direction' => $filters['direction'],
        'productId' => request('product_id', ''),
        'labels' => $labels,
    ];
@endphp

@push('styles')
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/product-statistics.css?v=20261021-2">
    <style>
        /* 规格详情 / 填写弹窗共用表格样式 */
        .wh-variant-table { border-collapse: separate; border-spacing: 0; width: 100%; font-size: 13px; }
        .wh-variant-table thead th {
            background: #f7f9fc; color: #5a6a7a; font-weight: 600; font-size: 12px;
            padding: 10px 12px; border-bottom: 2px solid #e3e8f0 !important; border-top: none !important;
            white-space: nowrap;
        }
        .wh-variant-table tbody td {
            padding: 10px 12px; vertical-align: middle; border-top: 1px solid #f0f3f8; background: #fff;
        }
        .wh-variant-table tbody tr:hover td { background: #f6faff; }
        .wh-variant-table .wh-thumb {
            width: 48px; height: 48px; object-fit: cover; border-radius: 6px;
            border: 1px solid #e6ebf2; box-shadow: 0 1px 3px rgba(30,45,70,.12); cursor: zoom-in;
        }
        .wh-code {
            display: inline-block; padding: 3px 8px; border-radius: 4px;
            background: #eef4fb; color: #2868b0; font-family: Consolas, monospace; font-size: 12px;
            max-width: 260px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; vertical-align: middle;
        }
        .wh-code-sku { background: #fff7e6; color: #b85b00; border: 1px solid #ffe0a3; }
        .wh-cell-label { font-size: 11px; color: #90a0b0; margin-bottom: 2px; letter-spacing: 0.5px; }
        .wh-name { color: #2d3a4a; font-weight: 600; }
        .wh-weight-badge {
            display: inline-block; min-width: 48px; padding: 3px 10px; border-radius: 12px;
            background: #e8f5e9; color: #2e7d32; font-weight: 600; text-align: center;
        }
        .wh-weight-missing {
            display: inline-block; padding: 3px 10px; border-radius: 12px;
            background: #fdecea; color: #c62828; font-weight: 600;
        }

        /* 填写弹窗：参考仓库验收表单样式 */
        .wh-fill-section-title { font-weight: 700; color: #2d3a4a; font-size: 13px; margin: 0 0 8px; }
        .wh-fill-notice {
            display: flex; align-items: flex-start; gap: 6px; padding: 6px 10px; margin-bottom: 10px;
            border: 1px solid #f5d0cd; background: #fdf1f0; border-radius: 4px;
            color: #c0392b; font-size: 12px; line-height: 18px;
        }
        .wh-fill-list { display: flex; flex-direction: column; gap: 10px; max-height: 55vh; overflow-y: auto; padding-right: 4px; }

        /* 实拍图列 */
        .wh-real-images-cell { padding: 6px 8px !important; vertical-align: middle; }
        .wh-real-images-grid { display: flex; flex-wrap: wrap; gap: 4px; align-items: center; min-height: 52px; }
        .wh-real-image-item { position: relative; width: 48px; height: 48px; }
        .wh-real-image-thumb {
            width: 48px; height: 48px; object-fit: cover; border-radius: 4px;
            border: 1px solid #e6ebf2; cursor: zoom-in; transition: transform .15s;
        }
        .wh-real-image-thumb:hover { transform: scale(1.05); }
        .wh-real-image-delete {
            position: absolute; top: -6px; right: -6px; width: 18px; height: 18px;
            border-radius: 50%; background: #e74c3c; color: #fff; border: none;
            font-size: 10px; cursor: pointer; line-height: 18px; text-align: center;
            opacity: 0; transition: opacity .15s; padding: 0;
            box-shadow: 0 1px 3px rgba(0,0,0,.3);
        }
        .wh-real-image-item:hover .wh-real-image-delete { opacity: 1; }

        /* 统一上传区域样式（参考 PCL SKU 管理） */
        .wh-upload-zone {
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            gap: 6px; width: 100%; min-width: 190px; padding: 10px 12px;
            border: 2px dashed #c0d0e0; border-radius: 6px; background: #fafbfc;
            cursor: pointer; transition: all .15s;
            position: relative;
        }
        .wh-upload-zone:hover { border-color: #3498db; background: #eef6fc; }
        .wh-upload-zone.is-active,
        .wh-upload-zone:focus-within { border-color: #3498db; box-shadow: 0 0 0 2px rgba(52,152,219,.2); }
        .wh-upload-zone.is-paste-target {
            border-color: #3498db; box-shadow: 0 0 0 2px rgba(52,152,219,.35); background: #eaf4fc;
            animation: wh-pulse 1.2s ease-in-out infinite;
        }
        @keyframes wh-pulse {
            0%, 100% { box-shadow: 0 0 0 2px rgba(52,152,219,.35); }
            50% { box-shadow: 0 0 0 4px rgba(52,152,219,.15); }
        }
        .wh-upload-icon { font-size: 22px; color: #7a90a8; margin-bottom: 2px; }
        .wh-upload-zone:hover .wh-upload-icon { color: #3498db; }
        .wh-upload-hint-text { font-size: 12px; color: #7a90a8; line-height: 1.5; text-align: center; }
        .wh-upload-hint-text strong { color: #3498db; }
        .wh-paste-btn {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 5px 14px; margin-top: 2px;
            background: #3c8dbc; color: #fff; border: none; border-radius: 4px;
            font-size: 12px; font-weight: 500; cursor: pointer; transition: all .15s;
            font-family: inherit;
        }
        .wh-paste-btn:hover { background: #2f7ba8; }
        .wh-paste-btn kbd {
            display: inline-block; padding: 0 4px; font-family: Consolas, monospace;
            font-size: 10px; line-height: 16px; border: 1px solid rgba(255,255,255,.4);
            border-radius: 3px; background: rgba(255,255,255,.15); color: #fff;
        }
        .wh-paste-btn .wh-clip-icon { font-size: 13px; }
        /* 有图时的"继续添加"小按钮 */
        .wh-real-image-add {
            display: inline-flex; align-items: center; justify-content: center;
            width: 48px; height: 48px; border: 1.5px dashed #c0d0e0; border-radius: 4px;
            color: #7a90a8; cursor: pointer; font-size: 16px; background: #fafbfc;
            transition: all .15s; flex-shrink: 0;
        }
        .wh-real-image-add:hover { border-color: #3498db; color: #3498db; background: #eef6fc; }

        .wh-real-images-cell input[type="file"].wh-real-image-input { display: none !important; }
        .wh-fill-item { padding: 10px 12px; border: 1px solid #e6ebf2; border-radius: 6px; background: #fbfdff; }
        .wh-fill-item-head { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .wh-fill-head-left { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
        .wh-fill-head-label { font-size: 11px; color: #90a0b0; font-weight: 600; letter-spacing: 0.5px; padding: 2px 6px; background: #f0f3f8; border-radius: 3px; }
        .wh-fill-head-right { display: flex; align-items: center; gap: 6px; }
        .wh-fill-sku-tag {
            display: inline-block; max-width: 50%; padding: 2px 9px; border: 1px solid #cfe0ee;
            border-radius: 12px; background: #eef6fc; color: #2f7ba8; font-size: 12px;
            line-height: 18px; word-break: break-all;
        }
        .wh-fill-ref { font-size: 12px; color: #8899a6; white-space: nowrap; }
        .wh-fill-ref strong { color: #2e7d32; }
        .wh-fill-ref.is-missing strong { color: #c62828; }
        .wh-fill-weight { width: 150px; height: 30px; margin-left: auto; }
        .wh-fill-item-meta { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-top: 8px; }
        .wh-fill-label { font-size: 12px; color: #7c96ab; white-space: nowrap; }
        .wh-reason-pill {
            padding: 3px 12px; border: 1px solid #d9e2ec; border-radius: 4px; background: #fff;
            color: #5a6a7a; font-size: 12px; line-height: 20px; cursor: pointer;
        }
        .wh-reason-pill:hover { border-color: #8fb8d6; color: #2f7ba8; }
        .wh-reason-pill.active { border-color: #3c8dbc; background: #eaf4fb; color: #2f7ba8; font-weight: 600; }
        .wh-fill-remark { flex: 1 1 180px; min-width: 160px; height: 30px; }
        .wh-fill-modal-lg { width: 980px; max-width: 95vw; }
        .wh-fill-item-images { display: flex; align-items: center; gap: 10px; margin-top: 8px; }
        .wh-fill-upload-trigger { display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border: 1px dashed #3c8dbc; border-radius: 4px; background: #f4f8fc; color: #3c8dbc; font-size: 13px; font-weight: 500; cursor: pointer; transition: all .15s; }
        .wh-fill-upload-trigger:hover { background: #eaf4fb; border-color: #2f7ba8; color: #2f7ba8; }
        .wh-fill-physical-preview { min-width: 72px; height: 72px; display: flex; align-items: center; justify-content: center; border: 1px dashed #dfe6ed; border-radius: 4px; background: #fafbfc; }
        .wh-fill-physical-remove { font-size: 12px; color: #c0392b; cursor: pointer; }
        .wh-fill-item-images.is-paste-target {
            padding: 4px 8px; border-radius: 6px; border: 2px dashed #3498db;
            box-shadow: 0 0 0 2px rgba(52,152,219,.2); background: #eaf4fc;
        }
        /* 弹窗内大虚线框适配 */
        .wh-fill-item-images .wh-upload-zone {
            width: 200px; min-width: 200px; flex-shrink: 0;
        }
        .wh-fill-item-images .wh-fill-physical-preview {
            width: 72px; min-width: 72px; height: 72px;
        }
        /* 6 列布局：产品信息 | PCL 编号 | SKU与重量 | 实拍图 | 审核状态 | 操作 */
        #warehouse-product-statistics-table { min-width: 0 !important; width: 100%; table-layout: fixed; }
        #warehouse-product-statistics-table th:nth-child(1),
        #warehouse-product-statistics-table td:nth-child(1) { width: 24%; min-width: 0; }
        #warehouse-product-statistics-table th:nth-child(2),
        #warehouse-product-statistics-table td:nth-child(2) { width: 130px; min-width: 130px; }
        #warehouse-product-statistics-table th:nth-child(3),
        #warehouse-product-statistics-table td:nth-child(3) { width: 240px; min-width: 240px; }
        #warehouse-product-statistics-table th:nth-child(4),
        #warehouse-product-statistics-table td:nth-child(4) { width: 200px; min-width: 200px; }
        #warehouse-product-statistics-table th:nth-child(5),
        #warehouse-product-statistics-table td:nth-child(5) { width: 90px; min-width: 90px; }
        #warehouse-product-statistics-table th:nth-child(6),
        #warehouse-product-statistics-table td:nth-child(6) { width: 220px; min-width: 220px; }
        #warehouse-product-statistics-table td:nth-child(1) { overflow: hidden; }
        #warehouse-product-statistics-table td:nth-child(1) .product-statistics-product-cell { min-width: 0; }
        #warehouse-product-statistics-table td:nth-child(1) .product-statistics-product-meta { min-width: 0; }
        #warehouse-product-statistics-table td:nth-child(1) .product-statistics-product-cell strong { max-width: 100%; }
        #warehouse-product-statistics-table td:nth-child(2) code {
            display: inline-block; max-width: 100%; overflow: hidden;
            text-overflow: ellipsis; vertical-align: middle;
        }
        #warehouse-product-statistics-table td:nth-child(6) .btn { margin: 1px 1px; padding: 3px 7px; }
        /* 列表列：SKU 与重量 */
        #warehouse-product-statistics-table .product-statistics-variant-summary { display: flex; flex-direction: column; align-items: flex-start; gap: 5px; }
        #warehouse-product-statistics-table .wh-count-badge { font-size: 11px; font-weight: 600; padding: 2px 7px; }
        .wh-sku-weight-list { display: flex; flex-direction: column; gap: 4px; width: 100%; }
        .wh-sku-weight-item {
            display: flex; align-items: center; justify-content: space-between; gap: 6px;
            max-width: 100%; padding: 3px 8px; border-radius: 4px;
            background: #f6f9fc; border: 1px solid #e8eef5;
        }
        .wh-sku-weight-name {
            min-width: 0; font-family: Consolas, monospace; font-size: 12px; color: #2868b0;
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }
        .wh-sku-weight-value {
            flex: 0 0 auto; padding: 1px 8px; border-radius: 10px;
            background: #e8f5e9; color: #2e7d32; font-size: 12px; font-weight: 700; white-space: nowrap;
        }
        .wh-sku-weight-value.is-missing { background: #fdecea; color: #c62828; font-weight: 600; }
        .wh-sku-weight-more { font-size: 11px; color: #90a0b0; padding-left: 2px; }

        /* 实拍图上传弹窗 */
        .wh-upload-modal-images {
            display: flex; flex-wrap: wrap; gap: 8px; align-items: flex-start;
            margin-bottom: 12px; padding: 10px;
            border: 1px solid #e6ebf2; border-radius: 6px; background: #fafbfc;
            min-height: 60px;
        }
        .wh-upload-modal-images:empty::before {
            content: '暂无已上传的实拍图'; color: #90a0b0; font-size: 12px;
            display: block; width: 100%; text-align: center; padding: 12px 0;
        }
        .wh-upload-modal-images .wh-real-image-item { width: 72px; height: 72px; }
        .wh-upload-modal-images .wh-real-image-thumb { width: 72px; height: 72px; }
        .wh-upload-modal-notice {
            margin: 10px 0 0; font-size: 12px; color: #90a0b0; text-align: center;
        }
        #wh-upload-zone { margin-top: 8px; }
    </style>
@endpush

@section('content')
    <section class="content-header product-statistics-header">
        <h1>{{ $labels['pageTitle'] }} <small>{{ $labels['subtitle'] }}</small></h1>
    </section>

    <section class="content logistics-page product-statistics-page">
        <div class="box box-solid product-statistics-filter-box">
            <div class="box-body">
                <form id="warehouse-product-statistics-filter-form" class="product-statistics-filter-form" autocomplete="off" method="get" action="{{ route('warehouse.product-statistics.index') }}">
                    <input id="warehouse-product-statistics-sort-field" type="hidden" name="sort" value="{{ $filters['sort'] }}">
                    <input id="warehouse-product-statistics-direction-field" type="hidden" name="direction" value="{{ $filters['direction'] }}">
                    <input id="warehouse-product-statistics-per-page-field" type="hidden" name="per_page" value="{{ $filters['per_page'] }}">
                    <div class="product-statistics-filters">
                        <div class="product-statistics-filter-field">
                            <label>产品名称</label>
                            <input name="name" type="search" class="form-control" value="{{ $filters['name'] }}" placeholder="输入产品名称">
                        </div>
                        <div class="product-statistics-filter-field product-statistics-search-field">
                            <label>搜索</label>
                            <div class="product-statistics-search-control">
                                <i class="fa fa-search" aria-hidden="true"></i>
                                <input name="keyword" type="search" class="form-control" value="{{ $filters['keyword'] }}" placeholder="搜索产品名、PCL 或 SKU">
                            </div>
                        </div>
                        <div class="product-statistics-filter-field">
                            <label>审核状态</label>
                            <select name="warehouse_status" class="form-control">
                                <option value="">全部</option>
                                <option value="pending_review" @selected(($filters['warehouse_status'] ?? '') === 'pending_review')>待审核</option>
                                <option value="pending" @selected(($filters['warehouse_status'] ?? '') === 'pending')>待处理</option>
                                <option value="resolved" @selected(($filters['warehouse_status'] ?? '') === 'resolved')>已处理</option>
                                <option value="approved" @selected(($filters['warehouse_status'] ?? '') === 'approved')>已审核</option>
                            </select>
                        </div>
                    </div>
                    <div class="product-statistics-filter-actions">
                        <button class="btn btn-primary" type="submit"><i class="fa fa-search"></i> 查询</button>
                        <a class="btn btn-default" href="{{ route('warehouse.product-statistics.index') }}">重置</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="box box-solid product-statistics-table-box">
            <div class="box-header with-border product-statistics-list-toolbar">
                <h3 class="box-title">产品列表 <small>共 {{ $productPagination['total'] }} 条</small></h3>
            </div>
            <div class="box-body table-responsive no-padding product-statistics-table-scroll">
                <table class="table table-hover product-statistics-table" id="warehouse-product-statistics-table">
                    <thead>
                    <tr>
                        <th>{{ $labels['productInfo'] }}</th>
                        <th style="width:130px;">{{ $labels['productCode'] }}</th>
                        <th style="width:240px;">{{ $labels['specification'] }}</th>
                        <th style="width:200px;">实拍图</th>
                        <th style="width:90px;">审核状态</th>
                        <th class="product-statistics-operation-column" style="width:220px;">操作</th>
                    </tr>
                    </thead>
                    <tbody id="warehouse-product-statistics-body">
                    @forelse($products as $product)
                        <tr data-product-id="{{ $product['id'] }}" data-pcl-code="{{ $product['product_code'] ?? '' }}">
                            <td>
                                <div class="product-statistics-cell product-statistics-product-cell">
                                    @if(!empty($product['image_url']))
                                        <img src="{{ $product['image_url'] }}" alt="{{ $product['name'] }}" class="product-statistics-thumb erp-zoomable" loading="lazy" decoding="async" title="点击放大查看" style="cursor:zoom-in;" onclick="window.erpZoomOpen && window.erpZoomOpen(this.src); return false;">
                                    @endif
                                    <div class="product-statistics-product-meta">
                                        <strong class="product-statistics-product-name" title="{{ $product['name'] }}">{{ $product['name'] }}</strong>
                                        @if(!empty($product['stores']))
                                            <div class="product-statistics-product-sub text-muted" title="{{ implode(' / ', $product['stores']) }}">
                                                <i class="fa fa-store"></i> {{ implode('、', array_slice($product['stores'], 0, 2)) }}{{ count($product['stores']) > 2 ? ' …' : '' }}
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            </td>
                            <td>
                                @if(!empty($product['product_code']))
                                    <code style="display:block;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="{{ $product['product_code'] }}">{{ $product['product_code'] }}</code>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td>
                                <div class="product-statistics-variant-summary">
                                    <span class="label label-info wh-count-badge" title="{{ $labels['variantCount'] }}">
                                        {{ $product['variant_count'] }} 个 SKU
                                    </span>
                                    @if(!empty($product['variant_skus']))
                                        @php
                                            $skuItems = collect($product['variant_skus']);
                                            $hiddenCount = max(0, $skuItems->count() - 2);
                                            $titleText = $skuItems->map(fn ($v) => ((float) ($v['weight'] ?? 0)) > 0
                                                ? $v['sku'].' - '.rtrim(rtrim(number_format((float) $v['weight'], 2, '.', ''), '0'), '.').' g'
                                                : $v['sku'].' - 缺重量')->implode('；');
                                        @endphp
                                        <div class="wh-sku-weight-list" title="{{ $titleText }}">
                                            @foreach($skuItems->take(2) as $v)
                                                @php $w = (float) ($v['weight'] ?? 0); @endphp
                                                <div class="wh-sku-weight-item">
                                                    <span class="wh-sku-weight-name">{{ $v['sku'] }}</span>
                                                    @if($w > 0)
                                                        <span class="wh-sku-weight-value">{{ rtrim(rtrim(number_format($w, 2, '.', ''), '0'), '.') }} g</span>
                                                    @else
                                                        <span class="wh-sku-weight-value is-missing">缺重量</span>
                                                    @endif
                                                </div>
                                            @endforeach
                                            @if($hiddenCount > 0)
                                                <span class="wh-sku-weight-more">+{{ $hiddenCount }} 个</span>
                                            @endif
                                        </div>
                                    @endif
                                </div>
                            </td>
                            {{-- 实拍图：只读显示，无图则空 --}}
                            <td class="wh-real-images-cell" data-pcl-code="{{ $product['product_code'] ?? '' }}" data-link-sku-id="{{ $product['link_sku_id'] ?? '' }}">
                                <div class="wh-real-images-grid">
                                    @if(!empty($product['link_sku_images_urls']))
                                        @foreach($product['link_sku_images_urls'] as $idx => $url)
                                            <span class="wh-real-image-item">
                                                <img src="{{ $url }}" alt="实拍图{{ $idx + 1 }}" class="wh-real-image-thumb erp-zoomable" loading="lazy" decoding="async" title="点击放大查看" onclick="window.erpZoomOpen && window.erpZoomOpen(this.src); return false;">
                                            </span>
                                        @endforeach
                                    @else
                                        <span class="text-muted" style="font-size:12px;">—</span>
                                    @endif
                                </div>
                            </td>
                            {{-- 审核状态 --}}
                            <td>
                                @switch($product['warehouse_display_status'] ?? 'pending_review')
                                    @case('approved') <span class="label label-success">已审核</span> @break
                                    @case('pending') <span class="label label-warning">待处理</span> @break
                                    @case('resolved') <span class="label label-info">已处理</span> @break
                                    @default <span class="label label-default">待审核</span>
                                @endswitch
                            </td>
                            {{-- 操作 --}}
                            <td>
                                @php
                                    $missingWeightSkus = collect($product['variant_skus'] ?? [])
                                        ->filter(fn ($variant) => (float) ($variant['weight'] ?? 0) <= 0)
                                        ->pluck('sku')
                                        ->filter()
                                        ->unique()
                                        ->values();
                                    $refreshSkus = ($product['warehouse_display_status'] ?? '') === 'resolved'
                                        ? collect($product['variant_skus'] ?? [])->pluck('sku')->filter()->unique()->values()
                                        : $missingWeightSkus;
                                @endphp
                                <button type="button" class="btn btn-default btn-xs warehouse-product-statistics-view-btn" data-product-id="{{ $product['id'] }}">
                                    <i class="fa fa-eye"></i> {{ $labels['view'] }}
                                </button>
                                <button type="button" class="btn btn-warning btn-xs warehouse-product-statistics-fill-btn" data-product-id="{{ $product['id'] }}">
                                    <i class="fa fa-exclamation-triangle"></i> {{ $labels['fill'] }}
                                </button>
                                @if(in_array(($product['warehouse_display_status'] ?? 'pending_review'), ['pending_review', 'resolved'], true))
                                    <button type="button" class="btn btn-success btn-xs warehouse-product-statistics-approve-btn" data-pcl-code="{{ $product['product_code'] ?? '' }}" data-review-status="{{ $product['warehouse_review_status'] ?? 'pending' }}">
                                        <i class="fa fa-check"></i> {{ $labels['approve'] }}
                                    </button>
                                @endif
                                @if($refreshSkus->isNotEmpty())
                                    <button type="button" class="btn btn-info btn-xs warehouse-product-statistics-refresh-weight-btn"
                                            data-skus="{{ $refreshSkus->implode(',') }}"
                                            title="{{ ($product['warehouse_display_status'] ?? '') === 'resolved' ? '该异常已处理，抓取全部 SKU 最新数据' : '重量缺失，抓取 SKU 最新数据' }}">
                                        <i class="fa fa-cloud-download"></i> 抓取最新数据
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="product-statistics-empty-row text-center text-muted">
                                <i class="fa fa-search"></i> {{ $labels['emptyRows'] }}
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            @if($productPagination['total'] > 0)
                @include('portals.partials.pagination', [
                    'p' => $productPagination,
                    'routeName' => 'warehouse.product-statistics.index',
                    'queryParams' => request()->except(['page', 'per_page']),
                    'perPageOptions' => [5, 10, 20],
                ])
            @endif
        </div>
    </section>

    <div class="modal fade" id="warehouse-product-statistics-variant-modal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-label="{{ $labels['close'] }}"><span aria-hidden="true">&times;</span></button>
                    <h4 class="modal-title">商品规格详情</h4>
                </div>
                <div class="modal-body">
                    <div id="warehouse-variant-detail-identity" class="product-statistics-detail-identity"></div>
                    <div id="warehouse-variant-detail-loading" class="text-muted text-center" hidden><i class="fa fa-spinner fa-spin"></i> 正在加载规格详情…</div>
                    <div id="warehouse-variant-detail-error" class="alert alert-danger" hidden></div>
                    <div id="warehouse-variant-detail-content" hidden>
                        <table class="table wh-variant-table">
                            <thead>
                            <tr>
                                <th style="width:70px;">货品图片</th>
                                <th style="width:130px;">PCL 产品编号</th>
                                <th style="width:180px;">SKU（通途规格）</th>
                                <th>规格产品名</th>
                                <th style="width:110px;">SKU 重量(g)</th>
                            </tr>
                            </thead>
                            <tbody id="warehouse-variant-detail-body"></tbody>
                        </table>
                        <div id="warehouse-variant-detail-empty" class="text-muted text-center" hidden>暂无规格记录</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal">{{ $labels['close'] }}</button>
                </div>
            </div>
        </div>
    </div>

    {{-- 填写异常数据弹窗：按 SKU 填写重量 / 驳回理由 / 备注 / 实物图，未填写的不提交 --}}
    <div class="modal fade" id="warehouse-product-statistics-fill-modal" tabindex="-1" role="dialog">
        <div class="modal-dialog wh-fill-modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-label="{{ $labels['close'] }}"><span aria-hidden="true">&times;</span></button>
                    <h4 class="modal-title">填写异常数据</h4>
                    <p class="text-muted" style="margin:4px 0 0;font-size:12px;">为需要处理的 SKU 填写重量 / 驳回理由 / 备注，全部留空的 SKU 不会提交。</p>
                </div>
                <div class="modal-body">
                    <div id="warehouse-fill-identity" class="product-statistics-detail-identity"></div>
                    <div id="warehouse-fill-loading" class="text-muted text-center"><i class="fa fa-spinner fa-spin"></i> 正在加载规格数据…</div>
                    <div id="warehouse-fill-error" class="alert alert-danger" hidden></div>
                    <div id="warehouse-fill-content" hidden>
                        <div class="wh-fill-section-title">SKU 异常数据 <span style="color:#c0392b;">*</span></div>
                        <div class="wh-fill-notice">
                            <i class="fa fa-exclamation-circle" style="margin-top:2px;"></i>
                            <span>备注需具体写清哪个 SKU 存在问题；只填写需要处理的 SKU，全部留空的不会提交。</span>
                        </div>
                        <div id="warehouse-fill-body" class="wh-fill-list"></div>
                        <div id="warehouse-fill-empty" class="text-muted text-center" hidden>暂无规格记录</div>

                        {{-- 实拍图（PCL 级别）--}}
                        <div class="wh-fill-section-title" style="margin-top:14px;">仓库实拍图</div>
                        <div class="wh-upload-modal-images" id="wh-fill-real-images"></div>
                        <div class="wh-upload-zone" id="wh-fill-real-upload">
                            <i class="fa fa-cloud-upload wh-upload-icon"></i>
                            <div class="wh-upload-hint-text">拖拽图片到这里、粘贴图片，或<strong>点击选择文件</strong></div>
                            <button type="button" class="wh-paste-btn" id="wh-fill-real-paste">
                                <i class="fa fa-clipboard wh-clip-icon"></i> 粘贴图片 <kbd>Ctrl+V</kbd>
                            </button>
                            <input type="file" id="wh-fill-real-file" accept="image/jpeg,image/jpg,image/png,image/webp" multiple style="display:none;">
                        </div>
                        <p class="wh-upload-modal-notice">可多张上传仓库实拍图，仅用于异常反馈，不会写入 PCL SKU 产品实拍图。</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <span id="warehouse-fill-status" class="pull-left text-muted" style="line-height:34px;"></span>
                    <button type="button" class="btn btn-default" data-dismiss="modal">取消</button>
                    <button type="button" class="btn btn-success" id="warehouse-fill-submit"><i class="fa fa-check"></i> 提交</button>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        window.__warehouseProductStatisticsConfig = @json($warehouseProductStatisticsConfig);
    </script>
    <script src="{{ $portalOrigin }}/assets/erp/warehouse-product-statistics.js?v=20261028-2"></script>
    <script>
        // 审核通过
        (function () {
            var cfg = window.__warehouseProductStatisticsConfig || {};
            var approveUrl = cfg.approveUrl || '';

            function csrfToken() {
                var meta = document.querySelector('meta[name="csrf-token"]');
                return meta ? (meta.getAttribute('content') || '') : '';
            }

            function rowOf(btn) {
                return btn && btn.closest ? btn.closest('tr') : null;
            }

            function renderReviewStatus(row, status) {
                var td = row.querySelectorAll('td')[4];
                if (!td) return;
                td.innerHTML = status === 'approved'
                    ? '<span class="label label-success">已审核</span>'
                    : '<span class="label label-warning">待审核</span>';
            }

            document.addEventListener('click', function (e) {
                var approveBtn = e.target.closest('.warehouse-product-statistics-approve-btn');
                if (!approveBtn) return;
                e.preventDefault();
                var row = rowOf(approveBtn);
                var pclCode = row ? (row.getAttribute('data-pcl-code') || '') : '';
                if (!pclCode) { alert('缺少 PCL 编号'); return; }
                if (!confirm('确认审核通过？')) return;
                approveBtn.disabled = true;
                var body = '_token=' + encodeURIComponent(csrfToken())
                    + '&pcl_code=' + encodeURIComponent(pclCode);
                fetch(approveUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
                    body: body
                }).then(function (res) {
                    return res.json().then(function (data) {
                        if (!res.ok) throw new Error(data.message || '操作失败');
                        return data;
                    });
                }).then(function () {
                    approveBtn.setAttribute('data-review-status', 'approved');
                    if (row) renderReviewStatus(row, 'approved');
                }).catch(function (err) {
                    alert('审核失败：' + (err.message || '未知错误'));
                }).finally(function () { approveBtn.disabled = false; });
            });
        })();
    </script>
@endpush
