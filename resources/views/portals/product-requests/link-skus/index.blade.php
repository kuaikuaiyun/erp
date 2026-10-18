@extends('portal.layout')

@section('page_title', __('product_requests.link_skus.title'))

@php
    $portalOrigin = rtrim(request()->getSchemeAndHttpHost(), '/');
    $t = static fn (string $key, array $replace = []): string => __('product_requests.'.$key, $replace);
    $clientConfig = [
        'csrfToken' => csrf_token(), 'noticeTitle' => $t('link_skus.title'), 'loading' => $t('common.loading'),
        'loadFailed' => $t('common.modal_load_failed'), 'submitFailed' => $t('common.modal_submit_failed'),
        'invalidFile' => $t('uploads.invalid_file'), 'tooMany' => $t('uploads.too_many', ['count' => ':count']),
        'removeImage' => $t('uploads.remove_image'), 'reuse' => $t('common.reuse'), 'reuseEmpty' => $t('procurement.reuse_empty'),
        'reuseSelected' => $t('procurement.reuse_selected', ['report_no' => ':report_no']),
        'reuseRequired' => $t('procurement.reuse_check_hint'), 'reuseFailed' => $t('common.modal_load_failed'),
        'pasteHint' => $t('uploads.paste_hint'), 'submitProgress' => $t('common.submit_progress'),
        'imageUploading' => $t('editor.uploading'), 'imageUploadFailed' => $t('editor.upload_failed'),
    ];
@endphp
@push('styles')<link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/product-requests.css?v=20261018-3">@endpush
@push('scripts')<script>window.ErpProductRequestConfig = @json($clientConfig);</script><script src="{{ $portalOrigin }}/assets/erp/product-requests.js?v=20261018-3"></script>@endpush

@section('content')
<section class="content product-request-page">
    <header class="content-header"><h1>{{ $t('link_skus.title') }}</h1><p>{{ $t('link_skus.subtitle') }}</p></header>
    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    <div class="box box-solid product-request-filter-box"><div class="box-header with-border"><h3 class="box-title"><i class="fa fa-filter"></i> {{ $t('filters.keyword') }}</h3></div><div class="box-body"><form class="product-request-filter-form" method="get" action="{{ route('product-requests.link-skus.index') }}" data-loading="region" data-loading-target=".product-request-table-box"><div class="product-request-filter-grid"><div class="product-request-filter-field"><label>{{ $t('filters.keyword') }}</label><input class="form-control" name="keyword" value="{{ $filters['keyword'] }}" placeholder="{{ $t('link_skus.keyword_placeholder') }}"></div></div><div class="product-request-filter-actions"><button class="btn btn-primary" type="submit"><i class="fa fa-search"></i> {{ $t('common.search') }}</button><a class="btn btn-default" href="{{ route('product-requests.link-skus.index') }}">{{ $t('common.reset') }}</a></div></form></div></div>
    <div class="box box-solid product-request-table-box"><div class="box-header with-border product-request-list-toolbar"><h3 class="box-title">{{ $t('link_skus.title') }}</h3></div><div class="box-body table-responsive no-padding product-request-table-scroll"><table class="table table-hover product-request-table product-request-link-sku-table"><thead><tr><th class="product-request-sticky-left product-request-image-cell">{{ $t('table.product') }}</th><th>{{ $t('fields.pcl_product_no') }}</th><th class="product-request-sku-cell">{{ $t('table.skus') }}</th><th>{{ $t('table.supplier_url') }}</th><th>{{ $t('table.updated_at') }}</th><th class="product-request-sticky-right">{{ $t('common.operation') }}</th></tr></thead><tbody>
        @forelse($links as $link)
            @php($coverData = $coverMap[$link->supplier_url_hash] ?? null)
            <tr><td class="product-request-sticky-left product-request-image-cell">@if($coverData)<img class="product-request-thumbnail" src="{{ route('product-requests.attachments.show', $coverData) }}" alt="{{ $coverData[1]->original_name }}" data-product-request-image-preview data-product-request-image-alt="{{ $coverData[1]->original_name }}">@else{{ $t('common.not_available') }}@endif</td><td>{{ $pclMap[$link->supplier_url_hash] ?? $t('common.not_filled') }}</td><td class="product-request-sku-cell">@include('portals.product-requests.partials.sku-tags', ['skus' => $link->skus, 'empty' => $t('common.not_available')])</td><td class="product-request-link-wrap">@if($link->supplier_url)<a href="{{ $link->supplier_url }}" target="_blank" rel="noopener" title="{{ $link->supplier_url }}">{{ $link->supplier_url }}</a>@else{{ $t('common.not_filled') }}@endif</td><td>{{ optional($link->updated_at)->timezone('Asia/Shanghai')->format('Y-m-d H:i') }}</td><td class="product-request-sticky-right product-request-actions"><button type="button" class="btn btn-primary btn-xs product-request-action-button product-request-action-primary" data-product-request-modal-url="{{ route('product-requests.link-skus.edit', $link) }}"><i class="fa fa-edit"></i> {{ $t('common.edit') }}</button></td></tr>
        @empty
            <tr><td colspan="6" class="product-request-empty">{{ $t('common.no_records') }}</td></tr>
        @endforelse
    </tbody></table></div>@if($links->hasPages())<div class="box-footer clearfix product-request-pagination" data-loading="region" data-loading-target=".product-request-table-box">{{ $links->links() }}</div>@endif</div>
    @include('portals.product-requests.partials.modal')
</section>
@endsection
