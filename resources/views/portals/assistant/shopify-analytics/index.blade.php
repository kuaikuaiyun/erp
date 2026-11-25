@extends('portal.layout')

@section('page_title', __('assistant.shopify_analytics.title'))

@php
    $portalOrigin = rtrim(request()->getSchemeAndHttpHost(), '/');
    $t = static fn (string $key, array $replace = []): string => __('assistant.shopify_analytics.'.$key, $replace);
    $money = static fn (mixed $value): string => number_format((float) $value, 2);
    $number = static fn (mixed $value): string => number_format((int) $value);
    $rateNumber = static function (float $rate): string {
        return rtrim(rtrim(number_format($rate, 6, '.', ''), '0'), '.');
    };
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
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/assistant-shopify-analytics-20261013-3.css?v=20261013-3">
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/assistant-shopify-analytics-20261014-1.css?v=20261014-1">
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/assistant-shopify-analytics-20261014-2.css?v=20261014-2">
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/assistant-shopify-analytics-20261020-1.css?v=20261023-2">
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/assistant-team-picker-20261023-1.css?v=20261023-1">
    <link rel="stylesheet" href="{{ asset('css/assistant-store-analytics-template-controls.css') }}?v=20261124-1">
    <style>
        .assistant-email-reminder-alert{background:#fff8e5;border:1px solid #f2cf79;border-left:4px solid #e6a51f;border-radius:7px;color:#74520d;margin-bottom:14px;padding:11px 14px}.assistant-email-reminder-alert summary{align-items:center;cursor:pointer;display:flex;gap:10px;list-style:none}.assistant-email-reminder-alert summary::-webkit-details-marker{display:none}.assistant-email-reminder-alert summary strong{font-size:15px}.assistant-email-reminder-alert summary .fa-angle-down{margin-left:auto;transition:transform .2s}.assistant-email-reminder-alert[open] summary .fa-angle-down{transform:rotate(180deg)}.assistant-email-reminder-list{background:#fff;border:1px solid #efdda9;border-radius:6px;margin-top:11px;max-height:360px;overflow:auto}.assistant-email-reminder-list table{margin:0}.assistant-email-reminder-list th{background:#fffaf0;color:#725514;position:sticky;top:0;z-index:1}.assistant-email-reminder-list td,.assistant-email-reminder-list th{padding:8px 10px!important;vertical-align:middle!important}.assistant-email-reminder-status{display:inline-block;border-radius:12px;font-size:12px;font-weight:600;padding:4px 9px;white-space:nowrap}.assistant-email-reminder-status.is-purchased{background:#dff5ea;color:#178455}.assistant-email-reminder-status.is-exempt{background:#e8eef5;color:#5a6e82}.assistant-email-reminder-status.is-warning{background:#fff0cf;color:#b16b00}.assistant-email-reminder-status.is-idle{background:#f1f3f5;color:#87939e}.assistant-email-reminder-form{display:inline-block;margin-left:4px}
    </style>
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

        <div class="assistant-shopify-subscription-summary" role="status" aria-label="{{ $t('subscriptions.title') }}">
            @foreach ([
                ['key' => 'regular', 'icon' => 'fa-shopping-cart'],
                ['key' => 'subscribed', 'icon' => 'fa-check-circle'],
                ['key' => 'recurring', 'icon' => 'fa-repeat'],
            ] as $subscriptionCard)
                @php
                    $subscriptionKey = $subscriptionCard['key'];
                @endphp
                <div class="assistant-shopify-subscription-card assistant-shopify-subscription-card-{{ $subscriptionKey }}">
                    <div class="assistant-shopify-subscription-heading">
                        <span class="assistant-shopify-subscription-icon"><i class="fa {{ $subscriptionCard['icon'] }}"></i></span>
                        <strong>{{ $t('subscriptions.'.$subscriptionKey) }}</strong>
                    </div>
                    <div class="assistant-shopify-subscription-metrics">
                        <span>
                            <small>{{ $t('subscriptions.orders') }}</small>
                            <strong>{{ $number($summary[$subscriptionKey.'_orders_count']) }}</strong>
                        </span>
                        <span>
                            <small>{{ $t('subscriptions.sales') }}</small>
                            <strong>{{ $usd }}{{ $money($summary[$subscriptionKey.'_total_sales']) }}</strong>
                        </span>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="box box-default assistant-shopify-filter-box">
            <div class="box-header with-border">
                <h3 class="box-title"><i class="fa fa-filter"></i> {{ $t('filters.title') }}</h3>
            </div>
            <form method="get" action="{{ route('assistant.shopify-analytics.index') }}" class="box-body assistant-shopify-filter-form" data-loading="region" data-loading-target=".assistant-shopify-analytics-page">
                <div class="assistant-shopify-filter-primary-row">
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
                        <select id="assistant-shopify-timezone" name="timezone" class="form-control" data-filter-multiselect data-placeholder="请选择统计时区" data-search-placeholder="搜索统计时区">
                            @foreach ($timezoneOptions as $value => $label)
                                <option value="{{ $value }}" @selected($filters['timezone'] === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group">
                        <label id="assistant-shopify-team-label">{{ $t('table.team') }}</label>
                        <select class="form-control" name="team[]" multiple data-filter-multiselect data-placeholder="{{ $t('filters.team_all') }}" data-search-placeholder="搜索团队" data-no-results="{{ $t('filters.team_empty') }}" data-select-all-text="全选" data-clear-text="{{ $t('filters.team_clear') }}">@foreach (collect($teamOptions)->merge($filters['team'])->unique()->values() as $teamOption)<option value="{{ $teamOption }}" @selected(in_array($teamOption, $filters['team'], true))>{{ $teamOption }}</option>@endforeach</select>
                    </div>
                    <div class="form-group">
                        <label for="assistant-shopify-owner-assignment">{{ $t('filters.owner_assignment') }}</label>
                        <select id="assistant-shopify-owner-assignment" name="owner_assignment" class="form-control" data-filter-multiselect data-placeholder="{{ __('assistant.domain_stores.common.all') }}" data-search-placeholder="搜索归属状态">
                            <option value="">{{ __('assistant.domain_stores.common.all') }}</option>
                            <option value="assigned" @selected($filters['owner_assignment'] === 'assigned')>{{ $t('filters.owner_assigned') }}</option>
                            <option value="unassigned" @selected($filters['owner_assignment'] === 'unassigned')>{{ $t('filters.owner_unassigned') }}</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="assistant-shopify-email-purchased">{{ $t('table.email_purchased') }}</label>
                        <select id="assistant-shopify-email-purchased" name="email_purchased" class="form-control" data-filter-multiselect data-placeholder="{{ __('assistant.domain_stores.common.all') }}" data-search-placeholder="搜索邮箱购买状态">
                            <option value="">{{ __('assistant.domain_stores.common.all') }}</option>
                            <option value="1" @selected($filters['email_purchased'] === '1')>{{ __('assistant.domain_stores.common.yes') }}</option>
                            <option value="0" @selected($filters['email_purchased'] === '0')>{{ __('assistant.domain_stores.common.no') }}</option>
                        </select>
                    </div>
                </div>
                <div class="assistant-shopify-filter-secondary-row">
                    <div class="form-group assistant-shopify-date-filter">
                        <label for="assistant-shopify-from-date">{{ $t('filters.from_date') }}</label>
                        <input id="assistant-shopify-from-date" name="from_date" type="date" class="form-control" value="{{ $filters['from_date'] }}">
                    </div>
                    <div class="form-group assistant-shopify-date-filter">
                        <label for="assistant-shopify-to-date">{{ $t('filters.to_date') }}</label>
                        <input id="assistant-shopify-to-date" name="to_date" type="date" class="form-control" value="{{ $filters['to_date'] }}">
                    </div>
                    <div class="assistant-shopify-filter-actions">
                        <button type="submit" class="btn btn-primary"><i class="fa fa-search"></i> {{ $t('filters.submit') }}</button>
                        <button type="button" class="btn btn-success" data-toggle="modal" data-target="#assistant-shopify-export-modal">
                            <i class="fa fa-download"></i> {{ $t('actions.export_data') }}
                        </button>
                        <a href="{{ route('assistant.shopify-analytics.index') }}" class="btn btn-default">{{ $t('filters.reset') }}</a>
                    </div>
                </div>
                <input type="hidden" name="per_page" value="{{ $filters['per_page'] }}">
            </form>
            <div class="box-footer assistant-shopify-overview-notice">
                <i class="fa fa-info-circle"></i> {{ $t('overview.notice') }} {{ $t('overview.reference_rate_notice') }}
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

        @if (($summary['email_purchase_reminder_count'] ?? 0) > 0)
            <details class="assistant-email-reminder-alert">
                <summary><i class="fa fa-envelope-o fa-lg"></i><div><strong>{{ $number($summary['email_purchase_reminder_count']) }} 家历史有出单店铺尚未购买邮箱</strong><div>无需筛选，点击查看该项目全部具体店铺；确认无需购买的店铺可直接关闭提醒。</div></div><i class="fa fa-angle-down"></i></summary>
                <div class="assistant-email-reminder-list table-responsive"><table class="table table-condensed"><thead><tr><th>店铺</th><th>域名</th><th>邮箱购买</th><th>归属人</th><th>历史订单数</th><th>历史营业额</th><th>操作</th></tr></thead><tbody>
                @foreach($emailReminderStores as $reminderStore)
                    <tr><td><strong>{{ $reminderStore->shop_name ?: '—' }}</strong></td><td>{{ $reminderStore->domain ?: '—' }}</td><td><span class="assistant-email-reminder-status is-warning">未购买</span></td><td>{{ $reminderStore->ownerUser?->name ?: $reminderStore->ownerUser?->username ?: '未归属' }}</td><td>{{ $number($reminderStore->analytics_orders_count) }}</td><td>{{ $usd }}{{ $money($reminderStore->analytics_total_sales) }}</td><td><form class="assistant-email-reminder-form" method="post" action="{{ route('assistant.store-analytics.email-purchase-reminder.update',$reminderStore) }}" data-email-reminder-form data-exempt="1">@csrf @method('PATCH')<input type="hidden" name="exempt" value="1"><input type="hidden" name="reason" value=""><button class="btn btn-xs btn-default" type="submit">无需购买</button></form></td></tr>
                @endforeach
                </tbody></table></div>
            </details>
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
                <button type="button" class="btn btn-default btn-sm" data-toggle="modal" data-target="#assistant-shopify-reference-rates-modal">
                    <i class="fa fa-exchange"></i> {{ $t('exchange_rates.button') }}
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
                            'email_purchased' => $t('table.email_purchased'),
                            'total_sales' => $t('metrics.business_summary'),
                            'subscribed_orders_count' => $t('subscriptions.subscribed'),
                            'regular_orders_count' => $t('subscriptions.regular'),
                            'recurring_orders_count' => $t('subscriptions.recurring'),
                            'shop_timezone' => $t('table.shop_timezone'),
                            'team' => $t('table.team'),
                            'shop_status' => $t('table.shop_status'),
                        ] as $field => $label)
                            <th class="assistant-shopify-sortable @if ($field === 'total_sales') assistant-shopify-summary-list-column @endif @if (in_array($field, ['regular_orders_count', 'subscribed_orders_count', 'recurring_orders_count'], true)) assistant-shopify-subscription-list-column @endif @if ($filters['sort'] === $field) is-sorted @endif">
                                <a href="{{ $sortUrl($field) }}" data-loading="region" data-loading-target=".assistant-shopify-analytics-page">
                                    {{ $label }}
                                    <i class="fa @if ($filters['sort'] === $field && $filters['direction'] === 'asc') fa-sort-asc @elseif ($filters['sort'] === $field) fa-sort-desc @else fa-sort @endif"></i>
                                </a>
                            </th>
                        @endforeach
                        <th>{{ $t('table.owner') }}</th>
                        <th class="assistant-shopify-action-column">{{ $t('table.actions') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($stores as $store)
                        @php
                            $metric = $store->shopifyAnalyticsPeriod;
                            // A successful source synchronization can quite
                            // validly have zero orders in the chosen report
                            // range. It should display zero, not masquerade
                            // as an unavailable snapshot.
                            $hasSnapshot = $metric?->sync_status === \App\Models\AssistantShopifyStoreMetric::STATUS_SUCCESS;
                            $isInactive = $store->shop_status !== \App\Models\AssistantDomainStore::SHOP_STATUS_IN_USE;
                            $isConfigured = filled($store->shopify_client_id) && filled($store->shopify_client_secret);
                            $isBatchRefreshable = !$isInactive && $isConfigured;
                            $selectionUnavailableReason = $isInactive
                                ? $t('batch.inactive_unavailable')
                                : ($isConfigured ? '' : $t('batch.not_configured_unavailable'));
                            $timezone = $metric?->shop_timezone ?: $t('filters.timezone_pending');
                            $singleRefreshFormId = 'assistant-shopify-refresh-'.$store->getKey();
                            $originalSales = $hasSnapshot
                                ? $currencyPrefix($metric->original_currency_code).$money($metric->original_total_sales)
                                : $t('card.not_available');
                            $originalCurrency = strtoupper((string) ($metric?->original_currency_code ?: ''));
                            $hasReferenceRate = $hasSnapshot && (bool) ($metric?->reference_rate_configured);
                            $referenceRateWarning = $t('exchange_rates.unconfigured', [
                                'currency' => $originalCurrency !== '' ? $originalCurrency : $t('exchange_rates.unknown_currency'),
                            ]);
                            $totalSales = $hasReferenceRate && $metric?->total_sales !== null
                                ? $usd.$money($metric->total_sales)
                                : null;
                            $orders = (int) ($metric?->orders_count ?? 0);
                        @endphp
                        <tr class="{{ $isInactive ? 'is-inactive' : '' }}">
                            <td class="assistant-shopify-selection-column">
                                <input type="checkbox" form="assistant-shopify-batch-refresh-form" name="selected_ids[]" value="{{ $store->getKey() }}" data-shopify-select title="{{ $selectionUnavailableReason }}" @disabled(!$isBatchRefreshable)>
                            </td>
                            <td class="assistant-shopify-store-cell"><strong>{{ $store->shop_name }}</strong></td>
                            <td class="assistant-shopify-domain-cell" title="{{ $store->domain }}">{{ $store->domain }}</td>
                            <td>
                                @if ($store->email_purchased)
                                    <span class="assistant-email-reminder-status is-purchased">已购买</span>
                                @elseif ($store->email_purchase_reminder_exempt)
                                    <span class="assistant-email-reminder-status is-exempt" title="{{ $store->email_purchase_reminder_exempt_reason }}">无需购买</span>
                                @elseif ($orders > 0)
                                    <span class="assistant-email-reminder-status is-warning"><i class="fa fa-exclamation-circle"></i> 有出单·未购买</span>
                                @else
                                    <span class="assistant-email-reminder-status is-idle">未购买·暂无订单</span>
                                @endif
                            </td>
                            <td class="assistant-shopify-summary-list-cell">
                                @if (!$hasSnapshot)
                                    <span class="assistant-shopify-value-tag">{{ $t('card.not_available') }}</span>
                                @else
                                    <div class="assistant-shopify-list-category-stat">
                                        <span>
                                            <small>{{ $t('subscriptions.orders') }}</small>
                                            <strong>{{ $number($metric->orders_count) }}</strong>
                                        </span>
                                        <span>
                                            <small>{{ $t('subscriptions.sales') }}</small>
                                            @if (!$hasReferenceRate)
                                                <strong class="assistant-shopify-rate-warning" title="{{ $referenceRateWarning }}"><i class="fa fa-exclamation-circle"></i></strong>
                                            @elseif ($totalSales !== null)
                                                <strong>{{ $totalSales }}</strong>
                                            @else
                                                <strong>{{ $t('card.not_available') }}</strong>
                                            @endif
                                        </span>
                                        <span>
                                            <small>{{ $t('subscriptions.original_sales') }}</small>
                                            <strong>{{ $originalSales }}</strong>
                                        </span>
                                    </div>
                                @endif
                            </td>
                            @foreach (['subscribed', 'regular', 'recurring'] as $subscriptionKey)
                                @php
                                    $categoryOrders = data_get($metric, $subscriptionKey.'_orders_count');
                                    $categorySales = data_get($metric, $subscriptionKey.'_total_sales');
                                    $categoryOriginalSales = data_get($metric, $subscriptionKey.'_original_total_sales');
                                @endphp
                                <td class="assistant-shopify-subscription-list-cell">
                                    @if (!$hasSnapshot)
                                        <span class="assistant-shopify-value-tag">{{ $t('card.not_available') }}</span>
                                    @else
                                        <div class="assistant-shopify-list-category-stat">
                                            <span>
                                                <small>{{ $t('subscriptions.orders') }}</small>
                                                <strong>{{ $number($categoryOrders) }}</strong>
                                            </span>
                                            <span>
                                                <small>{{ $t('subscriptions.sales') }}</small>
                                                @if (!$hasReferenceRate)
                                                    <strong class="assistant-shopify-rate-warning" title="{{ $referenceRateWarning }}"><i class="fa fa-exclamation-circle"></i></strong>
                                                @else
                                                    <strong>{{ $usd }}{{ $money($categorySales) }}</strong>
                                                @endif
                                            </span>
                                            <span>
                                                <small>{{ $t('subscriptions.original_sales') }}</small>
                                                <strong>{{ $currencyPrefix($metric?->original_currency_code) }}{{ $money($categoryOriginalSales) }}</strong>
                                            </span>
                                        </div>
                                    @endif
                                </td>
                            @endforeach
                            <td title="{{ $timezone }}"><span class="assistant-shopify-value-tag assistant-shopify-value-tag-timezone">{{ $timezone }}</span></td>
                            <td>{{ \App\Models\AssistantDomainStore::displayMultiValue($store->team) ?: $t('card.not_available') }}</td>
                            <td>{{ \App\Models\AssistantDomainStore::shopStatusLabels()[$store->shop_status] ?? $store->shop_status }}</td>
                            <td>{{ $store->ownerUser?->name ?: $store->ownerUser?->username ?: $t('table.owner_unassigned') }}</td>
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
                                @if (! $store->email_purchased)
                                    <form class="assistant-email-reminder-form" method="post" action="{{ route('assistant.store-analytics.email-purchase-reminder.update', $store) }}" data-email-reminder-form data-exempt="{{ $store->email_purchase_reminder_exempt ? '0' : '1' }}">
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="exempt" value="{{ $store->email_purchase_reminder_exempt ? '0' : '1' }}">
                                        <input type="hidden" name="reason" value="">
                                        <button type="submit" class="btn btn-xs {{ $store->email_purchase_reminder_exempt ? 'btn-warning' : 'btn-default' }}">{{ $store->email_purchase_reminder_exempt ? '恢复提醒' : '无需购买' }}</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="13" class="assistant-shopify-empty-state">
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

        <div id="assistant-shopify-reference-rates-modal" class="modal fade" tabindex="-1" role="dialog" aria-labelledby="assistant-shopify-reference-rates-title">
            <div class="modal-dialog modal-md" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <button type="button" class="close" data-dismiss="modal" aria-label="{{ $t('exchange_rates.close') }}"><span aria-hidden="true">&times;</span></button>
                        <h4 id="assistant-shopify-reference-rates-title" class="modal-title"><i class="fa fa-exchange"></i> {{ $t('exchange_rates.title') }}</h4>
                    </div>
                    <div class="modal-body">
                        <p class="assistant-shopify-rate-modal-notice">{{ $t('exchange_rates.description') }}</p>
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped assistant-shopify-rate-table">
                                <thead>
                                <tr>
                                    <th>{{ $t('exchange_rates.currency') }}</th>
                                    <th>{{ $t('exchange_rates.rate') }}</th>
                                </tr>
                                </thead>
                                <tbody>
                                @foreach ($referenceRates as $currency => $rate)
                                    <tr>
                                        <td>{{ $t('exchange_rates.currencies.'.strtolower($currency)) }} <code>{{ $currency }}</code></td>
                                        <td>1 {{ $currency }} = {{ $usd }}{{ $rateNumber($rate) }}</td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-default" data-dismiss="modal">{{ $t('exchange_rates.close') }}</button>
                    </div>
                </div>
            </div>
        </div>

        <div id="assistant-shopify-export-modal" class="modal fade" tabindex="-1" role="dialog" aria-labelledby="assistant-shopify-export-title">
            <div class="modal-dialog assistant-shopify-export-dialog" role="document">
                <form class="modal-content" method="get" action="{{ route('assistant.shopify-analytics.export-orders') }}">
                    <div class="modal-header">
                        <button type="button" class="close" data-dismiss="modal" aria-label="{{ $t('export.cancel') }}"><span aria-hidden="true">&times;</span></button>
                        <h4 id="assistant-shopify-export-title" class="modal-title"><i class="fa fa-download"></i> {{ $t('export.title') }}</h4>
                    </div>
                    <div class="modal-body">
                        <div class="assistant-shopify-export-range">
                            <span class="assistant-shopify-export-range-icon"><i class="fa fa-calendar" aria-hidden="true"></i></span>
                            <div>
                                <small>{{ $t('export.range_label') }}</small>
                                <strong>{{ $filters['from_date'] }} <i class="fa fa-long-arrow-right" aria-hidden="true"></i> {{ $filters['to_date'] }}</strong>
                                <em>{{ $filters['timezone'] }}</em>
                            </div>
                        </div>
                        <div class="form-group assistant-shopify-export-group">
                            <label for="assistant-shopify-export-group-by">{{ $t('export.group_by_label') }}</label>
                            <select id="assistant-shopify-export-group-by" class="form-control" name="group_by">
                                <option value="store">{{ $t('export.group_by_store') }}</option>
                                <option value="date">{{ $t('export.group_by_date') }}</option>
                            </select>
                            <p class="help-block">{{ $t('export.group_by_hint') }}</p>
                        </div>

                        <input type="hidden" name="shop_name" value="{{ $filters['shop_name'] }}">
                        <input type="hidden" name="domains" value="{{ implode(',', $filters['domains']) }}">
                        @foreach ($filters['team'] as $team)
                            <input type="hidden" name="team[]" value="{{ $team }}">
                        @endforeach
                        <input type="hidden" name="owner_assignment" value="{{ $filters['owner_assignment'] }}">
                        <input type="hidden" name="email_purchased" value="{{ $filters['email_purchased'] }}">
                        <input type="hidden" name="timezone" value="{{ $filters['timezone'] }}">
                        <input type="hidden" name="from_date" value="{{ $filters['from_date'] }}">
                        <input type="hidden" name="to_date" value="{{ $filters['to_date'] }}">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-default" data-dismiss="modal">{{ $t('export.cancel') }}</button>
                        <button type="submit" class="btn btn-primary"><i class="fa fa-download"></i> {{ $t('export.download') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </section>
@endsection

@push('scripts')
    <script src="{{ asset('js/assistant-filter-multiselect.js') }}?v=20261124-2"></script>
    <script>
        window.ErpAssistantShopifyAnalyticsConfig = @json($shopifyAnalyticsJavascript);
    </script>
    <script src="{{ $portalOrigin }}/assets/erp/assistant-shopify-analytics-20261012-3.js?v=20261012-1"></script>
    <script src="{{ $portalOrigin }}/assets/erp/assistant-team-picker-20261023-1.js?v=20261023-1"></script>
    <script>
        document.querySelectorAll('[data-email-reminder-form]').forEach(function(form){form.addEventListener('submit',function(event){if(form.dataset.exempt!=='1'||form.dataset.confirmed==='1')return;event.preventDefault();var reason=window.prompt('可填写无需购买邮箱的原因（选填）：','');if(reason===null)return;form.querySelector('[name="reason"]').value=reason.trim();form.dataset.confirmed='1';form.submit();});});
    </script>
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
