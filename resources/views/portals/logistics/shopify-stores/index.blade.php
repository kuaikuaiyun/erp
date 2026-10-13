@extends('portal.layout')

@section('page_title', 'Shopify 店铺同步')

@php
    $portalOrigin = rtrim(request()->getSchemeAndHttpHost(), '/');
@endphp

@push('styles')
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/logistics.css?v=20261009-10">
@endpush

@section('content')
    <section class="content-header">
        <h1>Shopify 店铺同步 <small>保存店铺资料时自动登记 Webhook；订单同步按需人工执行</small></h1>
    </section>

    <section class="content logistics-page">
        @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
        @if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif

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
                    <i class="fa fa-refresh"></i> 批量同步近{{ config('logistics.shopify.sync_days', 10) }}天
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
                            <th>所属部门</th>
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
                                <td>厦门快云</td>
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
                                        <button class="btn btn-success btn-xs" type="submit"><i class="fa fa-refresh"></i> 同步近{{ config('logistics.shopify.sync_days', 10) }}天</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="14" class="text-center text-muted">暂无符合条件的 Shopify 店铺。</td></tr>
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
                syncButton.disabled = selectedItems.length === 0;
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
    </script>
@endpush
