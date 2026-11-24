@extends('portal.layout')

@section('page_title', __('logistics.shopify_stores.title'))

@php
    $portalOrigin = rtrim(request()->getSchemeAndHttpHost(), '/');
@endphp

@push('styles')
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/logistics.css?v=20261014-1">
    <link rel="stylesheet" href="{{ $portalOrigin }}/css/frontend-list-template.css?v=20261123-19">
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/shopify-stores-list.css?v=6">
@endpush

@section('content')
    <section class="content-header">
        <h1>{{ __('logistics.shopify_stores.title') }} <small>{{ __('logistics.shopify_stores.subtitle') }}</small></h1>
    </section>

    <section class="content logistics-page flt shopify-stores-list">
        @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
        @if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif

        <form method="get" action="{{ route('logistics.shopify-stores.index') }}" class="flt-card flt-filters" data-loading="region" data-loading-target=".shopify-stores-list"
            data-logistics-filters data-search-options="{{ __('frontend_list_template.search_options') }}"
            data-no-options="{{ __('frontend_list_template.no_options') }}" data-clear-choice="{{ __('frontend_list_template.clear_choice') }}">
            <input type="hidden" name="per_page" value="{{ $filters['per_page'] }}">
            <div class="shopify-filter-body">
                <div class="flt-filter-grid">
                    @foreach(['shop_name', 'shop_login_url', 'store_prefix', 'tongtu_prefix', 'user_name', 'team'] as $field)
                        <div class="shopify-filter-field">
                            <label for="shopify-{{ $field }}">{{ __('logistics.shopify_stores.'.$field) }}</label>
                            <input id="shopify-{{ $field }}" name="{{ $field }}" class="form-control" value="{{ $filters[$field] }}" maxlength="2000" placeholder="{{ __('logistics.shopify_stores.search_placeholder') }}">
                            @error($field)<span class="text-danger">{{ $message }}</span>@enderror
                        </div>
                    @endforeach
                    <div class="shopify-filter-field">
                        <label for="shopify-webhook-status">{{ __('logistics.shopify_stores.webhook_status') }}</label>
                        <select id="shopify-webhook-status" name="webhook_status" class="form-control">
                            <option value="">{{ __('logistics.common.all') }}</option>
                            @foreach(__('logistics.shopify_stores.webhook_options') as $value => $label)
                                <option value="{{ $value }}" @selected($filters['webhook_status'] === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="shopify-filter-field">
                        <label for="shopify-sync-status">{{ __('logistics.shopify_stores.sync_status') }}</label>
                        <select id="shopify-sync-status" name="sync_status" class="form-control">
                            <option value="">{{ __('logistics.common.all') }}</option>
                            @foreach(__('logistics.shopify_stores.sync_options') as $value => $label)
                                <option value="{{ $value }}" @selected($filters['sync_status'] === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="shopify-filter-field">
                        <label for="shopify-token-status">{{ __('logistics.shopify_stores.token_status') }}</label>
                        <select id="shopify-token-status" name="token_status" class="form-control">
                            <option value="">{{ __('logistics.common.all') }}</option>
                            @foreach(__('logistics.shopify_stores.token_options') as $value => $label)
                                <option value="{{ $value }}" @selected($filters['token_status'] === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('token_status')<span class="text-danger">{{ $message }}</span>@enderror
                    </div>
                </div>
                <div class="shopify-filter-dates">
                    @foreach(['sync', 'webhook'] as $prefix)
                        <div class="flt-time-range shopify-date-range">
                            @foreach(['from', 'to'] as $bound)
                                @php
                                    $field = $prefix.'_'.$bound;
                                @endphp
                                <div class="shopify-filter-field">
                                    <label for="shopify-{{ $field }}">{{ __('logistics.shopify_stores.'.$field) }}</label>
                                    <input id="shopify-{{ $field }}" name="{{ $field }}" type="date" class="form-control" value="{{ $filters[$field] }}">
                                    @error($field)<span class="text-danger">{{ $message }}</span>@enderror
                                </div>
                            @endforeach
                        </div>
                    @endforeach
                </div>
                <div class="flt-filter-actions">
                <button type="submit" class="flt-btn flt-primary"><i class="fa fa-search"></i> {{ __('logistics.common.search') }}</button>
                <a href="{{ route('logistics.shopify-stores.index') }}" class="flt-btn">{{ __('logistics.common.reset') }}</a>
                </div>
            </div>
        </form>

        @if($activeSyncBatch)
            @php
                $syncProgress = $activeSyncBatch->progressPayload();
                $syncRunning = !$syncProgress['completed'];
                $syncTitles = __('logistics.shopify_stores.progress_status');
                $syncTitle = $syncTitles[$syncProgress['status']] ?? $syncTitles['completed'];
            @endphp
            <div id="shopify-sync-progress"
                 class="logistics-match-progress logistics-shopify-sync-progress"
                 data-url="{{ route('logistics.shopify-stores.sync-progress', $activeSyncBatch) }}"
                 data-completed="{{ $syncProgress['completed'] ? '1' : '0' }}">
                <div class="logistics-match-progress-heading">
                    <span>
                        <i class="fa fa-refresh {{ $syncRunning ? 'fa-spin' : '' }}" data-shopify-sync-icon></i>
                        <strong data-shopify-sync-title>{{ $syncTitle }}</strong>
                    </span>
                    <span data-shopify-sync-percent>{{ $syncProgress['percent'] }}%</span>
                </div>
                <div class="progress">
                    <div class="progress-bar" data-shopify-sync-bar role="progressbar" style="width: {{ $syncProgress['percent'] }}%"></div>
                </div>
                <div class="logistics-match-progress-summary" data-shopify-sync-summary>
                    {{ __('logistics.shopify_stores.progress_summary', $syncProgress) }}
                </div>
                <div class="logistics-shopify-sync-current {{ $syncProgress['current_store'] ? '' : 'hide' }}" data-shopify-sync-current>
                    <i class="fa fa-building-o"></i> {{ __('logistics.shopify_stores.current_store') }}：<strong>{{ $syncProgress['current_store'] }}</strong>
                </div>
                <div class="logistics-match-progress-note">
                    <i class="fa fa-clock-o"></i> {{ __('logistics.shopify_stores.progress_note', $syncProgress) }}
                </div>
                <div class="logistics-shopify-sync-actions">
                    <form method="post" action="{{ route('logistics.shopify-stores.sync-cancel', $activeSyncBatch) }}" data-shopify-sync-cancel-form class="{{ $syncProgress['can_cancel'] ? '' : 'hide' }}">
                        @csrf
                        <input type="hidden" name="return_to" value="{{ request()->fullUrl() }}">
                        <button type="submit" class="btn btn-warning btn-xs" data-confirm="{{ __('logistics.shopify_stores.cancel_confirm') }}" onclick="return confirm(this.dataset.confirm);">
                            <i class="fa fa-stop-circle"></i> {{ __('logistics.shopify_stores.cancel_sync') }}
                        </button>
                    </form>
                </div>
                <div class="logistics-shopify-sync-issues {{ $syncIssueDetails === [] ? 'hide' : '' }}" data-shopify-sync-issues>
                    <details {{ $syncProgress['completed'] && $syncIssueDetails !== [] ? 'open' : '' }}>
                        <summary>{{ __('logistics.shopify_stores.issues') }}（<span data-shopify-sync-issue-count>{{ count($syncIssueDetails) }}</span>）</summary>
                        <div data-shopify-sync-issue-list>
                            @foreach($syncIssueDetails as $issue)
                                <div><strong>{{ $issue['store_name'] }}</strong>：{{ $issue['reason'] }}</div>
                            @endforeach
                        </div>
                    </details>
                </div>
            </div>
        @endif

        <details class="flt-guidance">
            <summary><i class="fa fa-info-circle" aria-hidden="true"></i><strong>{{ __('logistics.shopify_stores.guidance') }}</strong></summary>
            <ul class="flt-guidance-items">
                <li>{{ __('logistics.shopify_stores.scope_hint') }}</li>
                <li>{{ __('logistics.shopify_stores.search_hint') }}</li>
                <li>{{ __('logistics.shopify_stores.filter_hint') }}</li>
                <li>{{ __('logistics.shopify_stores.date_hint') }}</li>
            </ul>
        </details>

        <form id="shopify-batch-sync-form" method="post" action="{{ route('logistics.shopify-stores.batch-sync') }}">
            @csrf
            <input type="hidden" name="return_to" value="{{ request()->fullUrl() }}">
        </form>
        <form id="shopify-batch-webhook-form" method="post" action="{{ route('logistics.shopify-stores.batch-register-webhooks') }}">
            @csrf
            <input type="hidden" name="return_to" value="{{ request()->fullUrl() }}">
        </form>
        <div class="flt-card flt-records">
            <div class="flt-toolbar">
                <div class="flt-selection" aria-live="polite">
                    <span>{{ __('frontend_list_template.records', ['count' => $stores->total()]) }}</span>
                    <span class="flt-toolbar-divider" aria-hidden="true"></span>
                    <span data-shopify-selected-count data-template="{{ __('frontend_list_template.selected_records', ['count' => '__count__']) }}">{{ __('frontend_list_template.selected_records', ['count' => 0]) }}</span>
                    <button type="button" id="shopify-clear-selection" disabled>{{ __('logistics.shopify_stores.clear_selection') }}</button>
                </div>
                <div class="flt-toolbar-actions">
                <button class="flt-btn" type="submit" form="shopify-batch-webhook-form" id="batch-webhook-button" disabled>
                    <i class="fa fa-rss"></i> {{ __('logistics.shopify_stores.batch_webhook') }}
                </button>
                <button class="flt-btn flt-primary" type="submit" form="shopify-batch-sync-form" id="batch-sync-button" disabled>
                    <i class="fa fa-refresh"></i> {{ __('logistics.shopify_stores.batch_sync', ['days' => $manualSyncDays]) }}
                </button>
                </div>
            </div>
            <div class="flt-table-scroll shopify-store-table-scroll {{ $stores->isEmpty() ? 'is-empty' : '' }}" tabindex="0" aria-label="{{ __('logistics.shopify_stores.menu') }}">
                <table class="shopify-store-table">
                    <thead>
                        <tr>
                            <th class="flt-check"><input id="shopify-store-check-all" type="checkbox" aria-label="{{ __('logistics.shopify_stores.check_all') }}"></th>
                            <th scope="col">{{ __('logistics.shopify_stores.shop_name') }}</th>
                            <th scope="col">{{ __('logistics.shopify_stores.login_url') }}</th>
                            <th scope="col">{{ __('logistics.shopify_stores.store_prefix') }}</th>
                            <th scope="col">{{ __('logistics.shopify_stores.tongtu_prefix') }}</th>
                            <th scope="col">{{ __('logistics.shopify_stores.operator') }}</th>
                            <th scope="col">{{ __('logistics.shopify_stores.team') }}</th>
                            <th scope="col">{{ __('logistics.shopify_stores.token_time') }}</th>
                            <th scope="col">{{ __('logistics.shopify_stores.last_sync') }}</th>
                            <th scope="col">{{ __('logistics.shopify_stores.sync_message') }}</th>
                            <th scope="col">{{ __('logistics.shopify_stores.webhook_status') }}</th>
                            <th scope="col">{{ __('logistics.shopify_stores.webhook_time') }}</th>
                            <th scope="col">{{ __('logistics.shopify_stores.webhook_error') }}</th>
                            <th class="logistics-action-column" scope="col">{{ __('logistics.shopify_stores.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($stores as $store)
                            @php
                                $connection = $store->shopifyConnection;
                                $teamLabel = \App\Models\AssistantDomainStore::displayMultiValue($store->team);
                                $tokenLabel = $connection?->token_obtained_at?->timezone('Asia/Shanghai')->format('Y-m-d H:i:s') ?: __('logistics.shopify_stores.token_missing');
                                $tokenTitle = $connection?->token_expires_at?->timezone('Asia/Shanghai')->format('Y-m-d H:i:s') ? __('logistics.shopify_stores.token_until', ['time' => $connection->token_expires_at->timezone('Asia/Shanghai')->format('Y-m-d H:i:s')]) : '';
                                $tokenIsAbnormal = !$connection?->token_obtained_at || !$connection?->token_expires_at?->isFuture();
                                $hasConnectionFailure = $tokenIsAbnormal
                                    || $connection?->webhook_status !== 'active'
                                    || $connection?->last_sync_status === 'failed';
                            @endphp
                            <tr class="{{ $hasConnectionFailure ? 'logistics-row-failed' : '' }}">
                                <td class="logistics-sticky-left"><input class="shopify-store-check" type="checkbox" value="{{ $store->id }}" aria-label="{{ __('logistics.shopify_stores.check_store', ['store' => $store->shop_name]) }}"></td>
                                <td title="{{ $store->shop_name }}">{{ $store->shop_name }}</td>
                                <td class="logistics-url-cell" title="{{ $store->shop_login_url }}">{{ $store->shop_login_url ?: '—' }}</td>
                                <td title="{{ $store->store_prefix }}">{{ $store->store_prefix ?: '—' }}</td>
                                <td title="{{ $store->tongtu_prefix }}">{{ $store->tongtu_prefix ?: '—' }}</td>
                                <td title="{{ $store->user_name }}">{{ $store->user_name ?: '—' }}</td>
                                <td title="{{ $teamLabel }}">{{ $teamLabel ?: '—' }}</td>
                                <td><span title="{{ $tokenTitle }}">{{ $tokenLabel }}</span></td>
                                <td>{{ $connection?->last_synced_at?->timezone('Asia/Shanghai')->format('Y-m-d H:i:s') ?: '—' }}</td>
                                <td class="logistics-message-cell">{{ $connection?->last_sync_message ?: '—' }}</td>
                                <td>{{ __('logistics.shopify_stores.webhook_'.match($connection?->webhook_status) { 'active' => 'active', 'failed' => 'failed', default => 'missing' }) }}</td>
                                <td>{{ $connection?->webhook_registered_at?->timezone('Asia/Shanghai')->format('Y-m-d H:i:s') ?: '—' }}</td>
                                <td class="logistics-message-cell">{{ $connection?->webhook_last_error ?: '—' }}</td>
                                <td class="logistics-actions">
                                    <div class="shopify-store-actions">
                                        <form method="post" action="{{ route('logistics.shopify-stores.register-webhooks', $store) }}" class="logistics-inline-form">
                                            @csrf
                                            <input type="hidden" name="return_to" value="{{ request()->fullUrl() }}">
                                            <button class="flt-btn" type="submit">{{ __('logistics.shopify_stores.webhook_register') }}</button>
                                        </form>
                                        <form method="post" action="{{ route('logistics.shopify-stores.sync', $store) }}" class="logistics-inline-form">
                                            @csrf
                                            <input type="hidden" name="return_to" value="{{ request()->fullUrl() }}">
                                            <button class="flt-btn" type="submit" {{ isset($syncRunning) && $syncRunning ? 'disabled' : '' }}>{{ __('logistics.shopify_stores.sync_days', ['days' => $manualSyncDays]) }}</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if($stores->isEmpty())
                <div class="flt-empty shopify-store-empty"><i class="fa fa-search" aria-hidden="true"></i><h4>{{ __('logistics.shopify_stores.empty') }}</h4><p>{{ __('logistics.shopify_stores.empty_hint') }}</p></div>
            @endif
            <nav class="flt-pagination" aria-label="{{ __('logistics.shopify_stores.pagination') }}" data-loading="region" data-loading-target=".flt-records">
                <form method="get" action="{{ route('logistics.shopify-stores.index') }}">
                    @foreach($filters as $field => $value)
                        @if($field !== 'per_page')<input type="hidden" name="{{ $field }}" value="{{ $value }}">@endif
                    @endforeach
                    <label>{{ __('logistics.shopify_stores.per_page') }}
                        <select name="per_page" onchange="this.form.requestSubmit()">
                            @foreach([20, 50, 100] as $size)<option value="{{ $size }}" @selected($filters['per_page'] === $size)>{{ $size }}</option>@endforeach
                        </select>
                    </label>
                </form>
                @if($stores->previousPageUrl())<a class="flt-btn" href="{{ $stores->previousPageUrl() }}">{{ __('logistics.shopify_stores.previous') }}</a>
                @else<button class="flt-btn" type="button" disabled>{{ __('logistics.shopify_stores.previous') }}</button>@endif
                <strong>{{ __('logistics.shopify_stores.page_label', ['page' => $stores->currentPage(), 'pages' => $stores->lastPage()]) }}</strong>
                @if($stores->nextPageUrl())<a class="flt-btn" href="{{ $stores->nextPageUrl() }}">{{ __('logistics.shopify_stores.next') }}</a>
                @else<button class="flt-btn" type="button" disabled>{{ __('logistics.shopify_stores.next') }}</button>@endif
                <form method="get" action="{{ route('logistics.shopify-stores.index') }}">
                    @foreach($filters as $field => $value)<input type="hidden" name="{{ $field }}" value="{{ $value }}">@endforeach
                    <label><span class="flt-sr-only">{{ __('logistics.shopify_stores.page') }}</span><input name="page" type="number" required min="1" max="{{ $stores->lastPage() }}" step="1" placeholder="{{ __('logistics.shopify_stores.page') }}"></label>
                    <button class="flt-btn flt-primary" type="submit">{{ __('logistics.shopify_stores.jump') }}</button>
                </form>
            </nav>
        </div>
    </section>
@endsection

@push('scripts')
    <script src="{{ $portalOrigin }}/assets/erp/shopify-orders-filters.js?v=3" defer></script>
    <script>
        (function () {
            var all = document.getElementById('shopify-store-check-all');
            var syncButton = document.getElementById('batch-sync-button');
            var webhookButton = document.getElementById('batch-webhook-button');
            var syncForm = document.getElementById('shopify-batch-sync-form');
            var webhookForm = document.getElementById('shopify-batch-webhook-form');
            var clearSelection = document.getElementById('shopify-clear-selection');
            var counts = document.querySelectorAll('[data-shopify-selected-count]');
            var syncLocked = {{ isset($syncRunning) && $syncRunning ? 'true' : 'false' }};
            var items = function () { return Array.prototype.slice.call(document.querySelectorAll('.shopify-store-check')); };
            var selected = function () { return items().filter(function (item) { return item.checked; }); };
            var populate = function (form) {
                form.querySelectorAll('input[data-shopify-selection]').forEach(function (input) { input.remove(); });
                selected().forEach(function (item) {
                    var input = document.createElement('input');
                    input.type = 'hidden'; input.name = 'selected_ids[]'; input.value = item.value;
                    input.setAttribute('data-shopify-selection', '1');
                    form.appendChild(input);
                });
            };
            var refresh = function () {
                var selectedItems = selected();
                counts.forEach(function (count) { count.textContent = count.dataset.template.replace('__count__', selectedItems.length); });
                clearSelection.disabled = selectedItems.length === 0;
                all.disabled = items().length === 0;
                items().forEach(function (item) { item.closest('tr').dataset.selected = item.checked ? '1' : '0'; });
                syncButton.disabled = syncLocked || selectedItems.length === 0;
                webhookButton.disabled = selectedItems.length === 0;
                all.checked = items().length > 0 && selectedItems.length === items().length;
                all.indeterminate = selectedItems.length > 0 && !all.checked;
            };
            clearSelection.addEventListener('click', function () { items().forEach(function (item) { item.checked = false; }); refresh(); });
            all.addEventListener('change', function () { items().forEach(function (item) { item.checked = all.checked; }); refresh(); });
            items().forEach(function (item) { item.addEventListener('change', refresh); });
            syncForm.addEventListener('submit', function () { populate(syncForm); });
            webhookForm.addEventListener('submit', function () { populate(webhookForm); });
            refresh();
        }());

        (function () {
            var panel = document.getElementById('shopify-sync-progress');
            if (!panel || panel.getAttribute('data-completed') === '1') {
                return;
            }

            var title = panel.querySelector('[data-shopify-sync-title]');
            var icon = panel.querySelector('[data-shopify-sync-icon]');
            var percent = panel.querySelector('[data-shopify-sync-percent]');
            var bar = panel.querySelector('[data-shopify-sync-bar]');
            var summary = panel.querySelector('[data-shopify-sync-summary]');
            var current = panel.querySelector('[data-shopify-sync-current]');
            var cancelForm = panel.querySelector('[data-shopify-sync-cancel-form]');
            var issues = panel.querySelector('[data-shopify-sync-issues]');
            var issueCount = panel.querySelector('[data-shopify-sync-issue-count]');
            var issueList = panel.querySelector('[data-shopify-sync-issue-list]');
            var reloading = false;
            var labels = @json(__('logistics.shopify_stores.progress_status'));
            var summaryText = @json(__('logistics.shopify_stores.progress_summary'));
            var currentText = @json(__('logistics.shopify_stores.current_store'));
            var escapeHtml = function (value) {
                var node = document.createElement('div');
                node.textContent = value == null ? '' : String(value);
                return node.innerHTML;
            };
            var renderIssues = function (rows) {
                rows = Array.isArray(rows) ? rows : [];
                issueCount.textContent = rows.length;
                issueList.innerHTML = rows.map(function (row) {
                    return '<div><strong>' + escapeHtml(row.store_name) + '</strong>：' + escapeHtml(row.reason) + '</div>';
                }).join('');
                issues.classList.toggle('hide', rows.length === 0);
            };
            var render = function (data) {
                title.textContent = labels[data.status] || labels.processing;
                percent.textContent = data.percent + '%';
                bar.style.width = data.percent + '%';
                summary.textContent = summaryText.replace(/:([a-z_]+)/g, function (key, name) { return data[name] ?? key; });
                if (data.current_store) {
                    current.innerHTML = '<i class="fa fa-building-o"></i> ' + escapeHtml(currentText) + '：<strong>' + escapeHtml(data.current_store) + '</strong>';
                    current.classList.remove('hide');
                } else {
                    current.classList.add('hide');
                }
                cancelForm.classList.toggle('hide', !data.can_cancel);
                renderIssues(data.issues);
                if (data.completed) {
                    icon.classList.remove('fa-spin');
                    panel.setAttribute('data-completed', '1');
                    window.clearInterval(timer);
                    if (!reloading) {
                        reloading = true;
                        window.setTimeout(function () { window.location.reload(); }, 900);
                    }
                }
            };
            var poll = function () {
                window.fetch(panel.getAttribute('data-url'), {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin'
                }).then(function (response) {
                    if (!response.ok) { throw new Error('HTTP ' + response.status); }
                    return response.json();
                }).then(render).catch(function () {
                    title.textContent = @json(__('logistics.shopify_stores.poll_failed'));
                });
            };

            var timer = window.setInterval(poll, 3000);
            poll();
        }());
    </script>
@endpush
