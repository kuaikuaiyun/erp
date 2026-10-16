@extends('portal.layout')

@section('page_title', __('logistics.paypal_risk.title'))

@php
    $portalOrigin = rtrim(request()->getSchemeAndHttpHost(), '/');
    $importLabels = ['never' => '未接收', 'processing' => '处理中', 'success' => '接收成功', 'partial' => '部分完成', 'failed' => '接收失败'];
    $importIcons = ['success' => 'fa-check-circle', 'partial' => 'fa-exclamation-triangle', 'failed' => 'fa-times-circle'];
    $riskClientConfig = [
        'routes' => [
            'store' => route('logistics.paypal-risk.accounts.store'),
            'update' => route('logistics.paypal-risk.accounts.update', ['account' => '__ID__']),
            'detail' => route('logistics.paypal-risk.accounts.detail', ['account' => '__ID__']),
            'export' => route('logistics.paypal-risk.accounts.export', ['account' => '__ID__', 'tab' => '__TAB__']),
        ],
        'filters' => [
            'date_from' => $filters['date_from'],
            'date_to' => $filters['date_to'],
            'event_date_from' => $filters['event_date_from'],
            'event_date_to' => $filters['event_date_to'],
            'date_mode' => $filters['date_mode'],
        ],
        'labels' => [
            'sourceReason' => __('logistics.paypal_risk.ui.source_reason'),
            'manualReason' => __('logistics.paypal_risk.ui.manual_reason'),
            'linkedDisputes' => __('logistics.paypal_risk.ui.linked_disputes'),
            'disputeType' => __('logistics.paypal_risk.ui.dispute_type'),
            'outcome' => __('logistics.paypal_risk.ui.outcome'),
            'sellerResponseDueAt' => __('logistics.paypal_risk.ui.seller_response_due_at'),
            'updatedAt' => __('logistics.paypal_risk.ui.updated_at'),
            'linkedRefunds' => __('logistics.paypal_risk.ui.linked_refunds'),
            'trackingNumber' => __('logistics.paypal_risk.ui.tracking_number'),
            'logisticsStatus' => __('logistics.paypal_risk.ui.logistics_status'),
            'paymentTransactionId' => __('logistics.paypal_risk.ui.payment_transaction_id'),
            'paymentTime' => __('logistics.paypal_risk.ui.payment_time'),
            'matchStatus' => __('logistics.paypal_risk.ui.match_status'),
            'shopifyOrderNumber' => __('logistics.paypal_risk.ui.shopify_order_number'),
            'tongtuOrderNumber' => __('logistics.paypal_risk.ui.tongtu_order_number'),
            'storeName' => __('logistics.paypal_risk.ui.store_name'),
            'products' => __('logistics.paypal_risk.ui.products'),
            'paidAmount' => __('logistics.paypal_risk.ui.paid_amount'),
            'refundStatus' => __('logistics.paypal_risk.ui.refund_status'),
            'disputeStatus' => __('logistics.paypal_risk.ui.dispute_status'),
            'shippingRate' => __('logistics.paypal_risk.ui.shipping_rate'),
            'deliveryRate' => __('logistics.paypal_risk.ui.delivery_rate'),
            'paymentCount' => __('logistics.paypal_risk.ui.payment_count'),
            'receivedAmount' => __('logistics.paypal_risk.ui.received_amount'),
            'filteredRefundRate' => __('logistics.paypal_risk.ui.filtered_refund_rate'),
            'filteredDisputeRate' => __('logistics.paypal_risk.ui.filtered_dispute_rate'),
            'exportCurrentTab' => __('logistics.paypal_risk.export.button'),
            'detailFilterKeyword' => __('logistics.paypal_risk.detail_filters.keyword'),
            'detailFilterSearch' => __('logistics.paypal_risk.detail_filters.search'),
            'detailFilterReset' => __('logistics.paypal_risk.detail_filters.reset'),
            'detailFilterStatus' => __('logistics.paypal_risk.detail_filters.status'),
            'detailFilterType' => __('logistics.paypal_risk.detail_filters.type'),
            'detailFilterOutcome' => __('logistics.paypal_risk.detail_filters.outcome'),
            'detailFilterMatchStatus' => __('logistics.paypal_risk.detail_filters.match_status'),
            'detailFilterAll' => __('logistics.paypal_risk.detail_filters.all'),
            'detailFilterMatched' => __('logistics.paypal_risk.detail_filters.matched'),
            'detailFilterUnmatched' => __('logistics.paypal_risk.detail_filters.unmatched'),
            'detailFilterPlaceholders' => [
                'orders' => __('logistics.paypal_risk.detail_filters.orders_placeholder'),
                'products' => __('logistics.paypal_risk.detail_filters.products_placeholder'),
                'refunds' => __('logistics.paypal_risk.detail_filters.refunds_placeholder'),
                'disputes' => __('logistics.paypal_risk.detail_filters.disputes_placeholder'),
            ],
            'detailFilterOptions' => [
                'refund_status' => [
                    \App\Models\LogisticsPayPalRefund::statusLabelFor('D'),
                    \App\Models\LogisticsPayPalRefund::statusLabelFor('P'),
                    \App\Models\LogisticsPayPalRefund::statusLabelFor('S'),
                    \App\Models\LogisticsPayPalRefund::statusLabelFor('V'),
                ],
                'dispute_type' => [
                    \App\Models\LogisticsPayPalDispute::typeLabelFor('INQUIRY'),
                    \App\Models\LogisticsPayPalDispute::typeLabelFor('CHARGEBACK'),
                    \App\Models\LogisticsPayPalDispute::typeLabelFor('PRE_ARBITRATION'),
                    \App\Models\LogisticsPayPalDispute::typeLabelFor('ARBITRATION'),
                ],
                'dispute_status' => [
                    \App\Models\LogisticsPayPalDispute::statusLabelFor('OPEN'),
                    \App\Models\LogisticsPayPalDispute::statusLabelFor('WAITING_FOR_BUYER_RESPONSE'),
                    \App\Models\LogisticsPayPalDispute::statusLabelFor('WAITING_FOR_SELLER_RESPONSE'),
                    \App\Models\LogisticsPayPalDispute::statusLabelFor('UNDER_REVIEW'),
                    \App\Models\LogisticsPayPalDispute::statusLabelFor('RESOLVED'),
                ],
                'dispute_outcome' => [
                    \App\Models\LogisticsPayPalDispute::outcomeLabelFor('RESOLVED_BUYER_FAVOUR'),
                    \App\Models\LogisticsPayPalDispute::outcomeLabelFor('RESOLVED_SELLER_FAVOUR'),
                    \App\Models\LogisticsPayPalDispute::outcomeLabelFor('CANCELED_BY_BUYER'),
                    \App\Models\LogisticsPayPalDispute::outcomeLabelFor('CANCELED_BY_SELLER'),
                    \App\Models\LogisticsPayPalDispute::outcomeLabelFor('RESOLVED_WITH_REFUND'),
                    \App\Models\LogisticsPayPalDispute::outcomeLabelFor('RESOLVED_WITH_PAYOUT'),
                ],
            ],
        ],
    ];
@endphp

@push('styles')
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/paypal-risk.css?v=20261016-3">
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/logistics-order-detail.css?v=20261015-2">
@endpush

@section('content')
    <section class="content-header paypal-risk-page-header">
        <div>
            <h1>{{ __('logistics.paypal_risk.title') }} <small>{{ __('logistics.paypal_risk.subtitle') }}</small></h1>
        </div>
        <div class="paypal-risk-header-actions">
            <button class="btn btn-default" type="button" data-paypal-account-create><i class="fa fa-plus"></i> 新增PayPal账号</button>
        </div>
    </section>

    <section class="content paypal-risk-page">
        @if(session('success'))
            <div class="alert alert-success"><i class="fa fa-check-circle"></i> {{ session('success') }}</div>
        @endif
        @if(session('error'))
            <div class="alert alert-danger"><i class="fa fa-exclamation-circle"></i> {{ session('error') }}</div>
        @endif
        @if(session('warning'))
            <div class="alert alert-warning"><i class="fa fa-exclamation-triangle"></i> {{ session('warning') }}</div>
        @endif
        @if($errors->any())
            <div class="alert alert-danger"><i class="fa fa-exclamation-circle"></i> {{ $errors->first() }}</div>
        @endif
        <div class="alert alert-info paypal-risk-passive-note"><i class="fa fa-shield"></i> {{ __('logistics.paypal_risk.ui.passive_note') }}</div>

        <div class="paypal-risk-kpis" aria-label="当前筛选概览">
            <article class="paypal-risk-kpi">
                <span class="paypal-risk-kpi-icon tone-blue"><i class="fa fa-paypal"></i></span>
                <div><small>PayPal账号</small><strong>{{ number_format($portfolio['accounts']) }}</strong><em>当前筛选范围</em></div>
            </article>
            <article class="paypal-risk-kpi">
                <span class="paypal-risk-kpi-icon tone-indigo"><i class="fa fa-shopping-bag"></i></span>
                <div><small>支付订单</small><strong>{{ number_format($portfolio['orders']) }}</strong><em>已付款订单</em></div>
            </article>
            <article class="paypal-risk-kpi">
                <span class="paypal-risk-kpi-icon tone-cyan"><i class="fa fa-usd"></i></span>
                <div><small>实收金额（USD）</small><strong>${{ number_format($portfolio['paid_amount'], 2) }}</strong><em>订单实付汇总</em></div>
            </article>
            <article class="paypal-risk-kpi">
                <span class="paypal-risk-kpi-icon tone-orange"><i class="fa fa-reply"></i></span>
                <div><small>整体退款率</small><strong>{{ number_format($portfolio['refund_rate'], 2) }}%</strong><em>退款订单 / 总订单</em></div>
            </article>
            <article class="paypal-risk-kpi">
                <span class="paypal-risk-kpi-icon tone-red"><i class="fa fa-gavel"></i></span>
                <div><small title="{{ __('logistics.paypal_risk.ui.dispute_rate_hint') }}">整体纠纷率（按笔数）</small><strong>{{ number_format($portfolio['dispute_rate'], 2) }}%</strong><em>计入纠纷订单 / 总订单</em></div>
            </article>
        </div>

        <div class="box box-solid paypal-risk-filter-box">
            <div class="box-header with-border"><h3 class="box-title"><i class="fa fa-filter"></i> 筛选条件</h3></div>
            <div class="box-body">
                <form method="get" action="{{ route('logistics.paypal-risk.index') }}" class="paypal-risk-filter-form" autocomplete="off" data-loading="region" data-loading-target=".paypal-risk-page">
                    <div class="paypal-risk-filter-field paypal-risk-filter-keyword">
                        <label for="paypal-risk-keyword">{{ __('logistics.paypal_risk.filters.keyword') }}</label>
                        <div class="paypal-risk-search-input"><i class="fa fa-search"></i><input id="paypal-risk-keyword" class="form-control" name="keyword" value="{{ $filters['keyword'] }}" placeholder="{{ __('logistics.paypal_risk.filters.keyword_placeholder') }}"></div>
                    </div>
                    <div class="paypal-risk-filter-field paypal-risk-filter-date-mode">
                        <label>{{ __('logistics.paypal_risk.filters.date_mode') }}</label>
                        <input type="hidden" name="date_mode" value="{{ $filters['date_mode'] }}" data-paypal-date-mode-input>
                        <div class="btn-group" role="group" aria-label="{{ __('logistics.paypal_risk.filters.date_mode') }}">
                            <button type="button" class="btn btn-default {{ $filters['date_mode'] === 'order' ? 'active' : '' }}" data-paypal-date-mode="order">{{ __('logistics.paypal_risk.filters.payment_date') }}</button>
                            <button type="button" class="btn btn-default {{ $filters['date_mode'] === 'event' ? 'active' : '' }}" data-paypal-date-mode="event">{{ __('logistics.paypal_risk.filters.event_date') }}</button>
                        </div>
                    </div>
                    <div class="paypal-risk-filter-field paypal-risk-filter-date" data-paypal-date-panel="order" @if($filters['date_mode'] !== 'order') hidden @endif>
                        <label>{{ __('logistics.paypal_risk.filters.payment_date') }}</label>
                        <div><input class="form-control" type="date" name="date_from" value="{{ $filters['date_from'] }}" @disabled($filters['date_mode'] !== 'order')><span>{{ __('logistics.paypal_risk.filters.range_to') }}</span><input class="form-control" type="date" name="date_to" value="{{ $filters['date_to'] }}" @disabled($filters['date_mode'] !== 'order')></div>
                    </div>
                    <div class="paypal-risk-filter-field paypal-risk-filter-date" data-paypal-date-panel="event" @if($filters['date_mode'] !== 'event') hidden @endif>
                        <label>{{ __('logistics.paypal_risk.filters.event_date') }}</label>
                        <div><input class="form-control" type="date" name="event_date_from" value="{{ $filters['event_date_from'] }}" @disabled($filters['date_mode'] !== 'event')><span>{{ __('logistics.paypal_risk.filters.range_to') }}</span><input class="form-control" type="date" name="event_date_to" value="{{ $filters['event_date_to'] }}" @disabled($filters['date_mode'] !== 'event')></div>
                    </div>
                    <div class="paypal-risk-filter-actions">
                        <button class="btn btn-primary" type="submit"><i class="fa fa-search"></i> 查询</button>
                        <a class="btn btn-default" href="{{ route('logistics.paypal-risk.index') }}" data-loading="region" data-loading-target=".paypal-risk-page">重置</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="paypal-risk-list-heading">
            <div><h2>{{ __('logistics.paypal_risk.ui.account_dashboard') }}</h2></div>
        </div>

        @if($accounts->count())
            <div class="paypal-risk-card-grid">
                @foreach($accounts as $account)
                    @php($model = $accountModels->get($account['id']))
                    <article class="paypal-risk-card {{ $account['is_enabled'] ? '' : 'is-disabled' }}">
                        <div class="paypal-risk-card-accent"></div>
                        <header class="paypal-risk-card-header">
                            <span class="paypal-risk-brand-mark"><i class="fa fa-paypal"></i></span>
                            <div class="paypal-risk-account-identity">
                                <h3 title="{{ $account['account_name'] }}">{{ $account['account_name'] }}</h3>
                                <p title="{{ $account['account_email'] }}">{{ $account['masked_email'] }}</p>
                                <div class="paypal-risk-account-meta">
                                    <span>{{ __('logistics.paypal_risk.ui.merchant_id') }}</span><strong title="{{ $account['merchant_id'] }}">{{ $account['merchant_id'] }}</strong>
                                    <em>{{ $account['environment'] === 'sandbox' ? __('logistics.paypal_risk.ui.sandbox_environment') : __('logistics.paypal_risk.ui.live_environment') }}</em>
                                </div>
                            </div>
                            <div class="paypal-risk-card-scope-switch" role="group" aria-label="{{ __('logistics.paypal_risk.ui.metric_scope') }}">
                                <button type="button" class="btn btn-default btn-xs is-active" data-paypal-card-scope="current">{{ __('logistics.paypal_risk.ui.current_scope') }}</button>
                                <button type="button" class="btn btn-default btn-xs" data-paypal-card-scope="history">{{ __('logistics.paypal_risk.ui.history_scope') }}</button>
                            </div>
                        </header>

                        <div class="paypal-risk-card-scope-panel" data-paypal-card-scope-panel="current">
                        <div class="paypal-risk-card-metrics">
                            <div><small>{{ __('logistics.paypal_risk.ui.payment_count') }}</small><strong>{{ number_format($account['order_count']) }}</strong></div>
                            <div><small>{{ __('logistics.paypal_risk.ui.received_amount') }}</small><strong>${{ number_format($account['paid_amount'], 2) }}</strong></div>
                            <div><small>{{ __('logistics.paypal_risk.ui.linked_store_count') }}</small><strong>{{ number_format($account['store_count']) }}</strong></div>
                            <div><small>{{ __('logistics.paypal_risk.ui.shipping_rate') }}</small><strong>{{ number_format($account['shipping_rate'], 2) }}%</strong></div>
                            <div><small>{{ __('logistics.paypal_risk.ui.delivery_rate') }}</small><strong>{{ number_format($account['delivery_rate'], 2) }}%</strong></div>
                        </div>

                        <div class="paypal-risk-rate-block refund">
                            <div><span>{{ __('logistics.paypal_risk.ui.filtered_refund_rate') }}</span><strong>{{ number_format($account['refund_rate'], 2) }}%</strong></div>
                            <div class="paypal-risk-progress"><i style="width: {{ min(100, $account['refund_rate'] * 5) }}%"></i></div>
                            <div class="paypal-risk-rate-note">
                                <span>{{ __('logistics.paypal_risk.ui.refund_order_summary', ['count' => $account['refund_order_count'], 'amount' => number_format($account['refund_amount'], 2)]) }}</span>
                                <span>{{ __('logistics.paypal_risk.ui.cumulative_refund_rate') }} {{ number_format($account['cumulative_refund_rate'], 2) }}%</span>
                            </div>
                        </div>
                        <div class="paypal-risk-rate-block dispute">
                            <div><span title="{{ __('logistics.paypal_risk.ui.dispute_rate_hint') }}">{{ __('logistics.paypal_risk.ui.filtered_dispute_rate') }}</span><strong>{{ number_format($account['dispute_rate'], 2) }}%</strong></div>
                            <div class="paypal-risk-progress"><i style="width: {{ min(100, $account['dispute_rate'] * 10) }}%"></i></div>
                            <div class="paypal-risk-rate-note">
                                <span>{{ __('logistics.paypal_risk.ui.dispute_order_summary', ['count' => $account['dispute_order_count'], 'amount' => number_format($account['dispute_amount'], 2)]) }}</span>
                                <span>{{ __('logistics.paypal_risk.ui.dispute_amount_rate', ['rate' => number_format($account['dispute_amount_rate'], 2).'%']) }}</span>
                            </div>
                        </div>
                        <p class="paypal-risk-matched-only-note"><i class="fa fa-info-circle"></i> {{ __('logistics.paypal_risk.ui.matched_only_note') }}</p>
                        </div>
                        <div class="paypal-risk-card-scope-panel" data-paypal-card-scope-panel="history" hidden>
                            <div class="paypal-risk-card-metrics">
                                <div><small>{{ __('logistics.paypal_risk.ui.payment_count') }}</small><strong>{{ number_format($account['history']['order_count']) }}</strong></div>
                                <div><small>{{ __('logistics.paypal_risk.ui.received_amount') }}</small><strong>${{ number_format($account['history']['paid_amount'], 2) }}</strong></div>
                                <div><small>{{ __('logistics.paypal_risk.ui.linked_store_count') }}</small><strong>{{ number_format($account['history']['store_count']) }}</strong></div>
                                <div><small>{{ __('logistics.paypal_risk.ui.shipping_rate') }}</small><strong>{{ number_format($account['history']['shipping_rate'], 2) }}%</strong></div>
                                <div><small>{{ __('logistics.paypal_risk.ui.delivery_rate') }}</small><strong>{{ number_format($account['history']['delivery_rate'], 2) }}%</strong></div>
                            </div>
                            <div class="paypal-risk-rate-block refund">
                                <div><span>{{ __('logistics.paypal_risk.ui.history_refund_rate') }}</span><strong>{{ number_format($account['history']['refund_rate'], 2) }}%</strong></div>
                                <div class="paypal-risk-progress"><i style="width: {{ min(100, $account['history']['refund_rate'] * 5) }}%"></i></div>
                                <div class="paypal-risk-rate-note"><span>{{ __('logistics.paypal_risk.ui.refund_order_summary', ['count' => $account['history']['refund_order_count'], 'amount' => number_format($account['history']['refund_amount'], 2)]) }}</span></div>
                            </div>
                            <div class="paypal-risk-rate-block dispute">
                                <div><span title="{{ __('logistics.paypal_risk.ui.dispute_rate_hint') }}">{{ __('logistics.paypal_risk.ui.history_dispute_rate') }}</span><strong>{{ number_format($account['history']['dispute_rate'], 2) }}%</strong></div>
                                <div class="paypal-risk-progress"><i style="width: {{ min(100, $account['history']['dispute_rate'] * 10) }}%"></i></div>
                                <div class="paypal-risk-rate-note">
                                    <span>{{ __('logistics.paypal_risk.ui.dispute_order_summary', ['count' => $account['history']['dispute_order_count'], 'amount' => number_format($account['history']['dispute_amount'], 2)]) }}</span>
                                    <span>{{ __('logistics.paypal_risk.ui.dispute_amount_rate', ['rate' => number_format($account['history']['dispute_amount_rate'], 2).'%']) }}</span>
                                </div>
                            </div>
                            <p class="paypal-risk-matched-only-note"><i class="fa fa-info-circle"></i> {{ __('logistics.paypal_risk.ui.matched_only_note') }}</p>
                        </div>

                        <footer class="paypal-risk-card-footer">
                            <div class="paypal-risk-sync-state sync-{{ $account['last_import_status'] }}" title="{{ $account['last_import_message'] }}">
                                <i class="fa {{ $importIcons[$account['last_import_status']] ?? 'fa-clock-o' }}"></i>
                                <span>{{ $importLabels[$account['last_import_status']] ?? $account['last_import_status'] }}</span>
                                <small>{{ $account['last_imported_at'] }}</small>
                            </div>
                            <div class="paypal-risk-card-actions">
                                <button type="button" class="btn btn-default btn-sm" data-paypal-detail="{{ $account['id'] }}"><i class="fa fa-bar-chart"></i> 查看</button>
                                <button type="button" class="btn btn-default btn-sm" data-paypal-account-edit
                                    data-id="{{ $account['id'] }}"
                                    data-name="{{ $model?->account_name }}"
                                    data-merchant="{{ $model?->merchant_id }}"
                                    data-email="{{ $model?->account_email }}"
                                    data-environment="{{ $model?->environment }}"
                                    data-enabled="{{ $model?->is_enabled ? '1' : '0' }}"><i class="fa fa-pencil"></i> 编辑</button>
                            </div>
                        </footer>
                        @if($account['unmatched_count'] > 0)<div class="paypal-risk-unmatched"><i class="fa fa-unlink"></i> 有 {{ $account['unmatched_count'] }} 条 PayPal 记录尚未匹配本地订单</div>@endif
                    </article>
                @endforeach
            </div>

            <div class="box box-solid paypal-risk-pagination-box" data-loading="region" data-loading-target=".paypal-risk-page"><div class="box-body paypal-risk-pagination">
                <span>显示 {{ $accounts->firstItem() ?? 0 }}-{{ $accounts->lastItem() ?? 0 }} 条，共 {{ $accounts->total() }} 个账号</span>
                <div class="paypal-risk-pagination-right">
                    @if($accounts->lastPage() > 1)
                        <ul class="pagination pagination-sm no-margin">
                            <li class="{{ $accounts->onFirstPage() ? 'disabled' : '' }}"><a href="{{ $accounts->previousPageUrl() ?: '#' }}">&laquo;</a></li>
                            @for($page = 1; $page <= $accounts->lastPage(); $page++)
                                @if($page === 1 || $page === $accounts->lastPage() || abs($page - $accounts->currentPage()) <= 1)
                                    <li class="{{ $page === $accounts->currentPage() ? 'active' : '' }}"><a href="{{ $accounts->url($page) }}">{{ $page }}</a></li>
                                @elseif($page === 2 || $page === $accounts->lastPage() - 1)
                                    <li class="disabled"><span>…</span></li>
                                @endif
                            @endfor
                            <li class="{{ $accounts->hasMorePages() ? '' : 'disabled' }}"><a href="{{ $accounts->nextPageUrl() ?: '#' }}">&raquo;</a></li>
                        </ul>
                    @endif
                    <form method="get" action="{{ route('logistics.paypal-risk.index') }}" data-loading="region" data-loading-target=".paypal-risk-page">
                        @foreach($filters as $name => $value)@if($name !== 'per_page')<input type="hidden" name="{{ $name }}" value="{{ $value }}">@endif @endforeach
                        <label>每页显示 <select class="form-control input-sm" name="per_page" onchange="this.form.submit()">@foreach([6, 12, 24] as $size)<option value="{{ $size }}" @selected($filters['per_page'] === $size)>{{ $size }}</option>@endforeach</select> 个</label>
                    </form>
                </div>
            </div></div>
        @else
            <div class="box box-solid paypal-risk-empty"><div class="box-body"><i class="fa fa-paypal"></i><h3>暂无符合条件的PayPal账号</h3><p>请调整筛选条件，或先新增一个PayPal账号。</p><button class="btn btn-primary" type="button" data-paypal-account-create><i class="fa fa-plus"></i> 新增账号</button></div></div>
        @endif
    </section>

    <div class="modal fade" id="paypal-account-modal" tabindex="-1" role="dialog" aria-labelledby="paypal-account-modal-title">
        <div class="modal-dialog" role="document"><form id="paypal-account-form" method="post" action="{{ route('logistics.paypal-risk.accounts.store') }}" class="modal-content" data-loading="button">@csrf
            <input id="paypal-account-method" type="hidden" name="_method" value="POST" disabled>
            <input type="hidden" name="return_to" value="{{ request()->fullUrl() }}">
            <div class="modal-header"><button type="button" class="close" data-dismiss="modal"><span>&times;</span></button><h4 class="modal-title" id="paypal-account-modal-title"><i class="fa fa-paypal"></i> 新增PayPal账号</h4></div>
            <div class="modal-body">
                <div class="alert alert-info paypal-risk-account-note"><i class="fa fa-info-circle"></i> {{ __('logistics.paypal_risk.ui.account_note') }}</div>
                <div class="row">
                    <div class="form-group col-sm-6"><label for="paypal-account-name">账号名称 <span class="text-red">*</span></label><input id="paypal-account-name" class="form-control" name="account_name" maxlength="191" required placeholder="例如：香港主体PP-01"></div>
                    <div class="form-group col-sm-6"><label for="paypal-account-environment">接口环境 <span class="text-red">*</span></label><select id="paypal-account-environment" class="form-control" name="environment" required><option value="live">Live 正式环境</option><option value="sandbox">Sandbox 测试环境</option></select></div>
                    <div class="form-group col-sm-6"><label for="paypal-account-merchant">{{ __('logistics.paypal_risk.ui.merchant_id') }} <span class="text-red">*</span></label><input id="paypal-account-merchant" class="form-control" name="merchant_id" maxlength="100" required placeholder="PayPal商户ID"></div>
                    <div class="form-group col-sm-6"><label for="paypal-account-email">PayPal账号邮箱</label><input id="paypal-account-email" class="form-control" name="account_email" type="email" maxlength="191" placeholder="merchant@example.com"></div>
                    <div class="form-group col-sm-12 paypal-risk-enabled-control"><input type="hidden" name="is_enabled" value="0"><label><input id="paypal-account-enabled" type="checkbox" name="is_enabled" value="1" checked> 启用账号（停用后保留历史数据，但拒绝接收新文件）</label></div>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">取消</button><button type="submit" class="btn btn-primary"><i class="fa fa-save"></i> 保存账号</button></div>
        </form></div>
    </div>

    <div class="modal fade" id="paypal-detail-modal" tabindex="-1" role="dialog" aria-labelledby="paypal-detail-modal-title">
        <div class="modal-dialog modal-lg paypal-risk-detail-dialog" role="document"><div class="modal-content">
            <div class="modal-header"><button type="button" class="close" data-dismiss="modal"><span>&times;</span></button><div class="paypal-risk-detail-title"><span class="paypal-risk-brand-mark"><i class="fa fa-paypal"></i></span><div><h4 class="modal-title" id="paypal-detail-modal-title">账号经营与风险明细</h4><p id="paypal-detail-subtitle">正在加载账号数据…</p></div></div></div>
            <div class="modal-body">
                <div id="paypal-detail-loading" class="paypal-risk-detail-loading"><i class="fa fa-spinner fa-spin"></i><span>正在加载风险数据</span></div>
                <div id="paypal-detail-error" class="alert alert-danger" hidden></div>
                <div id="paypal-detail-content" hidden>
                    <div id="paypal-detail-summary" class="paypal-risk-detail-summary"></div>
                    <ul class="nav nav-tabs paypal-risk-tabs" role="tablist">
                        <li class="active"><a href="#paypal-detail-panel" data-toggle="tab" data-paypal-tab="overview">风险概览</a></li>
                        <li><a href="#paypal-detail-panel" data-toggle="tab" data-paypal-tab="orders">订单明细</a></li>
                        <li><a href="#paypal-detail-panel" data-toggle="tab" data-paypal-tab="products">产品表现</a></li>
                        <li><a href="#paypal-detail-panel" data-toggle="tab" data-paypal-tab="refunds">退款记录</a></li>
                        <li><a href="#paypal-detail-panel" data-toggle="tab" data-paypal-tab="disputes">纠纷记录</a></li>
                    </ul>
                    <div id="paypal-detail-filters" class="paypal-risk-detail-filters" hidden></div>
                    <div class="tab-content"><div class="tab-pane active" id="paypal-detail-panel"><div id="paypal-detail-body"></div><div id="paypal-detail-pagination" class="paypal-risk-detail-pagination"></div></div></div>
                </div>
            </div>
            <div class="modal-footer"><span class="pull-left paypal-risk-modal-note"><i class="fa fa-calculator"></i> {{ __('logistics.paypal_risk.ui.shipping_note') }}</span><button id="paypal-detail-export" type="button" class="btn btn-success" hidden><i class="fa fa-download"></i> {{ __('logistics.paypal_risk.export.button') }}</button><button type="button" class="btn btn-default" data-dismiss="modal">关闭</button></div>
        </div></div>
    </div>
    @include('portals.logistics.partials.order-detail-modal')
@endsection

@push('scripts')
    <script>
        window.ErpPayPalRisk = @json($riskClientConfig);
    </script>
    <script src="{{ $portalOrigin }}/assets/erp/paypal-risk.js?v=20261016-1"></script>
    <script src="{{ $portalOrigin }}/assets/erp/logistics-order-detail.js?v=20261014-1"></script>
@endpush
