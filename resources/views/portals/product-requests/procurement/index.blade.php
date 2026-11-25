@extends('portal.layout')

@section('page_title', __('product_requests.procurement.title'))

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
        'csrfToken' => csrf_token(), 'noticeTitle' => $t('procurement.title'), 'loading' => $t('common.loading'),
        'loadFailed' => $t('common.modal_load_failed'), 'submitFailed' => $t('common.modal_submit_failed'),
        'invalidFile' => $t('uploads.invalid_file'), 'tooMany' => $t('uploads.too_many', ['count' => ':count']),
        'removeImage' => $t('uploads.remove_image'), 'reuse' => $t('common.reuse'), 'reuseEmpty' => $t('procurement.dedupe_empty'),
        'reuseSelected' => $t('procurement.reuse_selected', ['report_no' => ':report_no']),
        'reuseRequired' => $t('procurement.supplier_url_required_hint'), 'reuseFailed' => $t('common.modal_load_failed'),
        'dedupeEmpty' => $t('procurement.dedupe_empty'), 'dedupeDetail' => $t('procurement.dedupe_detail'),
        'dedupeSkus' => $t('procurement.dedupe_skus'),
        'dedupePrice' => $t('procurement.dedupe_price'), 'dedupeWeight' => $t('procurement.dedupe_weight'),
        'dedupePackage' => $t('procurement.dedupe_package'), 'dedupeLargeVolume' => $t('fields.large_volume_weight_g'),
        'dedupeSellerImages' => $t('procurement.dedupe_seller_images'),
        'dedupeNoImages' => $t('procurement.dedupe_no_images'),
        'dedupeNameRequired' => $t('procurement.dedupe_name_required'),
        'pasteHint' => $t('uploads.paste_hint'), 'submitProgress' => $t('common.submit_progress'),
        'assignNoneSelected' => $t('procurement.assign_none_selected'),
        'assigneeTodayHint' => $t('procurement.assignee_today_hint', ['name' => ':name', 'count' => ':count']),
    ];
@endphp
@push('styles')
<link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/product-requests.css?v=20261125-03">
<link rel="stylesheet" href="{{ $portalOrigin }}/css/frontend-list-template.css?v=20261125-1">
<link rel="stylesheet" href="{{ $portalOrigin }}/css/product-request-procurement-template.css?v=20261125-3">
@endpush
@push('scripts')
<script>window.ErpProductRequestConfig = @json($clientConfig);</script>
<script src="{{ $portalOrigin }}/assets/erp/product-requests.js?v=20261125-01"></script>
<script src="{{ $portalOrigin }}/js/assistant-filter-multiselect.js?v=20261124-2"></script>
@endpush

@section('content')
<section class="content flt product-request-page product-request-procurement-template">
    <header class="content-header"><h1>{{ $t('procurement.title') }}</h1><p>{{ $t('procurement.subtitle') }}</p></header>
    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    <div class="box box-solid product-request-filter-box"><div class="box-header with-border"><h3 class="box-title"><i class="fa fa-filter"></i> {{ $t('filters.keyword') }}</h3></div><div class="box-body"><form class="product-request-filter-form" method="get" action="{{ route('product-requests.procurement.index') }}" data-loading="region" data-loading-target=".product-request-table-box"><input type="hidden" name="per_page" value="{{ $filters['per_page'] }}"><div class="product-request-filter-grid"><div class="product-request-filter-field"><label>{{ $t('filters.keyword') }}</label><input class="form-control" name="keyword" value="{{ $filters['keyword'] }}" placeholder="{{ $t('filters.keyword_placeholder') }}"></div><div class="product-request-filter-field"><label>{{ $t('table.placement_link') }}</label><input class="form-control" name="placement_link" value="{{ $filters['placement_link'] ?? '' }}" placeholder="请输入投放链接"></div><div class="product-request-filter-field form-group"><label>投放链接状态</label><select class="form-control" name="placement_link_status" data-filter-multiselect data-placeholder="全部" data-search-placeholder="搜索投放链接状态"><option value="">全部</option><option value="filled" @selected(($filters['placement_link_status'] ?? '') === 'filled')>已填写</option><option value="empty" @selected(($filters['placement_link_status'] ?? '') === 'empty')>未填写</option></select></div><div class="product-request-filter-field form-group"><label>{{ $t('filters.status') }}</label><select class="form-control" name="status" data-filter-multiselect data-placeholder="{{ $t('common.all') }}" data-search-placeholder="搜索状态"><option value="">{{ $t('common.all') }}</option>@foreach($statusLabels as $value => $label)<option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>@endforeach</select></div><div class="product-request-filter-field form-group"><label>{{ $t('table.assign_status') }}</label><select class="form-control" name="assign_status" data-filter-multiselect data-placeholder="{{ $t('common.all') }}" data-search-placeholder="搜索分配状态"><option value="">{{ $t('common.all') }}</option><option value="unassigned" @selected(($filters['assign_status'] ?? '') === 'unassigned')>{{ $t('procurement.assign_unassigned') }}</option><option value="assigned" @selected(($filters['assign_status'] ?? '') === 'assigned')>{{ $t('procurement.assign_assigned') }}</option></select></div></div><div class="product-request-filter-actions"><button class="btn btn-primary" type="submit"><i class="fa fa-search"></i> {{ $t('common.search') }}</button><a class="btn btn-default" href="{{ route('product-requests.procurement.index') }}">{{ $t('common.reset') }}</a></div></form></div></div>
    <div class="box box-solid product-request-table-box"><div class="box-header with-border product-request-list-toolbar"><h3 class="box-title">{{ $t('procurement.title') }}</h3>@if($canAssign)<button type="button" class="btn btn-primary btn-sm product-request-assign-button" data-product-request-assign-url="{{ route('product-requests.procurement.assign.form') }}"><i class="fa fa-users"></i> {{ $t('procurement.assign_button') }}</button>@endif</div><div class="box-body table-responsive no-padding product-request-table-scroll"><table class="table table-hover product-request-table product-request-procurement-table"><thead><tr><th class="product-request-sticky-left">{{ $t('table.report_no') }}</th>@if($canAssign)<th class="product-request-checkbox-col"><input type="checkbox" data-product-request-assign-check-all aria-label="{{ $t('procurement.assign_check_all') }}"></th>@endif<th>{{ $t('table.product') }}</th><th>{{ $t('table.reporter') }}</th><th>{{ $t('table.standard_chinese_name') }}</th><th>{{ $t('fields.pcl_product_no') }}</th><th>{{ $t('table.supplier_url') }}</th><th>{{ $t('table.placement_link') }}</th><th>{{ $t('table.assign_status') }}</th><th>{{ $t('table.operator') }}</th><th>{{ $t('table.staff_status') }}</th><th>{{ $t('table.updated_at') }}</th><th class="product-request-sticky-right">{{ $t('common.operation') }}</th></tr></thead><tbody>
        @forelse($requests as $productRequest)
            @php
                $cover = $coverFor($productRequest);
            @endphp
            <tr><td class="product-request-sticky-left">{{ $productRequest->report_no }}</td>@if($canAssign)<td class="product-request-checkbox-col">@if($productRequest->status === \App\Models\ProductRequest::STATUS_PENDING)<input type="checkbox" name="assign_ids[]" value="{{ $productRequest->getKey() }}" data-product-request-assign-checkbox aria-label="{{ $t('procurement.assign_check_all') }}">@endif</td>@endif<td>@if($cover)<img class="product-request-thumbnail" loading="lazy" decoding="async" src="{{ route('product-requests.attachments.show', [$productRequest, $cover]) }}?w=240" alt="{{ $cover->original_name }}" data-product-request-image-preview data-product-request-image-alt="{{ $cover->original_name }}">@else{{ $t('common.not_available') }}@endif</td><td>{{ $productRequest->operation_user_name ?: $t('common.not_available') }}</td><td>{{ $productRequest->standard_chinese_name ?: $t('common.not_filled') }}</td><td>{{ $productRequest->pcl_product_no ?: $t('common.not_filled') }}</td><td class="product-request-ellipsis" title="{{ $productRequest->supplier_url }}">@if($productRequest->supplier_url)<a href="{{ $productRequest->supplier_url }}" target="_blank" rel="noopener" title="{{ $productRequest->supplier_url }}">{{ $productRequest->supplier_url }}</a>@else{{ $t('common.not_filled') }}@endif</td>
                <td class="product-request-ellipsis" title="{{ $productRequest->placement_link }}">@if($productRequest->placement_link)<a href="{{ $productRequest->placement_link }}" target="_blank" rel="noopener" title="{{ $productRequest->placement_link }}">{{ $productRequest->placement_link }}</a>@else{{ $t('common.not_filled') }}@endif</td>
                <td>@if($productRequest->procurement_assignee_id)<div class="product-request-assignment-state"><span class="flt-status" data-status="active" title="{{ $t('procurement.assign_assigned_at') }}：{{ optional($productRequest->procurement_assigned_at)->timezone('Asia/Shanghai')->format('Y-m-d H:i') }}">{{ $t('procurement.assign_assigned') }}</span>@if(trim((string) $productRequest->procurement_assignee_name) !== '')<small class="product-request-assignee-name">{{ $productRequest->procurement_assignee_name }}</small>@endif</div>@else<span class="flt-status" data-status="pending">{{ $t('procurement.assign_unassigned') }}</span>@endif</td><td class="product-request-operator" title="{{ $t('fields.erp_operator') }}">{{ $productRequest->erp_operator_display_name ?: $t('common.not_filled') }}</td><td>@if(trim((string) $productRequest->inspection_reject_reason) !== '')<span class="flt-status" data-status="rejected" title="{{ $productRequest->inspection_reject_reason }}">{{ $t('status.inspection_rejected') }}</span>@else<span class="flt-status" data-status="{{ $productRequest->status === \App\Models\ProductRequest::STATUS_PENDING ? 'pending' : 'active' }}">{{ $statusLabels[$productRequest->status] ?? $t('common.not_available') }}</span>@endif</td><td>{{ optional($productRequest->updated_at)->timezone('Asia/Shanghai')->format('Y-m-d H:i') }}</td><td class="product-request-sticky-right product-request-actions"><a class="btn btn-default btn-xs product-request-action-button product-request-action-view" href="{{ route('product-requests.show', $productRequest) }}" data-product-request-modal-url="{{ route('product-requests.show', ['productRequest' => $productRequest, 'modal' => 1]) }}"><i class="fa fa-eye"></i> {{ $t('common.view') }}</a>@if($canInspect && in_array($productRequest->status, [\App\Models\ProductRequest::STATUS_PENDING, \App\Models\ProductRequest::STATUS_INSPECTION_PASSED], true) && !($productRequest->status === \App\Models\ProductRequest::STATUS_INSPECTION_PASSED && $productRequest->warehouse_acceptance_result === \App\Models\ProductRequest::WAREHOUSE_PASSED) && trim((string) $productRequest->inspection_reject_reason) === '')<button class="btn btn-primary btn-xs product-request-action-button product-request-action-primary" type="button" data-product-request-modal-url="{{ route('product-requests.procurement.edit', ['productRequest' => $productRequest, 'modal' => 1]) }}"><i class="fa fa-pencil"></i> {{ $t('common.edit') }}</button>@endif</td></tr>
        @empty
            <tr><td colspan="{{ $canAssign ? 13 : 12 }}" class="product-request-empty">{{ $t('common.no_records') }}</td></tr>
        @endforelse
    </tbody></table></div>@include('portals.product-requests.procurement.pagination', ['paginator' => $requests])</div>
    @include('portals.product-requests.partials.modal')
</section>
@endsection
