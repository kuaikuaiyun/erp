<form action="{{ admin_base_path('auth/login') }}" method="post" id="admin-login-form" @if($usesTencentCaptcha) data-tencent-options-url="{{ admin_base_path('auth/tencent-captcha/options') }}" @endif>
    @csrf
    <div class="login-field {{ $errors->has('username') ? 'has-error' : '' }}">
        <label for="login-username">{{ __('enterprise_login.account') }}</label>
        @error('username')<span id="login-username-error" class="login-field__error" role="alert"><i class="fa fa-times-circle-o"></i> {{ $message }}</span>@enderror
        <div class="login-field__control">
            <input type="text" class="login-input" id="login-username" name="username" value="{{ old('username') }}" autocomplete="username" @error('username') aria-invalid="true" aria-describedby="login-username-error" @enderror required>
        </div>
    </div>

    <div class="login-field {{ $errors->has('password') ? 'has-error' : '' }}">
        <label for="login-password">{{ __('enterprise_login.password_prompt') }}</label>
        @error('password')<span id="login-password-error" class="login-field__error" role="alert"><i class="fa fa-times-circle-o"></i> {{ $message }}</span>@enderror
        <div class="login-field__control">
            <input type="password" class="login-input" id="login-password" name="password" autocomplete="current-password" @error('password') aria-invalid="true" aria-describedby="login-password-error" @enderror required>
            <button class="login-eye" type="button" aria-label="{{ __('enterprise_login.show_password') }}" aria-pressed="false" data-login-eye><i class="fa fa-eye" aria-hidden="true"></i></button>
        </div>
    </div>

    @if ($usesTencentCaptcha)
        @error('captcha')<p class="login-captcha__error" role="alert">{{ $message }}</p>@enderror
        <div id="login-tencent-captcha-status" class="login-captcha-tencent-status" role="status" aria-live="polite"></div>
        <input type="hidden" name="tencent_captcha_ticket" id="login-tencent-captcha-ticket">
        <input type="hidden" name="tencent_captcha_randstr" id="login-tencent-captcha-randstr">
    @elseif ($captcha['enabled'])
        <div class="login-captcha {{ $errors->has('captcha') ? 'has-error' : '' }}">
            <div class="login-captcha__heading"><i class="fa fa-shield" aria-hidden="true"></i> {{ __('configuration.login.captcha_heading') }}</div>
            @error('captcha')<span class="login-captcha__error" role="alert"><i class="fa fa-times-circle-o" aria-hidden="true"></i> {{ $message }}</span>@enderror
            <p id="login-captcha-status" role="status" hidden></p>
            <div class="login-captcha-widget">
                @if ($captcha['provider'] === 'turnstile')
                    <div class="cf-turnstile" data-sitekey="{{ $captcha['site_key'] }}" data-theme="light" data-size="flexible" data-callback="erpCaptchaVerified" data-error-callback="erpCaptchaFailed" data-expired-callback="erpCaptchaExpired"></div>
                @elseif ($captcha['provider'] === 'recaptcha_v2')
                    <div class="g-recaptcha" data-sitekey="{{ $captcha['site_key'] }}" data-theme="light" data-callback="erpCaptchaVerified" data-error-callback="erpCaptchaFailed" data-expired-callback="erpCaptchaExpired"></div>
                @endif
            </div>
        </div>
    @endif

    <div class="login-actions">
        <button type="submit" class="login-submit">{{ trans('admin.login') }}</button>
    </div>
</form>
