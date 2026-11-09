@extends('portals.finance.layout', ['title' => __('finance.menus.home')])
@push('styles')
<link rel="stylesheet" href="{{ request()->getSchemeAndHttpHost() }}/assets/erp/finance-workbench.css?v=20261109-1">
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
        <dialog id="finance-sync-logs" class="finance-sync-log-dialog" aria-labelledby="finance-sync-logs-title" data-url="{{ route('finance.sync-logs') }}">
            <header>
                <h2 id="finance-sync-logs-title">最近同步记录</h2>
                <form method="dialog"><button class="btn btn-default btn-sm" autofocus>关闭</button></form>
            </header>
            <div class="finance-sync-log-status">
                <span data-sync-log-status role="status"></span>
                <button type="button" class="btn btn-default btn-sm" data-sync-log-retry hidden>重试</button>
            </div>
            <div data-sync-log-content></div>
        </dialog>
        @include('portals.finance.partials.payment-account-create')
    @endif
</div>
@endsection
@push('scripts')
<script src="{{ request()->getSchemeAndHttpHost() }}/assets/erp/finance-workbench.js?v=20261109-1"></script>
@if($canManage)<script src="{{ request()->getSchemeAndHttpHost() }}/assets/erp/finance-payment-accounts.js?v=20261104-4"></script>@endif
@endpush
