@extends('portal.layout')

@section('page_title', $labels['pageTitle'])

@php
    $portalOrigin = rtrim(request()->getSchemeAndHttpHost(), '/');
    $warehouseProductStatisticsConfig = [
        'products' => $products,
        'pagination' => $productPagination,
        'detailUrl' => route('warehouse.product-statistics.detail'),
        'reportUrl' => route('warehouse.product-statistics.report-weight-exception'),
        'sort' => $filters['sort'],
        'direction' => $filters['direction'],
        'productId' => request('product_id', ''),
        'labels' => $labels,
    ];
@endphp

@push('styles')
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/product-statistics.css?v=20261021-2">
    <style>
        /* 规格详情弹窗表格美化 */
        #warehouse-product-statistics-variant-modal .wh-variant-table { border-collapse: separate; border-spacing: 0; width: 100%; font-size: 13px; }
        #warehouse-product-statistics-variant-modal .wh-variant-table thead th {
            background: #f7f9fc; color: #5a6a7a; font-weight: 600; font-size: 12px;
            padding: 10px 12px; border-bottom: 2px solid #e3e8f0 !important; border-top: none !important;
            white-space: nowrap;
        }
        #warehouse-product-statistics-variant-modal .wh-variant-table tbody td {
            padding: 10px 12px; vertical-align: middle; border-top: 1px solid #f0f3f8; background: #fff;
        }
        #warehouse-product-statistics-variant-modal .wh-variant-table tbody tr:hover td { background: #f6faff; }
        #warehouse-product-statistics-variant-modal .wh-variant-table .wh-thumb {
            width: 48px; height: 48px; object-fit: cover; border-radius: 6px;
            border: 1px solid #e6ebf2; box-shadow: 0 1px 3px rgba(30,45,70,.12); cursor: zoom-in;
        }
        #warehouse-product-statistics-variant-modal .wh-code {
            display: inline-block; padding: 3px 8px; border-radius: 4px;
            background: #eef4fb; color: #2868b0; font-family: Consolas, monospace; font-size: 12px;
            max-width: 260px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; vertical-align: middle;
        }
        #warehouse-product-statistics-variant-modal .wh-name { color: #2d3a4a; font-weight: 600; }
        #warehouse-product-statistics-variant-modal .wh-weight-badge {
            display: inline-block; min-width: 48px; padding: 3px 10px; border-radius: 12px;
            background: #e8f5e9; color: #2e7d32; font-weight: 600; text-align: center;
        }
        #warehouse-product-statistics-variant-modal .wh-weight-missing {
            display: inline-block; padding: 3px 10px; border-radius: 12px;
            background: #fdecea; color: #c62828; font-weight: 600;
        }
        #warehouse-product-statistics-variant-modal .wh-report-btn {
            border-radius: 4px; padding: 4px 12px; font-size: 12px;
        }
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
                        <th>{{ $labels['productCode'] }}</th>
                        <th>{{ $labels['specification'] }}</th>
                        <th class="product-statistics-operation-column" style="width:80px;">{{ $labels['view'] }}</th>
                    </tr>
                    </thead>
                    <tbody id="warehouse-product-statistics-body">
                    @forelse($products as $product)
                        <tr data-product-id="{{ $product['id'] }}">
                            <td>
                                <div class="product-statistics-cell product-statistics-product-cell">
                                    @if(!empty($product['image_url']))
                                        <img src="{{ $product['image_url'] }}" alt="{{ $product['name'] }}" class="product-statistics-thumb" loading="lazy" decoding="async">
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
                                    <code>{{ $product['product_code'] }}</code>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td>
                                <div class="product-statistics-variant-summary">
                                    <span class="label label-info" title="{{ $labels['variantCount'] }}">
                                        {{ $product['variant_count'] }} 个规格
                                    </span>
                                    @if(!empty($product['variant_skus']))
                                        <span class="product-statistics-variant-preview text-muted" title="{{ implode('，', $product['variant_skus']) }}">
                                            {{ implode('，', array_slice($product['variant_skus'], 0, 2)) }}{{ count($product['variant_skus']) > 2 ? ' …' : '' }}
                                        </span>
                                    @endif
                                </div>
                            </td>
                            <td>
                                <button type="button" class="btn btn-default btn-xs warehouse-product-statistics-view-btn" data-product-id="{{ $product['id'] }}">
                                    <i class="fa fa-eye"></i> {{ $labels['view'] }}
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="product-statistics-empty-row text-center text-muted">
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
                    <div id="warehouse-variant-detail-refresh-summary" hidden style="margin:8px 0 12px;padding:6px 10px;background:#f4f6f9;border-left:3px solid #3c8dbc;border-radius:2px;font-size:13px;"></div>
                    <div id="warehouse-variant-detail-loading" class="text-muted text-center" hidden><i class="fa fa-spinner fa-spin"></i> 正在加载规格详情…</div>
                    <div id="warehouse-variant-detail-error" class="alert alert-danger" hidden></div>
                    <div id="warehouse-variant-detail-content" hidden>
                        <table class="table wh-variant-table">
                            <thead>
                            <tr>
                                <th style="width:70px;">货品图片</th>
                                <th>产品编号</th>
                                <th>通途SKU</th>
                                <th>规格产品名</th>
                                <th style="width:90px;">通途重量(g)</th>
                                <th style="width:80px;">操作</th>
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
@endsection

@push('scripts')
    <script>
        window.__warehouseProductStatisticsConfig = @json($warehouseProductStatisticsConfig);
    </script>
    <script src="{{ $portalOrigin }}/assets/erp/warehouse-product-statistics.js?v=20261024-1"></script>
@endpush
