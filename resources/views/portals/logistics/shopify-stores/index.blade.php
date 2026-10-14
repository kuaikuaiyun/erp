@extends('portal.layout')

@section('page_title', 'Shopify 店铺同步')

@php
    $portalOrigin = rtrim(request()->getSchemeAndHttpHost(), '/');
@endphp

@push('styles')
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/logistics.css?v=20261014-1">
@endpush

@section('content')
    <section class="content-header">
        <h1>Shopify 店铺同步 <small>保存店铺资料时自动登记 Webhook；订单同步按需人工执行</small></h1>
    </section>

    <section class="content logistics-page">
        @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
        @if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif

        @if($activeSyncBatch)
            @php
                $syncProgress = $activeSyncBatch->progressPayload();
                $syncRunning = !$syncProgress['completed'];
                $syncTitle = match ($syncProgress['status']) {
                    'queued' => 'Shopify 同步任务排队中',
                    'processing' => 'Shopify 店铺后台同步中',
                    'cancelling' => '正在停止 Shopify 同步任务',
                    'cancelled' => 'Shopify 同步已停止',
                    default => 'Shopify 同步完成',
                };
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
                    已处理 {{ $syncProgress['processed'] }} / {{ $syncProgress['total'] }} 家；成功 {{ $syncProgress['success'] }} 家，失败 {{ $syncProgress['failed'] }} 家，已停止 {{ $syncProgress['cancelled'] }} 家；新增订单 {{ $syncProgress['created_orders'] }} 条，更新订单 {{ $syncProgress['updated_orders'] }} 条，Shopify SKU 缺失 {{ $syncProgress['sku_exceptions'] }} 单（{{ $syncProgress['sku_missing_items'] }} / {{ $syncProgress['sku_items'] }} 个商品明细），未付款跳过 {{ $syncProgress['skipped_unpaid'] }} 条，Webhook 警告 {{ $syncProgress['webhook_warnings'] }} 家。
                </div>
                <div class="logistics-shopify-sync-current {{ $syncProgress['current_store'] ? '' : 'hide' }}" data-shopify-sync-current>
                    <i class="fa fa-building-o"></i> 当前店铺：<strong>{{ $syncProgress['current_store'] }}</strong>
                </div>
                <div class="logistics-match-progress-note">
                    <i class="fa fa-clock-o"></i> 每家店铺固定同步近 {{ $syncProgress['sync_days'] }} 天；可关闭本页，后台任务不会中断，返回后仍会显示进度。
                </div>
                <div class="logistics-shopify-sync-actions">
                    <form method="post" action="{{ route('logistics.shopify-stores.sync-cancel', $activeSyncBatch) }}" data-shopify-sync-cancel-form class="{{ $syncProgress['can_cancel'] ? '' : 'hide' }}">
                        @csrf
                        <input type="hidden" name="return_to" value="{{ request()->fullUrl() }}">
                        <button type="submit" class="btn btn-warning btn-xs" onclick="return confirm('确认停止尚未开始的店铺吗？当前正在同步的店铺会正常完成。');">
                            <i class="fa fa-stop-circle"></i> 停止剩余任务
                        </button>
                    </form>
                </div>
                <div class="logistics-shopify-sync-issues {{ $syncIssueDetails === [] ? 'hide' : '' }}" data-shopify-sync-issues>
                    <details {{ $syncProgress['completed'] && $syncIssueDetails !== [] ? 'open' : '' }}>
                        <summary>查看异常与警告（<span data-shopify-sync-issue-count>{{ count($syncIssueDetails) }}</span>）</summary>
                        <div data-shopify-sync-issue-list>
                            @foreach($syncIssueDetails as $issue)
                                <div><strong>{{ $issue['store_name'] }}</strong>：{{ $issue['reason'] }}</div>
                            @endforeach
                        </div>
                    </details>
                </div>
            </div>
        @endif

        <div class="box box-solid logistics-tip-box">
            <div class="box-body">
                <i class="fa fa-info-circle text-aqua"></i>
                仅显示助理系统中“建站系统为 Shopify 且店铺状态为在用”的店铺。保存满足条件的店铺会自动登记订单创建、订单更新、退款创建三类 Webhook；下方“登记 Webhook”不会同步历史订单。
            </div>
        </div>

        <form id="shopify-batch-sync-form" method="post" action="{{ route('logistics.shopify-stores.batch-sync') }}">
            @csrf
            <input type="hidden" name="return_to" value="{{ request()->fullUrl() }}">
        </form>
        <form id="shopify-batch-webhook-form" method="post" action="{{ route('logistics.shopify-stores.batch-register-webhooks') }}">
            @csrf
            <input type="hidden" name="return_to" value="{{ request()->fullUrl() }}">
        </form>
        <div class="box box-solid logistics-table-box">
            <div class="box-header with-border logistics-list-toolbar">
                <h3 class="box-title">Shopify 店铺列表</h3>
                <button class="btn btn-primary btn-sm" type="submit" form="shopify-batch-webhook-form" id="batch-webhook-button" disabled>
                    <i class="fa fa-rss"></i> 批量登记 Webhook
                </button>
                <button class="btn btn-success btn-sm" type="submit" form="shopify-batch-sync-form" id="batch-sync-button" disabled>
                    <i class="fa fa-refresh"></i> 批量同步近{{ $manualSyncDays }}天
                </button>
                @include('portals.logistics.partials.pagination', ['paginator' => $stores, 'filters' => $filters, 'routeName' => 'logistics.shopify-stores.index'])
            </div>
            <div class="box-body table-responsive no-padding logistics-shopify-stores-scroll">
                <table class="table table-hover logistics-table logistics-shopify-stores-table">
                    <thead>
                        <tr>
                            <th class="logistics-sticky-left"><input id="shopify-store-check-all" type="checkbox" aria-label="全选店铺"></th>
                            <th>店铺名称</th>
                            <th>店铺登入网址</th>
                            <th>店铺前缀</th>
                            <th>通途前缀</th>
                            <th>运营人员</th>
                            <th>所属组</th>
                            <th>Token 更新时间</th>
                            <th>最近同步时间</th>
                            <th>最近同步结果/失败原因</th>
                            <th>Webhook 状态</th>
                            <th>Webhook 更新时间</th>
                            <th>Webhook 失败原因</th>
                            <th class="logistics-action-column">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($stores as $store)
                            @php
                                $connection = $store->shopifyConnection;
                                $tokenExpired = $connection?->token_obtained_at && (!$connection->token_expires_at || !$connection->token_expires_at->isFuture());
                                $tokenClass = !$connection?->token_obtained_at ? 'label-default' : ($tokenExpired ? 'label-danger' : 'label-success');
                                $tokenLabel = $connection?->token_obtained_at?->timezone('Asia/Shanghai')->format('Y-m-d H:i:s') ?: '未获取';
                                $tokenTitle = $connection?->token_expires_at?->timezone('Asia/Shanghai')->format('Y-m-d H:i:s') ? '有效至 '.$connection->token_expires_at->timezone('Asia/Shanghai')->format('Y-m-d H:i:s') : '';
                                $hasConnectionFailure = $connection && ($connection->last_sync_status === 'failed' || $connection->webhook_status === 'failed');
                            @endphp
                            <tr class="{{ $hasConnectionFailure ? 'logistics-row-failed' : '' }}">
                                <td class="logistics-sticky-left"><input class="shopify-store-check" type="checkbox" value="{{ $store->id }}" aria-label="选择 {{ $store->shop_name }}"></td>
                                <td>{{ $store->shop_name }}</td>
                                <td class="logistics-url-cell">{{ $store->shop_login_url ?: '—' }}</td>
                                <td>{{ $store->store_prefix ?: '—' }}</td>
                                <td>{{ $store->tongtu_prefix ?: '—' }}</td>
                                <td>{{ $store->user_name ?: '—' }}</td>
                                <td>{{ \App\Models\AssistantDomainStore::displayMultiValue($store->team) ?: '—' }}</td>
                                <td><span class="label {{ $tokenClass }} logistics-tag" title="{{ $tokenTitle }}">{{ $tokenLabel }}</span></td>
                                <td>{{ $connection?->last_synced_at?->timezone('Asia/Shanghai')->format('Y-m-d H:i:s') ?: '—' }}</td>
                                <td class="logistics-message-cell">{{ $connection?->last_sync_message ?: '—' }}</td>
                                <td><span class="label {{ $connection?->webhookStatusClass() ?: 'label-default' }} logistics-tag">{{ $connection?->webhook_status === 'active' ? '已登记' : ($connection?->webhook_status === 'failed' ? '登记失败' : '未登记') }}</span></td>
                                <td>{{ $connection?->webhook_registered_at?->timezone('Asia/Shanghai')->format('Y-m-d H:i:s') ?: '—' }}</td>
                                <td class="logistics-message-cell">{{ $connection?->webhook_last_error ?: '—' }}</td>
                                <td class="logistics-actions">
                                    <form method="post" action="{{ route('logistics.shopify-stores.register-webhooks', $store) }}" class="logistics-inline-form">
                                        @csrf
                                        <input type="hidden" name="return_to" value="{{ request()->fullUrl() }}">
                                        <button class="btn btn-primary btn-xs" type="submit"><i class="fa fa-rss"></i> 登记 Webhook</button>
                                    </form>
                                    <form method="post" action="{{ route('logistics.shopify-stores.sync', $store) }}" class="logistics-inline-form">
                                        @csrf
                                        <input type="hidden" name="return_to" value="{{ request()->fullUrl() }}">
                                        <button class="btn btn-success btn-xs" type="submit" {{ isset($syncRunning) && $syncRunning ? 'disabled' : '' }}><i class="fa fa-refresh"></i> 同步近{{ $manualSyncDays }}天</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="15" class="text-center text-muted">暂无符合条件的 Shopify 店铺。</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="box-footer clearfix">
                @include('portals.logistics.partials.pagination', ['paginator' => $stores, 'filters' => $filters, 'routeName' => 'logistics.shopify-stores.index'])
            </div>
        </div>
    </section>
@endsection

@push('scripts')
    <script>
        (function () {
            var all = document.getElementById('shopify-store-check-all');
            var syncButton = document.getElementById('batch-sync-button');
            var webhookButton = document.getElementById('batch-webhook-button');
            var syncForm = document.getElementById('shopify-batch-sync-form');
            var webhookForm = document.getElementById('shopify-batch-webhook-form');
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
                syncButton.disabled = syncLocked || selectedItems.length === 0;
                webhookButton.disabled = selectedItems.length === 0;
                all.checked = items().length > 0 && selectedItems.length === items().length;
                all.indeterminate = selectedItems.length > 0 && !all.checked;
            };
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
            var labels = {
                queued: 'Shopify 同步任务排队中',
                processing: 'Shopify 店铺后台同步中',
                cancelling: '正在停止 Shopify 同步任务',
                cancelled: 'Shopify 同步已停止',
                completed: 'Shopify 同步完成'
            };
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
                title.textContent = labels[data.status] || 'Shopify 店铺后台同步中';
                percent.textContent = data.percent + '%';
                bar.style.width = data.percent + '%';
                summary.textContent = '已处理 ' + data.processed + ' / ' + data.total + ' 家；成功 ' + data.success + ' 家，失败 ' + data.failed + ' 家，已停止 ' + data.cancelled + ' 家；新增订单 ' + data.created_orders + ' 条，更新订单 ' + data.updated_orders + ' 条，Shopify SKU 缺失 ' + data.sku_exceptions + ' 单（' + data.sku_missing_items + ' / ' + data.sku_items + ' 个商品明细），未付款跳过 ' + data.skipped_unpaid + ' 条，Webhook 警告 ' + data.webhook_warnings + ' 家。';
                if (data.current_store) {
                    current.innerHTML = '<i class="fa fa-building-o"></i> 当前店铺：<strong>' + escapeHtml(data.current_store) + '</strong>';
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
                    title.textContent = '进度读取暂时失败，系统仍在后台同步';
                });
            };

            var timer = window.setInterval(poll, 3000);
            poll();
        }());
    </script>
@endpush
