@php
    $rawAvatar = $user->getRawOriginal('avatar');
    $avatarName = trim((string) ($user->name ?: $user->username));
    $avatarInitial = $avatarName === ''
        ? '?'
        : (function_exists('mb_substr') ? mb_substr($avatarName, 0, 1, 'UTF-8') : substr($avatarName, 0, 1));
    $avatarClass = $avatarClass ?? 'user-image';
@endphp

@if (filled($rawAvatar))
    <img src="{{ $user->avatar }}" class="{{ $avatarClass }}" alt="{{ __('admin.user_image') }}">
@else
    <span class="{{ $avatarClass }} admin-initial-avatar" role="img" aria-label="{{ __('admin.user_image') }}">{{ $avatarInitial }}</span>
@endif
