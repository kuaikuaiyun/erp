@extends('portal.layout')

@section('page_title', __('aikaipin.operation_library.title'))

@push('styles')
    <link rel="stylesheet" href="{{ rtrim(request()->getSchemeAndHttpHost(), '/') }}/assets/erp/aikaipin-product-library.css?v=20261023-1">
    <style>
        .operation-ai-product-page .aikaipin-operation-column { width:140px; }
        .operation-ai-product-page .aikaipin-operation-cell { text-align:center; }
        .operation-ai-detail-dialog { width:880px; max-width:94vw; }
        .operation-ai-detail-modal .modal-header { padding:18px 22px; border-bottom:1px solid #e8edf3; }
        .operation-ai-detail-modal .modal-title { color:#263445; font-size:18px; font-weight:700; }
        .operation-ai-detail-modal .modal-title .fa { width:34px; height:34px; margin-right:9px; border-radius:9px; background:#eaf4fb; color:#3c8dbc; line-height:34px; text-align:center; }
        .operation-ai-detail-modal .modal-body { padding:20px 22px 24px; background:#f6f8fb; }
        .operation-ai-detail-hero { display:flex; align-items:center; gap:15px; grid-column:1 / -1; padding:17px 18px; border:1px solid #dfe7ef; border-radius:9px; background:#fff; box-shadow:0 2px 8px rgba(31,54,78,.04); }
        .operation-ai-detail-hero-thumb { width:74px; height:74px; flex:0 0 74px; overflow:hidden; border:1px solid #e4eaf0; border-radius:8px; background:#f3f6f9; }
        .operation-ai-detail-hero-thumb img { width:100%; height:100%; object-fit:cover; }
        .operation-ai-detail-hero-thumb .fa { display:block; color:#b6c2cf; font-size:26px; line-height:74px; text-align:center; }
        .operation-ai-detail-hero-main { min-width:0; }
        .operation-ai-detail-hero-main h4 { margin:0 0 7px; color:#243447; font-size:18px; font-weight:700; }
        .operation-ai-detail-code { display:inline-block; padding:3px 8px; border-radius:4px; background:#f0f4f8; color:#53677d; font-family:Consolas,monospace; font-size:12px; }
        .operation-ai-detail-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px; }
        .operation-ai-detail-item { min-width:0; padding:14px 16px; border:1px solid #e2e8ef; border-radius:8px; background:#fff; }
        .operation-ai-detail-item.is-wide { grid-column:1 / -1; }
        .operation-ai-detail-label { display:flex; align-items:center; gap:6px; margin-bottom:8px; color:#8290a0; font-size:12px; font-weight:600; }
        .operation-ai-detail-label:before { content:''; width:3px; height:12px; border-radius:2px; background:#5fa8d3; }
        .operation-ai-detail-value { min-height:20px; color:#263445; line-height:1.65; word-break:break-word; }
        .operation-ai-detail-tag { display:inline-block; margin:0 6px 5px 0; padding:3px 9px; border-radius:12px; background:#edf5fb; color:#347ca8; font-size:12px; }
        .operation-ai-detail-images { display:grid; grid-template-columns:repeat(auto-fill,minmax(108px,1fr)); gap:10px; }
        .operation-ai-detail-image { padding:0; overflow:hidden; border:1px solid #e3e8ee; border-radius:7px; background:#f7f9fb; }
        .operation-ai-detail-image img { display:block; width:100%; height:112px; object-fit:cover; transition:transform .18s ease; }
        .operation-ai-detail-image:hover img { transform:scale(1.04); }
        .operation-ai-detail-link { display:inline-flex; align-items:center; gap:6px; color:#2786bd; }
        .operation-ai-product-page .aikaipin-pagination-control { display:flex; align-items:center; justify-content:space-between; gap:16px; min-height:48px; }
        .operation-ai-product-page .aikaipin-pagination-summary { color:#7c8998; font-size:12px; }
        .operation-ai-product-page .pagination > li > a,
        .operation-ai-product-page .pagination > li > span { min-width:34px; height:32px; margin-left:6px; padding:6px 10px; border:1px solid #dce4ec; border-radius:5px !important; color:#53677d; line-height:18px; text-align:center; }
        .operation-ai-product-page .pagination > li > a:hover { border-color:#86bad9; background:#edf6fc; color:#287eaf; }
        .operation-ai-product-page .pagination > .active > a,
        .operation-ai-product-page .pagination > .active > a:hover { border-color:#3c8dbc; background:#3c8dbc; color:#fff; box-shadow:0 2px 6px rgba(60,141,188,.25); }
        .operation-ai-product-page .pagination > .disabled > span,
        .operation-ai-product-page .pagination > .disabled > a { background:#f7f9fb; color:#b3bec9; }
        @media (max-width:767px) { .operation-ai-detail-grid { grid-template-columns:1fr; } .operation-ai-detail-item.is-wide { grid-column:auto; } }
    </style>
@endpush

@section('content')
<section class="content aikaipin-product-library-page operation-ai-product-page">
    <div class="box box-solid aikaipin-filter-box">
        <div class="box-body">
            <form class="aikaipin-filter-form" method="get" action="{{ route('operation.ai-product-library.index') }}">
                <div class="aikaipin-filter-grid">
                    <div class="aikaipin-filter-field aikaipin-filter-keyword">
                        <label for="operation-ai-product-keyword">{{ __('aikaipin.filters.keyword') }}</label>
                        <input id="operation-ai-product-keyword" class="form-control" type="search" name="keyword" value="{{ $filters['keyword'] }}"
                            placeholder="{{ __('aikaipin.filters.keyword_placeholder') }}" autocomplete="off">
                    </div>
                    <div class="aikaipin-filter-field">
                        <label for="operation-ai-product-category">{{ __('aikaipin.filters.category') }}</label>
                        <select id="operation-ai-product-category" class="form-control" name="category">
                            <option value="">{{ __('aikaipin.filters.all') }}</option>
                            @foreach ($categories as $category)
                                <option value="{{ $category }}" @selected($filters['category'] === $category)>{{ $category }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="aikaipin-filter-field">
                        <label for="operation-ai-product-source">{{ __('aikaipin.filters.source') }}</label>
                        <select id="operation-ai-product-source" class="form-control" name="source">
                            <option value="">{{ __('aikaipin.filters.all') }}</option>
                            @foreach (trans('aikaipin.upload.source_options') as $value => $label)
                                <option value="{{ $value }}" @selected($filters['source'] === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="aikaipin-filter-field">
                        <label for="operation-ai-product-type">{{ __('aikaipin.filters.type') }}</label>
                        <select id="operation-ai-product-type" class="form-control" name="type">
                            <option value="">{{ __('aikaipin.filters.all') }}</option>
                            @foreach (trans('aikaipin.upload.type_options') as $value => $label)
                                <option value="{{ $value }}" @selected($filters['type'] === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="aikaipin-filter-actions">
                        <button class="btn btn-primary" type="submit"><i class="fa fa-search"></i> {{ __('aikaipin.filters.search') }}</button>
                        <a class="btn btn-default" href="{{ route('operation.ai-product-library.index') }}"><i class="fa fa-undo"></i> {{ __('aikaipin.filters.reset') }}</a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="box box-solid aikaipin-table-box">
        <div class="box-header with-border aikaipin-list-toolbar">
            <div class="aikaipin-list-toolbar-actions">
                <div class="aikaipin-list-toolbar-summary">
                    <i class="fa fa-cubes"></i>
                    <span>{{ trans_choice('aikaipin.product_library.record_count', $products->total(), ['count' => $products->total()]) }}</span>
                </div>
            </div>
        </div>
        <div class="box-body no-padding">
            <div class="table-responsive">
                <table class="table table-hover aikaipin-product-table">
                    <thead><tr>
                        <th class="aikaipin-image-column">{{ __('aikaipin.table.image') }}</th>
                        <th class="aikaipin-product-code-column">{{ __('aikaipin.table.product_code') }}</th>
                        <th class="aikaipin-name-column">{{ __('aikaipin.table.product_name_sku') }}</th>
                        <th class="aikaipin-category-column">{{ __('aikaipin.table.category') }}</th>
                        <th class="aikaipin-source-column">{{ __('aikaipin.table.source_type') }}</th>
                        <th class="aikaipin-updated-column">{{ __('aikaipin.table.updated_at') }}</th>
                        <th class="aikaipin-operation-column">{{ __('aikaipin.table.operation') }}</th>
                    </tr></thead>
                    <tbody>
                    @forelse ($products as $product)
                        @php
                            $images = $product->imageFiles();
                            $skus = $product->skuValues();
                        @endphp
                        <tr>
                            <td class="aikaipin-image-cell">
                                <span class="aikaipin-product-image">
                                    @if ($images !== [])
                                        <img src="{{ route('operation.ai-product-library.images.show', ['product' => $product, 'imageIndex' => 0]) }}" alt="{{ $product->name }}" loading="lazy">
                                    @else
                                        <i class="fa fa-picture-o" aria-hidden="true"></i>
                                    @endif
                                </span>
                            </td>
                            <td class="aikaipin-product-code-cell"><span class="aikaipin-product-code">{{ $product->product_code }}</span></td>
                            <td class="aikaipin-name-cell">
                                <strong>{{ $product->name }}</strong>
                                @if ($skus !== [])
                                    <span class="aikaipin-sku-line" title="{{ implode(', ', $skus) }}">
                                        <span>{{ $skus[0] }}</span>
                                        @if (count($skus) > 1)<span class="aikaipin-sku-more">+{{ count($skus) - 1 }}</span>@endif
                                    </span>
                                @endif
                            </td>
                            <td class="aikaipin-category-cell">
                                @foreach ($product->categoryValues() as $category)<span class="label label-default">{{ $category }}</span>@endforeach
                            </td>
                            <td class="aikaipin-source-cell">
                                <span class="aikaipin-source">{{ trans('aikaipin.upload.source_options.'.$product->source_platform) }}</span>
                                <span class="aikaipin-type">{{ trans('aikaipin.upload.type_options.'.$product->product_type) }}</span>
                            </td>
                            <td class="aikaipin-updated-cell"><time datetime="{{ $product->updated_at?->toAtomString() }}">{{ $product->updated_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') }}</time></td>
                            <td class="aikaipin-operation-cell">
                                <button type="button" class="btn btn-default btn-sm" data-operation-ai-detail
                                    data-detail-url="{{ route('operation.ai-product-library.detail', ['product' => $product]) }}">
                                    <i class="fa fa-eye"></i> {{ __('aikaipin.operation_library.detail') }}
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr class="aikaipin-empty-row"><td colspan="7">
                            <div class="aikaipin-empty-state"><i class="fa fa-search"></i><p>{{ __('aikaipin.messages.no_records') }}</p></div>
                        </td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if ($products->total() > 0)
            <div class="box-footer clearfix">
                @include('portals.aikaipin.product-library.partials.pagination', ['products' => $products])
            </div>
        @endif
    </div>
</section>

<div class="modal fade operation-ai-detail-modal" id="operation-ai-product-detail-modal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog operation-ai-detail-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="{{ __('aikaipin.operation_library.close') }}"><span aria-hidden="true">&times;</span></button>
                <h4 class="modal-title"><i class="fa fa-cube"></i>{{ __('aikaipin.operation_library.detail_title') }}</h4>
            </div>
            <div class="modal-body">
                <div class="text-center text-muted" data-operation-ai-detail-loading><i class="fa fa-spinner fa-spin"></i> {{ __('aikaipin.operation_library.loading') }}</div>
                <div class="alert alert-danger" data-operation-ai-detail-error hidden></div>
                <div class="operation-ai-detail-grid" data-operation-ai-detail-content hidden></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">{{ __('aikaipin.operation_library.close') }}</button></div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    var modal = document.getElementById('operation-ai-product-detail-modal');
    if (!modal) return;
    var loading = modal.querySelector('[data-operation-ai-detail-loading]');
    var error = modal.querySelector('[data-operation-ai-detail-error]');
    var content = modal.querySelector('[data-operation-ai-detail-content]');
    var labels = @json(__('aikaipin.operation_library'));

    function escapeHtml(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (character) {
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[character];
        });
    }
    function value(value) {
        if (Array.isArray(value)) return value.length ? value.map(function (entry) { return '<span class="operation-ai-detail-tag">' + escapeHtml(entry) + '</span>'; }).join('') : escapeHtml(labels.empty);
        return value ? escapeHtml(value) : escapeHtml(labels.empty);
    }
    function item(label, body, wide) {
        return '<div class="operation-ai-detail-item' + (wide ? ' is-wide' : '') + '"><span class="operation-ai-detail-label">' +
            escapeHtml(label) + '</span><div class="operation-ai-detail-value">' + body + '</div></div>';
    }
    function render(data) {
        var landing = data.landing_page_url
            ? '<a class="operation-ai-detail-link" href="' + escapeHtml(data.landing_page_url) + '" target="_blank" rel="noopener noreferrer"><i class="fa fa-external-link"></i>' + escapeHtml(data.landing_page_url) + '</a>'
            : escapeHtml(labels.empty);
        var images = data.images && data.images.length
            ? '<div class="operation-ai-detail-images">' + data.images.map(function (url) { return '<button type="button" class="operation-ai-detail-image" onclick="window.erpZoomOpen && window.erpZoomOpen(this.querySelector(\'img\').src)"><img src="' + escapeHtml(url) + '" alt=""></button>'; }).join('') + '</div>'
            : escapeHtml(labels.empty);
        var heroImage = data.images && data.images.length
            ? '<img src="' + escapeHtml(data.images[0]) + '" alt="">'
            : '<i class="fa fa-picture-o"></i>';
        var hero = '<div class="operation-ai-detail-hero"><div class="operation-ai-detail-hero-thumb">' + heroImage +
            '</div><div class="operation-ai-detail-hero-main"><h4>' + escapeHtml(data.name) + '</h4><span class="operation-ai-detail-code">' + escapeHtml(data.product_code) + '</span></div></div>';
        var packageFile = data.package_download_url
            ? '<a class="btn btn-primary btn-sm" href="' + escapeHtml(data.package_download_url) + '"><i class="fa fa-download"></i> ' + escapeHtml(labels.download_package) + '</a>' +
                '<span class="text-muted" style="margin-left:8px;">' + escapeHtml(data.package_file_name) + '</span>'
            : escapeHtml(labels.empty);
        content.innerHTML =
            hero + item(labels.sku, value(data.skus), true) + item(labels.category, value(data.categories), true) +
            item(labels.source_type, value(data.source_label) + ' / ' + value(data.type_label)) + item(labels.landing_page, landing) +
            item(labels.images, images, true) + item(labels.reference_file, value(data.reference_file_name)) +
            item(labels.package_file, packageFile) + item(labels.creator, value(data.created_by_name)) +
            item(labels.updater, value(data.updated_by_name)) + item(labels.created_at, value(data.created_at)) +
            item(labels.updated_at, value(data.updated_at));
        loading.hidden = true; error.hidden = true; content.hidden = false;
    }
    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-operation-ai-detail]');
        if (!button) return;
        event.preventDefault();
        loading.hidden = false; error.hidden = true; content.hidden = true; content.innerHTML = '';
        window.jQuery(modal).modal('show');
        fetch(button.getAttribute('data-detail-url'), {headers:{'X-Requested-With':'XMLHttpRequest'}})
            .then(function (response) { return response.json().then(function (data) { if (!response.ok) throw new Error(data.message || labels.load_failed); return data; }); })
            .then(render)
            .catch(function (exception) { loading.hidden = true; error.textContent = exception.message || labels.load_failed; error.hidden = false; });
    });
})();
</script>
@endpush
