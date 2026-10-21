@php
    $portalOrigin = rtrim(request()->getSchemeAndHttpHost(), '/');
    $t = static fn (string $key, array $replace = []): string => __('store_management.'.$key, $replace);
    $money = static fn (mixed $value): string => number_format((float) $value, 2);
    $number = static fn (mixed $value): string => number_format((int) $value);
    $currencyPrefix = static fn (mixed $code): string => strtoupper(trim((string) ($code ?: ''))) === ''
        ? ''
        : strtoupper(trim((string) $code)).' ';
    $usd = $t('currency.usd_prefix');
    $hasFilters = $filters['shop_name'] !== '' || $filters['domains'] !== [];
    $referenceRates = $referenceRates ?? [];
@endphp

@extends('portal.layout')

@section('page_title', $t('page_title'))

@push('styles')
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/assistant-shopify-analytics-20261012-1.css?v=20261012-6">
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/assistant-shopify-analytics-20261012-3.css?v=20261012-1">
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/assistant-shopify-analytics-20261013-1.css?v=20261013-1">
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/assistant-shopify-analytics-20261013-2.css?v=20261013-2">
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/assistant-shopify-analytics-20261013-3.css?v=20261013-3">
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/assistant-shopify-analytics-20261014-1.css?v=20261014-1">
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/assistant-shopify-analytics-20261014-2.css?v=20261014-2">
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/assistant-shopify-analytics-20261020-1.css?v=20261020-6">
@endpush

@section('content')
    <section class="content-header">
        <h1>{{ $t('title') }} <small>{{ $t('subtitle') }}</small></h1>
        <ol class="breadcrumb" style="margin-right: 30px;">
            <li><a href="/"><i class="fa fa-home"></i> {{ __('portal.menu.home') }}</a></li>
            <li class="active">{{ $t('title') }}</li>
        </ol>
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
                <span class="assistant-shopify-summary-icon"><i class="fa fa-usd"></i></span>
                <div>
                    <span class="assistant-shopify-summary-label">{{ $t('overview.total_sales') }}</span>
                    <strong>{{ $usd }}{{ $money($summary['total_sales']) }}</strong>
                </div>
            </div>
            <div class="assistant-shopify-summary-card">
                <span class="assistant-shopify-summary-icon"><i class="fa fa-undo"></i></span>
                <div>
                    <span class="assistant-shopify-summary-label">{{ $t('overview.refunds') }}</span>
                    <strong>{{ $usd }}{{ $money($summary['refund_total']) }}</strong>
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
                <span class="assistant-shopify-summary-icon"><i class="fa fa-shopping-cart"></i></span>
                <div>
                    <span class="assistant-shopify-summary-label">{{ $t('overview.average_order_value') }}</span>
                    <strong>{{ $usd }}{{ $money($summary['average_order_value']) }}</strong>
                </div>
            </div>
            <div class="assistant-shopify-summary-card">
                <span class="assistant-shopify-summary-icon"><i class="fa fa-money"></i></span>
                <div>
                    <span class="assistant-shopify-summary-label">{{ $t('overview.original_total_sales') }}</span>
                    <strong>{{ $money($summary['original_total_sales']) }}</strong>
                </div>
            </div>
        </div>

        <div class="assistant-shopify-subscription-summary" role="status" aria-label="{{ $t('subscriptions.title') }}">
            @foreach ([
                ['key' => 'regular', 'icon' => 'fa-shopping-bag', 'title' => $t('subscriptions.regular')],
                ['key' => 'subscribed', 'icon' => 'fa-refresh', 'title' => $t('subscriptions.subscribed')],
                ['key' => 'recurring', 'icon' => 'fa-repeat', 'title' => $t('subscriptions.recurring')],
            ] as $group)
                <div class="assistant-shopify-subscription-card assistant-shopify-subscription-card-{{ $group['key'] }}">
                    <div class="assistant-shopify-subscription-heading">
                        <span class="assistant-shopify-subscription-icon"><i class="fa {{ $group['icon'] }}"></i></span>
                        <strong>{{ $group['title'] }}</strong>
                    </div>
                    <div class="assistant-shopify-subscription-metrics">
                        <span>
                            <small>{{ $t('subscriptions.orders') }}</small>
                            <strong>{{ $number($summary[$group['key'].'_orders_count']) }}</strong>
                        </span>
                        <span>
                            <small>{{ $t('subscriptions.sales') }}</small>
                            <strong>{{ $usd }}{{ $money($summary[$group['key'].'_total_sales']) }}</strong>
                        </span>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="box box-default assistant-shopify-filter-box">
            <div class="box-header with-border">
                <h3 class="box-title"><i class="fa fa-filter"></i> {{ $t('filters.title') }}</h3>
            </div>
            <form method="get" action="{{ route('operation.domain-stores.index') }}" class="box-body assistant-shopify-filter-form">
                <div class="assistant-shopify-filter-primary-row">
                    <div class="form-group">
                        <label for="op-shopify-shop-name">{{ $t('filters.shop_name') }}</label>
                        <input id="op-shopify-shop-name" name="shop_name" class="form-control"
                               value="{{ $filters['shop_name'] }}" placeholder="{{ $t('filters.shop_name_placeholder') }}">
                    </div>
                    <div class="form-group">
                        <label for="op-shopify-domains">{{ $t('filters.domains') }}</label>
                        <input id="op-shopify-domains" name="domains" class="form-control"
                               value="{{ implode(', ', $filters['domains']) }}" placeholder="{{ $t('filters.domains_placeholder') }}">
                    </div>
                    <div class="form-group">
                        <label for="op-shopify-timezone">{{ $t('filters.timezone') }}</label>
                        <select id="op-shopify-timezone" name="timezone" class="form-control">
                            <option value="UTC" @selected($filters['timezone'] === 'UTC')>{{ $t('filters.timezone_utc') }}</option>
                            <option value="Asia/Shanghai" @selected($filters['timezone'] === 'Asia/Shanghai')>{{ $t('filters.timezone_shanghai') }}</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="op-shopify-from-date">{{ $t('filters.from_date') }}</label>
                        <input id="op-shopify-from-date" type="date" name="from_date" class="form-control"
                               value="{{ $filters['from_date'] }}">
                    </div>
                    <div class="form-group">
                        <label for="op-shopify-to-date">{{ $t('filters.to_date') }}</label>
                        <input id="op-shopify-to-date" type="date" name="to_date" class="form-control"
                               value="{{ $filters['to_date'] }}">
                    </div>
                </div>
                <div class="assistant-shopify-filter-secondary-row">
                    <div class="form-group assistant-shopify-filter-actions">
                        <button type="submit" class="btn btn-primary"><i class="fa fa-search"></i> {{ $t('filters.submit') }}</button>
                        <a href="{{ route('operation.domain-stores.index') }}" class="btn btn-default">{{ $t('filters.reset') }}</a>
                    </div>
                </div>
                <input type="hidden" name="per_page" value="{{ $filters['per_page'] }}">
            </form>
        </div>

        @if (session('success'))
            <div class="alert alert-success alert-dismissible">
                <button type="button" class="close" data-dismiss="alert">&times;</button>
                <i class="fa fa-check-circle"></i> {{ session('success') }}
            </div>
        @endif
        @if (session('error'))
            <div class="alert alert-danger alert-dismissible">
                <button type="button" class="close" data-dismiss="alert">&times;</button>
                <i class="fa fa-exclamation-circle"></i> {{ session('error') }}
            </div>
        @endif

        <div id="op-shopify-list" class="box box-default assistant-shopify-list-box" aria-live="polite">
            <div class="assistant-shopify-batch-toolbar">
                <strong class="assistant-shopify-selection-summary">
                    <span class="text-muted"><i class="fa fa-eye"></i> {{ $t('common.readonly_tip') }}</span>
                </strong>
                <div class="assistant-list-top-pagination">
                    @include('portals.operation.domain-stores.partials.pagination', ['stores' => $stores, 'filters' => $filters])
                </div>
            </div>

            <div class="assistant-shopify-table-scroll">
                <table class="table table-bordered table-hover assistant-shopify-list-table">
                    <thead>
                    <tr>
                        <th class="assistant-shopify-store-column">{{ $t('table.store') }}</th>
                        <th class="assistant-shopify-domain-column">{{ $t('table.domain') }}</th>
                        <th class="assistant-shopify-email-purchased-column">{{ $t('table.email_purchased') }}</th>
                        <th class="assistant-shopify-summary-list-column">{{ $t('metrics.business_summary') }}</th>
                        <th class="assistant-shopify-subscription-list-column">{{ $t('subscriptions.subscribed') }}</th>
                        <th class="assistant-shopify-subscription-list-column">{{ $t('subscriptions.regular') }}</th>
                        <th class="assistant-shopify-subscription-list-column">{{ $t('subscriptions.recurring') }}</th>
                        <th class="assistant-shopify-shop-status-column">{{ $t('table.shop_status') }}</th>
                        <th class="assistant-shopify-action-column">{{ $t('table.actions') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($stores as $store)
                        @php
                            $period = $store->orders_count !== null;
                            $inactive = $store->shop_status !== \App\Models\AssistantDomainStore::SHOP_STATUS_IN_USE;
                            $syncBusy = (bool) $store->shopifySyncBusy;
                            $originalCurrency = $store->original_currency_code;
                            $hasRate = $period && $referenceRates !== [] && ($referenceRates[strtoupper((string) $originalCurrency)] ?? null) !== null;
                            $refreshFormId = 'op-shopify-refresh-'.$store->getKey();
                        @endphp
                        <tr class="{{ $inactive ? 'is-inactive' : '' }}">
                            <td class="assistant-shopify-store-cell">
                                <strong>{{ $store->shop_name ?: $t('card.not_available') }}</strong>
                            </td>
                            <td class="assistant-shopify-domain-cell" title="{{ $store->domain }}">{{ $store->domain ?: $t('card.not_available') }}</td>
                            <td>
                                <span class="label {{ $store->email_purchased ? 'label-info' : 'label-default' }}">
                                    {{ $store->email_purchased ? __('assistant.shopify_analytics.common.yes') : __('assistant.shopify_analytics.common.no') }}
                                </span>
                            </td>
                            <td class="assistant-shopify-summary-list-cell">
                                @if (!$period)
                                    <span class="assistant-shopify-value-tag">{{ $t('card.no_data') }}</span>
                                @else
                                    <div class="assistant-shopify-list-category-stat">
                                        <span>
                                            <small>{{ $t('subscriptions.orders') }}</small>
                                            <strong>{{ $number($store->orders_count) }}</strong>
                                        </span>
                                        <span>
                                            <small>{{ $t('subscriptions.sales') }}</small>
                                            @if (!$hasRate)
                                                <strong class="assistant-shopify-rate-warning" title="{{ $t('card.rate_missing') }}"><i class="fa fa-exclamation-circle"></i></strong>
                                            @else
                                                <strong>{{ $usd }}{{ $money($store->total_sales) }}</strong>
                                            @endif
                                        </span>
                                        <span>
                                            <small>{{ $t('subscriptions.original_sales') }}</small>
                                            <strong>{{ $currencyPrefix($originalCurrency) }}{{ $money($store->original_total_sales) }}</strong>
                                        </span>
                                    </div>
                                @endif
                            </td>
                            @foreach (['subscribed', 'regular', 'recurring'] as $groupKey)
                                <td class="assistant-shopify-subscription-list-cell">
                                    @if (!$period)
                                        <span class="assistant-shopify-value-tag">{{ $t('card.no_data') }}</span>
                                    @else
                                        <div class="assistant-shopify-list-category-stat">
                                            <span>
                                                <small>{{ $t('subscriptions.orders') }}</small>
                                                <strong>{{ $number($store->{$groupKey.'_orders_count'}) }}</strong>
                                            </span>
                                            <span>
                                                <small>{{ $t('subscriptions.sales') }}</small>
                                                @if (!$hasRate)
                                                    <strong class="assistant-shopify-rate-warning" title="{{ $t('card.rate_missing') }}"><i class="fa fa-exclamation-circle"></i></strong>
                                                @else
                                                    <strong>{{ $usd }}{{ $money($store->{$groupKey.'_total_sales'}) }}</strong>
                                                @endif
                                            </span>
                                            <span>
                                                <small>{{ $t('subscriptions.original_sales') }}</small>
                                                <strong>{{ $currencyPrefix($originalCurrency) }}{{ $money($store->{$groupKey.'_original_total_sales'}) }}</strong>
                                            </span>
                                        </div>
                                    @endif
                                </td>
                            @endforeach
                            <td>
                                <span class="label {{ $store->shop_status === \App\Models\AssistantDomainStore::SHOP_STATUS_IN_USE ? 'label-success' : 'label-default' }}">
                                    {{ $shopStatusLabels[$store->shop_status] ?? $t('card.not_available') }}
                                </span>
                            </td>
                            <td class="assistant-shopify-action-column">
                                @if ($inactive)
                                    <button type="button" class="btn btn-default btn-xs" disabled><i class="fa fa-ban"></i> {{ $t('actions.inactive') }}</button>
                                @else
                                    <form id="{{ $refreshFormId }}" method="post" action="{{ route('operation.domain-stores.refresh', $store->getKey()) }}">
                                        @csrf
                                        <input type="hidden" name="return_to" value="{{ request()->fullUrl() }}">
                                        <input type="hidden" name="timezone" value="{{ $filters['timezone'] }}">
                                        <input type="hidden" name="from_date" value="{{ $filters['from_date'] }}">
                                        <input type="hidden" name="to_date" value="{{ $filters['to_date'] }}">
                                    </form>
                                    <button type="submit" form="{{ $refreshFormId }}" class="btn btn-primary btn-xs" @disabled($syncBusy)>
                                        <i class="fa fa-refresh {{ $syncBusy ? 'fa-spin' : '' }}"></i> {{ $t('actions.refresh') }}
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="assistant-shopify-empty-state">
                                <i class="fa fa-shopping-bag"></i>
                                <p>{{ $hasFilters ? $t('common.no_results') : $t('common.no_data') }}</p>
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            <div class="assistant-shopify-list-footer">
                @include('portals.operation.domain-stores.partials.pagination', ['stores' => $stores, 'filters' => $filters])
            </div>
        </div>
    </section>
@endsection