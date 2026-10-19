@extends('portal.layout')

@section('page_title', __('product_requests.operation.title'))

@php
    $portalOrigin = rtrim(request()->getSchemeAndHttpHost(), '/');
    $t = static fn (string $key, array $replace = []): string => __('product_requests.'.$key, $replace);
    $coverFor = static fn ($item) => $item->attachmentsFor(\App\Models\ProductRequestAttachment::STAGE_OPERATION)->first();
    $normalizeSkus = static fn (?string $value): array => collect(explode(',', (string) $value))->map(fn (string $sku): string => trim($sku))->filter(fn (string $sku): bool => $sku !== '')->unique()->sort()->values()->all();
    $remarkText = static function (?string $html): string {
        $text = str_replace(['<br>', '<br/>', '<br />'], ' ', (string) $html);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5);
        $text = str_replace("\xC2\xA0", ' ', $text);

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    };
    $clientConfig = [
        'csrfToken' => csrf_token(), 'noticeTitle' => $t('operation.title'), 'loading' => $t('common.loading'),
        'loadFailed' => $t('common.modal_load_failed'), 'submitFailed' => $t('common.modal_submit_failed'),
        'invalidFile' => $t('uploads.invalid_file'), 'tooMany' => $t('uploads.too_many', ['count' => ':count']),
        'removeImage' => $t('uploads.remove_image'), 'reuse' => $t('common.reuse'), 'reuseEmpty' => $t('procurement.reuse_empty'),
        'reuseSelected' => $t('procurement.reuse_selected', ['report_no' => ':report_no']),
        'reuseRequired' => $t('procurement.reuse_check_hint'), 'reuseFailed' => $t('common.modal_load_failed'),
        'skuIncomplete' => $t('help.sku_incomplete', ['count' => ':count']), 'skuDuplicated' => $t('help.sku_duplicated'),
        'skuTooMany' => $t('help.sku_too_many', ['count' => ':count']), 'skuSeparator' => $t('help.sku_separator'),
        'pasteHint' => $t('uploads.paste_hint'),
        'submitProgress' => $t('common.submit_progress'),
        'deleteConfirm' => $t('common.delete_confirm'), 'deleteFailed' => $t('common.delete_failed'),
    ];
@endphp

@push('styles')
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/product-requests.css?v=20261018-20">
@endpush
@push('scripts')
    <script>window.ErpProductRequestConfig = @json($clientConfig);</script>
    <script src="{{ $portalOrigin }}/assets/erp/product-requests.js?v=20261018-20"></script>
@endpush

@section('content')
<section class="content product-request-page">
    <header class="content-header"><h1>{{ $t('operation.title') }}</h1><p>{{ $t('operation.subtitle') }}</p></header>
    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    <div class="box box-solid product-request-filter-box">
        <div class="box-header with-border"><h3 class="box-title"><i class="fa fa-filter"></i> {{ $t('filters.keyword') }}</h3></div>
        <div class="box-body"><form class="product-request-filter-form" method="get" action="{{ route('product-requests.operation.index') }}" data-loading="region" data-loading-target=".product-request-table-box"><div class="product-request-filter-grid">
            <div class="product-request-filter-field"><label>{{ $t('filters.keyword') }}</label><input class="form-control" name="keyword" value="{{ $filters['keyword'] }}" placeholder="{{ $t('filters.keyword_placeholder') }}"></div>
            <div class="product-request-filter-field"><label>{{ $t('filters.status') }}</label><select class="form-control" name="status"><option value="">{{ $t('common.all') }}</option>@foreach($statusLabels as $value => $label)<option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>@endforeach</select></div>
            <div class="product-request-filter-field"><label>{{ $t('filters.review_status') }}</label><select class="form-control" name="review_status"><option value="">{{ $t('common.all') }}</option>@foreach($reviewStatusLabels as $value => $label)<option value="{{ $value }}" @selected($filters['review_status'] === $value)>{{ $label }}</option>@endforeach</select></div>
            <div class="product-request-filter-field"><label>{{ $t('table.operation_rectification_status') }}</label><select class="form-control" name="operation_rectification_status"><option value="">{{ $t('common.all') }}</option>@foreach($rectificationLabels as $value => $label)<option value="{{ $value }}" @selected($filters['operation_rectification_status'] === $value)>{{ $label }}</option>@endforeach</select></div>
            <div class="product-request-filter-field"><label>{{ $t('filters.warehouse_status') }}</label><select class="form-control" name="warehouse_status"><option value="">{{ $t('common.all') }}</option>@foreach($warehouseResultLabels as $value => $label)<option value="{{ $value }}" @selected($filters['warehouse_status'] === $value)>{{ $label }}</option>@endforeach</select></div>
            <div class="product-request-filter-field"><label>{{ $t('filters.per_page') }}</label><select class="form-control" name="per_page">@foreach([20, 50, 100] as $perPage)<option value="{{ $perPage }}" @selected($filters['per_page'] === $perPage)>{{ $perPage }}</option>@endforeach</select></div>
        </div><div class="product-request-filter-actions"><button class="btn btn-primary" type="submit"><i class="fa fa-search"></i> {{ $t('common.search') }}</button><a class="btn btn-default" href="{{ route('product-requests.operation.index') }}">{{ $t('common.reset') }}</a></div></form></div>
    </div>
    <div class="box box-solid product-request-table-box">
        <div class="box-header with-border product-request-list-toolbar"><h3 class="box-title">{{ $t('operation.title') }}</h3><span class="text-muted">{{ $t('operation.my_scope_hint') }}</span><span class="product-request-toolbar-spacer"></span><button type="button" class="btn btn-success btn-sm" data-product-request-modal-url="{{ route('product-requests.operation.create', ['modal' => 1]) }}"><i class="fa fa-plus"></i> {{ $t('common.new_request') }}</button></div>
        <div class="box-body table-responsive no-padding product-request-table-scroll"><table class="table table-hover product-request-table product-request-operation-table"><thead><tr><th class="product-request-sticky-left">{{ $t('table.report_no') }}</th><th>{{ $t('table.reported_at') }}</th><th class="product-request-sku-cell">{{ $t('table.skus') }}</th><th>{{ $t('table.staff_status') }}</th><th>{{ $t('table.review_status') }}</th><th class="product-request-remark-cell">{{ $t('table.review_remark') }}</th><th>{{ $t('table.warehouse_status') }}</th><th>{{ $t('table.operation_rectification_status') }}</th><th class="product-request-image-cell">{{ $t('table.product') }}</th><th class="product-request-image-cell product-request-image-cell-multi">{{ $t('table.seller_images') }}</th><th>{{ $t('table.operation_supplier_url') }}</th><th>{{ $t('table.reporter') }}</th><th>{{ $t('table.updated_at') }}</th><th class="product-request-sticky-right">{{ $t('common.operation') }}</th></tr></thead><tbody>
            @forelse($requests as $productRequest)
                @php
                    $cover = $coverFor($productRequest);
                    $sellerImages = $productRequest->attachmentsFor(\App\Models\ProductRequestAttachment::STAGE_PROCUREMENT)->take(3);
                    $rejectedReview = $productRequest->placementReviews->first(fn ($review) => $review->status === \App\Models\ProductRequestPlacementReview::STATUS_REJECTED);
                    $reviewRemarkText = $rejectedReview ? $remarkText($rejectedReview->remark) : '';
                    $reviewRemarkImages = $rejectedReview
                        ? $productRequest->attachments->where('stage', \App\Models\ProductRequestAttachment::STAGE_REVIEW)->take(3)
                        : collect();
                    $operationSkuSet = collect(explode(',', (string) $productRequest->skus))->map(fn ($sku) => trim($sku))->filter();
                    $procurementSkuSet = collect(explode(',', (string) $productRequest->procurement_skus))->map(fn ($sku) => trim($sku))->filter();
                    $hasMissingSku = $procurementSkuSet->isNotEmpty() && $operationSkuSet->contains(fn ($sku) => !$procurementSkuSet->contains($sku));
                @endphp
                <tr><td class="product-request-sticky-left">{{ $productRequest->report_no }}</td><td class="product-request-nowrap-cell">{{ optional($productRequest->reported_at)->format('Y-m-d') }}</td><td class="product-request-sku-cell">@if($hasMissingSku)<span class="product-request-sku-mismatch" title="{{ $t('operation.sku_changed_hint', ['operation' => $productRequest->skus ?: $t('common.not_available'), 'procurement' => $productRequest->procurement_skus]) }}"><i class="fa fa-exclamation-triangle"></i> {{ $t('operation.sku_changed') }}</span>@else @include('portals.product-requests.partials.sku-tags', ['skus' => $productRequest->skus, 'empty' => $t('common.not_available')])@endif</td><td>@if(trim((string) $productRequest->inspection_reject_reason) !== '')<span class="product-request-badge status-inspection_rejected" title="{{ $productRequest->inspection_reject_reason }}"><i class="fa fa-exclamation-triangle"></i> {{ $t('status.inspection_rejected') }}</span>@else<span class="product-request-badge status-{{ $productRequest->status }}">{{ $statusLabels[$productRequest->status] ?? $t('common.not_available') }}</span>@endif</td><td><span class="product-request-badge review-{{ $productRequest->placement_review_status }}">{{ $reviewStatusLabels[$productRequest->placement_review_status] ?? $t('common.not_available') }}</span></td><td class="product-request-remark-cell">
                    @if($rejectedReview)
                        @if($reviewRemarkText !== '')<span class="product-request-remark-text" title="{{ $reviewRemarkText }}">{{ \Illuminate\Support\Str::limit($reviewRemarkText, 60) }}</span> @endif
                        @foreach($reviewRemarkImages as $reviewRemarkImage)<img class="product-request-thumbnail" src="{{ route('product-requests.attachments.show', [$productRequest, $reviewRemarkImage]) }}" alt="{{ $reviewRemarkImage->original_name }}" data-product-request-image-preview data-product-request-image-alt="{{ $reviewRemarkImage->original_name }}"> @endforeach
                    @else
                        {{ $t('common.not_available') }}
                    @endif
                </td><td><span class="product-request-badge warehouse-{{ $productRequest->warehouse_acceptance_result }}">{{ $t('warehouse_result.'.$productRequest->warehouse_acceptance_result) }}</span></td><td><span class="product-request-badge rectification-{{ $productRequest->operation_rectification_status }}">{{ $t('rectification.'.$productRequest->operation_rectification_status) }}</span>@if((int) $productRequest->operation_user_id === (int) $user->getKey() && $productRequest->operation_rectification_status === \App\Models\ProductRequest::RECTIFICATION_PENDING)<form method="post" action="{{ route('product-requests.operation.rectify', $productRequest) }}" class="product-request-inline-form"><input type="hidden" name="_token" value="{{ csrf_token() }}"><button type="submit" class="btn btn-success btn-xs" data-product-request-confirm="{{ $t('rectification.confirm_operation') }}"><i class="fa fa-check"></i> {{ $t('rectification.mark_done') }}</button></form>@endif</td><td class="product-request-image-cell">@if($cover)<img class="product-request-thumbnail" src="{{ route('product-requests.attachments.show', [$productRequest, $cover]) }}" alt="{{ $cover->original_name }}" data-product-request-image-preview data-product-request-image-alt="{{ $cover->original_name }}">@else{{ $t('common.not_available') }}@endif</td><td class="product-request-image-cell product-request-image-cell-multi">@if($sellerImages->isNotEmpty())@foreach($sellerImages as $sellerImage)<img class="product-request-thumbnail" src="{{ route('product-requests.attachments.show', [$productRequest, $sellerImage]) }}" alt="{{ $sellerImage->original_name }}" data-product-request-image-preview data-product-request-image-alt="{{ $sellerImage->original_name }}">@endforeach @else{{ $t('common.not_available') }}@endif</td><td class="product-request-link-wrap">@if($productRequest->operation_supplier_url)<a href="{{ $productRequest->operation_supplier_url }}" target="_blank" rel="noopener" title="{{ $productRequest->operation_supplier_url }}">{{ $productRequest->operation_supplier_url }}</a>@else{{ $t('common.not_filled') }}@endif</td><td class="product-request-nowrap-cell">{{ $productRequest->operation_user_name ?: $t('common.not_available') }}</td><td class="product-request-nowrap-cell">{{ optional($productRequest->updated_at)->timezone('Asia/Shanghai')->format('Y-m-d H:i') }}</td><td class="product-request-sticky-right product-request-actions"><a class="btn btn-default btn-xs product-request-action-button product-request-action-view" href="{{ route('product-requests.show', $productRequest) }}" data-product-request-modal-url="{{ route('product-requests.show', ['productRequest' => $productRequest, 'modal' => 1]) }}"><i class="fa fa-eye"></i> {{ $t('common.view') }}</a>@if((int) $productRequest->operation_user_id === (int) $user->getKey() && trim((string) $productRequest->inspection_reject_reason) !== '')<button type="button" class="btn btn-warning btn-xs product-request-action-button" data-product-request-modal-url="{{ route('product-requests.operation.edit', ['productRequest' => $productRequest, 'modal' => 1]) }}"><i class="fa fa-pencil"></i> {{ $t('operation.edit') }}</button>@endif @if((int) $productRequest->operation_user_id === (int) $user->getKey() && $productRequest->status === \App\Models\ProductRequest::STATUS_INSPECTION_PASSED && $productRequest->placement_review_status !== \App\Models\ProductRequest::REVIEW_PENDING && $productRequest->placement_review_status !== \App\Models\ProductRequest::REVIEW_APPROVED)<button type="button" class="btn btn-primary btn-xs product-request-action-button product-request-action-primary" data-product-request-modal-url="{{ route('product-requests.placement.edit', ['productRequest' => $productRequest, 'modal' => 1]) }}"><i class="fa fa-external-link"></i> {{ $t('operation.placement_title') }}</button>@endif
                @if($productRequest->status === \App\Models\ProductRequest::STATUS_PENDING)<button type="button" class="btn btn-danger btn-xs product-request-action-button" data-product-request-delete data-delete-url="{{ route('product-requests.operation.destroy', $productRequest) }}" title="{{ $t('common.delete_confirm') }}"><i class="fa fa-trash-o"></i> {{ $t('common.delete') }}</button>@endif
                </td></tr>
            @empty
                <tr><td colspan="14" class="product-request-empty">{{ $t('common.no_records') }}</td></tr>
            @endforelse
        </tbody></table></div>
        {{ $requests->links('portals.product-requests.partials.pagination') }}
    </div>
    @include('portals.product-requests.partials.modal')
</section>
@endsection
