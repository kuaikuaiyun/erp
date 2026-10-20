@extends('portal.layout')

@section('page_title', __('aikaipin.product_library.title'))

@php
    $portalOrigin = rtrim(request()->getSchemeAndHttpHost(), '/');
    $t = static fn (string $key, array $replace = []): string => __('aikaipin.'.$key, $replace);
    $clientConfig = [
        'comingSoonTitle' => $t('messages.coming_soon_title'),
        'comingSoonMessage' => $t('messages.coming_soon_message'),
        'noRecords' => $t('messages.no_records'),
        'clearFilters' => $t('filters.reset'),
        'selectedCount' => $t('visibility.selected_count', ['count' => ':count']),
        'selectedNone' => $t('visibility.selected_none'),
        'selectionRequiredTitle' => $t('messages.selection_required_title'),
        'selectionRequiredMessage' => $t('messages.selection_required_message'),
        'demoTitle' => $t('messages.visibility_demo_title'),
        'demoMessage' => $t('messages.visibility_demo_message', ['count' => ':count']),
        'enableVisibilityTitle' => $t('visibility.enable_title'),
        'cancelVisibilityTitle' => $t('visibility.cancel_title'),
        'enableVisibilityDescription' => $t('visibility.enable_description', ['count' => ':count']),
        'cancelVisibilityDescription' => $t('visibility.cancel_description', ['count' => ':count']),
        'enableVisibilityConfirm' => $t('visibility.enable_confirm'),
        'cancelVisibilityConfirm' => $t('visibility.cancel_confirm'),
        'skuEmptyTitle' => $t('messages.sku_empty_title'),
        'skuEmptyMessage' => $t('messages.sku_empty_message'),
        'imageLimitTitle' => $t('messages.image_limit_title'),
        'imageLimitMessage' => $t('messages.image_limit_message'),
        'imageTooLargeTitle' => $t('messages.image_too_large_title'),
        'imageTooLargeMessage' => $t('messages.image_too_large_message', ['name' => ':name']),
        'archiveInvalidTitle' => $t('messages.archive_invalid_title'),
        'archiveInvalidMessage' => $t('messages.archive_invalid_message'),
        'archiveTooLargeTitle' => $t('messages.archive_too_large_title'),
        'archiveTooLargeMessage' => $t('messages.archive_too_large_message'),
        'productNameRequiredTitle' => $t('messages.product_name_required_title'),
        'productNameRequiredMessage' => $t('messages.product_name_required_message'),
        'archiveRequiredTitle' => $t('messages.archive_required_title'),
        'archiveRequiredMessage' => $t('messages.archive_required_message'),
        'uploadDemoTitle' => $t('messages.upload_demo_title'),
        'uploadDemoMessage' => $t('messages.upload_demo_message'),
        'fileSelected' => $t('upload.file_selected', ['name' => ':name', 'size' => ':size']),
        'fileUnselected' => $t('upload.file_unselected'),
        'serverFilters' => true,
        'filterResetUrl' => route('aikaipin.product-library.index'),
        'initialCategories' => $categories->map(static fn ($category): array => [
            'value' => (string) $category->getKey(),
            'label' => (string) $category->name,
        ])->values()->all(),
        'categoryStoreUrl' => route('aikaipin.product-library.categories.store'),
        'categoryDeleteUrlTemplate' => route('aikaipin.product-library.categories.destroy', ['category' => '__category__']),
        'csrfToken' => csrf_token(),
        'categoryEmptyTitle' => $t('messages.category_empty_title'),
        'categoryEmptyMessage' => $t('messages.category_empty_message'),
        'categoryDuplicateTitle' => $t('messages.category_duplicate_title'),
        'categoryDuplicateMessage' => $t('messages.category_duplicate_message'),
        'categoryCreatedTitle' => $t('messages.category_created_title'),
        'categoryCreatedMessage' => $t('messages.category_created_message'),
        'categoryDeletedTitle' => $t('messages.category_deleted_title'),
        'categoryDeletedMessage' => $t('messages.category_deleted_message'),
        'removeCategoryAria' => $t('upload.remove_category', ['name' => ':name']),
        'imagePreviewTitle' => $t('image_preview.title', ['name' => ':name']),
        'imagePreviewPosition' => $t('image_preview.position', ['current' => ':current', 'total' => ':total']),
        'imagePreviewPlaceholder' => $t('image_preview.placeholder'),
        'imagePreviewThumbnail' => $t('image_preview.thumbnail', ['number' => ':number']),
        'imagePreviewImageAlt' => $t('image_preview.image_alt', ['number' => ':number']),
    ];
@endphp

@push('styles')
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/aikaipin-product-library.css?v=20261020-1">
@endpush

@push('scripts')
    <script>window.ErpAikaipinProductLibraryConfig = @json($clientConfig);</script>
    <script src="{{ $portalOrigin }}/assets/erp/aikaipin-product-library.js?v=20261020-1"></script>
@endpush

@section('content')
<section class="content aikaipin-product-library-page">
    <div class="box box-solid aikaipin-filter-box">
        <div class="box-body">
            <form class="aikaipin-filter-form" method="get" action="{{ route('aikaipin.product-library.index') }}" data-aikaipin-filter-form>
                <div class="aikaipin-filter-grid">
                    <div class="aikaipin-filter-field aikaipin-filter-keyword">
                        <label for="aikaipin-keyword">{{ $t('filters.keyword') }}</label>
                        <input id="aikaipin-keyword" class="form-control" type="search" name="keyword" value="{{ $filters['keyword'] }}" autocomplete="off"
                            placeholder="{{ $t('filters.keyword_placeholder') }}" data-aikaipin-filter="keyword">
                    </div>
                    <div class="aikaipin-filter-field">
                        <label for="aikaipin-category">{{ $t('filters.category') }}</label>
                        <select id="aikaipin-category" class="form-control" name="category" data-aikaipin-filter="category">
                            <option value="">{{ $t('filters.all') }}</option>
                            @foreach ($categories as $category)
                                <option value="{{ $category->getKey() }}" @selected($filters['category'] === (int) $category->getKey())>{{ $category->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="aikaipin-filter-field">
                        <label for="aikaipin-source">{{ $t('filters.source') }}</label>
                        <select id="aikaipin-source" class="form-control" name="source" data-aikaipin-filter="source">
                            <option value="">{{ $t('filters.all') }}</option>
                            @foreach (trans('aikaipin.upload.source_options') as $value => $label)
                                <option value="{{ $value }}" @selected($filters['source'] === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="aikaipin-filter-field">
                        <label for="aikaipin-type">{{ $t('filters.type') }}</label>
                        <select id="aikaipin-type" class="form-control" name="type" data-aikaipin-filter="type">
                            <option value="">{{ $t('filters.all') }}</option>
                            @foreach (trans('aikaipin.upload.type_options') as $value => $label)
                                <option value="{{ $value }}" @selected($filters['type'] === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="aikaipin-filter-actions">
                        <button class="btn btn-primary" type="submit">
                            <i class="fa fa-search" aria-hidden="true"></i> {{ $t('filters.search') }}
                        </button>
                        <a class="btn btn-default" href="{{ route('aikaipin.product-library.index') }}">
                            <i class="fa fa-undo" aria-hidden="true"></i> {{ $t('filters.reset') }}
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="box box-solid aikaipin-table-box">
        <div class="box-header with-border aikaipin-list-toolbar">
            @if ($canManageProductLibrary)
                <div class="aikaipin-list-toolbar-actions">
                    <span class="aikaipin-selected-count" data-aikaipin-selected-count>{{ $t('visibility.selected_none') }}</span>
                    <button class="btn btn-default btn-sm" type="button" disabled data-aikaipin-batch-action="enable">
                        <i class="fa fa-eye" aria-hidden="true"></i> {{ $t('actions.enable_visibility') }}
                    </button>
                    <button class="btn btn-default btn-sm" type="button" disabled data-aikaipin-batch-action="cancel">
                        <i class="fa fa-eye-slash" aria-hidden="true"></i> {{ $t('actions.cancel_visibility') }}
                    </button>
                    <button class="btn btn-primary btn-sm" type="button" data-aikaipin-action="upload">
                        <i class="fa fa-upload" aria-hidden="true"></i> {{ $t('actions.upload_product') }}
                    </button>
                </div>
            @endif
        </div>
        <div class="box-body no-padding">
            <div class="table-responsive">
                <table class="table table-hover aikaipin-product-table">
                    <thead>
                    <tr>
                        @if ($canManageProductLibrary)
                            <th class="aikaipin-select-column">
                                <label class="aikaipin-check-label">
                                    <input type="checkbox" aria-label="{{ $t('actions.select_all') }}" data-aikaipin-select-all>
                                    <span class="sr-only">{{ $t('actions.select_all') }}</span>
                                </label>
                            </th>
                        @endif
                        <th class="aikaipin-image-column">{{ $t('table.image') }}</th>
                        <th class="aikaipin-product-code-column">{{ $t('table.product_code') }}</th>
                        <th class="aikaipin-name-column">{{ $t('table.product_name_sku') }}</th>
                        <th class="aikaipin-category-column">{{ $t('table.category') }}</th>
                        <th class="aikaipin-source-column">{{ $t('table.source_type') }}</th>
                        <th class="aikaipin-updated-column">{{ $t('table.updated_at') }}</th>
                        <th class="aikaipin-operation-column">{{ $t('table.operation') }}</th>
                    </tr>
                    </thead>
                    <tbody data-aikaipin-product-list>
                    @foreach ($products as $product)
                        @php
                            $skus = $product->skus->pluck('sku')->values()->all();
                            $skuCount = count($skus);
                            $allSkus = implode(', ', $skus);
                            $productName = (string) $product->name;
                            $imageCount = $product->images->count();
                            $previewImages = $product->images
                                ->map(static fn ($image): array => ['url' => null])
                                ->values()
                                ->all();
                            $updatedAt = $product->updated_at?->timezone(config('app.timezone'))->format('Y-m-d H:i');
                        @endphp
                        <tr data-aikaipin-product
                            data-keyword="{{ strtolower($product->product_code.' '.$productName.' '.$allSkus) }}"
                            data-source="{{ $product->source_platform }}"
                            data-type="{{ $product->product_type }}">
                            @if ($canManageProductLibrary)
                                <td class="aikaipin-select-cell">
                                    <label class="aikaipin-check-label">
                                        <input type="checkbox" value="{{ $product->getKey() }}" aria-label="{{ $t('actions.select_product', ['code' => $product->product_code]) }}" data-aikaipin-product-select>
                                        <span class="sr-only">{{ $t('actions.select_product', ['code' => $product->product_code]) }}</span>
                                    </label>
                                </td>
                            @endif
                            <td class="aikaipin-image-cell">
                                <button class="aikaipin-product-image" type="button"
                                    title="{{ $t('image_preview.open', ['name' => $productName]) }}"
                                    aria-label="{{ $t('image_preview.open', ['name' => $productName]) }}"
                                    data-aikaipin-preview-image
                                    data-aikaipin-preview-title="{{ $productName }}"
                                    data-aikaipin-preview-images='@json($previewImages)'>
                                    <i class="fa fa-picture-o" aria-hidden="true"></i>
                                    @if ($imageCount > 1)
                                        <span class="aikaipin-product-image-count">{{ $imageCount }}</span>
                                    @endif
                                    <span class="sr-only">{{ $t('image_preview.open', ['name' => $productName]) }}</span>
                                </button>
                            </td>
                            <td class="aikaipin-product-code-cell"><span class="aikaipin-product-code">{{ $product->product_code }}</span></td>
                            <td class="aikaipin-name-cell">
                                <strong>{{ $productName }}</strong>
                                @if ($skuCount > 0)
                                    <span class="aikaipin-sku-line" title="{{ $allSkus }}">
                                        <span>{{ $skus[0] }}</span>
                                        @if ($skuCount > 1)
                                            <span class="aikaipin-sku-more">+{{ $skuCount - 1 }}</span>
                                        @endif
                                    </span>
                                @endif
                            </td>
                            <td class="aikaipin-category-cell">
                                @foreach ($product->categories as $category)
                                    <span class="label label-default">{{ $category->name }}</span>
                                @endforeach
                            </td>
                            <td class="aikaipin-source-cell">
                                <span class="aikaipin-source">{{ trans('aikaipin.upload.source_options.'.$product->source_platform) }}</span>
                                <span class="aikaipin-type">{{ trans('aikaipin.upload.type_options.'.$product->product_type) }}</span>
                            </td>
                            <td class="aikaipin-updated-cell"><time datetime="{{ $product->updated_at?->toAtomString() }}">{{ $updatedAt }}</time></td>
                            <td class="aikaipin-operation-cell">
                                <div class="btn-group btn-group-sm aikaipin-row-actions" role="group" aria-label="{{ $t('table.operation') }}">
                                    @if ($product->landing_page_url)
                                        <a class="btn btn-default" href="{{ $product->landing_page_url }}" target="_blank" rel="noopener noreferrer"
                                            title="{{ $t('actions.open_landing_page') }}" aria-label="{{ $t('actions.open_landing_page') }}">
                                            <i class="fa fa-external-link" aria-hidden="true"></i> {{ $t('actions.landing_page') }}
                                        </a>
                                    @else
                                        <button class="btn btn-default" type="button" disabled>{{ $t('actions.landing_page') }}</button>
                                    @endif
                                    <button class="btn btn-default" type="button" disabled>{{ $t('actions.reference_page') }}</button>
                                    <button class="btn btn-default" type="button" disabled>
                                        <i class="fa fa-download" aria-hidden="true"></i> {{ $t('actions.download') }}
                                    </button>
                                    @if ($canManageProductLibrary)
                                        <button class="btn btn-primary" type="button" data-aikaipin-action="edit">
                                            <i class="fa fa-pencil" aria-hidden="true"></i> {{ $t('actions.edit') }}
                                        </button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                    @if ($products->isEmpty())
                    <tr class="aikaipin-empty-row" data-aikaipin-empty-row>
                        <td colspan="{{ $canManageProductLibrary ? 8 : 7 }}">
                            <div class="aikaipin-empty-state">
                                <i class="fa fa-search" aria-hidden="true"></i>
                                <p data-aikaipin-empty-message>{{ $t('messages.no_records') }}</p>
                                <a class="btn btn-default btn-sm" href="{{ route('aikaipin.product-library.index') }}">{{ $t('filters.reset') }}</a>
                            </div>
                        </td>
                    </tr>
                    @endif
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

<div id="aikaipin-image-preview-modal" class="modal fade" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="{{ $t('image_preview.close') }}"><span aria-hidden="true">&times;</span></button>
                <h4 class="modal-title" data-aikaipin-image-preview-title></h4>
            </div>
            <div class="modal-body">
                <div class="aikaipin-image-preview-frame">
                    <button class="aikaipin-image-preview-nav aikaipin-image-preview-prev" type="button"
                        title="{{ $t('image_preview.previous') }}" aria-label="{{ $t('image_preview.previous') }}"
                        data-aikaipin-image-preview-prev>
                        <i class="fa fa-angle-left" aria-hidden="true"></i>
                    </button>
                    <div class="aikaipin-image-preview-stage" data-aikaipin-image-preview-stage></div>
                    <button class="aikaipin-image-preview-nav aikaipin-image-preview-next" type="button"
                        title="{{ $t('image_preview.next') }}" aria-label="{{ $t('image_preview.next') }}"
                        data-aikaipin-image-preview-next>
                        <i class="fa fa-angle-right" aria-hidden="true"></i>
                    </button>
                </div>
                <div class="aikaipin-image-preview-position" data-aikaipin-image-preview-position></div>
                <div class="aikaipin-image-preview-thumbnails" role="tablist" aria-label="{{ $t('image_preview.thumbnails') }}"
                    data-aikaipin-image-preview-thumbnails></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">{{ $t('image_preview.close') }}</button>
            </div>
        </div>
    </div>
</div>

@if ($canManageProductLibrary)
    <div id="aikaipin-visibility-modal" class="modal fade" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-sm" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-label="{{ $t('visibility.close') }}"><span aria-hidden="true">&times;</span></button>
                    <h4 class="modal-title" data-aikaipin-visibility-title></h4>
                </div>
                <div class="modal-body">
                    <p data-aikaipin-visibility-description></p>
                    <div class="aikaipin-visibility-source">
                        <span class="aikaipin-visibility-source-label">{{ $t('visibility.source_label') }}</span>
                        @if ($operationVisibilitySource['is_available'])
                            <strong>{{ $operationVisibilitySource['name'] }}</strong>
                            <code>{{ $operationVisibilitySource['code'] }}</code>
                        @else
                            <span>{{ $t('visibility.source_unavailable') }}</span>
                            <code>{{ $operationVisibilitySource['code'] }}</code>
                        @endif
                        <small>{{ $t('visibility.source_scope_hint') }}</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal">{{ $t('visibility.cancel') }}</button>
                    <button type="button" class="btn btn-primary" data-aikaipin-visibility-confirm></button>
                </div>
            </div>
        </div>
    </div>

    <div id="aikaipin-upload-modal" class="modal fade" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-lg" role="document">
            <form class="modal-content aikaipin-upload-form" data-aikaipin-upload-form>
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-label="{{ $t('upload.close') }}"><span aria-hidden="true">&times;</span></button>
                    <h4 class="modal-title">{{ $t('upload.title') }}</h4>
                </div>
                <div class="modal-body">
                    <p class="aikaipin-upload-demo-note"><i class="fa fa-info-circle" aria-hidden="true"></i> {{ $t('upload.demo_notice') }}</p>
                    <section class="aikaipin-upload-section">
                        <header class="aikaipin-upload-section-heading">
                            <span class="aikaipin-upload-section-number">1</span>
                            <h5>{{ $t('upload.sections.basic_information') }}</h5>
                        </header>
                        <div class="aikaipin-upload-section-body">
                            <div class="row">
                                <div class="col-sm-6 form-group">
                                    <label>{{ $t('upload.source_platform') }}</label>
                                    <div class="btn-group aikaipin-choice-group" data-toggle="buttons">
                                        @foreach (trans('aikaipin.upload.source_options') as $value => $label)
                                            <label class="btn btn-default @if($value === 'shopify') active @endif">
                                                <input type="radio" name="source_platform" value="{{ $value }}" @checked($value === 'shopify')> {{ $label }}
                                            </label>
                                        @endforeach
                                    </div>
                                </div>
                                <div class="col-sm-6 form-group">
                                    <label>{{ $t('upload.product_type') }}</label>
                                    <div class="btn-group aikaipin-choice-group" data-toggle="buttons">
                                        @foreach (trans('aikaipin.upload.type_options') as $value => $label)
                                            <label class="btn btn-default @if($value === 'general') active @endif">
                                                <input type="radio" name="product_type" value="{{ $value }}" @checked($value === 'general')> {{ $label }}
                                            </label>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-sm-6 form-group">
                                    <label for="aikaipin-upload-name">{{ $t('upload.product_name') }}</label>
                                    <input id="aikaipin-upload-name" class="form-control" type="text" maxlength="191" autocomplete="off" data-aikaipin-upload-name>
                                </div>
                                <div class="col-sm-6 form-group">
                                    <label for="aikaipin-upload-landing-url">{{ $t('upload.landing_page_url') }}</label>
                                    <input id="aikaipin-upload-landing-url" class="form-control" type="url" autocomplete="off" data-aikaipin-upload-landing-url>
                                </div>
                            </div>
                        </div>
                    </section>
                    <section class="aikaipin-upload-section">
                        <header class="aikaipin-upload-section-heading">
                            <span class="aikaipin-upload-section-number">2</span>
                            <h5>{{ $t('upload.sections.product_details') }}</h5>
                        </header>
                        <div class="aikaipin-upload-section-body">
                            <div class="form-group">
                                <label for="aikaipin-upload-sku">{{ $t('upload.skus') }}</label>
                                <div class="input-group">
                                    <input id="aikaipin-upload-sku" class="form-control" type="text" autocomplete="off" placeholder="{{ $t('upload.sku_placeholder') }}" data-aikaipin-sku-input>
                                    <span class="input-group-btn">
                                        <button class="btn btn-default" type="button" data-aikaipin-add-sku><i class="fa fa-plus" aria-hidden="true"></i> {{ $t('upload.add_sku') }}</button>
                                    </span>
                                </div>
                                <div class="aikaipin-sku-tags" data-aikaipin-sku-tags aria-live="polite"></div>
                                <p class="help-block">{{ $t('upload.sku_hint') }}</p>
                            </div>
                            <div class="form-group aikaipin-upload-section-last-field">
                                <label>{{ $t('upload.categories') }}</label>
                                <div class="input-group aikaipin-category-add-group">
                                    <input id="aikaipin-upload-category" class="form-control" type="text" maxlength="80" autocomplete="off"
                                        placeholder="{{ $t('upload.category_placeholder') }}" data-aikaipin-category-input>
                                    <span class="input-group-btn">
                                        <button class="btn btn-default" type="button" data-aikaipin-add-category>
                                            <i class="fa fa-plus" aria-hidden="true"></i> {{ $t('upload.add_category') }}
                                        </button>
                                    </span>
                                </div>
                                <div class="aikaipin-category-choices" data-aikaipin-category-choices aria-live="polite"></div>
                                <p class="help-block">{{ $t('upload.category_hint') }}</p>
                            </div>
                        </div>
                    </section>
                    <section class="aikaipin-upload-section">
                        <header class="aikaipin-upload-section-heading">
                            <span class="aikaipin-upload-section-number">3</span>
                            <h5>{{ $t('upload.sections.attachments') }}</h5>
                        </header>
                        <div class="aikaipin-upload-section-body">
                            <div class="aikaipin-attachment-grid">
                                <div class="aikaipin-attachment-card">
                                    <label for="aikaipin-upload-images">{{ $t('upload.product_images') }}</label>
                                    <input id="aikaipin-upload-images" class="aikaipin-visually-hidden" type="file" accept="image/*" multiple data-aikaipin-upload-images>
                                    <label class="aikaipin-upload-file-card" for="aikaipin-upload-images">
                                        <span class="aikaipin-upload-file-icon"><i class="fa fa-picture-o" aria-hidden="true"></i></span>
                                        <span class="aikaipin-upload-file-content">
                                            <strong>{{ $t('upload.select_images') }}</strong>
                                            <small>{{ $t('upload.images_hint') }}</small>
                                        </span>
                                        <i class="fa fa-angle-right" aria-hidden="true"></i>
                                    </label>
                                    <div class="aikaipin-image-previews" data-aikaipin-image-previews></div>
                                </div>
                                <div class="aikaipin-attachment-card">
                                    <label for="aikaipin-upload-reference-file">{{ $t('upload.reference_file') }}</label>
                                    <input id="aikaipin-upload-reference-file" class="aikaipin-visually-hidden" type="file" data-aikaipin-reference-file>
                                    <label class="aikaipin-upload-file-card" for="aikaipin-upload-reference-file">
                                        <span class="aikaipin-upload-file-icon"><i class="fa fa-file-o" aria-hidden="true"></i></span>
                                        <span class="aikaipin-upload-file-content">
                                            <strong>{{ $t('upload.select_reference_file') }}</strong>
                                            <small data-aikaipin-reference-file-name>{{ $t('upload.file_unselected') }}</small>
                                        </span>
                                        <i class="fa fa-angle-right" aria-hidden="true"></i>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </section>
                    <section class="aikaipin-upload-section aikaipin-upload-package-section">
                        <header class="aikaipin-upload-section-heading">
                            <span class="aikaipin-upload-section-number">4</span>
                            <h5>{{ $t('upload.sections.package') }}</h5>
                        </header>
                        <div class="aikaipin-upload-section-body">
                            <input id="aikaipin-upload-archive" class="aikaipin-visually-hidden" type="file" accept=".zip,.rar,.7z,.tar,.gz,.tgz,.bz2,.xz" data-aikaipin-package-file>
                            <label class="aikaipin-package-file-card" for="aikaipin-upload-archive">
                                <span class="aikaipin-package-file-icon"><i class="fa fa-file-archive-o" aria-hidden="true"></i></span>
                                <span class="aikaipin-upload-file-content">
                                    <strong>{{ $t('upload.select_package_archive') }}</strong>
                                    <small>{{ $t('upload.package_hint') }}</small>
                                    <small class="aikaipin-selected-file-name" data-aikaipin-package-file-name>{{ $t('upload.file_unselected') }}</small>
                                </span>
                                <span class="aikaipin-package-file-action">{{ $t('upload.select_file') }}</span>
                            </label>
                            <div class="progress progress-sm active aikaipin-upload-progress" hidden data-aikaipin-upload-progress>
                                <div class="progress-bar progress-bar-primary progress-bar-striped" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" style="width: 0%" data-aikaipin-upload-progress-bar></div>
                            </div>
                        </div>
                    </section>
                    <section class="aikaipin-upload-section aikaipin-upload-section-last">
                        <header class="aikaipin-upload-section-heading">
                            <span class="aikaipin-upload-section-number">5</span>
                            <h5>{{ $t('upload.sections.authorization') }}</h5>
                        </header>
                        <div class="aikaipin-upload-section-body">
                            <label>{{ $t('upload.quick_authorization') }}</label>
                            @if ($quickAuthorizationDepartments !== [])
                                <div class="aikaipin-department-choices" data-toggle="buttons">
                                    @foreach ($quickAuthorizationDepartments as $department)
                                        <label class="btn btn-default btn-sm active" title="{{ $department['code'] }}">
                                            <input type="checkbox" name="quick_authorization[]" value="{{ $department['id'] }}" checked> {{ $department['name'] }}
                                        </label>
                                    @endforeach
                                </div>
                                <p class="help-block">{{ $t('upload.authorization_hint') }}</p>
                            @else
                                <p class="help-block">{{ $t('upload.authorization_empty') }}</p>
                            @endif
                        </div>
                    </section>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-default" type="button" data-dismiss="modal">{{ $t('upload.cancel') }}</button>
                    <button class="btn btn-primary" type="submit" data-aikaipin-upload-submit>
                        <i class="fa fa-upload" aria-hidden="true"></i> {{ $t('upload.simulate_submit') }}
                    </button>
                </div>
            </form>
        </div>
    </div>
@endif
@endsection
