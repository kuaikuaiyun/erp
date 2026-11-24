@extends('portal.layout')

@section('page_title', __('product_requests.warehouse_issues.title'))

@php
    $portalOrigin = rtrim(request()->getSchemeAndHttpHost(), '/');
    $t = static fn (string $key, array $replace = []): string => __('product_requests.'.$key, $replace);
    $coverFor = static fn ($item) => $item->attachmentsFor(\App\Models\ProductRequestAttachment::STAGE_OPERATION)->first();
    $remarkText = static function (?string $html): string {
        $text = str_replace(['<br>', '<br/>', '<br />'], ' ', (string) $html);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5);
        $text = str_replace("\xC2\xA0", ' ', $text);

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    };
    $clientConfig = [
        'csrfToken' => csrf_token(), 'noticeTitle' => $t('warehouse_issues.title'), 'loading' => $t('common.loading'),
        'loadFailed' => $t('common.modal_load_failed'), 'submitFailed' => $t('common.modal_submit_failed'),
    ];
@endphp

@push('styles')
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/product-requests.css?v=20261103-06">
    <link rel="stylesheet" href="{{ $portalOrigin }}/css/frontend-list-template.css?v=20261124-22">
    <style>
        .product-request-warehouse-issues-template{padding:15px}.product-request-warehouse-issues-template>.content-header{padding:0 0 15px}.product-request-warehouse-issues-template>.content-header h1{margin:0}.product-request-warehouse-issues-template>.content-header p{margin:6px 0 0;color:#718697}.product-request-warehouse-issues-filters{margin-bottom:15px;padding:18px 20px 15px}.product-request-warehouse-issues-filter-grid{display:grid;grid-template-columns:minmax(320px,2fr) minmax(180px,1fr);gap:14px 18px;align-items:end}.product-request-warehouse-issues-filter-grid>label>input{margin-top:8px}.product-request-warehouse-issues-filter-actions{display:flex;justify-content:flex-end;gap:9px}.product-request-warehouse-issues-records{overflow:hidden}.product-request-warehouse-issues-records .flt-toolbar{padding:12px 18px}.product-request-warehouse-issues-table-scroll{max-height:calc(100vh - 285px);overflow:auto}.product-request-warehouse-issues-table{width:100%!important;min-width:1460px!important;margin:0!important;border-collapse:separate!important;border-spacing:0!important;table-layout:fixed!important}.product-request-warehouse-issues-table th:nth-child(1){width:145px}.product-request-warehouse-issues-table th:nth-child(2){width:105px}.product-request-warehouse-issues-table th:nth-child(3){width:175px}.product-request-warehouse-issues-table th:nth-child(4){width:230px}.product-request-warehouse-issues-table th:nth-child(5){width:135px}.product-request-warehouse-issues-table th:nth-child(6){width:255px}.product-request-warehouse-issues-table th:nth-child(7){width:145px}.product-request-warehouse-issues-table th:nth-child(8){width:150px}.product-request-warehouse-issues-table th:nth-child(9){width:95px}.product-request-warehouse-issues-table thead th{position:sticky;top:0;z-index:4;padding:11px 10px!important;background:#eaf4fa!important;color:#345c75!important;border-right:1px solid #dce8ef!important;white-space:nowrap}.product-request-warehouse-issues-table tbody td{padding:11px 10px!important;border-right:1px solid #e7eef3!important;border-bottom:1px solid #e7eef3!important;vertical-align:middle!important}.product-request-warehouse-issues-table tbody tr:nth-child(odd)>td{background:#fff}.product-request-warehouse-issues-table tbody tr:nth-child(even)>td{background:#f3f9fd}.product-request-warehouse-issues-table tbody tr:hover>td{background:#eaf5fb!important}.product-request-warehouse-issues-table .product-request-sticky-left,.product-request-warehouse-issues-table .product-request-sticky-right{background:inherit!important}.product-request-warehouse-issues-table .product-request-remark-text{display:block;overflow:hidden;max-width:100%;margin-bottom:5px;text-overflow:ellipsis;white-space:nowrap}.product-request-warehouse-reject-reason{display:inline-flex;padding:3px 9px;border:1px solid #f0c9c4;border-radius:12px;background:#fff0ee;color:#bd5c4d;font-size:12px}.product-request-warehouse-issues-actions{display:flex;align-items:center;justify-content:center}.product-request-warehouse-issues-actions .flt-row-action{display:inline-flex;align-items:center;gap:4px;white-space:nowrap}.product-request-operation-pagination{border-top:1px solid #e3edf4}.product-request-operation-pagination .pagination-total{margin-right:auto}.product-request-operation-pagination form{display:flex;align-items:center;gap:8px;margin:0}.product-request-operation-pagination form input[type=hidden]{display:none}.product-request-operation-pagination .page-jump input{width:64px!important}.product-request-operation-pagination .is-disabled{pointer-events:none;opacity:.45}@media(max-width:760px){.product-request-warehouse-issues-template{padding:10px}.product-request-warehouse-issues-filter-grid{grid-template-columns:1fr}.product-request-warehouse-issues-filter-actions{justify-content:flex-start}.product-request-operation-pagination{justify-content:center}.product-request-operation-pagination .pagination-total,.product-request-operation-pagination .page-size{width:100%;margin:0;justify-content:center}}
    </style>
@endpush

@push('scripts')
    <script>window.ErpProductRequestConfig = @json($clientConfig);</script>
    <script src="{{ $portalOrigin }}/assets/erp/product-requests.js?v=20261124-03"></script>
@endpush

@section('content')
<section class="content product-request-page product-request-warehouse-issues-template flt">
    <header class="content-header"><h1>{{ $t('warehouse_issues.title') }}</h1><p>{{ $t('warehouse_issues.subtitle') }}</p></header>

    <form class="flt-card flt-filters product-request-warehouse-issues-filters" method="get" action="{{ route('product-requests.warehouse-issues.index') }}" data-loading="region" data-loading-target=".product-request-warehouse-issues-records">
        <div class="product-request-warehouse-issues-filter-grid">
            <label>{{ $t('filters.keyword') }}<input type="search" name="keyword" value="{{ $filters['keyword'] }}" placeholder="{{ $t('filters.keyword_placeholder') }}" autocomplete="off"></label>
            <input type="hidden" name="per_page" value="{{ $filters['per_page'] }}">
            <div class="product-request-warehouse-issues-filter-actions"><button class="flt-btn flt-primary" type="submit"><i class="fa fa-search"></i> {{ $t('common.search') }}</button><a class="flt-btn" href="{{ route('product-requests.warehouse-issues.index') }}"><i class="fa fa-refresh"></i> {{ $t('common.reset') }}</a></div>
        </div>
    </form>

    <div class="flt-card flt-records product-request-table-box product-request-warehouse-issues-records">
        <div class="flt-toolbar"><div class="flt-selection"><strong>{{ $t('warehouse_issues.title') }}</strong><span class="flt-toolbar-divider"></span><span>共 {{ $requests->total() }} 条记录</span></div></div>
        <div class="flt-table-scroll product-request-warehouse-issues-table-scroll"><table class="product-request-table product-request-warehouse-issues-table"><caption class="flt-sr-only">{{ $t('warehouse_issues.title') }}</caption><thead><tr><th class="product-request-sticky-left">{{ $t('table.report_no') }}</th><th class="product-request-image-cell">{{ $t('table.product') }}</th><th>{{ $t('table.standard_chinese_name') }}</th><th class="product-request-sku-cell">{{ $t('table.skus') }}</th><th>{{ $t('fields.warehouse_reject_reason') }}</th><th class="product-request-remark-cell">{{ $t('fields.warehouse_remark') }}</th><th>{{ $t('fields.warehouse_operator') }}</th><th>{{ $t('table.processed_at') }}</th><th class="product-request-sticky-right">{{ $t('common.operation') }}</th></tr></thead><tbody>
            @forelse($requests as $productRequest)
                @php
                    $cover = $coverFor($productRequest);
                    $rejectReason = $productRequest->warehouse_reject_reason
                        ? ($warehouseRejectReasonLabels[$productRequest->warehouse_reject_reason] ?? '')
                        : '';
                    $rejectRemark = $remarkText($productRequest->warehouse_remark);
                    $rejectImages = $productRequest->attachments->where('stage', \App\Models\ProductRequestAttachment::STAGE_WAREHOUSE_REMARK)->take(3);
                @endphp
                <tr>
                    <td class="product-request-sticky-left"><strong>{{ $productRequest->report_no }}</strong></td>
                    <td class="product-request-image-cell">@if($cover)<img class="product-request-thumbnail" loading="lazy" decoding="async" src="{{ route('product-requests.attachments.show', [$productRequest, $cover]) }}?w=240" alt="" data-product-request-image-preview data-product-request-image-alt="{{ $cover->original_name }}" onerror="this.classList.add('is-image-missing')"><span class="product-request-image-missing" title="图片暂不可用"><i class="fa fa-picture-o"></i></span>@else{{ $t('common.not_available') }}@endif</td>
                    <td>{{ $productRequest->standard_chinese_name ?: $t('common.not_filled') }}</td>
                    <td class="product-request-sku-cell"><div class="product-request-sku-stack"><div class="product-request-sku-stack-item"><span class="product-request-sku-stack-label">{{ $t('fields.operation_skus') }}</span>@include('portals.product-requests.partials.sku-tags', ['skus' => $productRequest->skus, 'empty' => $t('common.not_available'), 'collapse' => true, 'collapseLimit' => 1])</div><div class="product-request-sku-stack-item"><span class="product-request-sku-stack-label">{{ $t('fields.procurement_skus') }}</span>@include('portals.product-requests.partials.sku-tags', ['skus' => $productRequest->procurement_skus, 'empty' => $t('common.not_filled'), 'collapse' => true, 'collapseLimit' => 1])</div></div></td>
                    <td>@if($rejectReason !== '')<span class="product-request-warehouse-reject-reason">{{ $rejectReason }}</span>@else{{ $t('common.not_available') }}@endif</td>
                    <td class="product-request-remark-cell">@if($rejectRemark !== '')<span class="product-request-remark-text" title="{{ $rejectRemark }}">{{ \Illuminate\Support\Str::limit($rejectRemark, 80) }}</span>@endif @foreach($rejectImages as $rejectImage)<img class="product-request-thumbnail" loading="lazy" decoding="async" src="{{ route('product-requests.attachments.show', [$productRequest, $rejectImage]) }}?w=240" alt="" data-product-request-image-preview data-product-request-image-alt="{{ $rejectImage->original_name }}" onerror="this.classList.add('is-image-missing')"><span class="product-request-image-missing" title="图片暂不可用"><i class="fa fa-picture-o"></i></span> @endforeach @if($rejectRemark === '' && $rejectImages->isEmpty()){{ $t('common.not_available') }}@endif</td>
                    <td>{{ $productRequest->warehouse_operator_display_name ?: $t('common.not_available') }}</td>
                    <td class="product-request-nowrap-cell">{{ $productRequest->warehouse_processed_at ? $productRequest->warehouse_processed_at->timezone('Asia/Shanghai')->format('Y-m-d H:i') : $t('common.not_available') }}</td>
                    <td class="product-request-sticky-right product-request-warehouse-issues-actions"><a class="flt-row-action" href="{{ route('product-requests.show', $productRequest) }}" data-product-request-modal-url="{{ route('product-requests.show', ['productRequest' => $productRequest, 'modal' => 1]) }}"><i class="fa fa-eye"></i> {{ $t('common.view') }}</a></td>
                </tr>
            @empty
                <tr><td colspan="9"><div class="flt-empty"><i class="fa fa-inbox"></i><h4>{{ $t('common.no_records') }}</h4><p>可调整关键词后重新查询</p></div></td></tr>
            @endforelse
        </tbody></table></div>
        @include('portals.product-requests.operation.partials.pagination', ['paginator' => $requests])
    </div>
    @include('portals.product-requests.partials.modal')
</section>
@endsection
