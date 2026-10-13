@extends('portal.layout')

@section('page_title', __('assistant.shopify_analytics.title'))

@php
    $portalOrigin = rtrim(request()->getSchemeAndHttpHost(), '/');
    $t = static fn (string $key, array $replace = []): string => __('assistant.shopify_analytics.'.$key, $replace);
    $money = static fn (mixed $value): string => number_format((float) $value, 2);
    $number = static fn (mixed $value): string => number_format((int) $value);
    $usd = $t('currency.usd_prefix');
    $currencyPrefix = static function (?string $currency): string {
        return match (strtoupper((string) $currency)) {
            'CNY' => 'CNY ',
            'USD' => '$',
            'EUR' => 'EUR ',
            'GBP' => 'GBP ',
            'JPY' => 'JPY ',
            default => filled($currency) ? strtoupper((string) $currency).' ' : '',
        };
    };
    $sortUrl = static function (string $field) use ($filters): string {
        $direction = $filters['sort'] === $field && $filters['direction'] === 'asc' ? 'desc' : 'asc';
        $query = [
            ...$filters,
            'domains' => implode(',', $filters['domains']),
            'sort' => $field,
            'direction' => $direction,
        ];

        return route('assistant.shopify-analytics.index', $query);
    };
    $syncProgress = $activeSyncBatch?->progressPayload();
    $shopifyAnalyticsJavascript = [
        'selectedCount' => $t('batch.selected_count', ['count' => '__count__']),
        'eligibleCount' => $t('batch.eligible_count', ['count' => '__count__']),
        'selectPage' => $t('batch.select_page'),
        'clearPage' => $t('batch.clear_page'),
        'syncProgressUrl' => $activeSyncBatch === null
            ? null
            : route('assistant.shopify-analytics.sync-progress', $activeSyncBatch),
        'syncPollInterval' => 5000,
        'progressTemplates' => [
            'summary' => $t('batch.progress_summary', [
                'processed' => '__processed__',
                'total' => '__total__',
                'success' => '__success__',
                'failed' => '__failed__',
            ]),
            'waiting' => $t('batch.progress_waiting'),
            'current' => $t('batch.progress_current', ['name' => '__name__']),
            'completed' => $t('batch.progress_completed'),
        ],
    ];
@endphp

@push('styles')
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/assistant-shopify-analytics-20261012-1.css?v=20261012-6">
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/assistant-shopify-analytics-20261012-3.css?v=20261012-1">
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/assistant-shopify-analytics-20261013-1.css?v=20261013-1">
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/assistant-shopify-analytics-20261013-2.css?v=20261013-2">
@endpush

@section('content')
    <section class="content-header">
        <h1>{{ $t('title') }} <small>{{ $t('subtitle') }}</small></h1>
    </section>

    <section class="content assistant-shopify-analytics-page">
        <div class="assistant-shopify-analytics-summary" role="status">
            <div class="assistant-shopify-summary-card assistant-shopify-summary-card-primary">
                <span class="assistant-shopify-summary-icon"><i class="fa fa-shopping-bag"></i></span>
                <div>
                    <span class="assistant-shopify-summary-label">{{ $t('overview.stores') }}</span>
                    <strong>{{ $number($summary['stores_count']) }}</strong>
                </div>
            </div>
            <div class="assistant-shopify-summary-card">
                <span class="assistant-shopify-summary-icon"><i class="fa fa-cloud-download"></i></span>
                <div>
                    <span class="assistant-shopify-summary-label">{{ $t('overview.synced_stores') }}</span>
                    <strong>{{ $number($summary['synced_stores_count']) }}</strong>
                </div>
            </div>
            <div class="assistant-shopify-summary-card">
                <span class="assistant-shopify-summary-icon"><i class="fa fa-usd"></i></span>
                <div>
                    <span class="assistant-shopify-summary-label">{{ $t('overview.total_sales') }}</span>
                    <strong>{{ $usd }}{{ $money($summary['total_sales']) }}</strong>
                </div>
            </div>
            <div class="assistant-shopify-summary-card">
                <span class="assistant-shopify-summary-icon"><i class="fa fa-list-alt"></i></span>
                <div>
                    <span class="assistant-shopify-summary-label">{{ $t('overview.orders') }}</span>
                    <strong>{{ $number($summary['orders_count']) }}</strong>
                </div>
            </div>
            <div class="assistant-shopify-summary-card">
                <span class="assistant-shopify-summary-icon"><i class="fa fa-truck"></i></span>
                <div>
                    <span class="assistant-shopify-summary-label">{{ $t('overview.fulfilled_orders') }}</span>
                    <strong>{{ $number($summary['fulfilled_orders_count']) }}</strong>
                </div>
            </div>
            <div class="assistant-shopify-summary-card">
                <span class="assistant-shopify-summary-icon"><i class="fa fa-reply"></i></span>
                <div>
                    <span class="assistant-shopify-summary-label">{{ $t('overview.refunds') }}</span>
                    <strong>{{ $usd }}{{ $money($summary['refund_amount']) }}</strong>
                </div>
            </div>
            <div class="assistant-shopify-summary-card">
                <span class="assistant-shopify-summary-icon"><i class="fa fa-line-chart"></i></span>
                <div>
                    <span class="assistant-shopify-summary-label">{{ $t('overview.net_sales') }}</span>
                    <strong>{{ $usd }}{{ $money($summary['net_sales']) }}</strong>
                </div>
            </div>
        </div>

        <div class="box box-default assistant-shopify-filter-box">
            <div class="box-header with-border">
                <h3 class="box-title"><i class="fa fa-filter"></i> {{ $t('filters.title') }}</h3>
            </div>
            <form method="get" action="{{ route('assistant.shopify-analytics.index') }}" class="box-body assistant-shopify-filter-form" data-loading="region" data-loading-target=".assistant-shopify-analytics-page">
                <div class="form-group">
                    <label for="assistant-shopify-shop-name">{{ $t('filters.shop_name') }}</label>
                    <input id="assistant-shopify-shop-name" name="shop_name" class="form-control" value="{{ $filters['shop_name'] }}" placeholder="{{ $t('filters.shop_name_placeholder') }}">
                </div>
                <div class="form-group">
                    <label for="assistant-shopify-domains">{{ $t('filters.domains') }}</label>
                    <input id="assistant-shopify-domains" name="domains" class="form-control" value="{{ implode(', ', $filters['domains']) }}" placeholder="{{ $t('filters.domains_placeholder') }}">
                </div>
                <div class="form-group">
                    <label for="assistant-shopify-timezone">{{ $t('filters.timezone') }}</label>
                    <select id="assistant-shopify-timezone" name="timezone" class="form-control">
                        @foreach ($timezoneOptions as $value => $label)
                            <option value="{{ $value }}" @selected($filters['timezone'] === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group assistant-shopify-date-filter">
                    <label for="assistant-shopify-from-date">{{ $t('filters.from_date') }}</label>
                    <input id="assistant-shopify-from-date" name="from_date" type="date" class="form-control" value="{{ $filters['from_date'] }}">
                </div>
                <div class="form-group assistant-shopify-date-filter">
                    <label for="assistant-shopify-to-date">{{ $t('filters.to_date') }}</label>
                    <input id="assistant-shopify-to-date" name="to_date" type="date" class="form-control" value="{{ $filters['to_date'] }}">
                </div>
                <input type="hidden" name="per_page" value="{{ $filters['per_page'] }}">
                <div class="assistant-shopify-filter-actions">
                    <button type="submit" class="btn btn-primary"><i class="fa fa-search"></i> {{ $t('filters.submit') }}</button>
                    <a href="{{ route('assistant.shopify-analytics.index') }}" class="btn btn-default">{{ $t('filters.reset') }}</a>
                </div>
            </form>
            <div class="box-footer assistant-shopify-overview-notice">
                <i class="fa fa-info-circle"></i> {{ $t('overview.notice') }}
            </div>
        </div>

        <form id="assistant-shopify-batch-refresh-form" method="post" action="{{ route('assistant.shopify-analytics.refresh-batch') }}" data-loading="button">
            @csrf
            <input type="hidden" name="return_to" value="{{ request()->fullUrl() }}">
            <input type="hidden" name="timezone" value="{{ $filters['timezone'] }}">
            <input type="hidden" name="from_date" value="{{ $filters['from_date'] }}">
            <input type="hidden" name="to_date" value="{{ $filters['to_date'] }}">
        </form>

        @if ($syncProgress !== null)
            <div class="assistant-shopify-sync-progress" data-shopify-sync-progress>
                <div class="assistant-shopify-sync-progress-heading">
                    <strong><i class="fa fa-refresh"></i> {{ $t('batch.progress_title') }}</strong>
                    <span data-shopify-progress-summary>
                        {{ $t('batch.progress_summary', [
                            'processed' => $syncProgress['processed'],
                            'total' => $syncProgress['total'],
                            'success' => $syncProgress['success'],
                            'failed' => $syncProgress['failed'],
                        ]) }}
                    </span>
                </div>
                <div class="progress progress-sm assistant-shopify-sync-progress-bar" aria-label="{{ $t('batch.progress_title') }}">
                    <div class="progress-bar progress-bar-info" data-shopify-progress-bar style="width: {{ $syncProgress['percent'] }}%"></div>
                </div>
                <div class="assistant-shopify-sync-progress-current" data-shopify-progress-current>
                    @if ($syncProgress['completed'])
                        {{ $t('batch.progress_completed') }}
                    @elseif ($syncProgress['current_store'])
                        {{ $t('batch.progress_current', ['name' => $syncProgress['current_store']]) }}
                    @else
                        {{ $t('batch.progress_waiting') }}
                    @endif
                </div>
                <div class="assistant-shopify-sync-progress-issues" data-shopify-progress-issues @if ($syncIssues === []) hidden @endif>
                    @foreach ($syncIssues as $issue)
                        <div class="assistant-shopify-sync-progress-issue">
                            <strong>{{ $issue['store_name'] }}</strong><span>{{ $issue['reason'] }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        <div id="assistant-shopify-analytics-list" class="box box-default assistant-shopify-list-box" aria-live="polite">
            <div class="assistant-shopify-batch-toolbar">
                <button type="button" class="btn btn-default btn-sm" data-shopify-select-page>
                    {{ $t('batch.select_page') }}
                </button>
                <strong class="assistant-shopify-selection-summary">
                    <span data-shopify-selection-count>{{ $t('batch.selected_count', ['count' => 0]) }}</span>
                    <span data-shopify-eligible-count>{{ $t('batch.eligible_count', ['count' => 0]) }}</span>
                </strong>
                <button type="submit" form="assistant-shopify-batch-refresh-form" class="btn btn-info btn-sm" disabled data-shopify-batch-refresh>
                    <i class="fa fa-refresh"></i> {{ $t('batch.refresh_selected') }}
                </button>
            </div>

            <div class="assistant-shopify-table-scroll">
                <table class="table table-bordered table-hover assistant-shopify-list-table">
                    <thead>
                    <tr>
                        <th class="assistant-shopify-selection-column">
                            <input type="checkbox" data-shopify-select-all aria-label="{{ $t('batch.select_page') }}">
                        </th>
                        @foreach ([
                            'shop_name' => $t('table.store'),
                            'domain' => $t('table.domain'),
                            'shop_status' => $t('table.shop_status'),
                            'team' => $t('table.team'),
                            'email_purchased' => $t('table.email_purchased'),
                            'shop_timezone' => $t('table.shop_timezone'),
                            'total_sales' => $t('metrics.total_sales'),
                            'original_total_sales' => $t('metrics.original_total_sales'),
                            'orders_count' => $t('metrics.orders'),
                            'returning_customer_rate' => $t('metrics.returning_customer_rate'),
                            'fulfilled_orders_count' => $t('metrics.fulfilled_orders'),
                            'refund_amount' => $t('metrics.refund_amount'),
                            'net_sales' => $t('metrics.net_sales'),
                            'average_order_value' => $t('metrics.average_order_value'),
                            'sync_status' => $t('table.sync_status'),
                            'synced_at' => $t('table.last_synced_at'),
                        ] as $field => $label)
                            <th class="assistant-shopify-sortable @if ($filters['sort'] === $field) is-sorted @endif">
                                <a href="{{ $sortUrl($field) }}" data-loading="region" data-loading-target=".assistant-shopify-analytics-page">
                                    {{ $label }}
                                    <i class="fa @if ($filters['sort'] === $field && $filters['direction'] === 'asc') fa-sort-asc @elseif ($filters['sort'] === $field) fa-sort-desc @else fa-sort @endif"></i>
                                </a>
                            </th>
                        @endforeach
                        <th class="assistant-shopify-sortable @if ($filters['sort'] === 'report_period') is-sorted @endif">
                            <a href="{{ $sortUrl('report_period') }}" data-loading="region" data-loading-target=".assistant-shopify-analytics-page">
                                {{ $t('table.report_period') }}
                                <i class="fa @if ($filters['sort'] === 'report_period' && $filters['direction'] === 'asc') fa-sort-asc @elseif ($filters['sort'] === 'report_period') fa-sort-desc @else fa-sort @endif"></i>
                            </a>
                        </th>
                        <th class="assistant-shopify-action-column">{{ $t('table.actions') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($stores as $store)
                        @php
                            $metric = $store->shopifyAnalyticsPeriod;
                            $status = $metric?->sync_status ?: \App\Models\AssistantShopifyStoreMetric::STATUS_NOT_SYNCED;
                            $statusLabel = $metricStatuses[$status] ?? $metricStatuses[\App\Models\AssistantShopifyStoreMetric::STATUS_NOT_SYNCED];
                            $hasSnapshot = $metric?->sync_status === \App\Models\AssistantShopifyStoreMetric::STATUS_SUCCESS;
                            $isInactive = $store->shop_status !== \App\Models\AssistantDomainStore::SHOP_STATUS_IN_USE;
                            $isConfigured = filled($store->shopify_client_id) && filled($store->shopify_client_secret);
                            $isBatchRefreshable = !$isInactive && $isConfigured;
                            $selectionUnavailableReason = $isInactive
                                ? $t('batch.inactive_unavailable')
                                : ($isConfigured ? '' : $t('batch.not_configured_unavailable'));
                            $timezone = $metric?->shop_timezone ?: $t('filters.timezone_pending');
                            $reportDate = $metric?->from_date && $metric?->to_date
                                ? $metric->from_date->format('Y-m-d').' ~ '.$metric->to_date->format('Y-m-d')
                                : $t('card.not_available');
                            $syncedAt = $metric?->synced_at?->timezone('Asia/Shanghai')->format('Y-m-d H:i') ?: $t('card.not_available');
                            $returningRate = $hasSnapshot ? number_format((float) $metric->returning_customer_rate, 2).'%' : $t('card.not_available');
                            $failureReasonKey = $metric?->sync_message ?: match ($status) {
                                \App\Models\AssistantShopifyStoreMetric::STATUS_NOT_CONFIGURED => 'credentials_missing',
                                \App\Models\AssistantShopifyStoreMetric::STATUS_PERMISSION_DENIED => 'reports_permission_denied',
                                \App\Models\AssistantShopifyStoreMetric::STATUS_FAILED => 'unknown',
                                default => null,
                            };
                            $failureReason = $failureReasonKey === null ? null : $t('reasons.'.$failureReasonKey);
                            $singleRefreshFormId = 'assistant-shopify-refresh-'.$store->getKey();
                            $originalSales = $hasSnapshot
                                ? $currencyPrefix($metric->original_currency_code).$money($metric->original_total_sales)
                                : $t('card.not_available');
                        @endphp
                        <tr class="{{ $isInactive ? 'is-inactive' : '' }}">
                            <td class="assistant-shopify-selection-column">
                                <input type="checkbox" form="assistant-shopify-batch-refresh-form" name="selected_ids[]" value="{{ $store->getKey() }}" data-shopify-select title="{{ $selectionUnavailableReason }}" @disabled(!$isBatchRefreshable)>
                            </td>
                            <td class="assistant-shopify-store-cell"><strong>{{ $store->shop_name }}</strong></td>
                            <td class="assistant-shopify-domain-cell" title="{{ $store->domain }}">{{ $store->domain }}</td>
                            <td>{{ \App\Models\AssistantDomainStore::shopStatusLabels()[$store->shop_status] ?? $store->shop_status }}</td>
                            <td>{{ \App\Models\AssistantDomainStore::displayMultiValue($store->team) ?: $t('card.not_available') }}</td>
                            <td><span class="label {{ $store->email_purchased ? 'label-info' : 'label-default' }}">{{ $store->email_purchased ? $t('common.yes') : $t('common.no') }}</span></td>
                            <td title="{{ $timezone }}"><span class="assistant-shopify-value-tag assistant-shopify-value-tag-timezone">{{ $timezone }}</span></td>
                            <td><span class="assistant-shopify-value-tag assistant-shopify-value-tag-money">{{ $hasSnapshot ? $usd.$money($metric->total_sales) : $t('card.not_available') }}</span></td>
                            <td><span class="assistant-shopify-value-tag assistant-shopify-value-tag-money">{{ $originalSales }}</span></td>
                            <td><span class="assistant-shopify-value-tag assistant-shopify-value-tag-count">{{ $hasSnapshot ? $number($metric->orders_count) : $t('card.not_available') }}</span></td>
                            <td><span class="assistant-shopify-value-tag assistant-shopify-value-tag-rate">{{ $returningRate }}</span></td>
                            <td><span class="assistant-shopify-value-tag assistant-shopify-value-tag-count">{{ $hasSnapshot ? $number($metric->fulfilled_orders_count) : $t('card.not_available') }}</span></td>
                            <td><span class="assistant-shopify-value-tag assistant-shopify-value-tag-money">{{ $hasSnapshot ? $usd.$money($metric->refund_amount) : $t('card.not_available') }}</span></td>
                            <td><span class="assistant-shopify-value-tag assistant-shopify-value-tag-money">{{ $hasSnapshot ? $usd.$money($metric->net_sales) : $t('card.not_available') }}</span></td>
                            <td><span class="assistant-shopify-value-tag assistant-shopify-value-tag-money">{{ $hasSnapshot ? $usd.$money($metric->average_order_value) : $t('card.not_available') }}</span></td>
                            <td class="assistant-shopify-sync-status-cell">
                                <span class="label {{ \App\Models\AssistantShopifyStoreMetric::statusClass($status) }}">{{ $statusLabel }}</span>
                                @if ($failureReason !== null)
                                    <span class="assistant-shopify-failure-reason" title="{{ $failureReason }}">{{ $failureReason }}</span>
                                @endif
                            </td>
                            <td>{{ $syncedAt }}</td>
                            <td><span class="assistant-shopify-value-tag assistant-shopify-value-tag-date">{{ $reportDate }}</span></td>
                            <td class="assistant-shopify-action-column">
                                @if ($isInactive)
                                    <button type="button" class="btn btn-default btn-xs" disabled><i class="fa fa-ban"></i> {{ $t('actions.inactive') }}</button>
                                @else
                                    <form id="{{ $singleRefreshFormId }}" method="post" action="{{ route('assistant.shopify-analytics.refresh', $store) }}" data-loading="button">
                                        @csrf
                                        <input type="hidden" name="return_to" value="{{ request()->fullUrl() }}">
                                        <input type="hidden" name="timezone" value="{{ $filters['timezone'] }}">
                                        <input type="hidden" name="from_date" value="{{ $filters['from_date'] }}">
                                        <input type="hidden" name="to_date" value="{{ $filters['to_date'] }}">
                                    </form>
                                    <button type="submit" form="{{ $singleRefreshFormId }}" class="btn btn-primary btn-xs"><i class="fa fa-refresh"></i> {{ $t('actions.refresh') }}</button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="19" class="assistant-shopify-empty-state">
                                <i class="fa fa-shopping-bag"></i>
                                <p>{{ $t('card.no_snapshot') }}</p>
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        @include('portals.assistant.shopify-analytics.partials.pagination', ['stores' => $stores, 'filters' => $filters])
    </section>
@endsection

@push('scripts')
    <script>
        window.ErpAssistantShopifyAnalyticsConfig = @json($shopifyAnalyticsJavascript);
    </script>
    <script src="{{ $portalOrigin }}/assets/erp/assistant-shopify-analytics-20261012-3.js?v=20261012-1"></script>
    @if (session('success'))
        <script>
            window.ErpAdminNotifier.notify({type: 'success', title: @json($t('messages.success_title')), message: @json(session('success'))});
        </script>
    @endif
    @if (session('error'))
        <script>
            window.ErpAdminNotifier.notify({type: 'error', title: @json($t('messages.error_title')), message: @json(session('error'))});
        </script>
    @endif
@endpush
