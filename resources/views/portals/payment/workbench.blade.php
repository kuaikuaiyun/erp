@extends('portal.layout')

@php
    $translationPrefix = 'payment.workbench';
    $headerTranslationPrefix = $translationPrefix.'.header';
    $portalOrigin = rtrim(request()->getSchemeAndHttpHost(), '/');
    $greetingHour = now('Asia/Shanghai')->hour;
    $greetingKey = $greetingHour < 12
        ? 'morning'
        : ($greetingHour < 18 ? 'afternoon' : 'evening');
    $kpiPresentation = [
        'accounts' => ['icon' => 'fa-paypal', 'tone' => 'blue'],
        'recent_receipts' => ['icon' => 'fa-usd', 'tone' => 'cyan'],
        'urgent_cases' => ['icon' => 'fa-gavel', 'tone' => 'red'],
        'urgent_products' => ['icon' => 'fa-search', 'tone' => 'orange'],
    ];
@endphp

@section('page_title', __($translationPrefix.'.page_title'))

@push('styles')
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/payment-portal.css?v=20261026-2">
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/payment-shared.css?v=20261026-1">
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/payment-workbench.css?v=20261026-2">
@endpush

@section('content')
    <section class="content-header payment-workbench-header">
        <div class="payment-workbench-heading">
            <h1>{{ __($headerTranslationPrefix.'.title', ['portal' => $portal->name]) }} <small>{{ __($headerTranslationPrefix.'.subtitle', ['portal' => $portal->name]) }}</small></h1>
            <div class="payment-workbench-welcome"><h2>{{ __($headerTranslationPrefix.'.greeting.'.$greetingKey, ['name' => $user->name]) }}</h2><p>{{ __($headerTranslationPrefix.'.today', ['date' => now('Asia/Shanghai')->format('Y-m-d')]) }}</p></div>
        </div>
        <div class="payment-workbench-context" aria-label="{{ __($headerTranslationPrefix.'.context_label') }}">
            <span class="payment-filter-chip"><strong>{{ __('payment.common.scope') }}</strong>{{ __($translationPrefix.'.scope.'.$dashboard['scope_key']) }}</span>
            <span class="payment-filter-chip"><strong>{{ __('payment.common.currency') }}</strong>{{ $dashboard['currency'] }}</span>
            <span class="payment-filter-chip"><strong>{{ __('payment.common.cutoff') }}</strong>{{ $dashboard['cutoff'] }}</span>
        </div>
    </section>

    <section class="content payment-portal-page payment-workbench-page">
        <div class="payment-kpi-grid payment-kpi-grid-four">
            @foreach ($dashboard['kpis'] as $kpi)
                @php($presentation = $kpiPresentation[$kpi['key']])
                <article class="payment-kpi-card payment-tone-{{ $kpi['tone'] ?? 'primary' }}">
                    <span class="payment-kpi-icon payment-kpi-icon-{{ $presentation['tone'] }}"><i class="fa {{ $presentation['icon'] }}" aria-hidden="true"></i></span>
                    <div><p>{{ __($translationPrefix.'.kpis.'.$kpi['key']) }}</p><strong>{{ $kpi['value'] }}</strong><small>{{ $kpi['note'] }}</small></div>
                </article>
            @endforeach
        </div>

        <div class="payment-dashboard-grid">
            <article class="payment-panel payment-navigation-panel"><h2>{{ __($translationPrefix.'.account_health.title') }}</h2><p>{{ __($translationPrefix.'.account_health.description') }}</p><div class="payment-panel-actions"><a class="btn btn-primary" href="{{ route('payment.account-risk.index') }}">{{ __($translationPrefix.'.account_health.open_account_risk') }}</a><a class="btn btn-default" href="{{ route('payment.orders.index', ['date_from' => $dashboard['date_from'], 'date_to' => $dashboard['date_to']]) }}">{{ __($translationPrefix.'.account_health.view_cases') }}</a></div></article>
            <article class="payment-panel payment-navigation-panel"><h2>{{ __($translationPrefix.'.product_risk.title') }}</h2><p>{{ __($translationPrefix.'.product_risk.description') }}</p><div class="payment-panel-actions"><a class="btn btn-primary" href="{{ $portalOrigin }}/product-risk-profit">{{ __($translationPrefix.'.product_risk.open_statistics') }}</a><a class="btn btn-default" href="{{ $portalOrigin }}/product-alerts">{{ __($translationPrefix.'.product_risk.open_alerts') }}</a></div></article>
        </div>

        <div class="payment-dashboard-grid payment-dashboard-grid-bottom">
            <article class="payment-panel payment-todo-panel"><h2>{{ __($translationPrefix.'.todos.title') }}</h2><div class="payment-todo-list">@forelse ($dashboard['todos'] as $todo)<div class="payment-todo-item"><span class="payment-status-dot payment-tone-{{ $todo['tone'] }}"></span><div><strong>{{ $todo['title'] }}</strong><small>{{ $todo['description'] }}</small></div><a class="payment-text-action" href="{{ $todo['url'] }}">{{ __($translationPrefix.'.todos.open') }} <i class="fa fa-angle-right" aria-hidden="true"></i></a></div>@empty<p class="payment-workbench-empty">{{ __($translationPrefix.'.real.no_todos') }}</p>@endforelse</div></article>
            <article class="payment-panel payment-quick-search-panel"><h2>{{ __($translationPrefix.'.quick_search.title') }}</h2><p>{{ __($translationPrefix.'.quick_search.description') }}</p><form data-payment-quick-search><label class="sr-only" for="payment-quick-search">{{ __($translationPrefix.'.quick_search.placeholder') }}</label><div class="input-group"><input id="payment-quick-search" class="form-control" name="keyword" type="search" placeholder="{{ __($translationPrefix.'.quick_search.placeholder') }}"><span class="input-group-btn"><button class="btn btn-primary" type="submit"><i class="fa fa-search" aria-hidden="true"></i><span class="sr-only">{{ __('payment.common.search') }}</span></button></span></div></form><div class="payment-system-status"><p class="payment-positive">{{ $dashboard['status']['payments'] }}</p><p>{{ $dashboard['status']['risk'] }}</p><p class="payment-primary">{{ $dashboard['status']['alerts'] }}</p></div></article>
        </div>
    </section>
@endsection

@push('scripts')
    <script>window.PaymentPortal = { actionTitle: @json(__('payment.common.mock_action_title')), actionMessage: @json(__('payment.common.mock_action_message')), quickSearchUrl: @json(route('payment.orders.index')) };</script>
    <script src="{{ $portalOrigin }}/assets/erp/payment-portal.js?v=20261026-1"></script>
@endpush
