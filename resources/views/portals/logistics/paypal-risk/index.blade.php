@extends('portal.layout')

@section('page_title', __('logistics.paypal_risk.title'))

@php
    $portalOrigin = rtrim(request()->getSchemeAndHttpHost(), '/');
    $riskLabels = ['low' => '低风险', 'medium' => '中风险', 'high' => '高风险'];
    $riskIcons = ['low' => 'fa-shield', 'medium' => 'fa-exclamation-triangle', 'high' => 'fa-warning'];
    $importLabels = ['never' => '未接收', 'processing' => '处理中', 'success' => '接收成功', 'partial' => '部分完成', 'failed' => '接收失败'];
    $importIcons = ['success' => 'fa-check-circle', 'partial' => 'fa-exclamation-triangle', 'failed' => 'fa-times-circle'];
    $riskClientConfig = [
        'routes' => [
            'store' => route('logistics.paypal-risk.accounts.store'),
            'update' => route('logistics.paypal-risk.accounts.update', ['account' => '__ID__']),
            'detail' => route('logistics.paypal-risk.accounts.detail', ['account' => '__ID__']),
        ],
        'filters' => [
            'date_from' => $filters['date_from'],
            'date_to' => $filters['date_to'],
        ],
        'labels' => [
            'sourceReason' => __('logistics.paypal_risk.ui.source_reason'),
            'manualReason' => __('logistics.paypal_risk.ui.manual_reason'),
            'linkedDisputes' => __('logistics.paypal_risk.ui.linked_disputes'),
            'outcome' => __('logistics.paypal_risk.ui.outcome'),
            'sellerResponseDueAt' => __('logistics.paypal_risk.ui.seller_response_due_at'),
            'updatedAt' => __('logistics.paypal_risk.ui.updated_at'),
            'linkedRefunds' => __('logistics.paypal_risk.ui.linked_refunds'),
        ],
    ];
@endphp

@push('styles')
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/paypal-risk.css?v=20261014-2">
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/logistics-order-detail.css?v=20261014-1">
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
                <div><small>整体纠纷率</small><strong>{{ number_format($portfolio['dispute_rate'], 2) }}%</strong><em>纠纷订单 / 总订单</em></div>
            </article>
        </div>

        <div class="box box-solid paypal-risk-filter-box">
            <div class="box-header with-border"><h3 class="box-title"><i class="fa fa-filter"></i> 筛选条件</h3></div>
            <div class="box-body">
                <form method="get" action="{{ route('logistics.paypal-risk.index') }}" class="paypal-risk-filter-form" autocomplete="off" data-loading="region" data-loading-target=".paypal-risk-page">
                    <div class="paypal-risk-filter-field paypal-risk-filter-keyword">
                        <label for="paypal-risk-keyword">账号搜索</label>
                        <div class="paypal-risk-search-input"><i class="fa fa-search"></i><input id="paypal-risk-keyword" class="form-control" name="keyword" value="{{ $filters['keyword'] }}" placeholder="账号名称、邮箱或Merchant ID"></div>
                    </div>
                    <div class="paypal-risk-filter-field">
                        <label for="paypal-risk-level">风险等级</label>
                        <select id="paypal-risk-level" class="form-control" name="risk">
                            <option value="">全部风险</option>
                            @foreach($riskLabels as $value => $label)<option value="{{ $value }}" @selected($filters['risk'] === $value)>{{ $label }}</option>@endforeach
                        </select>
                    </div>
                    <div class="paypal-risk-filter-field">
                        <label for="paypal-risk-import-status">接收状态</label>
                        <select id="paypal-risk-import-status" class="form-control" name="import_status">
                            <option value="">全部状态</option>
                            @foreach(['never' => '未接收', 'success' => '接收成功', 'partial' => '部分完成', 'failed' => '接收失败'] as $value => $label)<option value="{{ $value }}" @selected($filters['import_status'] === $value)>{{ $label }}</option>@endforeach
                        </select>
                    </div>
                    <div class="paypal-risk-filter-field paypal-risk-filter-date">
                        <label>订单创建时间</label>
                        <div><input class="form-control" type="date" name="date_from" value="{{ $filters['date_from'] }}"><span>至</span><input class="form-control" type="date" name="date_to" value="{{ $filters['date_to'] }}"></div>
                    </div>
                    <div class="paypal-risk-filter-actions">
                        <button class="btn btn-primary" type="submit"><i class="fa fa-search"></i> 查询</button>
                        <a class="btn btn-default" href="{{ route('logistics.paypal-risk.index') }}" data-loading="region" data-loading-target=".paypal-risk-page">重置</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="paypal-risk-list-heading">
            <div><h2>账号风险看板</h2><p>安全指数 = 100 − 退款率 × 2 − 纠纷率 × 10，最低为 0 分。</p></div>
            <div class="paypal-risk-legend"><span><i class="low"></i>低风险 ≥ 85</span><span><i class="medium"></i>中风险 ≥ 70</span><span><i class="high"></i>高风险 &lt; 70</span></div>
        </div>

        @if($accounts->count())
            <div class="paypal-risk-card-grid">
                @foreach($accounts as $account)
                    @php($model = $accountModels->get($account['id']))
                    <article class="paypal-risk-card risk-{{ $account['risk'] }} {{ $account['is_enabled'] ? '' : 'is-disabled' }}">
                        <div class="paypal-risk-card-accent"></div>
                        <header class="paypal-risk-card-header">
                            <span class="paypal-risk-brand-mark"><i class="fa fa-paypal"></i></span>
                            <div class="paypal-risk-account-identity">
                                <h3 title="{{ $account['account_name'] }}">{{ $account['account_name'] }}</h3>
                                <p title="{{ $account['account_email'] }}">{{ $account['masked_email'] }}</p>
                            </div>
                            <span class="paypal-risk-pill risk-{{ $account['risk'] }}"><i class="fa {{ $riskIcons[$account['risk']] }}"></i> {{ $riskLabels[$account['risk']] }}</span>
                        </header>

                        <div class="paypal-risk-score-row">
                            <div class="paypal-risk-score-ring" style="--risk-score: {{ $account['score'] }}"><strong>{{ number_format($account['score'], 1) }}</strong><small>安全指数</small></div>
                            <div class="paypal-risk-score-copy">
                                <span>Merchant ID</span><strong title="{{ $account['merchant_id'] }}">{{ $account['merchant_id'] }}</strong>
                                <em>{{ $account['environment'] === 'sandbox' ? 'Sandbox 测试环境' : 'Live 正式环境' }}</em>
                            </div>
                        </div>

                        <div class="paypal-risk-card-metrics">
                            <div><small>订单总量</small><strong>{{ number_format($account['order_count']) }}</strong></div>
                            <div><small>实收金额</small><strong>${{ number_format($account['paid_amount'], 2) }}</strong></div>
                            <div><small>关联店铺</small><strong>{{ number_format($account['store_count']) }}</strong></div>
                        </div>

                        <div class="paypal-risk-rate-block refund">
                            <div><span>退款率</span><strong>{{ number_format($account['refund_rate'], 2) }}%</strong></div>
                            <div class="paypal-risk-progress"><i style="width: {{ min(100, $account['refund_rate'] * 5) }}%"></i></div>
                            <small>{{ $account['refund_order_count'] }} 笔退款订单 · ${{ number_format($account['refund_amount'], 2) }}</small>
                        </div>
                        <div class="paypal-risk-rate-block dispute">
                            <div><span>纠纷率</span><strong>{{ number_format($account['dispute_rate'], 2) }}%</strong></div>
                            <div class="paypal-risk-progress"><i style="width: {{ min(100, $account['dispute_rate'] * 10) }}%"></i></div>
                            <small>{{ $account['dispute_order_count'] }} 笔纠纷订单 · ${{ number_format($account['dispute_amount'], 2) }}</small>
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
                    <div class="form-group col-sm-6"><label for="paypal-account-merchant">Merchant ID <span class="text-red">*</span></label><input id="paypal-account-merchant" class="form-control" name="merchant_id" maxlength="100" required placeholder="PayPal商户ID"></div>
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
                    <div class="tab-content"><div class="tab-pane active" id="paypal-detail-panel"><div id="paypal-detail-body"></div><div id="paypal-detail-pagination" class="paypal-risk-detail-pagination"></div></div></div>
                </div>
            </div>
            <div class="modal-footer"><span class="pull-left paypal-risk-score-note"><i class="fa fa-calculator"></i> 安全指数仅作内部风控参考。</span><button type="button" class="btn btn-default" data-dismiss="modal">关闭</button></div>
        </div></div>
    </div>
    @include('portals.logistics.partials.order-detail-modal')
@endsection

@push('scripts')
    <script>
        window.ErpPayPalRisk = @json($riskClientConfig);
    </script>
    <script src="{{ $portalOrigin }}/assets/erp/paypal-risk.js?v=20261014-3"></script>
    <script src="{{ $portalOrigin }}/assets/erp/logistics-order-detail.js?v=20261014-1"></script>
@endpush
