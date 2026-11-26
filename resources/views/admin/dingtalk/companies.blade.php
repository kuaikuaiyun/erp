<div class="dg-workspace dc-workspace" data-ding-companies>
    <header class="dc-heading">
        <p>{{ __('dingtalk.company.intro') }}</p>
        <div class="dg-toolbar">
            <label class="dc-archive-toggle"><input type="checkbox" data-show-archived> {{ __('dingtalk.company.show_archived') }}</label>
            <button type="button" class="btn btn-primary" data-company-add><i class="fa fa-plus" aria-hidden="true"></i> {{ __('dingtalk.company.add') }}</button>
        </div>
    </header>
    <div class="dg-banner" role="status" aria-live="polite" data-page-status hidden></div>
    <div class="dg-toolbar" data-page-recovery hidden>
        <button type="button" class="btn btn-default" data-retry-refresh hidden>{{ __('enterprise_login.editor.retry_refresh') }}</button>
        <a class="btn btn-primary" data-reopen-login hidden>{{ __('enterprise_login.editor.sign_in_again') }}</a>
    </div>
    <div class="dc-cards" data-company-cards></div>

    <dialog class="dg-modal dg-modal-medium dc-company-editor" data-modal="edit" aria-labelledby="dc-edit-title">
        <header>
            <h4 id="dc-edit-title" data-edit-title></h4>
            <button type="button" class="dg-close" data-close aria-label="{{ __('dingtalk.workspace.close') }}">×</button>
        </header>
        <form data-company-form data-loading="off" autocomplete="off">
            <div class="dg-modal-body">
                <div class="dg-banner" data-status role="status" aria-live="polite" hidden></div>
                <div class="dc-editor-section dc-identity">
                    <label class="dg-field">{{ __('dingtalk.company.fields.name') }}
                        <input class="form-control" name="name" maxlength="190" required>
                    </label>
                    @if ($canConfigureLogin)
                        <div class="dg-inline">
                            <label class="dg-field">{{ __('enterprise_login.fields.domain') }}
                                <input class="form-control" name="domain" maxlength="253" autocapitalize="none" spellcheck="false" required aria-describedby="dc-domain-hint">
                                <small class="dg-help" id="dc-domain-hint">{{ __('enterprise_login.domain_hint') }}</small>
                            </label>
                            <label class="dg-field">{{ __('enterprise_login.fields.display_name') }}
                                <input class="form-control" name="display_name" maxlength="190" required>
                            </label>
                        </div>
                        <div class="dc-logo-area">
                            <label class="dg-field">{{ __('enterprise_login.fields.logo') }}
                                <input class="form-control" name="logo" type="file" accept="image/png,image/jpeg,image/webp" aria-describedby="dc-logo-hint">
                                <small class="dg-help" id="dc-logo-hint">{{ __('enterprise_login.editor.logo_limits') }}</small>
                            </label>
                            <div class="dc-current-logo" data-current-logo hidden>
                                <img alt="{{ __('enterprise_login.fields.logo') }}">
                                <label class="dc-enabled"><input type="checkbox" name="remove_logo"> {{ __('enterprise_login.remove_logo') }}</label>
                            </div>
                        </div>
                    @endif
                </div>
                @if ($canConfigureLogin)
                    <section class="dc-editor-section" aria-labelledby="dc-login-methods">
                        <h5 id="dc-login-methods">{{ __('enterprise_login.editor.login_methods') }}</h5>
                        <div class="dg-inline dc-method-options">
                            <label class="dc-enabled"><input type="checkbox" name="password_enabled" role="switch" checked> {{ __('enterprise_login.editor.password_method') }}</label>
                            <label class="dc-enabled"><input type="checkbox" name="dingtalk_enabled" role="switch"> {{ __('enterprise_login.editor.dingtalk_method') }}</label>
                        </div>
                    </section>
                @endif
                <details class="dc-editor-section dc-credentials" data-credentials @if ($canConfigureLogin) hidden @endif open>
                    <summary>{{ __('dingtalk.entry.section_credentials') }}</summary>
                    <div class="dc-credentials-body">
                        @if ($canConfigureLogin)
                            <label class="dg-field" data-existing-company hidden>{{ __('enterprise_login.editor.existing_company') }}
                                <select class="form-control" name="existing_company_id" disabled></select>
                            </label>
                        @endif
                        <p class="dg-help">{{ __('dingtalk.company.secret_hint') }}</p>
                        <div class="dg-inline">
                            <label class="dg-field">{{ __('dingtalk.company.fields.corp_id') }}
                                <input class="form-control" name="corp_id" maxlength="128" required>
                            </label>
                            <label class="dg-field">{{ __('dingtalk.company.fields.app_key') }}
                                <input class="form-control" name="app_key" maxlength="190" required>
                            </label>
                        </div>
                        <label class="dg-field">{{ __('dingtalk.company.fields.app_secret') }}
                            <input class="form-control" name="app_secret" type="password" maxlength="2048" autocomplete="new-password">
                        </label>
                        <div class="dg-field">
                            <label for="dc-callback">{{ __('dingtalk.company.fields.redirect_uri') }}</label>
                            <div class="dc-callback">
                                <input class="form-control" id="dc-callback" name="redirect_uri" type="url" maxlength="2048" @if ($canConfigureLogin) readonly aria-describedby="dc-callback-hint" @else required @endif>
                                @if ($canConfigureLogin)
                                    <button type="button" class="btn btn-default" data-copy-callback disabled>{{ __('enterprise_login.editor.copy_callback') }}</button>
                                @endif
                            </div>
                            @if ($canConfigureLogin)
                                <small class="dg-help" id="dc-callback-hint">{{ __('enterprise_login.editor.callback_hint') }}</small>
                                <span class="dg-help" role="status" aria-live="polite" data-copy-status></span>
                            @endif
                        </div>
                        <label class="dc-enabled"><input type="checkbox" name="enabled"> {{ $canConfigureLogin ? __('enterprise_login.editor.enable_integration') : __('dingtalk.company.enable') }}</label>
                        <p class="dg-help">{{ __('dingtalk.company.change_hint') }}</p>
                    </div>
                </details>
            </div>
            <footer>
                <button type="button" class="btn btn-default" data-close>{{ __('dingtalk.manage.cancel') }}</button>
                <button type="submit" class="btn btn-primary" data-save>{{ __('dingtalk.company.save_company') }}</button>
            </footer>
        </form>
    </dialog>
    <dialog class="dg-modal" data-modal="directory" aria-labelledby="dc-directory-title">
        <header>
            <div><h4 id="dc-directory-title" data-directory-title></h4><p>{{ __('dingtalk.company.detail_hint') }}</p></div>
            <button type="button" class="dg-close" data-close aria-label="{{ __('dingtalk.workspace.close') }}">×</button>
        </header>
        <div class="dg-modal-body" data-company-directory></div>
        <footer><button type="button" class="btn btn-default" data-close>{{ __('dingtalk.workspace.close') }}</button></footer>
    </dialog>
    <div hidden data-companies-config>{{ json_encode($options, JSON_UNESCAPED_UNICODE) }}</div>
    <div hidden data-companies-messages>{{ json_encode($messages, JSON_UNESCAPED_UNICODE) }}</div>
</div>
