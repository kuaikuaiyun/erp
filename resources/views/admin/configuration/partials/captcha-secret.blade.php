@php($inputId = str_replace('_', '-', $field))
@php($clearField = $clearField ?? 'clear_'.$field)
<div class="form-group" style="margin-bottom:0;">
    <label class="setting-label" for="{{ $inputId }}">{{ $label }}</label>
    <div class="secret-input-group">
        <div class="secret-input-wrapper">
            <input type="password" class="form-control" id="{{ $inputId }}" name="{{ $field }}" maxlength="2048" autocomplete="new-password" value="" data-secret-mask-input>
            @if($configured)<span class="secret-mask" data-secret-mask-for="{{ $inputId }}" aria-hidden="true">••••••••••••</span>@endif
        </div>
        @if($configured)
            <button type="button" class="btn btn-default" data-reveal-secret="{{ $identifier }}" data-secret-input="{{ $inputId }}" title="{{ __('configuration.captcha.secret.reveal') }}" aria-label="{{ __('configuration.captcha.secret.reveal') }}"><i class="fa fa-eye"></i></button>
        @endif
    </div>
    <div class="secret-status">{{ $configured ? __('configuration.captcha.secret.configured_help') : __('configuration.captcha.secret.not_configured_help') }}</div>
    <input type="hidden" name="{{ $clearField }}" value="0">
    @if($configured)
        <label style="font-weight:400;cursor:pointer;margin:8px 0 0;">
            <input type="checkbox" name="{{ $clearField }}" value="1" {{ old($clearField) ? 'checked' : '' }}>
            {{ __('configuration.captcha.secret.clear') }}
        </label>
    @endif
    @error($field)<div class="text-danger" style="margin-top:6px;">{{ $message }}</div>@enderror
</div>


