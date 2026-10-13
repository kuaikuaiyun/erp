@php
    $provider = old('provider', $settings['provider']);
    $changeLabels = [
        'auth.login_captcha.enabled' => 'configuration.captcha.audit.labels.enabled',
        'auth.login_captcha.provider' => 'configuration.captcha.audit.labels.provider',
        'auth.login_captcha.turnstile.site_key' => 'configuration.captcha.audit.labels.turnstile_site_key',
        'auth.login_captcha.turnstile.secret_key' => 'configuration.captcha.audit.labels.turnstile_secret_key',
        'auth.login_captcha.recaptcha_v2.site_key' => 'configuration.captcha.audit.labels.recaptcha_v2_site_key',
        'auth.login_captcha.recaptcha_v2.secret_key' => 'configuration.captcha.audit.labels.recaptcha_v2_secret_key',
        'auth.login_captcha.tencent_captcha.app_id' => 'configuration.captcha.audit.labels.tencent_captcha_app_id',
        'auth.login_captcha.tencent_captcha.app_secret_key' => 'configuration.captcha.audit.labels.tencent_captcha_app_secret_key',
        'auth.login_captcha.tencent_captcha.secret_id' => 'configuration.captcha.audit.labels.tencent_captcha_secret_id',
        'auth.login_captcha.tencent_captcha.secret_key' => 'configuration.captcha.audit.labels.tencent_captcha_secret_key',
        'auth.login_captcha.max_attempts' => 'configuration.captcha.audit.labels.max_attempts',
        'auth.login_captcha.decay_seconds' => 'configuration.captcha.audit.labels.decay_seconds',
    ];
@endphp

<div class="erp-config-workspace captcha-settings row">
    <div class="col-md-7 erp-config-primary">
        <form method="POST" action="{{ $saveUrl }}" class="box box-primary erp-config-card">
            @csrf
            <div class="box-header with-border">
                <h3 class="box-title"><i class="fa fa-shield"></i> {{ __('configuration.captcha.title') }}</h3>
                <span class="status-tag {{ $settings['enabled'] ? '' : 'is-disabled' }}">{{ $settings['enabled'] ? __('configuration.status.enabled') : __('configuration.status.disabled') }}</span>
            </div>
            <div class="box-body">
                <div class="intro-panel">
                    <span class="intro-panel__icon"><i class="fa fa-lock"></i></span>
                    <div>
                        <strong>{{ __('configuration.captcha.intro.title') }}</strong>
                        {{ __('configuration.captcha.intro.description') }}
                    </div>
                </div>

                <div class="setting-section">
                    <div class="setting-label">{{ __('configuration.captcha.enabled.title') }}</div>
                    <input type="hidden" name="enabled" value="0">
                    <label style="font-weight:400;cursor:pointer;margin:0;">
                        <input type="checkbox" name="enabled" value="1" {{ old('enabled', $settings['enabled']) ? 'checked' : '' }}>
                        {{ __('configuration.captcha.enabled.checkbox') }}
                    </label>
                    <div class="setting-help">{{ __('configuration.captcha.enabled.help') }}</div>
                    @error('enabled')<div class="text-danger" style="margin-top:6px;">{{ $message }}</div>@enderror
                </div>

                <div class="setting-section">
                    <div class="setting-label">{{ __('configuration.captcha.provider.title') }}</div>
                    <div class="setting-help" style="margin-bottom:8px;">{{ __('configuration.captcha.provider.help') }}</div>

                    <div class="provider-options">
                        @foreach(['turnstile', 'recaptcha_v2', 'tencent_captcha'] as $providerKey)
                            <label class="provider-option {{ $provider === $providerKey ? 'is-selected' : '' }}" data-provider-option="{{ $providerKey }}">
                                <input type="radio" name="provider" value="{{ $providerKey }}" {{ $provider === $providerKey ? 'checked' : '' }}>
                                {{ __('configuration.captcha.providers.'.$providerKey.'.name') }}
                                <span class="provider-description">{{ __('configuration.captcha.providers.'.$providerKey.'.description') }}</span>
                            </label>
                        @endforeach
                    </div>
                    @error('provider')<div class="text-danger" style="margin-top:6px;">{{ $message }}</div>@enderror

                    <div class="provider-panel" data-provider-panel="turnstile" {{ $provider === 'turnstile' ? '' : 'hidden' }}>
                        <div class="form-group">
                            <label class="setting-label" for="turnstile-site-key">{{ __('configuration.captcha.fields.turnstile_site_key') }}</label>
                            <input type="text" class="form-control" id="turnstile-site-key" name="turnstile_site_key" maxlength="255" autocomplete="off" value="{{ old('turnstile_site_key', $settings['turnstile']['site_key']) }}">
                            @error('turnstile_site_key')<div class="text-danger" style="margin-top:6px;">{{ $message }}</div>@enderror
                        </div>
                        @include('admin.configuration.partials.captcha-secret', ['label' => __('configuration.captcha.fields.turnstile_secret_key'), 'field' => 'turnstile_secret_key', 'clearField' => 'clear_turnstile_secret', 'identifier' => 'turnstile', 'configured' => $settings['turnstile']['has_secret']])
                    </div>

                    <div class="provider-panel" data-provider-panel="recaptcha_v2" {{ $provider === 'recaptcha_v2' ? '' : 'hidden' }}>
                        <div class="form-group">
                            <label class="setting-label" for="recaptcha-site-key">{{ __('configuration.captcha.fields.recaptcha_v2_site_key') }}</label>
                            <input type="text" class="form-control" id="recaptcha-site-key" name="recaptcha_v2_site_key" maxlength="255" autocomplete="off" value="{{ old('recaptcha_v2_site_key', $settings['recaptcha_v2']['site_key']) }}">
                            @error('recaptcha_v2_site_key')<div class="text-danger" style="margin-top:6px;">{{ $message }}</div>@enderror
                        </div>
                        @include('admin.configuration.partials.captcha-secret', ['label' => __('configuration.captcha.fields.recaptcha_v2_secret_key'), 'field' => 'recaptcha_v2_secret_key', 'clearField' => 'clear_recaptcha_v2_secret', 'identifier' => 'recaptcha_v2', 'configured' => $settings['recaptcha_v2']['has_secret']])
                    </div>

                    <div class="provider-panel" data-provider-panel="tencent_captcha" {{ $provider === 'tencent_captcha' ? '' : 'hidden' }}>
                        <div class="form-group">
                            <label class="setting-label" for="tencent-captcha-app-id">{{ __('configuration.captcha.fields.tencent_captcha_app_id') }}</label>
                            <input type="text" class="form-control" id="tencent-captcha-app-id" name="tencent_captcha_app_id" maxlength="64" inputmode="numeric" autocomplete="off" value="{{ old('tencent_captcha_app_id', $settings['tencent_captcha']['app_id']) }}">
                            <div class="setting-help">{{ __('configuration.captcha.tencent.app_id_help') }}</div>
                            @error('tencent_captcha_app_id')<div class="text-danger" style="margin-top:6px;">{{ $message }}</div>@enderror
                        </div>
                        @include('admin.configuration.partials.captcha-secret', ['label' => __('configuration.captcha.fields.tencent_captcha_app_secret_key'), 'field' => 'tencent_captcha_app_secret_key', 'identifier' => 'tencent_captcha_app_secret_key', 'configured' => $settings['tencent_captcha']['has_app_secret_key']])
                        @include('admin.configuration.partials.captcha-secret', ['label' => __('configuration.captcha.fields.tencent_captcha_secret_id'), 'field' => 'tencent_captcha_secret_id', 'identifier' => 'tencent_captcha_secret_id', 'configured' => $settings['tencent_captcha']['has_secret_id']])
                        @include('admin.configuration.partials.captcha-secret', ['label' => __('configuration.captcha.fields.tencent_captcha_secret_key'), 'field' => 'tencent_captcha_secret_key', 'identifier' => 'tencent_captcha_secret_key', 'configured' => $settings['tencent_captcha']['has_secret_key']])
                    </div>
                </div>

                <div class="setting-section">
                    <div class="setting-label">{{ __('configuration.captcha.rate_limit.title') }}</div>
                    <div class="row">
                        <div class="col-sm-6 form-group">
                            <label for="captcha-max-attempts">{{ __('configuration.captcha.fields.max_attempts') }}</label>
                            <input type="number" class="form-control" id="captcha-max-attempts" name="max_attempts" min="1" max="20" value="{{ old('max_attempts', $settings['max_attempts']) }}">
                            <div class="setting-help">{{ __('configuration.captcha.rate_limit.max_attempts_help') }}</div>
                            @error('max_attempts')<div class="text-danger" style="margin-top:6px;">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-sm-6 form-group" style="margin-bottom:0;">
                            <label for="captcha-decay-seconds">{{ __('configuration.captcha.fields.decay_seconds') }}</label>
                            <input type="number" class="form-control" id="captcha-decay-seconds" name="decay_seconds" min="30" max="3600" value="{{ old('decay_seconds', $settings['decay_seconds']) }}">
                            <div class="setting-help">{{ __('configuration.captcha.rate_limit.decay_seconds_help') }}</div>
                            @error('decay_seconds')<div class="text-danger" style="margin-top:6px;">{{ $message }}</div>@enderror
                        </div>
                    </div>
                </div>
            </div>
            <div class="box-footer">
                <button type="submit" class="btn btn-primary"><i class="fa fa-save"></i> {{ __('configuration.captcha.actions.save') }}</button>
            </div>
        </form>
    </div>

    <div class="col-md-5 erp-config-secondary">
        <div class="box box-default erp-config-card erp-config-audit">
            <div class="box-header with-border"><h3 class="box-title"><i class="fa fa-history"></i> {{ __('configuration.captcha.audit.title') }}</h3></div>
            <div class="box-body table-responsive no-padding">
                <table class="table table-hover change-table">
                    <thead><tr><th>{{ __('configuration.captcha.audit.time') }}</th><th>{{ __('configuration.captcha.audit.operator') }}</th><th>{{ __('configuration.captcha.audit.item') }}</th><th>{{ __('configuration.captcha.audit.action') }}</th><th>{{ __('configuration.captcha.audit.result') }}</th></tr></thead>
                    <tbody>
                    @forelse($changes as $change)
                        <tr>
                            <td style="white-space:nowrap;">{{ optional($change->created_at)->format('m-d H:i') }}</td>
                            <td>{{ $change->admin_username ?: '-' }}</td>
                            <td>{{ __($changeLabels[$change->key] ?? $change->key) }}</td>
                            <td class="change-action">{{ $change->action === 'revealed' ? __('configuration.captcha.audit.revealed') : __('configuration.captcha.audit.updated') }}</td>
                            <td>{{ \Illuminate\Support\Str::limit($change->new_value ?: __('configuration.status.not_configured'), 28) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-muted">{{ __('configuration.captcha.audit.empty') }}</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div id="captcha-reveal-error" class="alert alert-danger" style="display:none;position:fixed;right:20px;bottom:20px;z-index:10000;max-width:360px;">{{ __('configuration.captcha.secret.reveal_failed') }}</div>

<script>
    (function () {
        var revealUrlTemplate = @json($revealUrlTemplate);
        var csrfToken = @json(csrf_token());
        var errorBox = document.getElementById('captcha-reveal-error');

        function selectedProvider() {
            var selected = document.querySelector('[name="provider"]:checked');
            return selected ? selected.value : 'turnstile';
        }

        function syncProviderPanels() {
            var provider = selectedProvider();
            document.querySelectorAll('[data-provider-panel]').forEach(function (panel) {
                panel.hidden = panel.getAttribute('data-provider-panel') !== provider;
            });
            document.querySelectorAll('[data-provider-option]').forEach(function (option) {
                option.classList.toggle('is-selected', option.getAttribute('data-provider-option') === provider);
            });
        }

        function syncSecretMask(input) {
            var mask = document.querySelector('[data-secret-mask-for="' + input.id + '"]');
            if (mask) {
                mask.classList.toggle('is-hidden', input.value !== '' || input.type === 'text' || document.activeElement === input);
            }
        }

        function showRevealError() {
            errorBox.style.display = 'block';
            window.setTimeout(function () { errorBox.style.display = 'none'; }, 4000);
        }

        document.querySelectorAll('[name="provider"]').forEach(function (radio) {
            radio.addEventListener('change', syncProviderPanels);
        });

        document.querySelectorAll('[data-secret-mask-input]').forEach(function (input) {
            input.addEventListener('focus', function () { syncSecretMask(input); });
            input.addEventListener('input', function () { syncSecretMask(input); });
            input.addEventListener('blur', function () { syncSecretMask(input); });
        });

        document.querySelectorAll('[data-reveal-secret]').forEach(function (button) {
            button.addEventListener('click', function () {
                var input = document.getElementById(button.getAttribute('data-secret-input'));
                var secret = button.getAttribute('data-reveal-secret');
                var originalHtml = button.innerHTML;

                if (!input || !secret) {
                    showRevealError();
                    return;
                }

                button.disabled = true;
                button.innerHTML = '<i class="fa fa-spinner fa-spin"></i>';

                window.fetch(revealUrlTemplate.replace('__secret__', encodeURIComponent(secret)), {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                }).then(function (response) {
                    if (!response.ok) {
                        throw new Error('Secret reveal request failed.');
                    }
                    return response.json();
                }).then(function (data) {
                    input.value = typeof data.value === 'string' ? data.value : '';
                    input.type = 'text';
                    syncSecretMask(input);
                }).catch(showRevealError).finally(function () {
                    button.disabled = false;
                    button.innerHTML = originalHtml;
                });
            });
        });

        syncProviderPanels();
    })();
</script>


