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
        'deleteConfirm' => $t('link_skus.delete_confirm'), 'deleteFailed' => $t('common.delete_failed'),
    ];
@endphp
@push('styles')<link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/product-requests.css?v=20261018-20">@endpush
@push('scripts')<script>window.ErpProductRequestConfig = @json($clientConfig);</script><script src="{{ $portalOrigin }}/assets/erp/product-requests.js?v=20261018-20"></script>@endpush

@section('content')
<section class="content product-request-page">
    <header class="content-header"><h1>{{ $t('link_skus.title') }}</h1><p>{{ $t('link_skus.subtitle') }}</p></header>
    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif
    @if(isset($errors) && $errors->has('import_file'))<div class="alert alert-danger">{{ $errors->first('import_file') }}</div>@endif
    <div class="box box-solid product-request-filter-box"><div class="box-header with-border"><h3 class="box-title"><i class="fa fa-filter"></i> {{ $t('filters.keyword') }}</h3></div><div class="box-body"><form class="product-request-filter-form" method="get" action="{{ route('product-requests.link-skus.index') }}" data-loading="region" data-loading-target=".product-request-table-box"><div class="product-request-filter-grid"><div class="product-request-filter-field"><label>{{ $t('filters.keyword') }}</label><input class="form-control" name="keyword" value="{{ $filters['keyword'] }}" placeholder="{{ $t('link_skus.keyword_placeholder') }}"></div><div class="product-request-filter-field"><label>{{ $t('link_skus.source') }}</label><select class="form-control" name="source"><option value="">{{ $t('common.all') }}</option>@foreach(['system', 'import'] as $sourceValue)<option value="{{ $sourceValue }}" @selected(($filters['source'] ?? '') === $sourceValue)>{{ $t('link_skus.source_'.$sourceValue) }}</option>@endforeach</select></div><div class="product-request-filter-field"><label>{{ $t('link_skus.review_status') }}</label><select class="form-control" name="review_status"><option value="">{{ $t('common.all') }}</option><option value="pending" @selected(($filters['review_status'] ?? '') === 'pending')>{{ $t('link_skus.review_pending') }}</option><option value="done" @selected(($filters['review_status'] ?? '') === 'done')>{{ $t('link_skus.review_done') }}</option></select></div><div class="product-request-filter-field"><label>{{ $t('filters.per_page') }}</label><select class="form-control" name="per_page">@foreach([20, 50] as $perPage)<option value="{{ $perPage }}" @selected(($filters['per_page'] ?? 20) === $perPage)>{{ $perPage }}</option>@endforeach</select></div></div><div class="product-request-filter-actions"><button class="btn btn-primary" type="submit"><i class="fa fa-search"></i> {{ $t('common.search') }}</button><a class="btn btn-default" href="{{ route('product-requests.link-skus.index') }}">{{ $t('common.reset') }}</a></div></form></div></div>
    <div class="box box-solid product-request-table-box"><div class="box-header with-border product-request-list-toolbar"><h3 class="box-title">{{ $t('link_skus.title') }}</h3><span class="product-request-toolbar-spacer"></span>@if($canImport ?? false)<button type="button" class="btn btn-info btn-sm" data-toggle="modal" data-target="#product-request-import-modal"><i class="fa fa-upload"></i> {{ $t('import.open_button') }}</button>@endif</div><div class="box-body table-responsive no-padding product-request-table-scroll"><table class="table table-hover product-request-table product-request-link-sku-table"><thead><tr><th class="product-request-sticky-left product-request-image-cell">{{ $t('table.product') }}</th><th>{{ $t('link_skus.product_name') }}</th><th>{{ $t('fields.pcl_product_no') }}</th><th class="product-request-sku-cell">{{ $t('table.skus') }}</th><th>{{ $t('link_skus.source') }}</th><th>{{ $t('link_skus.review_status') }}</th><th>{{ $t('table.supplier_url') }}</th><th>{{ $t('table.updated_at') }}</th><th class="product-request-sticky-right">{{ $t('common.operation') }}</th></tr></thead><tbody>
        @forelse($links as $link)
            @php($linkImages = array_values((array) ($link->images ?? [])))
            <tr><td class="product-request-sticky-left product-request-image-cell">@if($link->product_image)<img class="product-request-thumbnail" src="{{ route('product-requests.link-skus.product-image', $link) }}" alt="{{ $t('link_skus.product_image_label') }}" data-product-request-image-preview data-product-request-image-alt="{{ $t('link_skus.product_image_label') }}">@elseif($linkImages !== [])<img class="product-request-thumbnail" src="{{ route('product-requests.link-skus.image', [$link, 0]) }}" alt="{{ $t('link_skus.images_label') }}" data-product-request-image-preview data-product-request-image-alt="{{ $t('link_skus.images_label') }}">@else{{ $t('common.not_available') }}@endif</td><td class="product-request-name-cell" title="{{ $nameMap[$link->supplier_url_hash] ?? '' }}">{{ $nameMap[$link->supplier_url_hash] ?? $t('common.not_filled') }}</td><td>{{ $pclMap[$link->supplier_url_hash] ?? $t('common.not_filled') }}</td><td class="product-request-sku-cell">@include('portals.product-requests.partials.sku-tags', ['skus' => $link->skus, 'empty' => $t('common.not_available')])</td><td><span class="product-request-badge link-source-{{ $link->source ?: 'system' }}">{{ $t('link_skus.source_'.($link->source ?: 'system')) }}</span></td><td>@if($link->review_status === 'pending')<span class="product-request-badge link-review-pending">{{ $t('link_skus.review_pending') }}</span><form class="product-request-inline-form" method="post" action="{{ route('product-requests.link-skus.review', $link) }}">@csrf<button type="submit" class="btn btn-success btn-xs"><i class="fa fa-check"></i> {{ $t('link_skus.review_done_action') }}</button></form>@else<span class="product-request-badge link-review-done">{{ $t('link_skus.review_done') }}</span>@endif</td><td class="product-request-link-wrap">@if($link->supplier_url)<a href="{{ $link->supplier_url }}" target="_blank" rel="noopener" title="{{ $link->supplier_url }}">{{ $link->supplier_url }}</a>@else{{ $t('common.not_filled') }}@endif</td><td>{{ optional($link->updated_at)->timezone('Asia/Shanghai')->format('Y-m-d H:i') }}</td><td class="product-request-sticky-right product-request-actions"><button type="button" class="btn btn-primary btn-xs product-request-action-button product-request-action-primary" data-product-request-modal-url="{{ route('product-requests.link-skus.edit', $link) }}"><i class="fa fa-edit"></i> {{ $t('common.edit') }}</button><button type="button" class="btn btn-danger btn-xs product-request-action-button" data-product-request-delete data-delete-url="{{ route('product-requests.link-skus.destroy', $link) }}" title="{{ $t('link_skus.delete_confirm') }}"><i class="fa fa-trash-o"></i> {{ $t('common.delete') }}</button></td></tr>
        @empty
            <tr><td colspan="9" class="product-request-empty">{{ $t('common.no_records') }}</td></tr>
        @endforelse
    </tbody></table></div>{{ $links->links('portals.product-requests.partials.pagination') }}</div>
    @if($canImport ?? false)
    <div class="modal fade" id="product-request-import-modal" tabindex="-1" role="dialog" aria-labelledby="product-request-import-title">
        <div class="modal-dialog" role="document">
            <form method="post" action="{{ route('product-requests.link-skus.import') }}" enctype="multipart/form-data" class="modal-content">
                @csrf
                <div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="{{ $t('common.close') }}"><span aria-hidden="true">&times;</span></button><h4 class="modal-title" id="product-request-import-title"><i class="fa fa-upload"></i> {{ $t('import.title') }}</h4></div>
                <div class="modal-body">
                    <p class="text-muted">{{ $t('import.notice') }}</p>
                    <div class="product-request-import-header-rule"><i class="fa fa-list-alt"></i><span>{{ $t('import.headers') }}</span></div>
                    <label class="product-request-import-dropzone" for="product-request-import-file" data-file-selected-template="{{ $t('import.file_selected', ['name' => '__name__']) }}">
                        <input id="product-request-import-file" type="file" name="import_file" accept=".xls,.xlsx" required>
                        <i class="fa fa-cloud-upload"></i>
                        <strong>{{ $t('import.dropzone') }}</strong>
                        <span>{{ $t('import.file_hint') }}</span>
                        <em id="product-request-import-file-name"></em>
                    </label>
                    <div class="product-request-import-footer-note">
                        <span><i class="fa fa-info-circle"></i> {{ $t('import.rule_note') }}</span>
                        <a href="{{ route('product-requests.operation.import-template') }}" data-loading="download"><i class="fa fa-file-excel-o"></i> {{ $t('import.template_download') }}</a>
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">{{ $t('common.cancel') }}</button><button type="submit" class="btn btn-success"><i class="fa fa-upload"></i> {{ $t('import.submit') }}</button></div>
            </form>
        </div>
    </div>
    <script>
        (function () {
            var zone = document.querySelector('.product-request-import-dropzone');
            var input = document.getElementById('product-request-import-file');
            var filename = document.getElementById('product-request-import-file-name');
            if (!zone || !input || !filename) return;
            var showFilename = function (file) {
                var template = zone.dataset.fileSelectedTemplate || '__name__';
                filename.textContent = file ? template.replace('__name__', file.name) : '';
                zone.classList.toggle('is-filled', Boolean(file));
            };
            input.addEventListener('change', function () {
                showFilename(this.files && this.files[0]);
            });
            ['dragenter', 'dragover'].forEach(function (eventName) {
                zone.addEventListener(eventName, function (event) {
                    event.preventDefault();
                    event.stopPropagation();
                    zone.classList.add('is-dragover');
                });
            });
            ['dragleave', 'drop'].forEach(function (eventName) {
                zone.addEventListener(eventName, function (event) {
                    event.preventDefault();
                    event.stopPropagation();
                    zone.classList.remove('is-dragover');
                });
            });
            zone.addEventListener('drop', function (event) {
                var file = event.dataTransfer && event.dataTransfer.files && event.dataTransfer.files[0];
                if (file) {
                    input.files = event.dataTransfer.files;
                    showFilename(file);
                }
            });
        })();
    </script>
    @endif
    @include('portals.product-requests.partials.modal')
</section>
@endsection
