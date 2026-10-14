@extends('portal.layout')

@section('page_title', __('product_requests.operation.title'))

@php
    $portalOrigin = rtrim(request()->getSchemeAndHttpHost(), '/');
    $t = static fn (string $key, array $replace = []): string => __('product_requests.'.$key, $replace);
    $coverFor = static fn ($item) => $item->attachmentsFor(\App\Models\ProductRequestAttachment::STAGE_OPERATION)->first();
    $clientConfig = [
        'csrfToken' => csrf_token(), 'noticeTitle' => $t('operation.title'), 'loading' => $t('common.loading'),
        'loadFailed' => $t('common.modal_load_failed'), 'submitFailed' => $t('common.modal_submit_failed'),
        'invalidFile' => $t('uploads.invalid_file'), 'tooMany' => $t('uploads.too_many', ['count' => ':count']),
        'removeImage' => $t('uploads.remove_image'), 'reuse' => $t('common.reuse'), 'reuseEmpty' => $t('procurement.reuse_empty'),
        'reuseSelected' => $t('procurement.reuse_selected', ['report_no' => ':report_no']),
        'reuseRequired' => $t('procurement.reuse_check_hint'), 'reuseFailed' => $t('common.modal_load_failed'),
    ];
@endphp

@push('styles')
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/product-requests.css?v=20261014-1">
@endpush
@push('scripts')
    <script>window.ErpProductRequestConfig = @json($clientConfig);</script>
    <script src="{{ $portalOrigin }}/assets/erp/product-requests.js?v=20261013-1"></script>
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
            <div class="product-request-filter-field"><label>{{ $t('filters.per_page') }}</label><select class="form-control" name="per_page">@foreach([20, 50, 100] as $perPage)<option value="{{ $perPage }}" @selected($filters['per_page'] === $perPage)>{{ $perPage }}</option>@endforeach</select></div>
        </div><div class="product-request-filter-actions"><button class="btn btn-primary" type="submit"><i class="fa fa-search"></i> {{ $t('common.search') }}</button><a class="btn btn-default" href="{{ route('product-requests.operation.index') }}">{{ $t('common.reset') }}</a></div></form></div>
    </div>
    <div class="box box-solid product-request-table-box">
        <div class="box-header with-border product-request-list-toolbar"><h3 class="box-title">{{ $t('operation.title') }}</h3><span class="text-muted">{{ $t('operation.my_scope_hint') }}</span><span class="product-request-toolbar-spacer"></span><button type="button" class="btn btn-success btn-sm" data-product-request-modal-url="{{ route('product-requests.operation.create', ['modal' => 1]) }}"><i class="fa fa-plus"></i> {{ $t('common.new_request') }}</button></div>
        <div class="box-body table-responsive no-padding product-request-table-scroll"><table class="table table-hover product-request-table product-request-operation-table"><thead><tr><th class="product-request-sticky-left">{{ $t('table.report_no') }}</th><th>{{ $t('table.reported_at') }}</th><th>{{ $t('table.skus') }}</th><th>{{ $t('table.operation_supplier_url') }}</th><th>{{ $t('table.reporter') }}</th><th>{{ $t('table.product') }}</th><th>{{ $t('table.status') }}</th><th>{{ $t('table.review_status') }}</th><th>{{ $t('table.warehouse_status') }}</th><th>{{ $t('table.updated_at') }}</th><th class="product-request-sticky-right">{{ $t('common.operation') }}</th></tr></thead><tbody>
            @forelse($requests as $productRequest)
                @php($cover = $coverFor($productRequest))
                <tr><td class="product-request-sticky-left">{{ $productRequest->report_no }}</td><td>{{ optional($productRequest->reported_at)->format('Y-m-d') }}</td><td>@include('portals.product-requests.partials.sku-tags', ['skus' => $productRequest->skus, 'empty' => $t('common.not_available')])</td><td class="product-request-ellipsis">@if($productRequest->operation_supplier_url)<a href="{{ $productRequest->operation_supplier_url }}" target="_blank" rel="noopener" title="{{ $productRequest->operation_supplier_url }}">{{ $productRequest->operation_supplier_url }}</a>@else{{ $t('common.not_filled') }}@endif</td><td>{{ $productRequest->operation_user_name ?: $t('common.not_available') }}</td><td>@if($cover)<img class="product-request-thumbnail" src="{{ route('product-requests.attachments.show', [$productRequest, $cover]) }}" alt="{{ $cover->original_name }}" data-product-request-image-preview data-product-request-image-alt="{{ $cover->original_name }}">@else{{ $t('common.not_available') }}@endif</td><td><span class="product-request-badge status-{{ $productRequest->status }}">{{ $statusLabels[$productRequest->status] ?? $t('common.not_available') }}</span></td><td><span class="product-request-badge review-{{ $productRequest->placement_review_status }}">{{ $reviewStatusLabels[$productRequest->placement_review_status] ?? $t('common.not_available') }}</span></td><td><span class="product-request-badge warehouse-{{ $productRequest->warehouse_acceptance_result }}">{{ $productRequest->warehouse_confirmation ? $t('warehouse_confirmation.'.$productRequest->warehouse_confirmation) : $t('warehouse_result.'.$productRequest->warehouse_acceptance_result) }}</span></td><td>{{ optional($productRequest->updated_at)->format('Y-m-d H:i') }}</td><td class="product-request-sticky-right product-request-actions"><a class="btn btn-default btn-xs product-request-action-button product-request-action-view" href="{{ route('product-requests.show', $productRequest) }}" data-product-request-modal-url="{{ route('product-requests.show', ['productRequest' => $productRequest, 'modal' => 1]) }}"><i class="fa fa-eye"></i> {{ $t('common.view') }}</a>@if((int) $productRequest->operation_user_id === (int) $user->getKey() && $productRequest->status === \App\Models\ProductRequest::STATUS_INSPECTION_PASSED && $productRequest->placement_review_status !== \App\Models\ProductRequest::REVIEW_PENDING)<button type="button" class="btn btn-primary btn-xs product-request-action-button product-request-action-primary" data-product-request-modal-url="{{ route('product-requests.placement.edit', ['productRequest' => $productRequest, 'modal' => 1]) }}"><i class="fa fa-external-link"></i> {{ $t('operation.placement_title') }}</button>@endif</td></tr>
            @empty
                <tr><td colspan="11" class="product-request-empty">{{ $t('common.no_records') }}</td></tr>
            @endforelse
        </tbody></table></div>
        @if($requests->hasPages())<div class="box-footer clearfix product-request-pagination" data-loading="region" data-loading-target=".product-request-table-box">{{ $requests->links() }}</div>@endif
    </div>
    @include('portals.product-requests.partials.modal')
</section>
@endsection
