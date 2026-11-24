@extends('portal.layout')

@section('page_title', __('logistics.missing_tongtu_page.title'))

@push('styles')
    <link rel="stylesheet" href="{{ asset('assets/erp/logistics.css') }}?v=20261104-1">
    <link rel="stylesheet" href="{{ asset('css/frontend-list-template.css') }}?v=20261123-19">
    <link rel="stylesheet" href="{{ asset('assets/erp/missing-tongtu-orders.css') }}?v=2">
@endpush

@section('content')
    <section class="content-header">
        <h1>{{ __('logistics.missing_tongtu_page.title') }}</h1>
    </section>
    <section class="content flt tongtu-check">
        <form method="get" action="{{ $checkUrl }}" class="flt-card flt-filters" data-loading="region" data-loading-target=".tongtu-result-box">
            <input type="hidden" name="per_page" value="{{ $orders->perPage() }}">
            <h3 class="tongtu-filter-title"><i class="fa fa-filter" aria-hidden="true"></i> {{ __('logistics.missing_tongtu_page.filters') }}</h3>
            <div class="flt-filter-grid">
                <label>{{ __('logistics.missing_tongtu_page.domain') }}
                    <input id="tongtu-store-domain" type="search" name="store_domain" value="{{ $storeDomain }}"
                        placeholder="{{ __('logistics.missing_tongtu_page.domain_placeholder') }}" maxlength="255" autocomplete="off">
                </label>
                <label>{{ __('logistics.missing_tongtu_page.order_number') }}
                    <input id="tongtu-order-number" type="search" name="tongtu_order_number" value="{{ $tongtuOrderNumber }}"
                        placeholder="{{ __('logistics.missing_tongtu_page.number_placeholder') }}" maxlength="191" autocomplete="off">
                </label>
                <div class="flt-time-range" role="group" aria-label="{{ __('logistics.missing_tongtu_page.payment_dates') }}">
                    <label>{{ __('logistics.missing_tongtu_page.paid_from') }}<input id="paid-from" type="date" name="paid_from" value="{{ $paidFrom }}"></label>
                    <label>{{ __('logistics.missing_tongtu_page.paid_to') }}<input id="paid-to" type="date" name="paid_to" value="{{ $paidTo }}"></label>
                </div>
            </div>
            <div class="flt-filter-actions">
                <button class="flt-btn flt-primary" type="submit"><i class="fa fa-search" aria-hidden="true"></i> {{ __('logistics.common.search') }}</button>
                <a class="flt-btn" href="{{ $checkUrl }}" data-loading="region" data-loading-target=".tongtu-result-box">{{ __('logistics.common.reset') }}</a>
            </div>
        </form>

        @if ($errors->any())
            <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
        @endif
        <div id="tongtu-sync-progress" class="alert alert-info" role="status"
             data-url="{{ request()->fullUrlWithQuery(['progress' => 1]) }}"
             data-active-template="{{ __('logistics.missing_tongtu_page.active', ['date' => '__date__', 'orders' => '__orders__']) }}"
             data-last-sync-template="{{ __('logistics.missing_tongtu_page.last_sync', ['time' => '__time__']) }}"
             data-synced-days="{{ $syncedDays }}" @if ($syncedDays >= $totalDays) hidden @endif>
            {{ __('logistics.missing_tongtu_page.coverage', ['from' => $coverageFrom, 'to' => $coverageTo]) }}
            <span data-coverage>{{ $syncedDays }} / {{ $totalDays }}</span> {{ __('logistics.missing_tongtu_page.days') }}
            <span data-active></span>
            <a href="{{ request()->fullUrl() }}" data-refresh hidden>{{ __('logistics.missing_tongtu_page.refresh') }}</a>
        </div>
        <div class="flt-card flt-records tongtu-result-box">
            <div class="flt-toolbar">
                <div class="flt-selection">{{ __('logistics.missing_tongtu_page.group_count', ['count' => $orders->total()]) }}</div>
                <div class="flt-toolbar-actions">
                    <span id="tongtu-last-sync" class="text-muted" @if (!$lastSync) hidden @endif>{{ __('logistics.missing_tongtu_page.last_sync', ['time' => $lastSync ?? '']) }}</span>
                    <a href="{{ $exportUrl }}" class="flt-btn" data-pjax="false" download>
                        <i class="fa fa-download" aria-hidden="true"></i> {{ __('logistics.missing_tongtu_page.export') }}
                    </a>
                </div>
            </div>
            <div class="flt-table-scroll tongtu-table-scroll {{ $orders->isEmpty() ? 'is-empty' : '' }}" tabindex="0" aria-label="{{ __('logistics.missing_tongtu_page.results') }}">
                <table class="results-table">
                    <thead>
                        <tr><th scope="col">{{ __('logistics.missing_tongtu_page.domain') }}</th><th scope="col">{{ __('logistics.missing_tongtu_page.order_number') }}</th>@if($canSyncMissingTongtuStores)<th scope="col" class="store-sync-cell">{{ __('logistics.missing_tongtu_sync.actions') }}</th>@endif</tr>
                    </thead>
                    <tbody>
                        @forelse ($orders as $group)
                            @php
                                $previewSize = 8;
                            @endphp
                            <tr>
                                <td class="{{ $group['domain'] ? 'store-domain' : 'text-muted' }}">{{ $group['domain'] ?? __('logistics.missing_tongtu_page.unmatched_domain') }}</td>
                                <td>
                                    <ul class="order-numbers">
                                        @foreach (array_slice($group['numbers'], 0, $previewSize) as $number)
                                            <li>{{ $number }}</li>
                                        @endforeach
                                    </ul>
                                    @if (count($group['numbers']) > $previewSize)
                                        <details class="more-orders">
                                            <summary>
                                                <span class="expand-orders">{{ __('logistics.missing_tongtu_page.expand_orders', ['count' => count($group['numbers']) - $previewSize]) }}</span>
                                                <span class="collapse-orders">{{ __('logistics.missing_tongtu_page.collapse_orders') }}</span>
                                            </summary>
                                            <ul class="order-numbers">
                                                @foreach (array_slice($group['numbers'], $previewSize) as $number)
                                                    <li>{{ $number }}</li>
                                                @endforeach
                                            </ul>
                                        </details>
                                    @endif
                                </td>
                                @if($canSyncMissingTongtuStores)
                                @php
                                    $syncStore = $missingTongtuSyncStores[$group['domain'] ?? ''] ?? ['store' => null, 'reason' => 'unmatched', 'busy' => false];
                                @endphp
                                <td class="store-sync-cell">
                                    @if($syncStore['store'] && !$syncStore['busy'])
                                    <form method="post" action="{{ route('logistics.shopify-stores.sync', $syncStore['store']) }}" data-loading="page">
                                        @csrf
                                        <button type="submit" class="flt-btn" title="{{ __('logistics.missing_tongtu_sync.hint') }}"><i class="fa fa-refresh" aria-hidden="true"></i> {{ __('logistics.missing_tongtu_sync.sync') }}</button>
                                    </form>
                                    @else
                                    <button type="button" class="flt-btn" disabled>{{ __('logistics.missing_tongtu_sync.unavailable') }}</button>
                                    <small class="text-muted">{{ __('logistics.missing_tongtu_sync.'.($syncStore['busy'] ? 'busy' : $syncStore['reason'])) }}</small>
                                    @endif
                                </td>
                                @endif
                            </tr>
                        @empty
                            <tr><td colspan="{{ $canSyncMissingTongtuStores ? 3 : 2 }}"><div class="flt-empty"><i class="fa fa-search" aria-hidden="true"></i><p>{{ __('logistics.missing_tongtu_page.'.($lastSync ? 'empty_results' : 'empty_unsynced')) }}</p></div></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="flt-pagination tongtu-pagination">
                <span>{{ __('logistics.missing_tongtu_page.page_summary', ['first' => $orders->firstItem() ?? 0, 'last' => $orders->lastItem() ?? 0, 'total' => $orders->total()]) }}</span>
                <form method="get" action="{{ $checkUrl }}" data-loading="region" data-loading-target=".tongtu-result-box">
                    @foreach ($paginationFilters as $key => $value)
                        @if ($key !== 'per_page')<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endif
                    @endforeach
                    <label>{{ __('logistics.orders.list.per_page') }}
                        <select name="per_page" onchange="this.form.requestSubmit()">
                            @foreach ([20, 50, 100] as $size)
                                <option value="{{ $size }}" @selected($orders->perPage() === $size)>{{ $size }}</option>
                            @endforeach
                        </select>
                    </label>
                </form>
                <nav class="tongtu-page-links" aria-label="{{ __('logistics.orders.list.pagination') }}">
                    @if ($orders->previousPageUrl())
                        <a class="flt-btn" href="{{ $orders->previousPageUrl() }}" data-loading="region" data-loading-target=".tongtu-result-box">{{ __('logistics.orders.list.previous') }}</a>
                    @else
                        <button class="flt-btn" type="button" disabled>{{ __('logistics.orders.list.previous') }}</button>
                    @endif
                    <span>{{ $orders->currentPage() }} / {{ $orders->lastPage() }}</span>
                    @if ($orders->nextPageUrl())
                        <a class="flt-btn" href="{{ $orders->nextPageUrl() }}" data-loading="region" data-loading-target=".tongtu-result-box">{{ __('logistics.orders.list.next') }}</a>
                    @else
                        <button class="flt-btn" type="button" disabled>{{ __('logistics.orders.list.next') }}</button>
                    @endif
                </nav>
                <form method="get" action="{{ $checkUrl }}" data-loading="region" data-loading-target=".tongtu-result-box">
                    @foreach ($paginationFilters as $key => $value)
                        <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                    @endforeach
                    <label><span class="flt-sr-only">{{ __('logistics.orders.list.page') }}</span><input type="number" name="page" min="1" max="{{ $orders->lastPage() }}" required placeholder="{{ __('logistics.orders.list.page') }}"></label>
                    <button class="flt-btn flt-primary" type="submit">{{ __('logistics.orders.list.jump') }}</button>
                </form>
            </div>
        </div>
    </section>
@endsection

@push('scripts')
    <script src="{{ asset('assets/erp/tongtu-sync-progress.js') }}?v=2" defer></script>
@endpush
