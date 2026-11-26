<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $pageTitle ?? __('enterprise_login.heading') }}</title>
    <link rel="stylesheet" href="{{ asset('vendor/laravel-admin/font-awesome/css/font-awesome.min.css') }}">
    <link rel="stylesheet" href="{{ asset('css/enterprise-login.css') }}?v=20261126-6">
</head>
<body>
<main class="enterprise-login">
    <aside class="login-brand">
        <div class="login-brand-mark">
            @if ($brandLogo ?? null)
                <img class="login-brand-logo" src="{{ $brandLogo }}" alt="">
            @else
                <span class="login-brand-default" aria-hidden="true"><i class="fa fa-cloud"></i></span>
            @endif
        </div>
        <div>{{ $brandName ?? config('admin.name') }}</div>
    </aside>
    @php
        $usesTencentCaptcha = !empty($passwordEnabled) && !empty($captcha['enabled']) && $captcha['provider'] === 'tencent_captcha';
    @endphp
    <section class="login-side" aria-labelledby="login-title">
        <div class="login-content">
            <header class="login-card-header">
                <h1 id="login-title">{{ __('enterprise_login.heading') }}</h1>
            </header>
            <div class="login-card-body">
                @if (!empty($loginError) || $errors->has('dingtalk') || $errors->has('login_expired'))
                    <p class="login-notice" role="alert">{{ $loginError ?? ($errors->first('dingtalk') ?: $errors->first('login_expired')) }}</p>
                @endif
                @if (!empty($paused))
                    <a href="{{ admin_base_path('auth/logout') }}">{{ __('dingtalk.login.sign_out_retry') }}</a>
                @endif
                @if ($dingtalkEnabled ?? false)
                    @if ($passwordEnabled ?? false)
                        <button type="button" class="scan-button" data-open-scan><i class="fa fa-qrcode" aria-hidden="true"></i> {{ __('enterprise_login.scan') }}</button>
                        <div class="login-divider">{{ __('enterprise_login.or') }}</div>
                        <dialog class="scan-dialog" aria-labelledby="scan-title" data-scan-dialog>
                            <div class="scan-dialog-header"><h2 id="scan-title">{{ __('enterprise_login.scan') }}</h2><button class="scan-close" type="button" aria-label="{{ __('enterprise_login.close') }}" data-close-scan>&times;</button></div>
                    @endif
                    <div data-scan-panel data-scan-url="{{ admin_base_path('auth/dingtalk/start') }}" @if (empty($passwordEnabled)) data-auto-scan @endif>
                        <p class="scan-hint">{{ __('enterprise_login.scan_hint') }}</p>
                        <div class="scan-stage">
                            <div data-scan-frame></div>
                            <p data-scan-status role="status" hidden></p>
                        </div>
                        <div class="scan-actions">
                            <button type="button" class="scan-button" data-retry-scan>{{ __('enterprise_login.refresh_scan') }}</button>
                            <a class="scan-button" data-scan-official aria-disabled="true" referrerpolicy="no-referrer">{{ __('dingtalk.login.official') }}</a>
                        </div>
                    </div>
                    @if ($passwordEnabled ?? false)
                        </dialog>
                    @endif
                @endif
                @if ($passwordEnabled ?? false)
                    @include('admin.auth.password')
                @endif
            </div>
            @if ($usesTencentCaptcha)
                <footer class="login-card-footer">{{ __('enterprise_login.tencent_security_notice') }}</footer>
            @endif
        </div>
    </section>
</main>
@php
    $loginOptions = [
        'captchaProvider' => !empty($captcha['enabled']) ? $captcha['provider'] : null,
        'captchaRequired' => __('configuration.login.captcha_failed'),
        'captchaFailed' => __('enterprise_login.captcha_unavailable'),
        'captchaExpired' => __('enterprise_login.captcha_expired'),
        'scanLoading' => __('enterprise_login.scan_loading'),
        'scanTitle' => __('dingtalk.login.frame_title'),
        'scanFailed' => __('dingtalk.login.frame_failed'),
        'scanExpired' => __('dingtalk.login.expired'),
        'showPassword' => __('enterprise_login.show_password'),
        'hidePassword' => __('enterprise_login.hide_password'),
    ];
@endphp
<script type="application/json" id="enterprise-login-options">@json($loginOptions)</script>
<script src="{{ asset('js/dingtalk-login.js') }}?v=20261126-2"></script>
@if (!empty($captcha['enabled']) && $captcha['provider'] === 'turnstile')
    <script data-captcha-script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
@elseif (!empty($captcha['enabled']) && $captcha['provider'] === 'recaptcha_v2')
    <script data-captcha-script src="https://www.google.com/recaptcha/api.js?hl={{ urlencode(app()->getLocale()) }}" async defer></script>
@elseif (!empty($captcha['enabled']) && $captcha['provider'] === 'tencent_captcha')
    <script data-captcha-script src="https://turing.captcha.qcloud.com/TCaptcha.js" async defer></script>
    @php
        $passwordOptions = [
            'appId' => (string) $captcha['site_key'],
            'load_failed' => __('configuration.login.tencent.load_failed'),
            'cancelled' => __('configuration.login.tencent.cancelled'),
            'failed' => __('configuration.login.tencent.failed'),
        ];
    @endphp
    <script type="application/json" id="password-captcha-options">@json($passwordOptions)</script>
    <script src="{{ asset('js/password-login.js') }}?v=20261126-2"></script>
@endif
<script>window.ErpInteractionLoadingConfig = @json(__('loading'));</script>
<link rel="stylesheet" href="{{ asset('assets/erp/interaction-loading-20261011-1.css') }}?v=20261011-3">
<script src="{{ asset('assets/erp/interaction-loading-20261011-1.js') }}?v=20261012-1"></script>
<script src="{{ asset('assets/erp/login-submit-guard-20261011-1.js') }}"></script>
</body>
</html>
