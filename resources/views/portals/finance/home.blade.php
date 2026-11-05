@extends('portals.finance.layout', ['title' => __('finance.menus.home')])
@push('styles')
<link rel="stylesheet" href="{{ request()->getSchemeAndHttpHost() }}/assets/erp/finance-workbench.css?v=20261104-4">
@endpush
@section('finance_content')
<div id="finance-workbench">
    <div class="finance-balance-overview" aria-label="{{ __('finance.workbench.overview') }}">
        @include('portals.finance.partials.paypal-summary')
        @include('portals.finance.partials.credit-summary')
    </div>
    <div class="finance-balance-tabs" role="tablist" aria-label="{{ __('finance.workbench.channels') }}">
        @foreach(['paypal' => 'paypal', 'credit-card' => 'credit_card'] as $tab => $label)
        <button type="button" id="finance-tab-{{ $tab }}" role="tab" data-balance-tab="{{ $tab }}" aria-controls="finance-panel-{{ $tab }}" aria-selected="{{ $activeBalanceTab === $tab ? 'true' : 'false' }}" tabindex="{{ $activeBalanceTab === $tab ? '0' : '-1' }}" class="finance-balance-tab {{ $activeBalanceTab === $tab ? 'is-active' : '' }}"><i class="fa {{ $tab === 'paypal' ? 'fa-paypal' : 'fa-credit-card' }}" aria-hidden="true"></i> {{ __('finance.workbench.'.$label) }}</button>
        @endforeach
    </div>
    <div id="finance-panel-paypal" role="tabpanel" aria-labelledby="finance-tab-paypal" tabindex="0" @if($activeBalanceTab !== 'paypal') hidden @endif>
        @include('portals.finance.partials.paypal-balance')
    </div>
    <div id="finance-panel-credit-card" role="tabpanel" aria-labelledby="finance-tab-credit-card" tabindex="0" @if($activeBalanceTab !== 'credit-card') hidden @endif>
        @include('portals.finance.partials.payment-balance')
    </div>
    @if($canManage)
        <details><summary>最近同步记录</summary><div class="finance-table-wrap"><table class="table finance-table"><thead><tr><th>账户</th><th>操作</th><th>状态</th><th>已保存页数 / 条数</th><th>结果说明</th><th>发起时间（北京时间）</th></tr></thead><tbody>@forelse($recentSyncLogs as $log)<tr><td>{{ \App\Portals\Finance\FinanceCatalog::simplified((string) ($log->account_name)) }}</td><td>{{ \App\Portals\Finance\FinanceCatalog::simplified((string) ($log->operation === 'balances' ? '刷新余额' : '同步交易')) }}</td><td>{{ ['running' => '执行中', 'success' => '成功', 'failed' => '失败'][$log->status] ?? $log->status }}</td><td>{{ $log->page_count }} / {{ $log->record_count }}</td><td>{{ \App\Portals\Finance\FinanceCatalog::simplified((string) ($log->error_code ? __('finance.sync.errors.'.$log->error_code) : '—')) }}</td><td>{{ \Carbon\CarbonImmutable::parse($log->created_at, 'UTC')->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s') }}</td></tr>@empty<tr><td colspan="6">暂无同步记录</td></tr>@endforelse</tbody></table></div></details>
        @include('portals.finance.partials.payment-account-create')
    @endif
</div>
@endsection
@push('scripts')
<script src="{{ request()->getSchemeAndHttpHost() }}/assets/erp/finance-workbench.js?v=20261103-3"></script>
@if($canManage)<script src="{{ request()->getSchemeAndHttpHost() }}/assets/erp/finance-payment-accounts.js?v=20261104-4"></script>@endif
@endpush
