@extends('portal.layout')

@section('page_title', __('product_requests.dedupe.title'))

@php
    $portalOrigin = rtrim(request()->getSchemeAndHttpHost(), '/');
    $t = static fn (string $key, array $replace = []): string => __('product_requests.'.$key, $replace);
@endphp
@push('styles')
<link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/product-requests.css?v=20261022-01">
<style>
.dedupe-card{border:1px solid #e4e4e4;border-radius:8px;background:#fff;margin-bottom:20px;overflow:hidden}
.dedupe-card-header{padding:14px 18px;background:#fafafa;border-bottom:1px solid #eee;display:flex;align-items:center;justify-content:space-between;gap:12px}
.dedupe-card-header h3{margin:0;font-size:15px;color:#333;font-weight:600}
.dedupe-card-body{padding:18px}
.dedupe-card-row{display:flex;gap:20px;margin-bottom:14px}
.dedupe-card-row:last-child{margin-bottom:0}
.dedupe-card-col{flex:1;min-width:0}
.dedupe-label{display:inline-block;font-size:12px;color:#999;margin-bottom:4px}
.dedupe-value{font-size:14px;color:#333;word-break:break-all}
.dedupe-link{color:#3c8dbc;text-decoration:none}
.dedupe-link:hover{text-decoration:underline}
.dedupe-images{display:flex;gap:8px;flex-wrap:wrap;margin-top:4px}
.dedupe-images img{width:80px;height:80px;object-fit:cover;border-radius:4px;border:1px solid #eee;cursor:pointer}
.dedupe-images a{display:inline-block}
.dedupe-sku-list{display:flex;flex-direction:column;gap:8px;margin-top:6px}
.dedupe-sku-item{display:flex;align-items:center;gap:10px;font-size:13px;color:#2c3e50;background:#fff;border:1px solid #e1e7ef;border-radius:6px;padding:8px 12px;transition:border-color .14s ease,box-shadow .14s ease}
.dedupe-sku-item:hover{border-color:#3c8dbc;box-shadow:0 2px 6px rgba(60,141,188,.15)}
.dedupe-sku-code{display:inline-block;font-family:'SF Mono',Menlo,Consolas,monospace;color:#1f5168;background:#eaf4fb;font-size:13px;font-weight:600;padding:3px 8px;border-radius:4px;letter-spacing:.2px;word-break:break-all}
.dedupe-sku-values{display:flex;flex-wrap:wrap;gap:6px;margin-left:auto;align-items:center}
.dedupe-sku-pill{display:inline-flex;align-items:center;gap:4px;font-size:12px;line-height:1;padding:5px 9px;border-radius:5px;font-weight:600;white-space:nowrap}
.dedupe-sku-pill i{font-style:normal;font-size:11px;opacity:.85;font-weight:500}
.dedupe-sku-price{background:#e8f7ee;color:#1f7a3e;border:1px solid #b8e0c6}
.dedupe-sku-weight{background:#fff3e0;color:#b1570b;border:1px solid #ffd9a8}
.dedupe-sku-pill-empty{color:#aaa;font-size:12px;font-weight:400;font-style:italic;background:transparent;border:1px dashed #d9d9d9;padding:5px 9px;border-radius:5px}
.dedupe-match-badge{display:inline-block;padding:3px 12px;border-radius:12px;font-size:12px;line-height:18px;font-weight:600}
.dedupe-match-url{background:#dff0d8;color:#2d6a30;border:1px solid #b8dca0}
.dedupe-match-keyword{background:#fcf8e3;color:#8a6d3b;border:1px solid #f0d97a}
.dedupe-match-both{background:#e8f4fd;color:#0958d9;border:1px solid #91d5ff}
.dedupe-source-badge{display:inline-block;padding:2px 8px;border-radius:3px;font-size:11px;margin-left:6px;background:#5bc0de;color:#fff}
.dedupe-empty{text-align:center;padding:60px 20px;color:#999}
.dedupe-empty .fa{font-size:48px;margin-bottom:12px;display:block}
.dedupe-form-grid{display:grid;grid-template-columns:1fr 1fr auto;gap:12px;align-items:end}
@media (max-width: 768px){.dedupe-form-grid{grid-template-columns:1fr}.dedupe-card-row{flex-direction:column}}
</style>
@endpush
@push('scripts')<script>$(function(){ $(document).on('click', '[data-product-request-image-preview]', function(e){ e.preventDefault(); var src = $(this).attr('src'); if(src){ window.open(src, '_blank'); } }); });</script>@endpush

@section('content')
<section class="content product-request-page">
    <header class="content-header"><h1>{{ $t('dedupe.title') }}</h1><p>{{ $t('dedupe.subtitle') }}</p></header>

    <div class="box box-solid product-request-filter-box">
        <div class="box-header with-border"><h3 class="box-title"><i class="fa fa-search"></i> {{ $t('dedupe.title') }}</h3></div>
        <div class="box-body">
            <form method="get" action="{{ route('product-requests.operation.dedupe') }}" class="dedupe-form">
                <div class="dedupe-form-grid">
                    <div>
                        <label class="control-label">{{ $t('dedupe.supplier_url_label') }}</label>
                        <input type="text" class="form-control" name="supplier_url" value="{{ $supplierUrl }}" placeholder="{{ $t('dedupe.supplier_url_placeholder') }}">
                    </div>
                    <div>
                        <label class="control-label">{{ $t('dedupe.keyword_label') }}</label>
                        <input type="text" class="form-control" name="keyword" value="{{ $keyword }}" placeholder="{{ $t('dedupe.keyword_placeholder') }}">
                    </div>
                    <div style="display:flex;gap:8px">
                        <button type="submit" class="btn btn-primary"><i class="fa fa-search"></i> {{ $t('dedupe.search_button') }}</button>
                        <a href="{{ route('product-requests.operation.dedupe') }}" class="btn btn-default">{{ $t('dedupe.reset_button') }}</a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    @if($keyword === '' && $supplierUrl === '')
        <div class="dedupe-empty"><i class="fa fa-search-plus"></i>{{ $t('dedupe.empty_hint') }}</div>
    @elseif(!empty($validationHint))
        <div style="margin:20px 0;padding:16px 20px;background:#e8f4fd;border:1px solid #91d5ff;border-radius:8px;color:#0958d9">
            <i class="fa fa-info-circle" style="margin-right:6px"></i>{{ $validationHint }}
        </div>
    @elseif($results === [])
        <div style="margin:20px 0;padding:16px 20px;background:#fff8e1;border:1px solid #ffe58f;border-radius:8px;color:#8a6d3b">
            <i class="fa fa-exclamation-triangle" style="margin-right:6px"></i>
            <strong>没有找到搜索信息</strong>
            <span style="color:#a98a4b;margin-left:8px">{{ $t('dedupe.no_results') }}</span>
            @if($supplierUrl !== '')<br><span style="color:#a98a4b;font-size:12px;margin-left:22px">链接：{{ \Illuminate\Support\Str::limit($supplierUrl, 80) }}</span>@endif
            @if($keyword !== '')<br><span style="color:#a98a4b;font-size:12px;margin-left:22px">关键词：{{ $keyword }}</span>@endif
        </div>
    @else
        @if($matchType)
            <p style="margin:14px 0 10px;color:#666;font-size:13px">
                <span class="dedupe-match-badge {{ $matchType === 'supplier_url' ? 'dedupe-match-url' : ($matchType === 'mixed' ? 'dedupe-match-both' : 'dedupe-match-keyword') }}">
                    {{ $matchType === 'supplier_url' ? $t('dedupe.match_url') : ($matchType === 'mixed' ? $t('dedupe.match_both') : $t('dedupe.match_keyword')) }}
                </span>
                共 {{ count($results) }} 条结果
            </p>
        @endif

        @foreach($results as $item)
            @php
                $skuValues = is_object($item['sku_values'] ?? null) ? (array) $item['sku_values'] : ($item['sku_values'] ?? []);
                $skus = $item['skus'] ?? [];
                $images = $item['seller_images'] ?? [];
                $reportNo = $item['report_no'] ?? '';
            @endphp
            <div class="dedupe-card">
                <div class="dedupe-card-header">
                    <h3>
                        {{ $item['standard_chinese_name'] ?? '—' }}
                        @if($reportNo && $reportNo !== __('product_requests.procurement.dedupe_link_sku_source'))
                            <span style="color:#999;font-weight:normal;font-size:12px;margin-left:8px">#{{ $reportNo }}</span>
                        @endif
                        @if($reportNo === __('product_requests.procurement.dedupe_link_sku_source'))
                            <span class="dedupe-source-badge">{{ $t('dedupe.result_source_link_sku') }}</span>
                        @endif
                    </h3>
                    @if(!empty($item['supplier_url']))
                        <a class="dedupe-link" href="{{ $item['supplier_url'] }}" target="_blank" rel="noopener" title="{{ $item['supplier_url'] }}"><i class="fa fa-external-link"></i> {{ $t('dedupe.supplier_url_label') }}</a>
                    @endif
                </div>
                <div class="dedupe-card-body">
                    <div class="dedupe-card-row">
                        <div class="dedupe-card-col">
                            <span class="dedupe-label">{{ $t('dedupe.pcl_label') }}</span>
                            <div class="dedupe-value">{{ $item['pcl_product_no'] ?? '—' }}</div>
                        </div>
                        <div class="dedupe-card-col">
                            <span class="dedupe-label">{{ $t('dedupe.supplier_url_label') }}</span>
                            <div class="dedupe-value">@if(!empty($item['supplier_url']))<a class="dedupe-link" href="{{ $item['supplier_url'] }}" target="_blank" rel="noopener" title="{{ $item['supplier_url'] }}">{{ \Illuminate\Support\Str::limit($item['supplier_url'], 60) }}</a>@else — @endif</div>
                        </div>
                    </div>

                    @if($skus !== [])
                    <div class="dedupe-card-row">
                        <div class="dedupe-card-col">
                            <span class="dedupe-label">{{ $t('dedupe.sku_label') }}</span>
                            <div class="dedupe-sku-list">
                                @foreach($skus as $sku)
                                    <div class="dedupe-sku-item">
                                        <span class="dedupe-sku-code">{{ $sku }}</span>
                                        @php
                                            $sv = $skuValues[$sku] ?? [];
                                            $price = is_array($sv) ? ($sv['price'] ?? null) : null;
                                            $weight = is_array($sv) ? ($sv['weight'] ?? null) : null;
                                        @endphp
                                        @if($price || $weight)
                                            <span class="dedupe-sku-values">
                                                @if($price)<span class="dedupe-sku-pill dedupe-sku-price" title="采购价（人民币）"><i>RMB</i>¥{{ $price }}</span>@endif
                                                @if($weight)<span class="dedupe-sku-pill dedupe-sku-weight" title="发货重量（克）"><i>重量</i>{{ $weight }}g</span>@endif
                                            </span>
                                        @else
                                            <span class="dedupe-sku-pill dedupe-sku-pill-empty">未录入价格/重量</span>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                    @else
                    <div class="dedupe-card-row">
                        <div class="dedupe-card-col">
                            <span class="dedupe-label">{{ $t('dedupe.sku_label') }}</span>
                            <div class="dedupe-value" style="color:#999">{{ $t('dedupe.sku_empty') }}</div>
                        </div>
                    </div>
                    @endif

                    @if($images !== [])
                    <div class="dedupe-card-row">
                        <div class="dedupe-card-col">
                            <span class="dedupe-label">{{ $t('dedupe.images_label') }}</span>
                            <div class="dedupe-images">
                                @foreach(array_slice($images, 0, 8) as $img)
                                    <a href="{{ $img['url'] }}" target="_blank" rel="noopener">
                                        <img src="{{ $img['url'] }}" alt="{{ $img['name'] ?? '' }}" data-product-request-image-preview>
                                    </a>
                                @endforeach
                                @if(count($images) > 8)<span style="align-self:center;color:#999;font-size:12px">+{{ count($images) - 8 }}</span>@endif
                            </div>
                        </div>
                    </div>
                    @endif

                    @if(!empty($item['cover_url']))
                    <div class="dedupe-card-row">
                        <div class="dedupe-card-col">
                            <span class="dedupe-label">{{ $t('dedupe.product_image_label') }}</span>
                            <div class="dedupe-images">
                                <a href="{{ $item['cover_url'] }}" target="_blank" rel="noopener">
                                    <img src="{{ $item['cover_url'] }}" alt="" data-product-request-image-preview>
                                </a>
                            </div>
                        </div>
                    </div>
                    @endif
                </div>
            </div>
        @endforeach
    @endif
</section>
@endsection
