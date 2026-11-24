@extends('portal.layout')

@section('page_title', __('logistics.orders.menu'))

@php
    $portalOrigin = rtrim(request()->getSchemeAndHttpHost(), '/');
    $advancedFilterCount = collect($filters)->except([
        'per_page', 'id', 'shopify_order_number', 'tongtu_order_number', 'shopify_order_id', 'shopify_sku',
        'product_name', 'store_domain', 'shopify_sku_status', 'product_runnable_status', 'store_name', 'owner_assignment', 'group_names',
    ])->filter(fn ($value) => $value !== '')->count();
    $display = static function ($value): string {
        if ($value === null || $value === '') return '—';
        if (is_string($value) && preg_match('/^-?\d+\.\d{1,4}$/', $value)) return rtrim(rtrim($value, '0'), '.');
        return (string) $value;
    };
    $weight = static function ($value) use ($display): string {
        if ($value === null || $value === '' || !is_numeric($value)) return $display($value);
        return $display($value).' '.__('logistics.orders.sale_product_detail_labels.weight_unit');
    };
    $date = static fn ($value): string => $value ? $value->timezone('Asia/Shanghai')->format('Y-m-d H:i:s') : '—';
    $lines = static function ($value) use ($display): array {
        if ($value === null || $value === '') return ['—'];
        return array_map($display, preg_split('/\r?\n/', (string) $value) ?: []);
    };
    $nonRunnableSkuLookup = array_fill_keys($nonRunnableProductKeys['skus'] ?? [], true);
    $nonRunnableProductCodeLookup = array_fill_keys($nonRunnableProductKeys['product_codes'] ?? [], true);
    $isNonRunnableProduct = static function ($shopifySku, $tongtuSku, $productCode) use ($nonRunnableSkuLookup, $nonRunnableProductCodeLookup): bool {
        $shopifySku = mb_strtolower(trim((string) $shopifySku));
        $tongtuSku = mb_strtolower(trim((string) $tongtuSku));
        $productCode = mb_strtolower(trim((string) $productCode));

        return ($shopifySku !== '' && isset($nonRunnableSkuLookup[$shopifySku]))
            || ($tongtuSku !== '' && isset($nonRunnableSkuLookup[$tongtuSku]))
            || ($productCode !== '' && isset($nonRunnableProductCodeLookup[$productCode]));
    };
@endphp

@push('styles')
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/logistics.css?v=20261104-1">
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/logistics-order-detail.css?v=20261018-1">
    <link rel="stylesheet" href="{{ $portalOrigin }}/css/frontend-list-template.css?v=20261123-19">
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/shopify-orders-list.css?v=6">
@endpush

@section('content')
    <section class="content-header">
        <h1>{{ __('logistics.orders.menu') }} <small>{{ __('logistics.orders.list.subtitle') }}</small></h1>
    </section>

    <section class="content logistics-page">
        @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
        @if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif
        @if($errors->has('scope'))<div class="alert alert-danger">{{ $errors->first('scope') }}</div>@endif
        @if($errors->has('refund_file'))<div class="alert alert-danger">{{ $errors->first('refund_file') }}</div>@endif
        @if($activeTrackingBatch)
            @php
                $trackingProgress = $activeTrackingBatch->progressPayload();
            @endphp
            <div id="tracking-sync-progress" class="logistics-match-progress" data-url="{{ route('logistics.orders.tracking-progress', $activeTrackingBatch) }}" data-completed="{{ $trackingProgress['completed'] ? '1' : '0' }}" data-title-queued="{{ __('logistics.tracking.queued') }}" data-title-processing="{{ __('logistics.tracking.processing') }}" data-title-completed="{{ __('logistics.tracking.completed') }}" data-summary-template="{{ __('logistics.tracking.summary', ['processed' => '{processed}', 'total' => '{total}', 'success' => '{success}', 'skipped' => '{skipped}', 'failed' => '{failed}']) }}">
                <div class="logistics-match-progress-heading"><span><i class="fa fa-refresh fa-spin"></i> <strong data-tracking-title>{{ $trackingProgress['completed'] ? __('logistics.tracking.completed') : ($trackingProgress['status'] === 'queued' ? __('logistics.tracking.queued') : __('logistics.tracking.processing')) }}</strong></span><span data-tracking-percent>{{ $trackingProgress['percent'] }}%</span></div>
                <div class="progress progress-sm active"><div class="progress-bar progress-bar-aqua progress-bar-striped" role="progressbar" data-tracking-bar aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $trackingProgress['percent'] }}" style="width: {{ $trackingProgress['percent'] }}%"></div></div>
                <div class="logistics-match-progress-summary" data-tracking-summary>{{ __('logistics.tracking.summary', $trackingProgress) }}</div>
                <div class="logistics-match-progress-note"><i class="fa fa-clock-o"></i> {{ __('logistics.tracking.note') }}</div>
                <div class="logistics-match-failures" data-tracking-failures @if($trackingFailureDetails === []) hidden @endif>
                    <details open><summary>{{ __('logistics.tracking.failure_details', ['count' => count($trackingFailureDetails)]) }}</summary><ul data-tracking-failure-list>@foreach($trackingFailureDetails as $failure)<li><strong>{{ __('logistics.tracking.order', ['number' => $failure['shopify_order_number']]) }}</strong><span>{{ __('logistics.tracking.tracking_number', ['number' => $failure['tracking_number']]) }}</span><em>{{ $failure['reason'] }}</em></li>@endforeach</ul></details>
                </div>
            </div>
        @endif

        <div class="flt shopify-orders-list">
        <div class="flt-card flt-filters">
            <h3 class="orders-filter-title"><i class="fa fa-filter" aria-hidden="true"></i> {{ __('logistics.orders.list.filters') }}</h3>
            <form method="get" action="{{ route('logistics.orders.index') }}" class="logistics-filter-form" data-loading="region" data-loading-target=".logistics-orders-scroll"
                data-logistics-filters data-search-options="{{ __('frontend_list_template.search_options') }}"
                data-no-options="{{ __('frontend_list_template.no_options') }}" data-clear-choice="{{ __('frontend_list_template.clear_choice') }}">
                <input type="hidden" name="per_page" value="{{ $filters['per_page'] }}">
                <div class="flt-filter-grid">
                    <div><label class="orders-field-label" for="orders-filter-id">{{ __('logistics.orders.list.field_id') }}</label><input class="form-control" name="id" id="orders-filter-id" value="{{ $filters['id'] }}" placeholder="{{ __('logistics.orders.list.placeholder_id') }}"></div>
                    <div><label class="orders-field-label" for="orders-filter-shopify_order_number">{{ __('logistics.orders.list.field_shopify_order_number') }}</label><input class="form-control" name="shopify_order_number" id="orders-filter-shopify_order_number" value="{{ $filters['shopify_order_number'] }}" placeholder="{{ __('logistics.orders.list.placeholder_shopify_order_number') }}"></div>
                    <div><label class="orders-field-label" for="orders-filter-tongtu_order_number">{{ __('logistics.orders.list.field_tongtu_order_number') }}</label><input class="form-control" name="tongtu_order_number" id="orders-filter-tongtu_order_number" value="{{ $filters['tongtu_order_number'] }}" placeholder="{{ __('logistics.orders.list.placeholder_tongtu_order_number') }}"></div>
                    <div><label class="orders-field-label" for="orders-filter-shopify_order_id">{{ __('logistics.orders.list.field_shopify_order_id') }}</label><input class="form-control" name="shopify_order_id" id="orders-filter-shopify_order_id" value="{{ $filters['shopify_order_id'] }}" placeholder="{{ __('logistics.orders.list.placeholder_shopify_order_id') }}"></div>
                    <div><label class="orders-field-label" for="orders-filter-shopify_sku">{{ __('logistics.orders.list.field_shopify_sku') }}</label><input class="form-control" name="shopify_sku" id="orders-filter-shopify_sku" value="{{ $filters['shopify_sku'] }}" placeholder="{{ __('logistics.orders.list.placeholder_shopify_sku') }}"></div>
                    <div><label class="orders-field-label" for="orders-filter-product_name">{{ __('logistics.orders.list.field_product_name') }}</label><input class="form-control" name="product_name" id="orders-filter-product_name" value="{{ $filters['product_name'] }}" placeholder="{{ __('logistics.orders.list.placeholder_product_name') }}"></div>
                    <div><label class="orders-field-label" for="orders-filter-store_domain">{{ __('logistics.orders.list.field_store_domain') }}</label><input class="form-control" name="store_domain" id="orders-filter-store_domain" value="{{ $filters['store_domain'] }}" placeholder="{{ __('logistics.orders.list.placeholder_store_domain') }}"></div>
                    <div><label class="orders-field-label" for="orders-filter-shopify_sku_status">{{ __('logistics.orders.list.field_shopify_sku_status') }}</label><select class="form-control" name="shopify_sku_status" id="orders-filter-shopify_sku_status"><option value="">{{ __('logistics.common.all') }}</option><option value="empty" @selected(($filters['shopify_sku_status'] ?? '') === 'empty')>{{ __('logistics.orders.list.sku_empty') }}</option><option value="filled" @selected(($filters['shopify_sku_status'] ?? '') === 'filled')>{{ __('logistics.orders.list.sku_filled') }}</option></select></div>
                    <div><label class="orders-field-label" for="orders-filter-product_runnable_status">{{ __('logistics.orders.list.field_product_runnable_status') }}</label><select class="form-control" name="product_runnable_status" id="orders-filter-product_runnable_status"><option value="">{{ __('logistics.common.all') }}</option><option value="non_runnable" @selected(($filters['product_runnable_status'] ?? '') === 'non_runnable')>{{ __('logistics.orders.list.contains_non_runnable') }}</option><option value="runnable" @selected(($filters['product_runnable_status'] ?? '') === 'runnable')>{{ __('logistics.orders.list.without_non_runnable') }}</option></select></div>
                    <div><label class="orders-field-label" for="orders-filter-store_name">{{ __('logistics.orders.list.field_store_name') }}</label><select class="form-control" name="store_name" id="orders-filter-store_name"><option value="">{{ __('logistics.orders.list.choose_store') }}</option>@foreach($filterOptions['stores'] as $value)<option value="{{ $value }}" @selected($filters['store_name'] === $value)>{{ $value }}</option>@endforeach</select></div>
                    <div><label class="orders-field-label" for="orders-filter-owner_assignment">{{ __('logistics.orders.list.field_owner_assignment') }}</label><select class="form-control" name="owner_assignment" id="orders-filter-owner_assignment"><option value="">{{ __('logistics.orders.list.all_owners') }}</option><option value="unassigned" @selected(($filters['owner_assignment'] ?? '') === 'unassigned')>{{ __('logistics.orders.list.unassigned') }}</option>@foreach($filterOptions['owners'] as $owner)<option value="{{ $owner['id'] }}" @selected((string) ($filters['owner_assignment'] ?? '') === (string) $owner['id'])>{{ $owner['name'] }}</option>@endforeach</select></div>
                    <div><label class="orders-field-label" for="orders-filter-group_names">{{ __('logistics.orders.list.field_group_names') }}</label><select class="form-control" name="group_names" id="orders-filter-group_names"><option value="">{{ __('logistics.orders.list.choose_group') }}</option>@foreach($filterOptions['groups'] as $value)<option value="{{ $value }}" @selected($filters['group_names'] === $value)>{{ $value }}</option>@endforeach</select></div>
                </div>
                <div id="orders-advanced-filters" class="orders-advanced-filters" data-orders-advanced @if($advancedFilterCount === 0) hidden @endif>
                    <div class="flt-filter-grid">
                        <div><label class="orders-field-label" for="orders-filter-staff_status">{{ __('logistics.orders.list.field_staff_status') }}</label><select class="form-control" name="staff_status" id="orders-filter-staff_status"><option value="">{{ __('logistics.orders.list.choose_staff_status') }}</option><option value="正常" @selected($filters['staff_status'] === '正常')>{{ __('logistics.orders.list.staff_normal') }}</option></select></div>
                        <div><label class="orders-field-label" for="orders-filter-buyer_name">{{ __('logistics.orders.list.field_buyer_name') }}</label><input class="form-control" name="buyer_name" id="orders-filter-buyer_name" value="{{ $filters['buyer_name'] }}" placeholder="{{ __('logistics.orders.list.placeholder_buyer_name') }}"></div>
                        <div><label class="orders-field-label" for="orders-filter-buyer_email">{{ __('logistics.orders.list.field_buyer_email') }}</label><input class="form-control" name="buyer_email" id="orders-filter-buyer_email" value="{{ $filters['buyer_email'] }}" placeholder="{{ __('logistics.orders.list.placeholder_buyer_email') }}"></div>
                        <div><label class="orders-field-label" for="orders-filter-buyer_phone">{{ __('logistics.orders.list.field_buyer_phone') }}</label><input class="form-control" name="buyer_phone" id="orders-filter-buyer_phone" value="{{ $filters['buyer_phone'] }}" placeholder="{{ __('logistics.orders.list.placeholder_buyer_phone') }}"></div>
                        <div><label class="orders-field-label" for="orders-filter-source_shop_domain">{{ __('logistics.orders.list.field_source_shop_domain') }}</label><select class="form-control" name="source_shop_domain" id="orders-filter-source_shop_domain"><option value="">{{ __('logistics.orders.list.choose_source') }}</option>@foreach($filterOptions['sourceDomains'] as $value)<option value="{{ $value }}" @selected($filters['source_shop_domain'] === $value)>{{ $value }}</option>@endforeach</select></div>
                        <div><label class="orders-field-label" for="orders-filter-store_assignment_status">{{ __('logistics.orders.list.field_store_assignment_status') }}</label><select class="form-control" name="store_assignment_status" id="orders-filter-store_assignment_status"><option value="">{{ __('logistics.common.all') }}</option><option value="assigned" @selected($filters['store_assignment_status'] === 'assigned')>{{ __('logistics.orders.list.assigned') }}</option><option value="unmatched" @selected($filters['store_assignment_status'] === 'unmatched')>{{ __('logistics.orders.list.unmatched') }}</option></select></div>
                        <div><label class="orders-field-label" for="orders-filter-is_add_on">{{ __('logistics.orders.list.field_is_add_on') }}</label><select class="form-control" name="is_add_on" id="orders-filter-is_add_on"><option value="">{{ __('logistics.orders.list.choose_add_on') }}</option><option value="已加购" @selected($filters['is_add_on'] === '已加购')>{{ __('logistics.orders.list.added') }}</option><option value="未加购" @selected($filters['is_add_on'] === '未加购')>{{ __('logistics.orders.list.not_added') }}</option></select></div>
                        <div><label class="orders-field-label" for="orders-filter-subscription_type">{{ __('logistics.orders.list.field_subscription_type') }}</label><select class="form-control" name="subscription_type" id="orders-filter-subscription_type"><option value="">{{ __('logistics.orders.list.choose_subscription') }}</option>@foreach($filterOptions['subscriptionTypes'] as $value)<option value="{{ $value }}" @selected($filters['subscription_type'] === $value)>{{ $value }}</option>@endforeach</select></div>
                        <div><label class="orders-field-label" for="orders-filter-payment_method">{{ __('logistics.orders.list.field_payment_method') }}</label><select class="form-control" name="payment_method" id="orders-filter-payment_method"><option value="">{{ __('logistics.orders.list.choose_payment_method') }}</option>@foreach($filterOptions['paymentMethods'] as $value)<option value="{{ $value }}" @selected($filters['payment_method'] === $value)>{{ $value }}</option>@endforeach</select></div>
                        <div><label class="orders-field-label" for="orders-filter-payment_gateway">{{ __('logistics.orders.list.field_payment_gateway') }}</label><select class="form-control" name="payment_gateway" id="orders-filter-payment_gateway"><option value="">{{ __('logistics.orders.list.choose_payment_gateway') }}</option>@foreach($filterOptions['paymentGateways'] as $value)<option value="{{ $value }}" @selected($filters['payment_gateway'] === $value)>{{ $value }}</option>@endforeach</select></div>
                        <div><label class="orders-field-label" for="orders-filter-collection_account">{{ __('logistics.orders.list.field_collection_account') }}</label><select class="form-control" name="collection_account" id="orders-filter-collection_account"><option value="">{{ __('logistics.orders.list.all_collection_accounts') }}</option>@foreach($filterOptions['collectionAccounts'] as $value)<option value="{{ $value }}" @selected($filters['collection_account'] === $value)>{{ $value }}</option>@endforeach</select></div>
                        <div><label class="orders-field-label" for="orders-filter-collection_account_email">{{ __('logistics.orders.list.field_collection_account_email') }}</label><input class="form-control" name="collection_account_email" id="orders-filter-collection_account_email" value="{{ $filters['collection_account_email'] }}" placeholder="{{ __('logistics.orders.list.placeholder_collection_account_email') }}"></div>
                        <div><label class="orders-field-label" for="orders-filter-transaction_id">{{ __('logistics.orders.list.field_transaction_id') }}</label><input class="form-control" name="transaction_id" id="orders-filter-transaction_id" value="{{ $filters['transaction_id'] }}" placeholder="{{ __('logistics.orders.list.placeholder_transaction_id') }}"></div>
                        <div><label class="orders-field-label" for="orders-filter-bill_number">{{ __('logistics.orders.list.field_bill_number') }}</label><input class="form-control" name="bill_number" id="orders-filter-bill_number" value="{{ $filters['bill_number'] }}" placeholder="{{ __('logistics.orders.list.placeholder_bill_number') }}"></div>
                        <div><label class="orders-field-label" for="orders-filter-logistics_status">{{ __('logistics.orders.list.field_logistics_status') }}</label><select class="form-control" name="logistics_status" id="orders-filter-logistics_status"><option value="">{{ __('logistics.orders.list.choose_status') }}</option>@foreach($trackingOptions as $value)<option value="{{ $value }}" @selected($filters['logistics_status'] === $value)>{{ $value }}</option>@endforeach</select></div>
                        <div><label class="orders-field-label" for="orders-filter-refund_number">{{ __('logistics.orders.list.field_refund_number') }}</label><input class="form-control" name="refund_number" id="orders-filter-refund_number" value="{{ $filters['refund_number'] }}" placeholder="{{ __('logistics.orders.list.placeholder_refund_number') }}"></div>
                        <div><label class="orders-field-label" for="orders-filter-refund_reason">{{ __('logistics.orders.list.field_refund_reason') }}</label><input class="form-control" name="refund_reason" id="orders-filter-refund_reason" value="{{ $filters['refund_reason'] }}" placeholder="{{ __('logistics.orders.list.placeholder_refund_reason') }}"></div>
                        <div><label class="orders-field-label" for="orders-filter-data_status">{{ __('logistics.orders.list.field_data_status') }}</label><select class="form-control" name="data_status" id="orders-filter-data_status"><option value="">{{ __('logistics.orders.list.choose_data_status') }}</option>@foreach($statusOptions as $value)<option value="{{ $value }}" @selected($filters['data_status'] === $value)>{{ $value }}</option>@endforeach</select></div>
                    </div>
                    <div class="orders-filter-dates">
                        <div><label class="orders-field-label" id="orders-filter-refunded">{{ __('logistics.orders.list.field_refunded') }}</label><div class="flt-time-range" role="group" aria-labelledby="orders-filter-refunded"><input type="date" class="form-control" name="refunded_from" value="{{ $filters['refunded_from'] }}" aria-label="{{ __('logistics.orders.list.date_from') }}"><input type="date" class="form-control" name="refunded_to" value="{{ $filters['refunded_to'] }}" aria-label="{{ __('logistics.orders.list.date_to') }}"></div></div>
                        <div><label class="orders-field-label" id="orders-filter-ordered">{{ __('logistics.orders.list.field_ordered') }}</label><div class="flt-time-range" role="group" aria-labelledby="orders-filter-ordered"><input type="date" class="form-control" name="ordered_from" value="{{ $filters['ordered_from'] }}" aria-label="{{ __('logistics.orders.list.date_from') }}"><input type="date" class="form-control" name="ordered_to" value="{{ $filters['ordered_to'] }}" aria-label="{{ __('logistics.orders.list.date_to') }}"></div></div>
                        <div><label class="orders-field-label" id="orders-filter-paid">{{ __('logistics.orders.list.field_paid') }}</label><div class="flt-time-range" role="group" aria-labelledby="orders-filter-paid"><input type="date" class="form-control" name="paid_from" value="{{ $filters['paid_from'] }}" aria-label="{{ __('logistics.orders.list.date_from') }}"><input type="date" class="form-control" name="paid_to" value="{{ $filters['paid_to'] }}" aria-label="{{ __('logistics.orders.list.date_to') }}"></div></div>
                    </div>
                </div>
                <div class="flt-filter-actions">
                    <button type="button" class="flt-btn orders-advanced-toggle" data-orders-advanced-toggle aria-controls="orders-advanced-filters" aria-expanded="{{ $advancedFilterCount > 0 ? 'true' : 'false' }}">
                        <span class="orders-expand-label">{{ __('logistics.orders.list.more_filters') }}</span>
                        <span class="orders-collapse-label">{{ __('logistics.orders.list.collapse_filters') }}</span>
                        <span class="orders-advanced-count" data-orders-advanced-count data-template="{{ __('logistics.orders.list.advanced_filter_count', ['count' => '__count__']) }}" @if($advancedFilterCount === 0) hidden @endif>{{ __('logistics.orders.list.advanced_filter_count', ['count' => $advancedFilterCount]) }}</span>
                        <i class="fa fa-chevron-down" aria-hidden="true"></i>
                    </button>
                    <button class="flt-btn flt-primary" type="submit"><i class="fa fa-search"></i> {{ __('logistics.common.search') }}</button>
                    <a class="flt-btn" href="{{ route('logistics.orders.index', ['per_page' => $filters['per_page']]) }}" data-loading="region" data-loading-target=".logistics-orders-scroll">{{ __('logistics.common.reset') }}</a>
                </div>
            </form>
        </div>

        <form id="tracking-sync-form" method="post" action="{{ route('logistics.orders.sync-tracking') }}">@csrf<input type="hidden" name="return_to" value="{{ request()->fullUrl() }}"></form>

        <div class="flt-card flt-records">
            <div class="flt-toolbar">
                <div class="flt-selection">
                    <span>{{ __('frontend_list_template.records', ['count' => $orders->total()]) }}</span>
                    <span class="flt-toolbar-divider" aria-hidden="true"></span>
                    <span id="orders-toolbar-selected-count" aria-live="polite" data-template="{{ __('frontend_list_template.selected_records', ['count' => '__count__']) }}">{{ __('frontend_list_template.selected_records', ['count' => 0]) }}</span>
                    <button id="orders-clear-selection" type="button" disabled>{{ __('logistics.orders.list.clear_selection') }}</button>
                </div>
                <div class="flt-toolbar-actions">
                    <a class="flt-btn flt-primary" href="{{ route('logistics.orders.create') }}"><i class="fa fa-plus"></i> {{ __('logistics.orders.manual.title') }}</a>
                    <button class="flt-btn" type="button" data-toggle="modal" data-target="#logistics-order-export-modal"><i class="fa fa-download"></i> {{ __('logistics.orders.list.export') }}</button>
                    <button class="flt-btn" type="button" data-toggle="modal" data-target="#logistics-refund-import-modal"><i class="fa fa-upload"></i> {{ __('logistics.refund_import.open_button') }}</button>
                    <button class="flt-btn" type="submit" form="tracking-sync-form" id="tracking-sync-button" disabled><i class="fa fa-truck"></i> {{ __('logistics.tracking.batch_sync') }}</button>
                </div>
            </div>
            <div class="flt-table-scroll logistics-orders-scroll {{ $orders->isEmpty() ? 'is-empty' : '' }}" tabindex="0" aria-label="{{ __('logistics.orders.menu') }}">
                <table class="table table-hover logistics-table logistics-orders-table">
                    <thead><tr>
                        <th class="logistics-sticky-left flt-check"><input id="order-check-all" type="checkbox" aria-label="{{ __('logistics.orders.list.check_all') }}"></th>
                        <th>{{ __('logistics.orders.list.field_id') }}</th><th class="logistics-order-identifiers-heading">{{ __('logistics.orders.identifier_information') }}</th><th class="logistics-store-information-heading">{{ __('logistics.orders.store_information') }}</th><th class="logistics-subscription-information-heading">{{ __('logistics.orders.subscription_information') }}</th><th>{{ __('logistics.orders.list.subscription_origin') }}</th><th class="logistics-sale-product-details-heading">{{ __('logistics.orders.sale_product_details') }}</th><th class="logistics-payment-information-heading">{{ __('logistics.orders.payment_information') }}</th><th class="logistics-amount-information-heading">{{ __('logistics.orders.amount_information') }}</th><th class="logistics-order-time-information-heading">{{ __('logistics.orders.order_time_information') }}</th><th>{{ __('logistics.orders.refunds.paypal_actual') }}</th><th>{{ __('logistics.orders.refunds.credit_card_actual') }}</th><th>{{ __('logistics.orders.list.field_transaction_id') }}</th><th>{{ __('logistics.orders.list.field_bill_number') }}</th><th class="logistics-buyer-information-heading">{{ __('logistics.orders.buyer_information') }}</th><th class="logistics-shipping-information-heading">{{ __('logistics.orders.shipping_information') }}</th><th>{{ __('logistics.orders.list.field_data_status') }}</th><th>{{ __('logistics.orders.list.actions') }}</th>
                    </tr></thead>
                    <tbody>
                    @forelse($orders as $order)
                        @php
                            $paypalRefunds = $order->paypalRefunds;
                            $paypalRefundCount = $paypalRefunds->count();
                            $paypalRefundDetails = $paypalRefunds->map(function ($refund) use ($date): array {
                                $reason = trim((string) ($refund->manual_reason ?: $refund->refund_reason ?: $refund->eventLabel()));
                                $reason = $reason !== '' ? $reason : __('logistics.orders.refunds.reason_not_provided');
                                $amount = number_format((float) $refund->refund_amount_usd, 2);

                                return [
                                    'reason' => $reason,
                                    'amount' => $amount,
                                    'title' => __('logistics.orders.refunds.paypal_item', [
                                    'number' => $refund->refund_transaction_id ?: __('logistics.common.not_available'),
                                    'amount' => $amount,
                                    'reason' => $reason,
                                    'time' => $date($refund->refunded_at ?: $refund->created_at),
                                    ]),
                                ];
                            });
                            $paypalRefundTitle = $paypalRefundDetails->pluck('title')->implode("\n");
                            $creditCardRefunds = $order->creditCardRefunds;
                            $creditCardRefundCount = $creditCardRefunds->count();
                            $creditCardRefundDetails = $creditCardRefunds->map(function ($refund) use ($date): array {
                                $reason = trim((string) $refund->refund_reason);
                                $reason = $reason !== '' ? $reason : __('logistics.orders.refunds.reason_not_provided');
                                $amount = number_format((float) $refund->refund_amount_usd, 2);

                                return [
                                    'reason' => $reason,
                                    'amount' => $amount,
                                    'title' => __('logistics.orders.refunds.credit_card_item', [
                                    'bill' => $refund->bill_number ?: __('logistics.common.not_available'),
                                    'reference' => $refund->refund_reference ?: __('logistics.common.not_available'),
                                    'amount' => $amount,
                                    'reason' => $reason,
                                    'time' => $date($refund->occurred_at),
                                    ]),
                                ];
                            });
                            $creditCardRefundTitle = $creditCardRefundDetails->pluck('title')->implode("\n");
                            $paymentReferences = $order->paymentReferences
                                ->filter(fn ($reference) => filled($reference->reference_number))
                                ->values();
                            $primaryPaymentReference = $paymentReferences->firstWhere('reference_type', \App\Models\LogisticsOrderPaymentReference::TYPE_PRIMARY)?->reference_number
                                ?: $order->bill_number;
                            $upsellReferenceCount = $paymentReferences
                                ->where('reference_type', \App\Models\LogisticsOrderPaymentReference::TYPE_UPSELL)
                                ->count();
                            $paymentReferenceTitle = $paymentReferences
                                ->pluck('reference_number')
                                ->filter()
                                ->implode("\n");
                            if ($paymentReferenceTitle === '' && filled($primaryPaymentReference)) {
                                $paymentReferenceTitle = (string) $primaryPaymentReference;
                            }
                            $subscriptionType = (string) $order->subscription_type;
                            $hasAddOn = $order->is_add_on === '已加购';
                            $hasSubscription = filled($subscriptionType) && $subscriptionType !== '无订阅';
                            $subscriptionStatus = trim((string) $order->subscription_status);
                            $subscriptionStatusTone = str_contains($subscriptionStatus, '已')
                                ? 'is-positive'
                                : ((str_contains($subscriptionStatus, '未') || str_contains($subscriptionStatus, '无')) ? 'is-negative' : 'is-neutral');
                            $collectionAccountEmailMatched = filled($order->collection_account_email);
                            $collectionAccountEmail = $collectionAccountEmailMatched
                                ? $order->collection_account_email
                                : __('logistics.orders.payment_identifiers.unmatched');
                            $saleProductDetails = $order->items->map(function ($item) use ($display, $weight, $isNonRunnableProduct): array {
                                $hasTongtuName = filled($item->tongtu_product_name);

                                return [
                                    'product_code' => $display($item->tongtu_product_code),
                                    'shopify_sku' => $display($item->shopify_sku),
                                    'tongtu_sku' => $display($item->tongtu_sku),
                                    'name' => $display($hasTongtuName ? $item->tongtu_product_name : $item->product_title),
                                    'name_label' => $hasTongtuName ? 'name' : 'shopify_product',
                                    'quantity' => $display($item->quantity),
                                    'goods_weight' => $weight($item->tongtu_goods_weight_gram),
                                    'package_weight' => $weight($item->tongtu_package_weight_gram),
                                    'is_non_runnable' => $isNonRunnableProduct($item->shopify_sku, $item->tongtu_sku, $item->tongtu_product_code),
                                ];
                            })->all();
                            if ($saleProductDetails === []) {
                                $productCodeLines = $lines($order->tongtu_product_code);
                                $skuLines = $lines($order->tongtu_sku);
                                $productNameLines = $lines($order->tongtu_product_name);
                                $quantityLines = $lines($order->sale_quantity);
                                $weightLines = $lines($order->sale_product_weight);
                                $saleProductDetailCount = max(count($productCodeLines), count($skuLines), count($productNameLines), count($quantityLines), count($weightLines));
                                for ($detailIndex = 0; $detailIndex < $saleProductDetailCount; $detailIndex++) {
                                    $saleProductDetails[] = [
                                        'product_code' => $productCodeLines[$detailIndex] ?? '—',
                                        'shopify_sku' => '—',
                                        'tongtu_sku' => $skuLines[$detailIndex] ?? '—',
                                        'name' => $productNameLines[$detailIndex] ?? '—',
                                        'name_label' => 'name',
                                        'quantity' => $quantityLines[$detailIndex] ?? '—',
                                        'goods_weight' => $weight($weightLines[$detailIndex] ?? null),
                                        'package_weight' => '—',
                                        'is_non_runnable' => false,
                                    ];
                                }
                            }
                            $hasNonRunnableProduct = collect($saleProductDetails)->contains('is_non_runnable', true);
                        @endphp
                        <tr class="{{ $hasNonRunnableProduct ? 'has-non-runnable-product' : '' }}" data-data-status="{{ trim((string) $order->data_status) }}"
                            data-logistics-status="{{ trim((string) $order->logistics_status) }}"
                            data-tracking-status="{{ trim((string) $order->tracking_sync_status) }}">
                            <td class="logistics-sticky-left"><input class="logistics-order-check" type="checkbox" value="{{ $order->id }}" aria-label="{{ __('logistics.orders.list.check_order', ['id' => $order->id]) }}"></td>
                            <td>{{ $order->id }}</td><td class="logistics-order-identifiers"><div class="logistics-order-identifier-line"><span class="logistics-order-identifier-label">{{ __('logistics.orders.identifiers.shopify_order_number') }}</span><span class="logistics-order-identifier-value">#{{ $display($order->shopify_order_number) }}</span></div><div class="logistics-order-identifier-line"><span class="logistics-order-identifier-label">{{ __('logistics.orders.identifiers.shopify_order_id') }}</span><span class="logistics-order-identifier-value">{{ $display($order->shopify_order_id) }}</span></div><div class="logistics-order-identifier-line"><span class="logistics-order-identifier-label">{{ __('logistics.orders.identifiers.tongtu_order_number') }}</span><span class="logistics-order-identifier-value">{{ $display($order->tongtu_order_number) }}</span></div></td>
                            <td class="logistics-store-information"><div class="logistics-store-information-line"><span class="logistics-store-information-label">{{ __('logistics.orders.store_identifiers.store_name') }}</span><span class="logistics-store-information-value">{{ $display($order->store_name) }}</span></div><div class="logistics-store-information-line"><span class="logistics-store-information-label">{{ __('logistics.common.store_domain') }}</span><span class="logistics-store-information-value">{{ $historicalDomains[$order->getKey()] ?? __('logistics.common.historical_domain_unknown') }}</span></div><div class="logistics-store-information-line"><span class="logistics-store-information-label">{{ __('logistics.orders.store_identifiers.login_address') }}</span><span class="logistics-store-information-value">{{ $display($order->source_shop_domain) }}</span></div><div class="logistics-store-information-line"><span class="logistics-store-information-label">{{ __('logistics.orders.list.owner') }}</span><span class="logistics-store-information-value">{{ $display($order->assigned_owner_name ?: __('logistics.orders.list.owner_unassigned')) }}</span></div><div class="logistics-store-information-line"><span class="logistics-store-information-label">{{ __('logistics.orders.list.operator') }}</span><span class="logistics-store-information-value">{{ $display($order->operator_name) }}</span></div><div class="logistics-store-information-line"><span class="logistics-store-information-label">{{ __('logistics.orders.store_identifiers.group_names') }}</span><span class="logistics-store-information-value">{{ $display($order->group_names) }}</span></div></td>
                            <td class="logistics-subscription-information"><div class="logistics-subscription-information-line"><span class="logistics-subscription-information-label">{{ __('logistics.orders.subscription_identifiers.add_on') }}</span><span class="logistics-subscription-information-value {{ $hasAddOn ? 'is-positive' : 'is-negative' }}">{{ $hasAddOn ? __('logistics.orders.subscription_identifiers.added') : __('logistics.orders.subscription_identifiers.not_added') }}</span></div><div class="logistics-subscription-information-line"><span class="logistics-subscription-information-label">{{ __('logistics.orders.subscription_identifiers.subscription_type') }}</span><span class="logistics-subscription-information-value {{ $hasSubscription ? 'is-subscription' : 'is-negative' }}">{{ $display($order->subscription_type) }}</span></div><div class="logistics-subscription-information-line"><span class="logistics-subscription-information-label">{{ __('logistics.orders.subscription_identifiers.subscription_status') }}</span><span class="logistics-subscription-information-value {{ $subscriptionStatusTone }}">{{ $display($order->subscription_status) }}</span></div></td><td>{{ $display($order->subscription_origin_order_id) }}</td>
                            <td class="logistics-sale-product-details">@foreach($saleProductDetails as $saleProductDetail)<div class="logistics-sale-product-detail-item"><div class="logistics-sale-product-detail-code {{ $saleProductDetail['product_code'] === '—' ? 'is-empty' : '' }}"><b>{{ __('logistics.orders.sale_product_detail_labels.product_code') }}</b><span title="{{ $saleProductDetail['product_code'] === '—' ? __('logistics.orders.sale_product_detail_labels.unmatched') : $saleProductDetail['product_code'] }}">{{ $saleProductDetail['product_code'] === '—' ? __('logistics.orders.sale_product_detail_labels.unmatched') : $saleProductDetail['product_code'] }}</span></div><div class="logistics-sale-product-detail-skus"><span class="logistics-sale-product-detail-sku is-original"><b>{{ __('logistics.orders.sale_product_detail_labels.shopify_sku') }}</b><span title="{{ $saleProductDetail['shopify_sku'] }}">{{ $saleProductDetail['shopify_sku'] }}</span></span><span class="logistics-sale-product-detail-sku {{ $saleProductDetail['tongtu_sku'] === '—' ? 'is-empty' : 'is-matched' }}"><b>{{ __('logistics.orders.sale_product_detail_labels.tongtu_sku') }}</b><span title="{{ $saleProductDetail['tongtu_sku'] === '—' ? __('logistics.orders.sale_product_detail_labels.unmatched') : $saleProductDetail['tongtu_sku'] }}">{{ $saleProductDetail['tongtu_sku'] === '—' ? __('logistics.orders.sale_product_detail_labels.unmatched') : $saleProductDetail['tongtu_sku'] }}</span></span></div><div class="logistics-sale-product-detail-metrics"><span><b>{{ __('logistics.orders.sale_product_detail_labels.quantity') }}</b>{{ $saleProductDetail['quantity'] }}</span><span><b>{{ __('logistics.orders.sale_product_detail_labels.goods_weight') }}</b>{{ $saleProductDetail['goods_weight'] }}</span><span><b>{{ __('logistics.orders.sale_product_detail_labels.package_weight') }}</b>{{ $saleProductDetail['package_weight'] }}</span></div><div class="logistics-sale-product-detail-name"><b>{{ __('logistics.orders.sale_product_detail_labels.'.$saleProductDetail['name_label']) }}</b><span title="{{ $saleProductDetail['name'] }}">{{ $saleProductDetail['name'] }}</span></div></div>@endforeach</td>
                            <td class="logistics-payment-information">
                                <div class="logistics-payment-information-line"><span class="logistics-payment-information-label">{{ __('logistics.orders.payment_identifiers.status') }}</span><span class="logistics-payment-information-value {{ $order->payment_status === '已付款' ? 'is-paid' : 'is-pending' }}">{{ $display($order->payment_status) }}</span></div>
                                <div class="logistics-payment-information-line"><span class="logistics-payment-information-label">{{ __('logistics.orders.payment_identifiers.method') }}</span><span class="logistics-payment-information-value">{{ $display($order->payment_method) }}</span></div>
                                <div class="logistics-payment-information-line"><span class="logistics-payment-information-label">{{ __('logistics.orders.payment_identifiers.gateway') }}</span><span class="logistics-payment-information-value">{{ $display($order->payment_gateway) }}</span></div>
                                <div class="logistics-payment-information-line"><span class="logistics-payment-information-label">{{ __('logistics.orders.payment_identifiers.collection_account') }}</span><span class="logistics-payment-information-value">{{ $display($order->collection_account) }}</span></div>
                                <div class="logistics-payment-information-line"><span class="logistics-payment-information-label">{{ __('logistics.orders.payment_identifiers.collection_account_email') }}</span><span class="logistics-payment-information-value {{ $collectionAccountEmailMatched ? '' : 'is-unmatched' }}" title="{{ $collectionAccountEmail }}">{{ $collectionAccountEmail }}</span></div>
                            </td>
                            <td class="logistics-amount-information"><div class="logistics-amount-information-line"><span class="logistics-amount-information-label">{{ __('logistics.orders.amount_identifiers.paid_amount') }}</span><span class="logistics-amount-information-value js-money-cell">{{ $display($order->paid_amount_usd) }}</span></div><div class="logistics-amount-information-line"><span class="logistics-amount-information-label">{{ __('logistics.orders.amount_identifiers.total_shipping') }}</span><span class="logistics-amount-information-value js-money-cell">{{ $display($order->total_shipping_usd) }}</span></div></td>
                            <td class="logistics-order-time-information"><div class="logistics-order-time-information-line"><span class="logistics-order-time-information-label">{{ __('logistics.orders.order_time_identifiers.stored') }}</span><span class="logistics-order-time-information-value">{{ $date($order->created_at) }}</span></div><div class="logistics-order-time-information-line"><span class="logistics-order-time-information-label">{{ __('logistics.orders.order_time_identifiers.ordered') }}</span><span class="logistics-order-time-information-value">{{ $date($order->order_created_at) }}</span></div><div class="logistics-order-time-information-line"><span class="logistics-order-time-information-label">{{ __('logistics.orders.order_time_identifiers.paid') }}</span><span class="logistics-order-time-information-value">{{ $date($order->paid_at) }}</span></div></td>
                            <td class="logistics-refund-summary" @if($paypalRefundCount > 0) title="{{ $paypalRefundTitle }}" @endif>
                                @if($paypalRefundCount > 0)
                                    <div class="logistics-refund-list">
                                        @foreach($paypalRefundDetails as $refundDetail)
                                            <div class="logistics-refund-item" title="{{ $refundDetail['title'] }}">
                                                <span class="logistics-refund-reason" title="{{ $refundDetail['reason'] }}">{{ $refundDetail['reason'] }}</span>
                                                <span class="logistics-refund-amount">US${{ $refundDetail['amount'] }}</span>
                                            </div>
                                        @endforeach
                                    </div>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td class="logistics-refund-summary" @if($creditCardRefundCount > 0) title="{{ $creditCardRefundTitle }}" @endif>
                                @if($creditCardRefundCount > 0)
                                    <div class="logistics-refund-list">
                                        @foreach($creditCardRefundDetails as $refundDetail)
                                            <div class="logistics-refund-item" title="{{ $refundDetail['title'] }}">
                                                <span class="logistics-refund-reason" title="{{ $refundDetail['reason'] }}">{{ $refundDetail['reason'] }}</span>
                                                <span class="logistics-refund-amount">US${{ $refundDetail['amount'] }}</span>
                                            </div>
                                        @endforeach
                                    </div>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td>{{ $display($order->transaction_id) }}</td><td class="logistics-payment-reference-cell" title="{{ $paymentReferenceTitle }}"><span>{{ $display($primaryPaymentReference) }}</span>@if($upsellReferenceCount > 0)<small>{{ __('logistics.payment_references.upsell_count', ['count' => $upsellReferenceCount]) }}</small>@endif</td>
                            <td class="logistics-buyer-information"><div class="logistics-buyer-information-line"><span class="logistics-buyer-information-label">{{ __('logistics.orders.buyer_identifiers.name') }}</span><span class="logistics-buyer-information-value" title="{{ $display($order->buyer_name) }}">{{ $display($order->buyer_name) }}</span></div><div class="logistics-buyer-information-line"><span class="logistics-buyer-information-label">{{ __('logistics.orders.buyer_identifiers.email') }}</span><span class="logistics-buyer-information-value" title="{{ $display($order->buyer_email) }}">{{ $display($order->buyer_email) }}</span></div><div class="logistics-buyer-information-line"><span class="logistics-buyer-information-label">{{ __('logistics.orders.buyer_identifiers.destination') }}</span><span class="logistics-buyer-information-value" title="{{ $display($order->destination) }}">{{ $display($order->destination) }}</span></div></td>
                            <td class="logistics-shipping-information"><div class="logistics-shipping-information-line"><span class="logistics-shipping-information-label">{{ __('logistics.orders.shipping_identifiers.company') }}</span><span class="logistics-shipping-information-value" title="{{ $display($order->shipping_company) }}">{{ $display($order->shipping_company) }}</span></div><div class="logistics-shipping-information-line"><span class="logistics-shipping-information-label">{{ __('logistics.orders.shipping_identifiers.tracking_number') }}</span><span class="logistics-shipping-information-value" title="{{ $display($order->tracking_number) }}">{{ $display($order->tracking_number) }}</span></div><div class="logistics-shipping-information-line"><span class="logistics-shipping-information-label">{{ __('logistics.orders.shipping_identifiers.status') }}</span><span class="logistics-shipping-information-value"><span class="label {{ $order->logisticsStatusClass() }} logistics-tag">{{ $order->logistics_status }}</span></span></div></td>
                            <td><span class="label {{ $order->statusClass() }} logistics-tag" title="{{ $order->sku_exception_reason }}">{{ $order->data_status }}</span></td><td class="orders-row-actions"><button class="flt-btn js-logistics-order-detail" type="button" data-order-detail-url="{{ route('logistics.orders.detail', $order) }}">{{ __('logistics.orders.list.view') }}</button><a class="flt-btn" href="{{ route('logistics.orders.edit', $order) }}">{{ __('logistics.orders.list.edit') }}</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="18"><div class="flt-empty"><i class="fa fa-search" aria-hidden="true"></i><h4>{{ __('logistics.orders.list.empty') }}</h4><p>{{ __('logistics.orders.list.empty_hint') }}</p></div></td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            <div class="flt-pagination orders-pagination">
                <span class="orders-pagination-summary">{{ __('logistics.orders.list.page_summary', ['first' => $orders->firstItem() ?? 0, 'last' => $orders->lastItem() ?? 0, 'total' => $orders->total()]) }}</span>
                <div class="orders-pagination-actions">
                    <form method="get" action="{{ route('logistics.orders.index') }}" data-loading="region" data-loading-target=".logistics-orders-scroll">
                        @foreach ($filters as $filterName => $filterValue)
                            @if ($filterName !== 'per_page')
                                <input type="hidden" name="{{ $filterName }}" value="{{ $filterValue }}">
                            @endif
                        @endforeach
                        <label>{{ __('logistics.orders.list.per_page') }}
                            <select name="per_page" onchange="this.form.submit()">
                                @foreach ([20, 50, 100] as $option)
                                    <option value="{{ $option }}" @selected($filters['per_page'] === $option)>{{ $option }}</option>
                                @endforeach
                            </select>
                        </label>
                    </form>
                    <nav class="orders-page-links" aria-label="{{ __('logistics.orders.list.pagination') }}">
                        @if ($orders->previousPageUrl())
                            <a class="flt-btn" href="{{ $orders->previousPageUrl() }}" data-loading="region" data-loading-target=".logistics-orders-scroll">{{ __('logistics.orders.list.previous') }}</a>
                        @else
                            <button class="flt-btn" type="button" disabled>{{ __('logistics.orders.list.previous') }}</button>
                        @endif
                        <span>{{ $orders->currentPage() }} / {{ $orders->lastPage() }}</span>
                        @if ($orders->nextPageUrl())
                            <a class="flt-btn" href="{{ $orders->nextPageUrl() }}" data-loading="region" data-loading-target=".logistics-orders-scroll">{{ __('logistics.orders.list.next') }}</a>
                        @else
                            <button class="flt-btn" type="button" disabled>{{ __('logistics.orders.list.next') }}</button>
                        @endif
                    </nav>
                    <form method="get" action="{{ route('logistics.orders.index') }}" data-loading="region" data-loading-target=".logistics-orders-scroll">
                        @foreach ($filters as $filterName => $filterValue)
                            <input type="hidden" name="{{ $filterName }}" value="{{ $filterValue }}">
                        @endforeach
                        <label><span class="flt-sr-only">{{ __('logistics.orders.list.page') }}</span><input type="number" name="page" min="1" max="{{ $orders->lastPage() }}" required placeholder="{{ __('logistics.orders.list.page') }}"></label>
                        <button type="submit" class="flt-btn flt-primary">{{ __('logistics.orders.list.jump') }}</button>
                    </form>
                </div>
            </div>
        </div>

        </div>

        <div class="modal fade" id="logistics-order-export-modal" tabindex="-1" role="dialog" aria-labelledby="logistics-order-export-title">
            <div class="modal-dialog" role="document">
                <form method="post" action="{{ route('logistics.orders.export') }}" class="modal-content" id="logistics-order-export-form" data-loading="download" data-selection-required="{{ __('logistics.orders.list.export_selection_required') }}">
                    @csrf
                    @foreach ($filters as $filterName => $filterValue)
                        <input type="hidden" name="{{ $filterName }}" value="{{ $filterValue }}">
                    @endforeach
                    <div class="modal-header">
                        <button type="button" class="close" data-dismiss="modal" aria-label="{{ __('logistics.common.close') }}"><span aria-hidden="true">&times;</span></button>
                        <h4 class="modal-title" id="logistics-order-export-title"><i class="fa fa-download"></i> {{ __('logistics.orders.list.export_title') }}</h4>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-info logistics-export-notice"><i class="fa fa-info-circle"></i> {{ __('logistics.orders.list.export_notice') }}</div>
                        <p class="logistics-modal-section-title">{{ __('logistics.orders.list.export_scope') }}</p>
                        <label class="logistics-export-scope">
                            <input type="radio" name="scope" value="selected" checked>
                            <span><strong>{{ __('logistics.orders.list.export_selected') }}</strong><small id="logistics-order-export-selected-count" data-template="{{ __('logistics.orders.list.export_selected_count', ['count' => '__count__']) }}">{{ __('logistics.orders.list.export_selected_count', ['count' => 0]) }}</small></span>
                        </label>
                        <label class="logistics-export-scope">
                            <input type="radio" name="scope" value="filtered">
                            <span><strong>{{ __('logistics.orders.list.export_filtered') }}</strong><small>{{ __('logistics.orders.list.export_filtered_count', ['count' => $orders->total()]) }}</small></span>
                        </label>
                        <div id="logistics-order-export-selected-inputs"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-default" data-dismiss="modal">{{ __('logistics.common.cancel') }}</button>
                        <button type="submit" class="btn btn-primary"><i class="fa fa-download"></i> {{ __('logistics.orders.list.export_confirm') }}</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="modal fade" id="logistics-refund-import-modal" tabindex="-1" role="dialog" aria-labelledby="logistics-refund-import-title">
            <div class="modal-dialog" role="document">
                <form method="post" action="{{ route('logistics.orders.import-refunds') }}" enctype="multipart/form-data" class="modal-content" id="logistics-refund-import-form" data-loading="button">
                    @csrf
                    <input type="hidden" name="return_to" value="{{ request()->fullUrl() }}">
                    <div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="{{ __('logistics.common.close') }}"><span aria-hidden="true">&times;</span></button><h4 class="modal-title" id="logistics-refund-import-title"><i class="fa fa-upload"></i> {{ __('logistics.refund_import.title') }}</h4></div>
                    <div class="modal-body logistics-refund-import-body">
                        <p class="text-muted">{{ __('logistics.refund_import.modal.notice') }}</p>
                        <div class="logistics-import-header-rule"><i class="fa fa-list-alt"></i><span>{{ __('logistics.refund_import.modal.headers') }}</span></div>
                        @if(session('logistics_refund_import_error_report'))
                            <div class="alert alert-warning">
                                <i class="fa fa-exclamation-circle"></i> {{ __('logistics.refund_import.messages.error_report_ready') }}
                                <a class="btn btn-warning btn-xs pull-right" href="{{ session('logistics_refund_import_error_report') }}" data-loading="download"><i class="fa fa-download"></i> {{ __('logistics.refund_import.messages.download_error_report') }}</a>
                            </div>
                        @endif
                        <label class="logistics-import-dropzone" for="logistics-refund-import-file" data-file-selected-template="{{ __('logistics.refund_import.modal.file_selected', ['name' => '__name__']) }}">
                            <input id="logistics-refund-import-file" type="file" name="refund_file" accept=".csv,.xls,.xlsx" required>
                            <i class="fa fa-cloud-upload"></i>
                            <strong>{{ __('logistics.refund_import.modal.dropzone') }}</strong>
                            <span>{{ __('logistics.refund_import.modal.file_hint') }}</span>
                            <em id="logistics-refund-import-file-name"></em>
                        </label>
                        <div class="logistics-import-footer-note">
                            <span><i class="fa fa-info-circle"></i> {{ __('logistics.refund_import.modal.rule_note') }}</span>
                            <a href="{{ route('logistics.orders.refund-import-template') }}" data-loading="download"><i class="fa fa-file-excel-o"></i> {{ __('logistics.refund_import.modal.template_download') }}</a>
                        </div>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">{{ __('logistics.common.cancel') }}</button><button type="submit" class="btn btn-success"><i class="fa fa-upload"></i> {{ __('logistics.refund_import.modal.confirm') }}</button></div>
                </form>
            </div>
        </div>
        @include('portals.logistics.partials.order-detail-modal', ['showDetailLoading' => true])
    </section>
@endsection

@push('scripts')
        <script>
        (function () {
            var formatFinancialCells = function () {
                var fixed = function (value) {
                    var normalized = String(value || '').trim().replace(/,/g, '');
                    return /^-?\d+(?:\.\d+)?$/.test(normalized) ? Number(normalized).toFixed(2) : null;
                };
                var headings = document.querySelectorAll('.logistics-orders-table thead th');

                document.querySelectorAll('.logistics-orders-table tbody tr').forEach(function (row) {
                    row.querySelectorAll('.js-money-cell, .js-rate-cell').forEach(function (cell) {
                        var value = fixed(cell.textContent);
                        var isRate = cell.classList.contains('js-rate-cell');
                        cell.classList.add('logistics-number-cell');
                        if (headings[cell.cellIndex]) headings[cell.cellIndex].classList.add('logistics-number-heading');
                        if (value !== null) {
                            cell.textContent = isRate ? value + '%' : '$' + value;
                        } else {
                            cell.classList.add('logistics-empty-value');
                        }
                    });
                    Array.prototype.forEach.call(row.children, function (cell) {
                        if (cell.textContent.trim() === '—') cell.classList.add('logistics-empty-value');
                    });
                });
            };

            formatFinancialCells();
            var refundImportZone = document.querySelector('.logistics-import-dropzone');
            var refundImportInput = document.getElementById('logistics-refund-import-file');
                var refundImportFilename = document.getElementById('logistics-refund-import-file-name');
            if (refundImportZone && refundImportInput && refundImportFilename) {
                var showRefundImportFilename = function (file) {
                    var template = refundImportZone.dataset.fileSelectedTemplate || '__name__';
                    refundImportFilename.textContent = file ? template.replace('__name__', file.name) : '';
                    refundImportZone.classList.toggle('is-filled', Boolean(file));
                };
                refundImportInput.addEventListener('change', function () {
                    showRefundImportFilename(this.files && this.files[0]);
                });
                ['dragenter', 'dragover'].forEach(function (eventName) {
                    refundImportZone.addEventListener(eventName, function (event) {
                        event.preventDefault();
                        event.stopPropagation();
                        refundImportZone.classList.add('is-dragover');
                    });
                });
                ['dragleave', 'dragend'].forEach(function (eventName) {
                    refundImportZone.addEventListener(eventName, function (event) {
                        event.preventDefault();
                        event.stopPropagation();
                        refundImportZone.classList.remove('is-dragover');
                    });
                });
                refundImportZone.addEventListener('drop', function (event) {
                    event.preventDefault();
                    event.stopPropagation();
                    refundImportZone.classList.remove('is-dragover');
                    var files = event.dataTransfer && event.dataTransfer.files;
                    if (!files || !files.length) return;
                    refundImportInput.files = files;
                    showRefundImportFilename(files[0]);
                });
            }
            var trackingProgressBox = document.getElementById('tracking-sync-progress');
            if (trackingProgressBox && trackingProgressBox.dataset.completed !== '1' && window.fetch) {
                var trackingProgressUrl = trackingProgressBox.dataset.url;
                var trackingProgressTimer = null;
                var trackingTitle = trackingProgressBox.querySelector('[data-tracking-title]');
                var trackingPercent = trackingProgressBox.querySelector('[data-tracking-percent]');
                var trackingBar = trackingProgressBox.querySelector('[data-tracking-bar]');
                var trackingSummary = trackingProgressBox.querySelector('[data-tracking-summary]');
                var trackingSummaryText = function (progress) {
                    return String(trackingProgressBox.dataset.summaryTemplate || '')
                        .replace('{processed}', progress.processed)
                        .replace('{total}', progress.total)
                        .replace('{success}', progress.success)
                        .replace('{skipped}', progress.skipped)
                        .replace('{failed}', progress.failed);
                };
                var updateTrackingProgress = function () {
                    window.fetch(trackingProgressUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
                        .then(function (response) { return response.ok ? response.json() : Promise.reject(response); })
                        .then(function (progress) {
                            trackingTitle.textContent = progress.completed ? trackingProgressBox.dataset.titleCompleted : (progress.status === 'queued' ? trackingProgressBox.dataset.titleQueued : trackingProgressBox.dataset.titleProcessing);
                            trackingPercent.textContent = progress.percent + '%';
                            trackingBar.style.width = progress.percent + '%';
                            trackingBar.setAttribute('aria-valuenow', progress.percent);
                            trackingSummary.textContent = trackingSummaryText(progress);
                            if (progress.completed) {
                                window.clearInterval(trackingProgressTimer);
                                window.setTimeout(function () { window.location.reload(); }, 1200);
                            }
                        })
                        .catch(function () { /* 下一次轮询会自动重试。 */ });
                };
                updateTrackingProgress();
                trackingProgressTimer = window.setInterval(updateTrackingProgress, 3000);
            }
            var all = document.getElementById('order-check-all');
            var toolbarSelectedCount = document.getElementById('orders-toolbar-selected-count');
            var clearSelection = document.getElementById('orders-clear-selection');
            var trackingButton = document.getElementById('tracking-sync-button');
            var exportForm = document.getElementById('logistics-order-export-form');
            var exportSelectedScope = exportForm.querySelector('input[name="scope"][value="selected"]');
            var exportFilteredScope = exportForm.querySelector('input[name="scope"][value="filtered"]');
            var exportSelectedCount = document.getElementById('logistics-order-export-selected-count');
            var exportSelectedInputs = document.getElementById('logistics-order-export-selected-inputs');
            var checks = function () { return Array.prototype.slice.call(document.querySelectorAll('.logistics-order-check')); };
            var selected = function () { return checks().filter(function (item) { return item.checked; }); };
            var populate = function (form) {
                form.querySelectorAll('input[data-selection]').forEach(function (input) { input.remove(); });
                selected().forEach(function (item) {
                    var input = document.createElement('input');
                    input.type = 'hidden'; input.name = 'selected_ids[]'; input.value = item.value; input.setAttribute('data-selection', '1');
                    form.appendChild(input);
                });
            };
            var refresh = function () {
                var selectedItems = selected();
                if (trackingButton) trackingButton.disabled = selectedItems.length === 0;
                all.checked = checks().length > 0 && selectedItems.length === checks().length;
                all.indeterminate = selectedItems.length > 0 && !all.checked;
                toolbarSelectedCount.textContent = toolbarSelectedCount.dataset.template.replace('__count__', selectedItems.length);
                clearSelection.disabled = selectedItems.length === 0;
                checks().forEach(function (item) { item.closest('tr').dataset.selected = item.checked ? '1' : '0'; });
                exportSelectedCount.textContent = exportSelectedCount.dataset.template.replace('__count__', selectedItems.length);
                exportSelectedScope.disabled = selectedItems.length === 0;
                if (selectedItems.length === 0 && exportSelectedScope.checked) exportFilteredScope.checked = true;
            };
            clearSelection.addEventListener('click', function () {
                checks().forEach(function (item) { item.checked = false; });
                refresh();
            });
            all.addEventListener('change', function () { checks().forEach(function (item) { item.checked = all.checked; }); refresh(); });
            checks().forEach(function (item) { item.addEventListener('change', refresh); });
            var trackingForm = document.getElementById('tracking-sync-form');
            if (trackingForm) trackingForm.addEventListener('submit', function () { populate(this); });
            exportForm.addEventListener('submit', function (event) {
                var scope = exportForm.querySelector('input[name="scope"]:checked').value;
                var selectedItems = selected();
                exportSelectedInputs.innerHTML = '';

                if (scope === 'selected' && selectedItems.length === 0) {
                    event.preventDefault();
                    window.alert(exportForm.dataset.selectionRequired);
                    return;
                }

                if (scope === 'selected') {
                    selectedItems.forEach(function (item) {
                        var input = document.createElement('input');
                        input.type = 'hidden'; input.name = 'selected_ids[]'; input.value = item.value;
                        exportSelectedInputs.appendChild(input);
                    });
                }
            });
            if (window.jQuery) window.jQuery('#logistics-order-export-modal').on('show.bs.modal', refresh);
            @if($errors->has('scope'))
            if (window.jQuery) window.jQuery('#logistics-order-export-modal').modal('show');
            @endif
            @if($errors->has('refund_file') || session('logistics_refund_import_failed') || session('logistics_refund_import_has_errors'))
            if (window.jQuery) window.jQuery('#logistics-refund-import-modal').modal('show');
            @endif
            refresh();
        }());
    </script>
    <script src="{{ $portalOrigin }}/assets/erp/logistics-order-detail.js?v=20261014-2"></script>
    <script src="{{ $portalOrigin }}/assets/erp/shopify-orders-filters.js?v=6" defer></script>
@endpush
