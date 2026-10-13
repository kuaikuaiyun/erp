<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex,nofollow">
    <title>{{ config('admin.title') }} | {{ trans('admin.login') }}</title>
    <link rel="stylesheet" href="{{ admin_asset('vendor/laravel-admin/AdminLTE/bootstrap/css/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ admin_asset('vendor/laravel-admin/font-awesome/css/font-awesome.min.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/erp/system-font.css?v=20260928-1') }}">
    <link rel="stylesheet" href="{{ asset('assets/erp/interaction-loading-20261011-1.css') }}?v=20261011-3">
    <script>window.ErpInteractionLoadingConfig = @json(__('loading'));</script>
    <style>
        :root { --login-primary: #2f80c5; --login-primary-dark: #1f659f; --login-ink: #1e293b; --login-muted: #64748b; --login-line: #d9e2ec; }
        * { box-sizing: border-box; }
        body.login-page { min-height: 100vh; min-height: 100dvh; margin: 0; color: var(--login-ink); background: #eef4f9; }
        .login-shell { display: flex; min-height: 100vh; min-height: 100dvh; align-items: center; justify-content: center; padding: 28px 20px; background: radial-gradient(circle at 15% 5%, rgba(114, 187, 238, .27), transparent 30%), radial-gradient(circle at 90% 88%, rgba(77, 144, 196, .18), transparent 32%), #eef4f9; }
        .login-panel { width: min(100%, 448px); overflow: hidden; border: 1px solid rgba(203, 213, 225, .9); border-radius: 14px; background: #fff; box-shadow: 0 20px 55px rgba(30, 66, 102, .16); }
        .login-panel__header { padding: 34px 38px 26px; border-bottom: 1px solid #edf2f7; text-align: center; }
        .login-brand { display: flex; align-items: center; justify-content: center; gap: 11px; color: var(--login-ink); font-size: 22px; font-weight: 700; letter-spacing: .2px; }
        .login-brand__mark { display: inline-flex; width: 37px; height: 37px; align-items: center; justify-content: center; border-radius: 10px; color: #fff; background: linear-gradient(135deg, #4296d6, #246da9); box-shadow: 0 6px 13px rgba(47, 128, 197, .26); }
        .login-panel__subtitle { margin: 12px 0 0; color: var(--login-muted); font-size: 14px; line-height: 1.6; }
        .login-panel__body { padding: 28px 38px 24px; }
        .login-field { position: relative; margin-bottom: 18px; }
        .login-field__control { position: relative; }
        .login-field .form-control { height: 46px; padding-right: 42px; border: 1px solid var(--login-line); border-radius: 8px; color: var(--login-ink); box-shadow: none; font-size: 14px; transition: border-color .15s, box-shadow .15s; }
        .login-field .form-control:focus { border-color: #72afe0; box-shadow: 0 0 0 3px rgba(83, 156, 210, .13); }
        .login-field.has-error .form-control { border-color: #e46b6b; }
        .login-field__icon { position: absolute; top: 50%; right: 15px; color: #93a4b7; font-size: 16px; transform: translateY(-50%); }
        .login-field__error, .login-captcha__error, .login-notice { display: block; margin: 0 0 8px; color: #c04444; font-size: 12px; line-height: 1.55; }
        .login-captcha { margin: 4px 0 18px; padding: 14px; border: 1px solid #dce7f0; border-radius: 8px; background: #fbfdff; }
        .login-captcha.has-error { border-color: #e5a0a0; background: #fffafb; }
        .login-captcha__heading { margin-bottom: 10px; color: #3e5369; font-size: 13px; font-weight: 600; }
        .login-captcha__heading .fa { margin-right: 7px; color: var(--login-primary); }
        .login-captcha-widget { min-height: 65px; overflow: hidden; }
        .login-captcha-widget--tencent { display: flex; min-height: 42px; align-items: center; color: var(--login-muted); font-size: 13px; }
        .login-captcha-tencent-status { display: none; margin: 0 0 8px; color: #c04444; font-size: 12px; line-height: 1.55; }
        .login-captcha-tencent-status.is-visible { display: block; }
        .login-actions { display: flex; min-height: 42px; align-items: center; justify-content: space-between; gap: 14px; }
        .login-remember { color: #556577; font-size: 13px; }
        .login-remember__label { display: inline-flex; align-items: center; gap: 7px; margin: 0; cursor: pointer; font-weight: 400; }
        .login-remember__input { position: absolute; width: 1px; height: 1px; opacity: 0; }
        .login-remember__box { display: inline-flex; width: 16px; height: 16px; align-items: center; justify-content: center; border: 1px solid #b8c5d1; border-radius: 4px; color: transparent; background: #fff; font-size: 10px; transition: all .15s; }
        .login-remember__input:checked + .login-remember__box { border-color: var(--login-primary); color: #fff; background: var(--login-primary); }
        .login-remember__input:focus + .login-remember__box { box-shadow: 0 0 0 3px rgba(83, 156, 210, .18); }
        .login-submit { min-width: 104px; height: 42px; border: 0; border-radius: 7px; background: linear-gradient(135deg, #3e93d1, #2876b3); box-shadow: 0 5px 11px rgba(47, 128, 197, .2); font-size: 14px; font-weight: 600; transition: transform .15s, box-shadow .15s, background .15s; }
        .login-submit:hover, .login-submit:focus { background: linear-gradient(135deg, #3488c5, #1f659f); box-shadow: 0 7px 14px rgba(47, 128, 197, .26); transform: translateY(-1px); }
        .login-submit:active { transform: translateY(0); }
        .login-panel__footer { padding: 15px 24px; border-top: 1px solid #edf2f7; color: #8a99a9; font-size: 12px; line-height: 1.55; text-align: center; }

        @media (max-width: 480px) {
            .login-shell { align-items: flex-start; padding: max(16px, env(safe-area-inset-top)) 12px 16px; }
            .login-panel { margin-top: 12px; border-radius: 11px; }
            .login-panel__header { padding: 28px 24px 22px; }
            .login-panel__body { padding: 22px 24px; }
            .login-brand { font-size: 20px; }
            .login-actions { align-items: stretch; flex-direction: column; }
            .login-remember { order: 2; padding: 3px 0; }
            .login-submit { width: 100%; }
        }
    </style>
</head>
<body class="login-page">
<main class="login-shell">
    <section class="login-panel" aria-labelledby="login-title">
        <header class="login-panel__header">
            <div class="login-brand"><span class="login-brand__mark"><i class="fa fa-cubes"></i></span><span id="login-title">{{ config('admin.name') }}</span></div>
            <p class="login-panel__subtitle">{{ __('configuration.login.subtitle') }}</p>
        </header>

        <div class="login-panel__body">
            @if($errors->has('login_expired'))
                <div class="login-notice" role="alert"><i class="fa fa-clock-o"></i> {{ $errors->first('login_expired') }}</div>
            @endif

            <form action="{{ admin_url('auth/login') }}" method="post" id="admin-login-form" @if($captcha['enabled'] && $captcha['provider'] === 'tencent_captcha') data-tencent-options-url="{{ admin_url('auth/tencent-captcha/options') }}" @endif>
                @csrf
                <div class="login-field {{ $errors->has('username') ? 'has-error' : '' }}">
                    <label class="sr-only" for="login-username">{{ trans('admin.username') }}</label>
                    @error('username')<span class="login-field__error" role="alert"><i class="fa fa-times-circle-o"></i> {{ $message }}</span>@enderror
                    <div class="login-field__control">
                        <input type="text" class="form-control" id="login-username" placeholder="{{ trans('admin.username') }}" name="username" value="{{ old('username') }}" autocomplete="username" required autofocus>
                        <i class="fa fa-user login-field__icon" aria-hidden="true"></i>
                    </div>
                </div>

                <div class="login-field {{ $errors->has('password') ? 'has-error' : '' }}">
                    <label class="sr-only" for="login-password">{{ trans('admin.password') }}</label>
                    @error('password')<span class="login-field__error" role="alert"><i class="fa fa-times-circle-o"></i> {{ $message }}</span>@enderror
                    <div class="login-field__control">
                        <input type="password" class="form-control" id="login-password" placeholder="{{ trans('admin.password') }}" name="password" autocomplete="current-password" required>
                        <i class="fa fa-lock login-field__icon" aria-hidden="true"></i>
                    </div>
                </div>

                @if($captcha['enabled'])
                    <div class="login-captcha {{ $errors->has('captcha') ? 'has-error' : '' }}">
                        <div class="login-captcha__heading"><i class="fa fa-shield"></i> {{ __('configuration.login.captcha_heading') }}</div>
                        @error('captcha')<span class="login-captcha__error" role="alert"><i class="fa fa-times-circle-o"></i> {{ $message }}</span>@enderror
                        <div id="login-tencent-captcha-status" class="login-captcha-tencent-status" role="status" aria-live="polite"></div>
                        <div class="login-captcha-widget {{ $captcha['provider'] === 'tencent_captcha' ? 'login-captcha-widget--tencent' : '' }}">
                            @if($captcha['provider'] === 'turnstile')
                                <div class="cf-turnstile" data-sitekey="{{ $captcha['site_key'] }}" data-theme="light" data-language="{{ app()->getLocale() }}"></div>
                            @elseif($captcha['provider'] === 'recaptcha_v2')
                                <div class="g-recaptcha" data-sitekey="{{ $captcha['site_key'] }}"></div>
                            @else
                                <span>{{ __('configuration.login.tencent_hint') }}</span>
                                <input type="hidden" name="tencent_captcha_ticket" id="login-tencent-captcha-ticket">
                                <input type="hidden" name="tencent_captcha_randstr" id="login-tencent-captcha-randstr">
                            @endif
                        </div>
                    </div>
                @endif

                <div class="login-actions">
                    @if(config('admin.auth.remember'))
                        <div class="login-remember">
                            <label class="login-remember__label">
                                <input class="login-remember__input" type="checkbox" name="remember" value="1" {{ (!old('username') || old('remember')) ? 'checked' : '' }}>
                                <span class="login-remember__box" aria-hidden="true"><i class="fa fa-check"></i></span>
                                <span>{{ trans('admin.remember_me') }}</span>
                            </label>
                        </div>
                    @else
                        <span></span>
                    @endif
                    <button type="submit" class="btn btn-primary login-submit">{{ trans('admin.login') }}</button>
                </div>
            </form>
        </div>

        <footer class="login-panel__footer">{{ __('configuration.login.footer', ['name' => config('admin.name')]) }}</footer>
    </section>
</main>

<script src="{{ asset('assets/erp/interaction-loading-20261011-1.js') }}?v=20261011-3"></script>
<script src="{{ asset('assets/erp/login-submit-guard-20261011-1.js') }}"></script>

@if($captcha['enabled'] && $captcha['provider'] === 'turnstile')
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
@elseif($captcha['enabled'] && $captcha['provider'] === 'recaptcha_v2')
    <script src="https://www.google.com/recaptcha/api.js?hl={{ urlencode(app()->getLocale()) }}" async defer></script>
@elseif($captcha['enabled'] && $captcha['provider'] === 'tencent_captcha')
    <script src="https://turing.captcha.qcloud.com/TCaptcha.js" async defer></script>
@endif

@if($captcha['enabled'] && $captcha['provider'] === 'tencent_captcha')
<script>
    (function () {
        var form = document.getElementById('admin-login-form');
        var ticket = document.getElementById('login-tencent-captcha-ticket');
        var randstr = document.getElementById('login-tencent-captcha-randstr');
        var status = document.getElementById('login-tencent-captcha-status');
        var button = form ? form.querySelector('button[type="submit"]') : null;
        var appId = @json((string) $captcha['site_key']);
        var messages = {
            loadFailed: @json(__('configuration.login.tencent.load_failed')),
            cancelled: @json(__('configuration.login.tencent.cancelled')),
            failed: @json(__('configuration.login.tencent.failed'))
        };

        if (!form || !ticket || !randstr || !status) { return; }

        function showStatus(message) { status.textContent = message; status.classList.add('is-visible'); }
        function clearStatus() { status.textContent = ''; status.classList.remove('is-visible'); }
        function setSubmitting(value) {
            if (window.ErpLoginSubmitGuard) {
                if (value) { window.ErpLoginSubmitGuard.begin(); } else { window.ErpLoginSubmitGuard.end(); }
                return;
            }
            if (button) { button.disabled = value; }
        }

        form.addEventListener('submit', function (event) {
            if (form.getAttribute('data-tencent-captcha-verified') === '1') { return; }

            event.preventDefault();
            clearStatus();
            ticket.value = '';
            randstr.value = '';

            if (typeof window.TencentCaptcha !== 'function') {
                showStatus(messages.loadFailed);
                return;
            }

            setSubmitting(true);
            window.fetch(form.getAttribute('data-tencent-options-url'), {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            }).then(function (response) {
                if (!response.ok) { throw new Error('Unable to create Tencent captcha options.'); }
                return response.json();
            }).then(function (options) {
                if (!options || !options.aid_encrypted || options.aid_encrypted_type !== 'CBC') {
                    throw new Error('Invalid Tencent captcha options.');
                }

                var captcha = new window.TencentCaptcha(appId, function (result) {
                    if (result && Number(result.ret) === 0 && result.ticket && result.randstr) {
                        ticket.value = String(result.ticket);
                        randstr.value = String(result.randstr);
                        form.setAttribute('data-tencent-captcha-verified', '1');
                        setSubmitting(true);
                        form.submit();
                        return;
                    }
                    setSubmitting(false);
                    showStatus(result && Number(result.ret) === 2 ? messages.cancelled : messages.failed);
                }, {
                    aidEncrypted: options.aid_encrypted,
                    aidEncryptedType: options.aid_encrypted_type
                });
                captcha.show();
            }).catch(function () {
                setSubmitting(false);
                showStatus(messages.loadFailed);
            });
        });
    })();
</script>
@endif
</body>
</html>
