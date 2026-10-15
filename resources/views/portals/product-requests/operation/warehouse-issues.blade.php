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
@push('styles')<link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/product-requests.css?v=20261015-19">@endpush
@push('scripts')<script>window.ErpProductRequestConfig = @json($clientConfig);</script><script src="{{ $portalOrigin }}/assets/erp/product-requests.js?v=20261015-19"></script>@endpush

@section('content')
<section class="content product-request-page">
    <header class="content-header"><h1>{{ $t('warehouse_issues.title') }}</h1><p>{{ $t('warehouse_issues.subtitle') }}</p></header>
    <div class="box box-solid product-request-filter-box"><div class="box-header with-border"><h3 class="box-title"><i class="fa fa-filter"></i> {{ $t('filters.keyword') }}</h3></div><div class="box-body"><form class="product-request-filter-form" method="get" action="{{ route('product-requests.warehouse-issues.index') }}" data-loading="region" data-loading-target=".product-request-table-box"><div class="product-request-filter-grid"><div class="product-request-filter-field"><label>{{ $t('filters.keyword') }}</label><input class="form-control" name="keyword" value="{{ $filters['keyword'] }}" placeholder="{{ $t('filters.keyword_placeholder') }}"></div><div class="product-request-filter-field"><label>{{ $t('filters.per_page') }}</label><select class="form-control" name="per_page">@foreach([20, 50, 100] as $perPage)<option value="{{ $perPage }}" @selected($filters['per_page'] === $perPage)>{{ $perPage }}</option>@endforeach</select></div></div><div class="product-request-filter-actions"><button class="btn btn-primary" type="submit"><i class="fa fa-search"></i> {{ $t('common.search') }}</button><a class="btn btn-default" href="{{ route('product-requests.warehouse-issues.index') }}">{{ $t('common.reset') }}</a></div></form></div></div>
    <div class="box box-solid product-request-table-box"><div class="box-header with-border product-request-list-toolbar"><h3 class="box-title">{{ $t('warehouse_issues.title') }}</h3></div><div class="box-body table-responsive no-padding product-request-table-scroll"><table class="table table-hover product-request-table"><thead><tr><th class="product-request-sticky-left">{{ $t('table.report_no') }}</th><th class="product-request-image-cell">{{ $t('table.product') }}</th><th>{{ $t('table.standard_chinese_name') }}</th><th class="product-request-sku-cell">{{ $t('table.skus') }}</th><th>{{ $t('fields.warehouse_reject_reason') }}</th><th class="product-request-remark-cell">{{ $t('fields.warehouse_remark') }}</th><th>{{ $t('fields.warehouse_operator') }}</th><th>{{ $t('table.processed_at') }}</th><th class="product-request-sticky-right">{{ $t('common.operation') }}</th></tr></thead><tbody>
        @forelse($requests as $productRequest)
            @php
                $cover = $coverFor($productRequest);
                $rejectReason = $productRequest->warehouse_reject_reason
                    ? ($warehouseRejectReasonLabels[$productRequest->warehouse_reject_reason] ?? '')
                    : '';
                $rejectRemark = $remarkText($productRequest->warehouse_remark);
                $rejectImages = $productRequest->attachments->where('stage', \App\Models\ProductRequestAttachment::STAGE_WAREHOUSE_REMARK)->take(3);
            @endphp
            <tr><td class="product-request-sticky-left">{{ $productRequest->report_no }}</td><td>@if($cover)<img class="product-request-thumbnail" src="{{ route('product-requests.attachments.show', [$productRequest, $cover]) }}" alt="{{ $cover->original_name }}" data-product-request-image-preview data-product-request-image-alt="{{ $cover->original_name }}">@else{{ $t('common.not_available') }}@endif</td><td>{{ $productRequest->standard_chinese_name ?: $t('common.not_filled') }}</td><td class="product-request-sku-cell"><div class="product-request-sku-stack"><div class="product-request-sku-stack-item"><span class="product-request-sku-stack-label">{{ $t('fields.operation_skus') }}</span>@include('portals.product-requests.partials.sku-tags', ['skus' => $productRequest->skus, 'empty' => $t('common.not_available')])</div><div class="product-request-sku-stack-item"><span class="product-request-sku-stack-label">{{ $t('fields.procurement_skus') }}</span>@include('portals.product-requests.partials.sku-tags', ['skus' => $productRequest->procurement_skus, 'empty' => $t('common.not_filled')])</div></div></td><td>@if($rejectReason !== '')<span class="product-request-warehouse-reject-reason">{{ $rejectReason }}</span>@else{{ $t('common.not_available') }}@endif</td><td class="product-request-remark-cell">
                @if($rejectRemark !== '')<span class="product-request-remark-text">{{ $rejectRemark }}</span> @endif
                @foreach($rejectImages as $rejectImage)<img class="product-request-thumbnail" src="{{ route('product-requests.attachments.show', [$productRequest, $rejectImage]) }}" alt="{{ $rejectImage->original_name }}" data-product-request-image-preview data-product-request-image-alt="{{ $rejectImage->original_name }}"> @endforeach
                @if($rejectRemark === '' && $rejectImages->isEmpty()){{ $t('common.not_available') }}@endif
            </td><td>{{ $productRequest->warehouse_operator_display_name ?: $t('common.not_available') }}</td><td class="product-request-nowrap-cell">{{ $productRequest->warehouse_processed_at ? \Carbon\Carbon::parse($productRequest->warehouse_processed_at)->format('Y-m-d H:i') : $t('common.not_available') }}</td><td class="product-request-sticky-right product-request-actions"><a class="btn btn-default btn-xs product-request-action-button product-request-action-view" href="{{ route('product-requests.show', $productRequest) }}" data-product-request-modal-url="{{ route('product-requests.show', ['productRequest' => $productRequest, 'modal' => 1]) }}"><i class="fa fa-eye"></i> {{ $t('common.view') }}</a></td></tr>
        @empty
            <tr><td colspan="9" class="product-request-empty">{{ $t('common.no_records') }}</td></tr>
        @endforelse
    </tbody></table></div>@if($requests->hasPages())<div class="box-footer clearfix product-request-pagination" data-loading="region" data-loading-target=".product-request-table-box">{{ $requests->links() }}</div>@endif</div>
    @include('portals.product-requests.partials.modal')
</section>
@endsection
