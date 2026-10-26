@extends('portal.layout')

@section('page_title', $labels['pageTitle'])

@php
    $portalOrigin = rtrim(request()->getSchemeAndHttpHost(), '/');
    $warehouseProductStatisticsConfig = [
        'products' => $products,
        'pagination' => $productPagination,
        'detailUrl' => route('warehouse.product-statistics.detail'),
        'fillUrl' => route('warehouse.product-statistics.fill-weight-exceptions'),
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
        .wh-fill-item { padding: 10px 12px; border: 1px solid #e6ebf2; border-radius: 6px; background: #fbfdff; }
        .wh-fill-item-head { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
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
                        <th class="product-statistics-operation-column" style="width:150px;">操作</th>
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
                                <button type="button" class="btn btn-warning btn-xs warehouse-product-statistics-fill-btn" data-product-id="{{ $product['id'] }}">
                                    <i class="fa fa-pencil"></i> {{ $labels['fill'] }}
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
    <script src="{{ $portalOrigin }}/assets/erp/warehouse-product-statistics.js?v=20261026-2"></script>
@endpush
