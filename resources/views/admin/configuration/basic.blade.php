@php($expirationSeconds = (int) old('login_expiration_seconds', $settings['login_expiration_seconds']))

<div class="erp-config-workspace basic-settings row">
    <div class="col-md-7 erp-config-primary">
        <form method="POST" action="{{ $saveUrl }}" class="box box-primary erp-config-card">
            @csrf
            <div class="box-header with-border">
                <h3 class="box-title"><i class="fa fa-cog"></i> {{ __('configuration.basic.form.title') }}</h3>
                <div class="erp-config-card__intro">{{ __('configuration.basic.form.intro') }}</div>
            </div>

            <div class="box-body">
                <section class="erp-config-section">
                    <div class="erp-config-section__title">{{ __('configuration.basic.sections.maintenance') }}</div>
                    <input type="hidden" name="maintenance_enabled" value="0">
                    <div class="erp-config-option-row">
                        <label class="erp-config-toggle">
                            <input type="checkbox" name="maintenance_enabled" value="1" {{ old('maintenance_enabled', $settings['maintenance_enabled']) ? 'checked' : '' }}>
                            {{ __('configuration.basic.maintenance.enabled.checkbox') }}
                        </label>
                    </div>
                    <div class="setting-help">{{ __('configuration.basic.maintenance.enabled.help') }}</div>

                    <div class="form-group" style="margin:16px 0 0;">
                        <label class="setting-label" for="maintenance-message">{{ __('configuration.basic.fields.maintenance_message') }}</label>
                        <textarea class="form-control" id="maintenance-message" name="maintenance_message" maxlength="500" placeholder="{{ __('configuration.basic.maintenance.message.placeholder') }}">{{ old('maintenance_message', $settings['maintenance_message']) }}</textarea>
                        <div class="setting-help">{{ __('configuration.basic.maintenance.message.help') }}</div>
                        @error('maintenance_message')<div class="text-danger" style="margin-top:6px;">{{ $message }}</div>@enderror
                    </div>
                </section>

                <section class="erp-config-section">
                    <div class="erp-config-section__title">{{ __('configuration.basic.sections.footer') }}</div>
                    <div class="form-group">
                        <label class="setting-label" for="footer-copyright-text">{{ __('configuration.basic.fields.footer_copyright_text') }}</label>
                        <input class="form-control" id="footer-copyright-text" name="footer_copyright_text" maxlength="200" value="{{ old('footer_copyright_text', $settings['footer_copyright_text']) }}" placeholder="{{ __('configuration.basic.footer.copyright.placeholder') }}">
                        <div class="setting-help">{{ __('configuration.basic.footer.copyright.help') }}</div>
                        @error('footer_copyright_text')<div class="text-danger" style="margin-top:6px;">{{ $message }}</div>@enderror
                    </div>
                    <div class="form-group" style="margin-bottom:0;">
                        <label class="setting-label" for="footer-contact-information">{{ __('configuration.basic.fields.footer_contact_information') }}</label>
                        <textarea class="form-control" id="footer-contact-information" name="footer_contact_information" maxlength="500" placeholder="{{ __('configuration.basic.footer.contact.placeholder') }}">{{ old('footer_contact_information', $settings['footer_contact_information']) }}</textarea>
                        <div class="setting-help">{{ __('configuration.basic.footer.contact.help') }}</div>
                        @error('footer_contact_information')<div class="text-danger" style="margin-top:6px;">{{ $message }}</div>@enderror
                    </div>
                </section>

                <section class="erp-config-section">
                    <div class="erp-config-section__title">{{ __('configuration.basic.sections.login_expiration') }}</div>
                    <div class="erp-config-choices">
                        @foreach($expirationOptions as $seconds => $label)
                            <label class="erp-config-choice">
                                <input type="radio" name="login_expiration_seconds" value="{{ $seconds }}" {{ $expirationSeconds === (int) $seconds ? 'checked' : '' }}>
                                {{ $label }}
                            </label>
                        @endforeach
                    </div>
                    <div class="setting-help" style="margin-top:8px;">{{ __('configuration.basic.expiration.help') }}</div>
                    @error('login_expiration_seconds')<div class="text-danger" style="margin-top:6px;">{{ $message }}</div>@enderror
                </section>
            </div>

            <div class="box-footer">
                <button type="submit" class="btn btn-primary"><i class="fa fa-save"></i> {{ __('configuration.basic.form.save') }}</button>
            </div>
        </form>
    </div>

    <div class="col-md-5 erp-config-secondary">
        <div class="box box-default erp-config-card erp-config-audit">
            <div class="box-header with-border">
                <h3 class="box-title"><i class="fa fa-history"></i> {{ __('configuration.basic.changes.title') }}</h3>
            </div>
            <div class="box-body table-responsive">
                <table class="table table-hover change-table">
                    <thead>
                    <tr>
                        <th>{{ __('configuration.basic.changes.time') }}</th>
                        <th>{{ __('configuration.basic.changes.operator') }}</th>
                        <th>{{ __('configuration.basic.changes.item') }}</th>
                        <th>{{ __('configuration.basic.changes.new_value') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($changes as $change)
                        <tr>
                            <td style="white-space:nowrap;">{{ optional($change->created_at)->format('m-d H:i') }}</td>
                            <td>{{ $change->admin_username ?: __('configuration.basic.changes.empty_value') }}</td>
                            <td>{{ $labels[$change->key] ?? $change->key }}</td>
                            <td>
                                @if($change->key === 'auth.login_expiration_seconds')
                                    {{ $expirationOptions[(int) $change->new_value] ?? __('configuration.basic.changes.empty_value') }}
                                @elseif($change->key === 'system.maintenance.enabled')
                                    {{ $change->new_value === '1' ? __('configuration.status.enabled') : __('configuration.status.disabled') }}
                                @else
                                    {{ IlluminateSupportStr::limit($change->new_value ?: __('configuration.basic.changes.empty_value'), 28) }}
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-center text-muted">{{ __('configuration.basic.changes.empty') }}</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>


