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
    ];
@endphp

@push('styles')
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/aikaipin-product-library.css?v=20261019-4">
@endpush

@push('scripts')
    <script>window.ErpAikaipinProductLibraryConfig = @json($clientConfig);</script>
    <script src="{{ $portalOrigin }}/assets/erp/aikaipin-product-library.js?v=20261019-3"></script>
@endpush

@section('content')
<section class="content aikaipin-product-library-page">
    <header class="content-header aikaipin-page-header">
        <div>
            <h1>{{ $t('product_library.title') }}</h1>
            <p>{{ $t('product_library.subtitle') }}</p>
        </div>
    </header>

    <div class="box box-solid aikaipin-filter-box">
        <div class="box-body">
            <form class="aikaipin-filter-form" data-aikaipin-filter-form>
                <div class="aikaipin-filter-grid">
                    <div class="aikaipin-filter-field aikaipin-filter-keyword">
                        <label for="aikaipin-keyword">{{ $t('filters.keyword') }}</label>
                        <input id="aikaipin-keyword" class="form-control" type="search" autocomplete="off"
                            placeholder="{{ $t('filters.keyword_placeholder') }}" data-aikaipin-filter="keyword">
                    </div>
                    <div class="aikaipin-filter-field">
                        <label for="aikaipin-category">{{ $t('filters.category') }}</label>
                        <select id="aikaipin-category" class="form-control" data-aikaipin-filter="category">
                            <option value="">{{ $t('filters.all') }}</option>
                            @foreach (trans('aikaipin.options.categories') as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="aikaipin-filter-field">
                        <label for="aikaipin-source">{{ $t('filters.source') }}</label>
                        <select id="aikaipin-source" class="form-control" data-aikaipin-filter="source">
                            <option value="">{{ $t('filters.all') }}</option>
                            @foreach (trans('aikaipin.options.sources') as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="aikaipin-filter-field">
                        <label for="aikaipin-type">{{ $t('filters.type') }}</label>
                        <select id="aikaipin-type" class="form-control" data-aikaipin-filter="type">
                            <option value="">{{ $t('filters.all') }}</option>
                            @foreach (trans('aikaipin.options.types') as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="aikaipin-filter-actions">
                        <button class="btn btn-primary" type="submit">
                            <i class="fa fa-search" aria-hidden="true"></i> {{ $t('filters.search') }}
                        </button>
                        <button class="btn btn-default" type="reset">
                            <i class="fa fa-undo" aria-hidden="true"></i> {{ $t('filters.reset') }}
                        </button>
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
                    <button class="btn btn-default btn-sm" type="button" data-aikaipin-action="category">
                        <i class="fa fa-sitemap" aria-hidden="true"></i> {{ $t('actions.category_management') }}
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
                        <th>{{ $t('table.product_code') }}</th>
                        <th class="aikaipin-name-column">{{ $t('table.product_name_sku') }}</th>
                        <th>{{ $t('table.category') }}</th>
                        <th>{{ $t('table.source_type') }}</th>
                        <th>{{ $t('table.updated_at') }}</th>
                        <th class="aikaipin-operation-column">{{ $t('table.operation') }}</th>
                    </tr>
                    </thead>
                    <tbody data-aikaipin-product-list>
                    @foreach ($products as $product)
                        @php
                            $skuCount = count($product['skus']);
                            $allSkus = implode(', ', $product['skus']);
                        @endphp
                        <tr data-aikaipin-product
                            data-keyword="{{ strtolower($product['code'].' '.trans('aikaipin.'.$product['name_key']).' '.$allSkus) }}"
                            data-category="{{ $product['category'] }}"
                            data-source="{{ $product['source'] }}"
                            data-type="{{ $product['type'] }}">
                            @if ($canManageProductLibrary)
                                <td class="aikaipin-select-cell">
                                    <label class="aikaipin-check-label">
                                        <input type="checkbox" value="{{ $product['code'] }}" aria-label="{{ $t('actions.select_product', ['code' => $product['code']]) }}" data-aikaipin-product-select>
                                        <span class="sr-only">{{ $t('actions.select_product', ['code' => $product['code']]) }}</span>
                                    </label>
                                </td>
                            @endif
                            <td class="aikaipin-image-cell">
                                <span class="aikaipin-product-image" title="{{ $t('table.placeholder_image') }}">
                                    <i class="fa fa-picture-o" aria-hidden="true"></i>
                                    <span class="sr-only">{{ $t('table.placeholder_image') }}</span>
                                </span>
                            </td>
                            <td><span class="aikaipin-product-code">{{ $product['code'] }}</span></td>
                            <td class="aikaipin-name-cell">
                                <strong>{{ trans('aikaipin.'.$product['name_key']) }}</strong>
                                <span class="aikaipin-sku-line" title="{{ $allSkus }}">
                                    <span>{{ $product['skus'][0] }}</span>
                                    @if ($skuCount > 1)
                                        <span class="aikaipin-sku-more">+{{ $skuCount - 1 }}</span>
                                    @endif
                                </span>
                            </td>
                            <td><span class="label label-default">{{ trans('aikaipin.options.categories.'.$product['category']) }}</span></td>
                            <td>
                                <span class="aikaipin-source">{{ trans('aikaipin.options.sources.'.$product['source']) }}</span>
                                <span class="aikaipin-type">{{ trans('aikaipin.options.types.'.$product['type']) }}</span>
                            </td>
                            <td><time datetime="{{ str_replace(' ', 'T', $product['updated_at']) }}">{{ $product['updated_at'] }}</time></td>
                            <td>
                                <div class="btn-group btn-group-sm aikaipin-row-actions" role="group" aria-label="{{ $t('table.operation') }}">
                                    <a class="btn btn-default" href="{{ $product['landing_url'] }}" target="_blank" rel="noopener noreferrer"
                                        title="{{ $t('actions.open_landing_page') }}" aria-label="{{ $t('actions.open_landing_page') }}">
                                        <i class="fa fa-external-link" aria-hidden="true"></i> {{ $t('actions.landing_page') }}
                                    </a>
                                    <a class="btn btn-default" href="{{ $product['reference_url'] }}" target="_blank" rel="noopener noreferrer"
                                        title="{{ $t('actions.open_reference_page') }}" aria-label="{{ $t('actions.open_reference_page') }}">
                                        <i class="fa fa-external-link" aria-hidden="true"></i> {{ $t('actions.reference_page') }}
                                    </a>
                                    <button class="btn btn-default" type="button" data-aikaipin-action="download">
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
                    <tr class="aikaipin-empty-row" data-aikaipin-empty-row hidden>
                        <td colspan="{{ $canManageProductLibrary ? 8 : 7 }}">
                            <div class="aikaipin-empty-state">
                                <i class="fa fa-search" aria-hidden="true"></i>
                                <p data-aikaipin-empty-message>{{ $t('messages.no_records') }}</p>
                                <button class="btn btn-default btn-sm" type="button" data-aikaipin-clear-filters>{{ $t('filters.reset') }}</button>
                            </div>
                        </td>
                    </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</section>

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
@endif
@endsection
