<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>{{ $pageTitle ?? __('dingtalk.login.title').' · '.config('admin.name') }}</title>
    <link rel="stylesheet" href="{{ asset('css/dingtalk-login.css') }}?v=20261116-3">
</head>
<body class="ding-login-page">
<main class="ding-login-card">
    @if(!empty($brandLogo))<img class="ding-company-logo" src="{{ $brandLogo }}" alt="{{ $brandName ?? config('admin.name') }}">
    @else<div class="ding-system-brand">{!! config('admin.logo') !!}</div>@endif
    <p class="ding-mode">{{ __('dingtalk.mode.dingtalk') }}</p>
    <p class="ding-company">{{ $brandName ?? config('admin.name') }}</p>
    <h1>{{ !empty($entryClosed) ? __('dingtalk.entry.public_title') : __('dingtalk.login.heading') }}</h1>
    <p class="ding-description">{{ $welcome ?? __('dingtalk.login.description') }}</p>
    @if(!empty($loginError))<p class="ding-message" role="alert">{{ $loginError }}</p>@endif
    @if($errors->any())<p class="ding-message" role="alert">{{ $errors->first() }}</p>@endif
    @if(!empty($paused))<p class="ding-description">{{ __('dingtalk.login.paused') }}</p>@endif
    @if(!empty($flow))
        <div class="ding-frame-shell"><iframe id="ding-login-frame" src="{{ $flow['frame_url'] }}" title="{{ __('dingtalk.login.frame_title') }}" width="360" height="400" referrerpolicy="no-referrer"></iframe></div>
        <p id="ding-login-status" class="ding-message" role="status" hidden></p>
        <a class="ding-primary-link" href="{{ $flow['authorize_url'] }}" referrerpolicy="no-referrer">{{ __('dingtalk.login.official') }}</a>
        @php
            $loginOptions = ['callback' => $flow['callback_url'], 'state' => $flow['state'], 'expiresAt' => $flow['expires_at'],
                'expired' => __('dingtalk.login.expired'), 'failed' => __('dingtalk.login.frame_failed')];
        @endphp
        <script type="application/json" id="ding-login-options">@json($loginOptions)</script>
        <script src="{{ asset('js/dingtalk-login.js') }}?v=20261113" defer></script>
    @endif
    @if(!empty($paused))<p><a class="ding-primary-link" href="{{ app(\App\Portal\Services\PortalDomainService::class)->mainUrl('/'.trim(config('admin.route.prefix'),'/').'/auth/logout') }}">{{ __('dingtalk.login.sign_out_retry') }}</a></p>@endif
    @if(!empty($entryUrl))<footer><a href="{{ $entryUrl }}">{{ !empty($paused) ? __('dingtalk.login.retry') : __('dingtalk.login.refresh') }}</a></footer>@endif
</main>
</body>
</html>
