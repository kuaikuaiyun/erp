<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>{{ __('dingtalk.login.choose') }} · {{ config('admin.name') }}</title>
    <link rel="stylesheet" href="{{ asset('css/dingtalk-login.css') }}?v=20261113">
</head>
<body class="ding-login-page">
<main class="ding-login-card">
    <h1>{{ __('dingtalk.login.choose') }}</h1>
    <p class="ding-description">{{ __('dingtalk.login.choose_hint') }}</p>
    <div class="ding-account-list">
        @foreach($choices as $binding)
            <form method="post" action="{{ route('dingtalk.select') }}" class="ding-account">
                @csrf
                <input type="hidden" name="account_id" value="{{ $binding->account_id }}">
                <strong>{{ $binding->account->name }}</strong>
                <span>{{ $binding->account->username }}</span>
                <small class="{{ $binding->account->isAdministrator() ? 'ding-admin' : '' }}">{{ $binding->account->isAdministrator() ? __('dingtalk.login.admin') : __('dingtalk.login.ordinary') }}</small>
                <button type="submit">{{ __('dingtalk.login.continue') }}</button>
            </form>
        @endforeach
    </div>
    <footer><a href="{{ app(\App\Portal\Services\PortalDomainService::class)->mainLoginUrl() }}">{{ __('dingtalk.login.restart') }}</a></footer>
</main>
</body>
</html>
