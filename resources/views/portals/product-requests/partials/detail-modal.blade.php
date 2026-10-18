@php
    $operationImages = $productRequest->attachmentsFor(\App\Models\ProductRequestAttachment::STAGE_OPERATION);
    $purchaseItemImages = $productRequest->attachmentsFor(\App\Models\ProductRequestAttachment::STAGE_PURCHASE_ITEM);
    $procurementImages = $productRequest->attachmentsFor(\App\Models\ProductRequestAttachment::STAGE_PROCUREMENT);
    $warehouseImages = $productRequest->attachmentsFor(\App\Models\ProductRequestAttachment::STAGE_WAREHOUSE);
    $procurementSkuValues = is_array($productRequest->procurement_sku_values) ? $productRequest->procurement_sku_values : [];
    $notFilled = __('product_requests.common.not_filled');
    $notAvailable = __('product_requests.common.not_available');
    $referenceLink = trim((string) $productRequest->reference_page_url);
    $placementLink = trim((string) $productRequest->placement_link);
    $isWebLink = static fn (?string $value): bool => str_starts_with(strtolower(trim((string) $value)), 'http://') || str_starts_with(strtolower(trim((string) $value)), 'https://');
    $warehouseResult = $productRequest->warehouse_acceptance_result
        ? ($warehouseResultLabels[$productRequest->warehouse_acceptance_result] ?? $notAvailable)
        : $notFilled;
    $warehouseRejectReason = $productRequest->warehouse_reject_reason
        ? ($warehouseRejectReasonLabels[$productRequest->warehouse_reject_reason] ?? $notAvailable)
        : $notFilled;
@endphp

<section class="product-request-detail-modal">
    <header class="modal-header product-request-detail-modal-header">
        <button type="button" class="close" data-dismiss="modal" aria-label="{{ __('product_requests.common.close') }}"><span aria-hidden="true">&times;</span></button>
        <div class="product-request-detail-modal-heading">
            <h4 class="modal-title"><i class="fa fa-file-text-o"></i> {{ __('product_requests.show.title') }}</h4>
            <p>{{ $productRequest->report_no }}</p>
        </div>
        <div class="product-request-detail-modal-status">
            <span class="product-request-badge status-{{ $productRequest->status }}">{{ $statusLabels[$productRequest->status] ?? $notAvailable }}</span>
            <span class="product-request-badge review-{{ $productRequest->placement_review_status }}">{{ $reviewStatusLabels[$productRequest->placement_review_status] ?? $notAvailable }}</span>
        </div>
    </header>

    <div class="modal-body product-request-detail-modal-body">
        <section class="product-request-detail-section">
            <header class="product-request-detail-section-header"><h5>{{ __('product_requests.show.overview') }}</h5></header>
            <div class="product-request-detail-section-body product-request-detail-modal-overview">
                <div class="product-request-detail-modal-image-panel">
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
                <div class="product-request-detail-modal-grid">
                    <div class="product-request-detail-item"><span class="product-request-detail-label">{{ __('product_requests.fields.report_no') }}</span><span class="product-request-detail-value">{{ $productRequest->report_no }}</span></div>
                    <div class="product-request-detail-item"><span class="product-request-detail-label">{{ __('product_requests.fields.reported_at') }}</span><span class="product-request-detail-value">{{ optional($productRequest->reported_at)->format('Y-m-d') }}</span></div>
                    <div class="product-request-detail-item"><span class="product-request-detail-label">{{ __('product_requests.fields.main_status') }}</span><span class="product-request-detail-value">{{ $statusLabels[$productRequest->status] ?? $notAvailable }}</span></div>
                    <div class="product-request-detail-item"><span class="product-request-detail-label">{{ __('product_requests.fields.skus') }}</span><span class="product-request-detail-value">@include('portals.product-requests.partials.sku-tags', ['skus' => $productRequest->skus, 'compareSkus' => $productRequest->procurement_skus, 'empty' => $notAvailable])</span></div>
                    <div class="product-request-detail-item"><span class="product-request-detail-label">{{ __('product_requests.fields.has_stock') }}</span><span class="product-request-detail-value">{{ $productRequest->has_stock ? __('product_requests.common.'.($productRequest->has_stock === 'yes' ? 'yes' : 'no')) : $notAvailable }}</span></div>
                    <div class="product-request-detail-item"><span class="product-request-detail-label">{{ __('product_requests.fields.request_source') }}</span><span class="product-request-detail-value">{{ $productRequest->operation_display_name }}</span></div>
                    <div class="product-request-detail-item product-request-detail-item-span-2"><span class="product-request-detail-label">{{ __('product_requests.fields.operation_supplier_url') }}</span><span class="product-request-detail-value">@if($productRequest->operation_supplier_url)<a href="{{ $productRequest->operation_supplier_url }}" target="_blank" rel="noopener">{{ $productRequest->operation_supplier_url }}</a>@else{{ $notFilled }}@endif</span></div>
                    <div class="product-request-detail-item product-request-detail-item-span-2"><span class="product-request-detail-label">{{ __('product_requests.fields.operation_remark') }}</span><span class="product-request-detail-value">{{ $productRequest->operation_remark ?: $notFilled }}</span></div>
                </div>
                <div class="product-request-detail-modal-image-panel product-request-detail-gallery-wide">
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
        </section>

        <section class="product-request-detail-section">
            <header class="product-request-detail-section-header"><h5>{{ __('product_requests.show.procurement_information') }}</h5></header>
            <div class="product-request-detail-section-body">
                <div class="product-request-detail-modal-grid">
                    <div class="product-request-detail-item"><span class="product-request-detail-label">{{ __('product_requests.fields.standard_chinese_name') }}</span><span class="product-request-detail-value">{{ $productRequest->standard_chinese_name ?: $notFilled }}</span></div>
                    <div class="product-request-detail-item"><span class="product-request-detail-label">{{ __('product_requests.fields.pcl_product_no') }}</span><span class="product-request-detail-value">{{ $productRequest->pcl_product_no ?: $notFilled }}</span></div>
                    <div class="product-request-detail-item product-request-detail-item-span-2"><span class="product-request-detail-label">{{ __('product_requests.fields.supplier_url') }}</span><span class="product-request-detail-value">@if($productRequest->supplier_url)<a href="{{ $productRequest->supplier_url }}" target="_blank" rel="noopener">{{ $productRequest->supplier_url }}</a>@else{{ $notFilled }}@endif</span></div>
                    <div class="product-request-detail-item product-request-detail-item-span-2"><span class="product-request-detail-label">{{ __('product_requests.fields.procurement_skus') }}</span><span class="product-request-detail-value"><span class="product-request-sku-tags">@forelse(collect(explode(',', (string) $productRequest->procurement_skus))->map(fn ($s) => trim($s))->filter() as $sku)@include('portals.product-requests.partials.sku-value-tag', ['sku' => $sku, 'values' => $procurementSkuValues[$sku] ?? []])@empty{{ $notFilled }}@endforelse</span></span></div>
                    <div class="product-request-detail-item"><span class="product-request-detail-label">{{ __('product_requests.fields.erp_operator') }}</span><span class="product-request-detail-value">{{ $productRequest->erp_operator_display_name ?: $notFilled }}</span></div>
                    @if(trim((string) $productRequest->inspection_reject_reason) !== '')
                    <div class="product-request-detail-item product-request-detail-item-span-2"><span class="product-request-detail-label">{{ __('product_requests.fields.inspection_reject_reason') }}</span><span class="product-request-detail-value product-request-remark-text">{{ $productRequest->inspection_reject_reason }}</span></div>
                    @endif
                </div>
                <div class="product-request-detail-modal-media">
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
        </section>

        <section class="product-request-detail-section">
            <header class="product-request-detail-section-header"><h5>{{ __('product_requests.show.placement_information') }}</h5></header>
            <div class="product-request-detail-section-body">
                <div class="product-request-detail-modal-grid product-request-detail-modal-placement-grid">
                    <div class="product-request-detail-item product-request-detail-item-span-2"><span class="product-request-detail-label">{{ __('product_requests.fields.placement_link') }}</span><span class="product-request-detail-value">@if($isWebLink($placementLink))<a href="{{ $placementLink }}" target="_blank" rel="noopener">{{ $placementLink }}</a>@elseif($placementLink !== ''){{ $placementLink }}@else{{ $notFilled }}@endif</span></div>
                    <div class="product-request-detail-item"><span class="product-request-detail-label">{{ __('product_requests.table.review_status') }}</span><span class="product-request-detail-value">{{ $reviewStatusLabels[$productRequest->placement_review_status] ?? $notAvailable }}</span></div>
                </div>
                <h6 class="product-request-detail-subtitle">{{ __('product_requests.review.history') }}</h6>
                <div class="product-request-detail-review-list">
                    @forelse($productRequest->placementReviews as $review)
                        <article class="product-request-detail-review-card">
                            <div class="product-request-detail-modal-grid product-request-detail-review-grid">
                                <div class="product-request-detail-item"><span class="product-request-detail-label">{{ __('product_requests.table.review_status') }}</span><span class="product-request-detail-value">{{ __('product_requests.review_status.'.$review->status) }}</span></div>
                                <div class="product-request-detail-item"><span class="product-request-detail-label">{{ __('product_requests.review.submitted_by') }}</span><span class="product-request-detail-value">{{ $review->submission_user_name }}</span></div>
                                <div class="product-request-detail-item"><span class="product-request-detail-label">{{ __('product_requests.review.submitted_at') }}</span><span class="product-request-detail-value">{{ optional($review->submitted_at)->timezone('Asia/Shanghai')->format('Y-m-d H:i') }}</span></div>
                                <div class="product-request-detail-item"><span class="product-request-detail-label">{{ __('product_requests.review.reviewed_by') }}</span><span class="product-request-detail-value">{{ $review->reviewer_name ?: $notFilled }}</span></div>
                                <div class="product-request-detail-item"><span class="product-request-detail-label">{{ __('product_requests.review.reviewed_at') }}</span><span class="product-request-detail-value">{{ optional($review->reviewed_at)->timezone('Asia/Shanghai')->format('Y-m-d H:i') }}</span></div>
                            </div>
                            <div class="product-request-review-remark"><span class="product-request-detail-label">{{ __('product_requests.fields.review_remark') }}</span><div class="product-request-remark-html">@if($review->remark){!! $review->remark !!}@else{{ $notFilled }}@endif</div></div>
                        </article>
                    @empty
                        <p class="text-muted">{{ __('product_requests.common.no_records') }}</p>
                    @endforelse
                </div>
            </div>
        </section>

        <section class="product-request-detail-section">
            <header class="product-request-detail-section-header"><h5>{{ __('product_requests.show.warehouse_information') }}</h5></header>
            <div class="product-request-detail-section-body">
                <div class="product-request-detail-modal-grid">
                    <div class="product-request-detail-item"><span class="product-request-detail-label">{{ __('product_requests.fields.actual_shipping_weight_g') }}</span><span class="product-request-detail-value">@if(is_array($productRequest->warehouse_sku_weights) && $productRequest->warehouse_sku_weights !== [])<span class="product-request-sku-tags">@foreach($productRequest->warehouse_sku_weights as $sku => $weight)@include('portals.product-requests.partials.sku-weight-tag', ['sku' => $sku, 'weight' => $weight])@endforeach</span>@else{{ $productRequest->actual_shipping_weight_g ?: $notFilled }}@endif</span></div>
                    <div class="product-request-detail-item"><span class="product-request-detail-label">{{ __('product_requests.fields.package_size_cm') }}</span><span class="product-request-detail-value">{{ collect([$productRequest->package_length_cm, $productRequest->package_width_cm, $productRequest->package_height_cm])->filter()->implode(' × ') ?: $notFilled }}</span></div>
                    <div class="product-request-detail-item"><span class="product-request-detail-label">{{ __('product_requests.fields.warehouse_acceptance_result') }}</span><span class="product-request-detail-value">{{ $warehouseResult }}</span></div>
                    <div class="product-request-detail-item"><span class="product-request-detail-label">{{ __('product_requests.fields.warehouse_reject_reason') }}</span><span class="product-request-detail-value">{{ $warehouseRejectReason }}</span></div>
                    <div class="product-request-detail-item"><span class="product-request-detail-label">{{ __('product_requests.fields.warehouse_operator') }}</span><span class="product-request-detail-value">{{ $productRequest->warehouse_operator_display_name ?: $notFilled }}</span></div>
                    <div class="product-request-detail-item product-request-detail-item-span-2"><span class="product-request-detail-label">{{ __('product_requests.fields.warehouse_remark') }}</span>@if($productRequest->warehouse_remark)<div class="product-request-remark-html">{!! $productRequest->warehouse_remark !!}</div>@else<span class="product-request-detail-value">{{ $notFilled }}</span>@endif</div>
                </div>
                <div class="product-request-detail-modal-media">
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
        </section>

        <section class="product-request-detail-section">
            <header class="product-request-detail-section-header"><h5>{{ __('product_requests.show.operation_history') }}</h5></header>
            <div class="product-request-detail-section-body">
                <ul class="product-request-history">
                    @forelse($productRequest->logs as $log)
                        <li><strong>{{ __('product_requests.events.'.$log->event) }}</strong><div>{{ $log->actor_department_name ? $log->actor_department_name.' - ' : '' }}{{ $log->actor_name }} · {{ optional($log->created_at)->timezone('Asia/Shanghai')->format('Y-m-d H:i') }}</div></li>
                    @empty
                        <li>{{ __('product_requests.show.no_history') }}</li>
                    @endforelse
                </ul>
            </div>
        </section>
    </div>

    <footer class="modal-footer product-request-detail-modal-footer">
        <button class="btn btn-default product-request-modal-cancel" type="button" data-dismiss="modal"><i class="fa fa-times"></i> {{ __('product_requests.common.close') }}</button>
    </footer>
</section>
