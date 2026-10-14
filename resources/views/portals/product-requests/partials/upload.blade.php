@php
    $existingAttachments = $existingAttachments ?? collect();
    $required = $required ?? false;
    $multiple = $maxFiles > 1;
    $inputErrorKey = $inputErrorKey ?? $inputName;
@endphp
<div class="product-request-upload" data-product-request-upload data-max-files="{{ $maxFiles }}" data-required="{{ $required ? '1' : '0' }}" data-required-message="{{ __('product_requests.uploads.required') }}" tabindex="0">
    <input
        id="{{ $inputId }}"
        class="product-request-upload-input"
        type="file"
        name="{{ $inputName }}{{ $multiple ? '[]' : '' }}"
        accept="image/jpeg,image/png,image/webp"
        @if($multiple) multiple @endif
    >
    <label class="product-request-upload-trigger" for="{{ $inputId }}">
        <i class="fa fa-cloud-upload"></i>
        <span>{{ __('product_requests.uploads.drop_or_click') }}</span>
    </label>
    <button type="button" class="btn btn-default btn-xs product-request-upload-paste" data-product-request-paste><i class="fa fa-paste"></i> {{ __('product_requests.uploads.paste') }}</button>
    <div class="product-request-upload-preview" data-product-request-preview>
        @foreach($existingAttachments as $attachment)
            <div class="product-request-upload-preview-item product-request-upload-existing">
                <a href="{{ route('product-requests.attachments.show', [$productRequest, $attachment]) }}" data-product-request-image-preview data-product-request-image-alt="{{ $attachment->original_name }}">
                    <img src="{{ route('product-requests.attachments.show', [$productRequest, $attachment]) }}" alt="{{ $attachment->original_name }}">
                </a>
            </div>
        @endforeach
    </div>
</div>
@error($inputErrorKey)<span class="help-block text-red">{{ $message }}</span>@enderror
@error($inputErrorKey.'.*')<span class="help-block text-red">{{ $message }}</span>@enderror
