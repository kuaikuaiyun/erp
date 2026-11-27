@extends('portal.layout')

@php
    $page = $manage ? 'aliexpress_selection_manage' : 'aliexpress_selection_browse';
    $title = __('aliexpress.menus.'.$page);
    $action = route($manage ? 'aliexpress.selection.index' : 'aliexpress.selection.browse');
    $lastPage = max(1, $products->lastPage());
@endphp
@section('page_title', $title)

@push('styles')
    <link rel="stylesheet" href="{{ request()->getSchemeAndHttpHost() }}/css/frontend-list-template.css?v=20261125-1">
    <style>
        .ae-selection .flt-table-scroll table { min-width: 900px; table-layout: auto; }
        .ae-selection .flt-table-scroll th { width: auto; }
        .ae-selection .ae-product { min-width: 190px; }
        .ae-selection .ae-specifications { min-width: 140px; }
        .ae-selection .ae-dialog { width: min(960px, 94vw); max-height: 88dvh; }
        .ae-selection .ae-dialog > header { position: sticky; top: 0; z-index: 2; padding-block: 12px; }
        .ae-selection .ae-dialog > header h3 { margin: 0; overflow-wrap: anywhere; }
        .ae-selection .ae-dialog .ae-dialog-status { text-align: center; }
        .ae-selection .ae-dialog-status .flt-spinner { vertical-align: middle; margin-right: 7px; }
        .ae-selection .ae-dialog-status .flt-btn { margin-left: 8px; }
        .ae-selection .ae-editor .ae-link-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin-top: 8px; }
        .ae-selection .ae-editor .ae-row-heading { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 12px; }
        .ae-selection .ae-editor .ae-preview { overflow-wrap: anywhere; color: #2188be; font-weight: 600; }
        .ae-selection .ae-editor .ae-field-error { display: block; margin-top: 6px; color: #b84137; }
        .ae-selection .ae-editor .ae-field-note { display: block; color: #71899d; margin: 8px 0; }
        .ae-selection .ae-editor .flt-filter-grid label { min-width: 0; margin-bottom: 0; }
        .ae-selection .ae-specification { margin: 12px 0; padding: 14px; min-width: 0; border: 1px solid #e1eaf1; border-radius: 8px; }
        .ae-selection .ae-specification small { display: block; margin: 5px 0; color: #71899d; }
        .ae-selection .ae-specification code { overflow-wrap: anywhere; white-space: normal; }
        .ae-selection .flt-empty-host, .ae-selection .flt-empty { min-height: 220px; }
        @media (max-width: 760px) {
            .ae-selection .ae-editor .flt-filter-grid { grid-template-columns: 1fr; gap: 14px; }
            .ae-selection .ae-dialog > header { padding-inline: 16px; }
        }
    </style>
@endpush

@section('content')
<section class="content-header"><h1>{{ $title }} <small>{{ __('aliexpress.page_descriptions.'.$page) }}</small></h1></section>
<section class="content flt ae-selection" data-aliexpress data-copied="{{ __('aliexpress.messages.copied') }}" data-copy-failed="{{ __('aliexpress.messages.copy_failed') }}">
    @if (session('success'))<div class="alert alert-success" role="status">{{ session('success') }}</div>@endif
    <form class="flt-card flt-filters" method="get" action="{{ $action }}" data-loading="region" data-loading-target="#ae-list"
        data-logistics-filters data-search-options="{{ __('frontend_list_template.search_options') }}"
        data-no-options="{{ __('frontend_list_template.no_options') }}" data-clear-choice="{{ __('frontend_list_template.clear_choice') }}">
        <input type="hidden" name="per_page" value="{{ $perPage }}">
        <div class="flt-filter-grid">
            <label>{{ __('aliexpress.fields.keyword') }}
                <input type="search" name="keyword" value="{{ $keyword }}" maxlength="191" placeholder="{{ __('aliexpress.hints.selection_search') }}">
            </label>
            @if ($manage)
                <div><label class="flt-field-label" for="ae-selection-status">{{ __('aliexpress.fields.status') }}</label>
                    <select name="status" id="ae-selection-status">
                        <option value="">{{ __('frontend_list_template.all') }}</option>
                        @foreach (['enabled', 'disabled'] as $option)
                            <option value="{{ $option }}" @selected($status === $option)>{{ __('aliexpress.status.'.$option) }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
        </div>
        <div class="flt-filter-actions">
            <button type="submit" class="flt-btn flt-primary"><i class="fa fa-search" aria-hidden="true"></i> {{ __('frontend_list_template.query') }}</button>
            <a class="flt-btn" href="{{ $action }}" data-loading="region" data-loading-target="#ae-list">{{ __('frontend_list_template.reset') }}</a>
        </div>
    </form>

    <section id="ae-list" class="flt-card flt-records">
        <div class="flt-toolbar">
            <div class="flt-selection">{{ __('frontend_list_template.records', ['count' => $products->total()]) }}<span aria-live="polite" data-ae-feedback></span></div>
            @if ($manage)
                <div class="flt-toolbar-actions">
                    <button type="button" class="flt-btn flt-primary" data-ae-form-url="{{ route('aliexpress.selection.create') }}" data-ae-title="{{ __('aliexpress.actions.create') }}">
                        <i class="fa fa-plus" aria-hidden="true"></i> {{ __('aliexpress.actions.create') }}
                    </button>
                </div>
            @endif
        </div>
        <div class="flt-table-region">
            <div class="flt-table-scroll {{ $products->isEmpty() ? 'is-empty' : '' }}" tabindex="0" aria-label="{{ $title }}">
                <table>
                    <caption class="flt-sr-only">{{ $title }}</caption>
                    <thead><tr>
                        @foreach (['name', 'supplier_product_id', 'sku', 'supplier_url', 'status', 'updated_at', 'actions'] as $column)
                            <th scope="col">{{ __('aliexpress.fields.'.$column) }}</th>
                        @endforeach
                    </tr></thead>
                    <tbody>
                    @foreach ($products as $product)
                        @php
                            $active = $product->skus->where('is_enabled', true);
                            $copy = implode("\n", array_filter([
                                $product->name, $product->supplier_url, $product->remark,
                                ...$active->map(fn ($sku) => $sku->sku.' · '.$sku->attribute.' · '.$sku->package_label)->all(),
                            ], fn ($value) => $value !== '' && $value !== null));
                        @endphp
                        <tr>
                            <td class="flt-name ae-product"><strong>{{ $product->name }}</strong>@if ($product->remark)<small>{{ $product->remark }}</small>@endif</td>
                            <td>{{ $product->supplier_product_id }}</td>
                            <td class="ae-specifications">
                                <button type="button" class="flt-row-action" data-ae-details data-ae-title="{{ __('aliexpress.actions.sku_details', ['name' => $product->name]) }}">{{ __('aliexpress.actions.view_skus', ['count' => $product->skus->count()]) }}</button>
                                <template data-ae-details-template>
                                    <div class="flt-dialog-content">
                                        @foreach ($product->skus as $sku)
                                            <div class="ae-specification">
                                                <code>{{ $sku->sku }}</code>
                                                <small>{{ $sku->attribute }} · {{ $sku->package_label }}</small>
                                                @if ($manage)
                                                    <small>{{ __('aliexpress.fields.supplier_sku') }} {{ $sku->supplier_sku }} · {{ __('aliexpress.status.'.($sku->is_enabled ? 'enabled' : 'disabled')) }}</small>
                                                @endif
                                                @if ($sku->is_enabled)
                                                    <button type="button" class="flt-row-action" data-ae-copy="{{ $sku->sku }}">{{ __('aliexpress.actions.copy_sku') }}</button>
                                                @endif
                                            </div>
                                        @endforeach
                                        <span role="status" data-ae-feedback></span>
                                    </div>
                                    <footer><button type="button" class="flt-btn" data-ae-close>{{ __('frontend_list_template.close') }}</button></footer>
                                </template>
                            </td>
                            <td><a href="{{ $product->supplier_url }}" target="_blank" rel="noopener noreferrer">{{ __('aliexpress.actions.open_link') }} <i class="fa fa-external-link" aria-hidden="true"></i></a></td>
                            <td><span class="flt-status" data-status="{{ $active->isNotEmpty() ? 'active' : 'disabled' }}">{{ __('aliexpress.status.'.($active->isNotEmpty() ? 'enabled' : 'disabled')) }}</span></td>
                            <td class="flt-time">{{ $product->updated_at?->timezone('Asia/Shanghai')->format('Y-m-d H:i') }}</td>
                            <td>
                                @if ($active->isNotEmpty())<button type="button" class="flt-row-action" data-ae-copy="{{ $copy }}">{{ __('aliexpress.actions.copy') }}</button>@endif
                                @if ($manage)
                                    <button type="button" class="flt-row-action" data-ae-form-url="{{ route('aliexpress.selection.edit', $product) }}" data-ae-title="{{ __('aliexpress.actions.edit') }}">{{ __('frontend_list_template.edit') }}</button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @if ($products->isEmpty())
                <div class="flt-empty-host"><div class="flt-empty"><i class="fa fa-search" aria-hidden="true"></i><h4>{{ __('frontend_list_template.empty_title') }}</h4><p>{{ __('frontend_list_template.empty_hint') }}</p></div></div>
            @endif
        </div>
        <nav class="flt-pagination" aria-label="{{ __('frontend_list_template.pagination') }}" data-loading="region" data-loading-target="#ae-list">
            <form method="get" action="{{ $action }}">
                <input type="hidden" name="keyword" value="{{ $keyword }}"><input type="hidden" name="status" value="{{ $status }}">
                <label>{{ __('frontend_list_template.per_page') }}
                    <select name="per_page" aria-label="{{ __('frontend_list_template.page_size') }}" onchange="this.form.requestSubmit()">
                        @foreach ([20, 50, 100] as $size)<option @selected($perPage === $size)>{{ $size }}</option>@endforeach
                    </select>
                </label>
            </form>
            @if ($products->onFirstPage())<button type="button" class="flt-btn" disabled>{{ __('frontend_list_template.previous') }}</button>@else<a class="flt-btn" href="{{ $products->previousPageUrl() }}">{{ __('frontend_list_template.previous') }}</a>@endif
            <strong>{{ __('frontend_list_template.page_label', ['page' => $products->currentPage(), 'pages' => $lastPage]) }}</strong>
            @if ($products->hasMorePages())<a class="flt-btn" href="{{ $products->nextPageUrl() }}">{{ __('frontend_list_template.next') }}</a>@else<button type="button" class="flt-btn" disabled>{{ __('frontend_list_template.next') }}</button>@endif
            <form method="get" action="{{ $action }}">
                <input type="hidden" name="keyword" value="{{ $keyword }}"><input type="hidden" name="status" value="{{ $status }}"><input type="hidden" name="per_page" value="{{ $perPage }}">
                <label>{{ __('frontend_list_template.jump_to') }} <input type="number" name="page" min="1" max="{{ $lastPage }}" step="1" required aria-label="{{ __('frontend_list_template.page_placeholder') }}"></label>
                <button type="submit" class="flt-btn flt-primary">{{ __('frontend_list_template.jump') }}</button>
            </form>
        </nav>
    </section>
    <dialog class="flt-dialog ae-dialog" aria-labelledby="ae-dialog-title" data-ae-dialog
        data-loading-text="{{ __('frontend_list_template.loading') }}" data-load-failed="{{ __('aliexpress.messages.form_load_failed') }}"
        data-save-failed="{{ __('aliexpress.messages.form_save_failed') }}">
        <header>
            <h3 id="ae-dialog-title" data-ae-dialog-title></h3>
            <button type="button" class="flt-icon-close" data-ae-close aria-label="{{ __('frontend_list_template.close') }}">×</button>
        </header>
        <div class="flt-dialog-content ae-dialog-status" data-ae-dialog-status aria-live="polite" hidden>
            <span class="flt-spinner" data-ae-dialog-spinner aria-hidden="true" hidden></span>
            <span data-ae-dialog-message></span>
            @if ($manage)<button type="button" class="flt-btn" data-ae-retry hidden>{{ __('aliexpress.messages.form_retry') }}</button>@endif
        </div>
        <div data-ae-dialog-content></div>
    </dialog>
</section>
@endsection

@push('scripts')
    <script src="{{ request()->getSchemeAndHttpHost() }}/assets/erp/shopify-orders-filters.js?v=20261123-19"></script>
    <script src="{{ request()->getSchemeAndHttpHost() }}/js/aliexpress-selection.js?v=2"></script>
@endpush
