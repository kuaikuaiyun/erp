@php
    $existingAttachments = $existingAttachments ?? collect();
    $existingItems = $existingItems ?? null;
    $required = $required ?? false;
    $multiple = $maxFiles > 1;
    $inputErrorKey = $inputErrorKey ?? $inputName;
    $removableName = $removableName ?? '';
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
    @if($removableName !== '')<input type="hidden" name="{{ $removableName }}" value="" data-removed-attachments>@endif
    <label class="product-request-upload-trigger" for="{{ $inputId }}">
        <i class="fa fa-cloud-upload"></i>
        <span>{{ __('product_requests.uploads.drop_or_click') }}</span>
    </label>
    <button type="button" class="product-request-upload-paste" data-product-request-paste><i class="fa fa-paste"></i> {{ __('product_requests.uploads.paste') }}<span class="product-request-upload-paste-key">Ctrl + V</span></button>
    <div class="product-request-upload-preview" data-product-request-preview>
        @if($existingItems !== null)
            @foreach($existingItems as $item)
                <div class="product-request-upload-preview-item product-request-upload-existing" data-existing-preview="true" data-attachment-id="{{ $item['id'] }}">
                    <a href="{{ $item['url'] }}" data-product-request-image-preview data-product-request-image-alt="{{ $item['name'] }}">
                        <img src="{{ $item['url'] }}" alt="{{ $item['name'] }}">
                    </a>
                    @if($removableName !== '')
                        <button type="button" class="product-request-upload-remove" data-existing-remove title="{{ __('product_requests.uploads.remove_image') }}">&times;</button>
                    @endif
                </div>
            @endforeach
        @else
            @foreach($existingAttachments as $attachment)
                <div class="product-request-upload-preview-item product-request-upload-existing" data-existing-preview="true" data-attachment-id="{{ $attachment->id }}">
                    <a href="{{ route('product-requests.attachments.show', [$productRequest, $attachment]) }}" data-product-request-image-preview data-product-request-image-alt="{{ $attachment->original_name }}">
                        <img loading="lazy" decoding="async" src="{{ route('product-requests.attachments.show', [$productRequest, $attachment]) }}?w=240" alt="{{ $attachment->original_name }}">
                    </a>
                    @if($removableName !== '')
                        <button type="button" class="product-request-upload-remove" data-existing-remove title="{{ __('product_requests.uploads.remove_image') }}">&times;</button>
                    @endif
                </div>
            @endforeach
        @endif
    </div>
</div>
@error($inputErrorKey)<span class="help-block text-red">{{ $message }}</span>@enderror
@error($inputErrorKey.'.*')<span class="help-block text-red">{{ $message }}</span>@enderror
