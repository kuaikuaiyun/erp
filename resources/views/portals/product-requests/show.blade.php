@extends('portal.layout')

@section('page_title', __('product_requests.show.title'))

@php
    $portalOrigin = rtrim(request()->getSchemeAndHttpHost(), '/');
    $operationImages = $productRequest->attachmentsFor(\App\Models\ProductRequestAttachment::STAGE_OPERATION);
    $purchaseItemImages = $productRequest->attachmentsFor(\App\Models\ProductRequestAttachment::STAGE_PURCHASE_ITEM);
    $procurementImages = $productRequest->attachmentsFor(\App\Models\ProductRequestAttachment::STAGE_PROCUREMENT);
    $warehouseImages = $productRequest->attachmentsFor(\App\Models\ProductRequestAttachment::STAGE_WAREHOUSE);
    $weightDifference = is_numeric($productRequest->actual_shipping_weight_g) && is_numeric($productRequest->estimated_shipping_weight_g)
        ? (float) $productRequest->actual_shipping_weight_g - (float) $productRequest->estimated_shipping_weight_g
        : null;
    $link = $productRequest->placement_link;
    $referenceLink = trim((string) $productRequest->reference_page_url);
    $notFilled = __('product_requests.common.not_filled');
    $notAvailable = __('product_requests.common.not_available');
    $warehouseResult = $productRequest->warehouse_acceptance_result
        ? ($warehouseResultLabels[$productRequest->warehouse_acceptance_result] ?? $notAvailable)
        : $notFilled;
    $warehouseRejectReason = $productRequest->warehouse_reject_reason
        ? ($warehouseRejectReasonLabels[$productRequest->warehouse_reject_reason] ?? $notAvailable)
        : $notFilled;
    $isWebLink = static fn (?string $value): bool => str_starts_with(strtolower(trim((string) $value)), 'http://') || str_starts_with(strtolower(trim((string) $value)), 'https://');
@endphp

@push('styles')
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/product-requests.css?v=20261018-1">
@endpush
@push('scripts')
    <script src="{{ $portalOrigin }}/assets/erp/product-requests.js?v=20261018-1"></script>
@endpush

@section('content')
<section class="content product-request-page product-request-detail-page">
    <header class="content-header product-request-detail-header">
        <div>
            <h1>{{ __('product_requests.show.title') }}</h1>
            <p>{{ $productRequest->report_no }}</p>
        </div>
        <div class="product-request-detail-status">
            <span class="product-request-badge status-{{ $productRequest->status }}">{{ $statusLabels[$productRequest->status] ?? $notAvailable }}</span>
            <span class="product-request-badge review-{{ $productRequest->placement_review_status }}">{{ $reviewStatusLabels[$productRequest->placement_review_status] ?? $notAvailable }}</span>
        </div>
    </header>

    <div class="product-request-toolbar product-request-detail-toolbar">
        <a class="btn btn-default" href="{{ url()->previous() }}"><i class="fa fa-angle-left"></i> {{ __('product_requests.common.back_to_list') }}</a>
    </div>

    <article class="product-request-box product-request-detail-card product-request-detail-overview-card">
        <div class="box-header"><h2 class="box-title">{{ __('product_requests.show.overview') }}</h2></div>
        <div class="box-body product-request-detail-overview-content">
            <div class="product-request-detail-gallery-panel">
                <span class="product-request-detail-label">{{ __('product_requests.fields.product_image') }}</span>
                <div class="product-request-image-grid">
                    @forelse($operationImages as $attachment)
                        <a href="{{ route('product-requests.attachments.show', [$productRequest, $attachment]) }}" data-product-request-image-preview data-product-request-image-alt="{{ $attachment->original_name }}">
                            <img src="{{ route('product-requests.attachments.show', [$productRequest, $attachment]) }}" alt="{{ $attachment->original_name }}">
                        </a>
                    @empty
                        <span class="text-muted">{{ $notAvailable }}</span>
                    @endforelse
                </div>
            </div>
            <div class="product-request-detail-grid product-request-detail-grid-compact">
                <div class="product-request-detail-item"><span class="product-request-detail-label">{{ __('product_requests.fields.report_no') }}</span><span class="product-request-detail-value">{{ $productRequest->report_no }}</span></div>
                <div class="product-request-detail-item"><span class="product-request-detail-label">{{ __('product_requests.fields.reported_at') }}</span><span class="product-request-detail-value">{{ optional($productRequest->reported_at)->format('Y-m-d') }}</span></div>
                <div class="product-request-detail-item"><span class="product-request-detail-label">{{ __('product_requests.fields.main_status') }}</span><span class="product-request-detail-value">{{ $statusLabels[$productRequest->status] ?? $notAvailable }}</span></div>
                <div class="product-request-detail-item"><span class="product-request-detail-label">{{ __('product_requests.fields.skus') }}</span><span class="product-request-detail-value">@include('portals.product-requests.partials.sku-tags', ['skus' => $productRequest->skus, 'compareSkus' => $productRequest->procurement_skus, 'empty' => $notAvailable])</span></div>
                <div class="product-request-detail-item"><span class="product-request-detail-label">{{ __('product_requests.fields.has_stock') }}</span><span class="product-request-detail-value">{{ $productRequest->has_stock ? __('product_requests.common.'.($productRequest->has_stock === 'yes' ? 'yes' : 'no')) : $notAvailable }}</span></div>
                <div class="product-request-detail-item"><span class="product-request-detail-label">{{ __('product_requests.fields.request_source') }}</span><span class="product-request-detail-value">{{ $productRequest->operation_display_name }}</span></div>
                <div class="product-request-detail-item product-request-detail-item-wide"><span class="product-request-detail-label">{{ __('product_requests.fields.operation_supplier_url') }}</span><span class="product-request-detail-value">@if($productRequest->operation_supplier_url)<a href="{{ $productRequest->operation_supplier_url }}" target="_blank" rel="noopener">{{ $productRequest->operation_supplier_url }}</a>@else{{ $notFilled }}@endif</span></div>
                <div class="product-request-detail-item product-request-detail-item-wide"><span class="product-request-detail-label">{{ __('product_requests.fields.operation_remark') }}</span><span class="product-request-detail-value">{{ $productRequest->operation_remark ?: $notFilled }}</span></div>
            </div>
            <div class="product-request-detail-gallery-panel product-request-detail-gallery-wide">
                <span class="product-request-detail-label">{{ __('product_requests.fields.purchase_item_images') }}</span>
                <div class="product-request-image-grid">
                    @forelse($purchaseItemImages as $attachment)
                        <a href="{{ route('product-requests.attachments.show', [$productRequest, $attachment]) }}" data-product-request-image-preview data-product-request-image-alt="{{ $attachment->original_name }}">
                            <img src="{{ route('product-requests.attachments.show', [$productRequest, $attachment]) }}" alt="{{ $attachment->original_name }}">
                        </a>
                    @empty
                        <span class="text-muted">{{ $notAvailable }}</span>
                    @endforelse
                </div>
            </div>
        </div>
    </article>

    <article class="product-request-box product-request-detail-card">
        <div class="box-header"><h2 class="box-title">{{ __('product_requests.show.procurement_information') }}</h2></div>
        <div class="box-body">
            <div class="product-request-detail-grid">
                <div class="product-request-detail-item"><span class="product-request-detail-label">{{ __('product_requests.fields.standard_chinese_name') }}</span><span class="product-request-detail-value">{{ $productRequest->standard_chinese_name ?: $notFilled }}</span></div>
                <div class="product-request-detail-item"><span class="product-request-detail-label">{{ __('product_requests.fields.pcl_product_no') }}</span><span class="product-request-detail-value">{{ $productRequest->pcl_product_no ?: $notFilled }}</span></div>
                <div class="product-request-detail-item product-request-detail-item-wide"><span class="product-request-detail-label">{{ __('product_requests.fields.supplier_url') }}</span><span class="product-request-detail-value">@if($productRequest->supplier_url)<a href="{{ $productRequest->supplier_url }}" target="_blank" rel="noopener">{{ $productRequest->supplier_url }}</a>@else{{ $notFilled }}@endif</span></div>
                <div class="product-request-detail-item product-request-detail-item-wide"><span class="product-request-detail-label">{{ __('product_requests.fields.procurement_skus') }}</span><span class="product-request-detail-value">@include('portals.product-requests.partials.sku-tags', ['skus' => $productRequest->procurement_skus, 'empty' => $notFilled])</span></div>
                <div class="product-request-detail-item"><span class="product-request-detail-label">{{ __('product_requests.fields.outer_package_size') }}</span><span class="product-request-detail-value">{{ $productRequest->outer_package_size ?: $notFilled }}</span></div>
                <div class="product-request-detail-item"><span class="product-request-detail-label">{{ __('product_requests.fields.minimum_purchase_price_rmb') }}</span><span class="product-request-detail-value">{{ $productRequest->minimum_purchase_price_rmb ?: $notFilled }}</span></div>
                <div class="product-request-detail-item"><span class="product-request-detail-label">{{ __('product_requests.fields.estimated_shipping_weight_g') }}</span><span class="product-request-detail-value">{{ $productRequest->estimated_shipping_weight_g ?: $notFilled }}</span></div>
                <div class="product-request-detail-item"><span class="product-request-detail-label">{{ __('product_requests.fields.large_volume_weight_g') }}</span><span class="product-request-detail-value">{{ $productRequest->large_volume_weight_g ?: $notFilled }}</span></div>
                <div class="product-request-detail-item"><span class="product-request-detail-label">{{ __('product_requests.fields.erp_operator') }}</span><span class="product-request-detail-value">{{ $productRequest->erp_operator_display_name ?: $notFilled }}</span></div>
                @if(trim((string) $productRequest->inspection_reject_reason) !== '')
                <div class="product-request-detail-item product-request-detail-item-wide"><span class="product-request-detail-label">{{ __('product_requests.fields.inspection_reject_reason') }}</span><span class="product-request-detail-value product-request-remark-text">{{ $productRequest->inspection_reject_reason }}</span></div>
                @endif
            </div>
            <div class="product-request-detail-media">
                <span class="product-request-detail-label">{{ __('product_requests.fields.seller_images') }}</span>
                <div class="product-request-image-grid">
                    @forelse($procurementImages as $attachment)
                        <a href="{{ route('product-requests.attachments.show', [$productRequest, $attachment]) }}" data-product-request-image-preview data-product-request-image-alt="{{ $attachment->original_name }}">
                            <img src="{{ route('product-requests.attachments.show', [$productRequest, $attachment]) }}" alt="{{ $attachment->original_name }}">
                        </a>
                    @empty
                        <span class="text-muted">{{ $notFilled }}</span>
                    @endforelse
                </div>
            </div>
        </div>
    </article>

    <article class="product-request-box product-request-detail-card">
        <div class="box-header"><h2 class="box-title">{{ __('product_requests.show.placement_information') }}</h2></div>
        <div class="box-body">
            <div class="product-request-detail-grid product-request-placement-grid">
                <div class="product-request-detail-item product-request-detail-item-wide"><span class="product-request-detail-label">{{ __('product_requests.fields.placement_link') }}</span><span class="product-request-detail-value">@if($link)<a href="{{ $link }}" target="_blank" rel="noopener">{{ $link }}</a>@else{{ $notFilled }}@endif</span></div>
                <div class="product-request-detail-item"><span class="product-request-detail-label">{{ __('product_requests.table.review_status') }}</span><span class="product-request-detail-value">{{ $reviewStatusLabels[$productRequest->placement_review_status] ?? $notAvailable }}</span></div>
            </div>
            <h3 class="product-request-subsection-title">{{ __('product_requests.review.history') }}</h3>
            <div class="product-request-review-list">
                @forelse($productRequest->placementReviews as $review)
                    <div class="product-request-review-card">
                        <div class="product-request-detail-grid product-request-review-grid">
                            <div class="product-request-detail-item"><span class="product-request-detail-label">{{ __('product_requests.table.review_status') }}</span><span class="product-request-detail-value">{{ __('product_requests.review_status.'.$review->status) }}</span></div>
                            <div class="product-request-detail-item"><span class="product-request-detail-label">{{ __('product_requests.review.submitted_by') }}</span><span class="product-request-detail-value">{{ $review->submission_user_name }}</span></div>
                            <div class="product-request-detail-item"><span class="product-request-detail-label">{{ __('product_requests.review.submitted_at') }}</span><span class="product-request-detail-value">{{ optional($review->submitted_at)->timezone('Asia/Shanghai')->format('Y-m-d H:i') }}</span></div>
                            <div class="product-request-detail-item"><span class="product-request-detail-label">{{ __('product_requests.review.reviewed_by') }}</span><span class="product-request-detail-value">{{ $review->reviewer_name ?: $notFilled }}</span></div>
                            <div class="product-request-detail-item"><span class="product-request-detail-label">{{ __('product_requests.review.reviewed_at') }}</span><span class="product-request-detail-value">{{ optional($review->reviewed_at)->timezone('Asia/Shanghai')->format('Y-m-d H:i') }}</span></div>
                        </div>
                        <div class="product-request-review-remark"><span class="product-request-detail-label">{{ __('product_requests.fields.review_remark') }}</span><div class="product-request-remark-html">@if($review->remark){!! $review->remark !!}@else{{ $notFilled }}@endif</div></div>
                    </div>
                @empty
                    <p class="text-muted">{{ __('product_requests.common.no_records') }}</p>
                @endforelse
            </div>
        </div>
    </article>

    <article class="product-request-box product-request-detail-card">
        <div class="box-header"><h2 class="box-title">{{ __('product_requests.show.warehouse_information') }}</h2></div>
        <div class="box-body">
            <div class="product-request-detail-grid">
                <div class="product-request-detail-item"><span class="product-request-detail-label">{{ __('product_requests.fields.actual_shipping_weight_g') }}</span><span class="product-request-detail-value">{{ $productRequest->actual_shipping_weight_g ?: $notFilled }}</span></div>
                <div class="product-request-detail-item"><span class="product-request-detail-label">{{ __('product_requests.fields.package_size_cm') }}</span><span class="product-request-detail-value">{{ collect([$productRequest->package_length_cm, $productRequest->package_width_cm, $productRequest->package_height_cm])->filter()->implode(' × ') ?: $notFilled }}</span></div>
                <div class="product-request-detail-item"><span class="product-request-detail-label">{{ __('product_requests.fields.warehouse_acceptance_result') }}</span><span class="product-request-detail-value">{{ $warehouseResult }}</span></div>
                <div class="product-request-detail-item"><span class="product-request-detail-label">{{ __('product_requests.fields.warehouse_reject_reason') }}</span><span class="product-request-detail-value">{{ $warehouseRejectReason }}</span></div>
                <div class="product-request-detail-item"><span class="product-request-detail-label">{{ __('product_requests.fields.warehouse_operator') }}</span><span class="product-request-detail-value">{{ $productRequest->warehouse_operator_display_name ?: $notFilled }}</span></div>
                <div class="product-request-detail-item product-request-detail-item-wide"><span class="product-request-detail-label">{{ __('product_requests.fields.warehouse_remark') }}</span>@if($productRequest->warehouse_remark)<div class="product-request-remark-html">{!! $productRequest->warehouse_remark !!}</div>@else<span class="product-request-detail-value">{{ $notFilled }}</span>@endif</div>
            </div>
            <div class="product-request-detail-media">
                <span class="product-request-detail-label">{{ __('product_requests.fields.warehouse_images') }}</span>
                <div class="product-request-image-grid">
                    @forelse($warehouseImages as $attachment)
                        <a href="{{ route('product-requests.attachments.show', [$productRequest, $attachment]) }}" data-product-request-image-preview data-product-request-image-alt="{{ $attachment->original_name }}">
                            <img src="{{ route('product-requests.attachments.show', [$productRequest, $attachment]) }}" alt="{{ $attachment->original_name }}">
                        </a>
                    @empty
                        <span class="text-muted">{{ $notFilled }}</span>
                    @endforelse
                </div>
            </div>
        </div>
    </article>

    <article class="product-request-box product-request-detail-card">
        <div class="box-header"><h2 class="box-title">{{ __('product_requests.show.operation_history') }}</h2></div>
        <div class="box-body">
            <ul class="product-request-history">
                @forelse($productRequest->logs as $log)
                    <li><strong>{{ __('product_requests.events.'.$log->event) }}</strong><div>{{ $log->actor_department_name ? $log->actor_department_name.' - ' : '' }}{{ $log->actor_name }} · {{ optional($log->created_at)->timezone('Asia/Shanghai')->format('Y-m-d H:i') }}</div></li>
                @empty
                    <li>{{ __('product_requests.show.no_history') }}</li>
                @endforelse
            </ul>
        </div>
    </article>
</section>
@endsection
