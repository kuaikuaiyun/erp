@php
    $notifications = [];

    foreach (['success', 'error', 'warning', 'info'] as $defaultType) {
        $flash = session()->get($defaultType);

        if ($flash instanceof \Illuminate\Support\MessageBag) {
            $type = (string) \Illuminate\Support\Arr::get($flash->get('type'), 0, $defaultType);
            $title = (string) \Illuminate\Support\Arr::get($flash->get('title'), 0, '');
            $message = (string) \Illuminate\Support\Arr::get($flash->get('message'), 0, '');
        } elseif (is_array($flash)) {
            $type = (string) ($flash['type'] ?? $defaultType);
            $title = (string) ($flash['title'] ?? '');
            $message = (string) ($flash['message'] ?? '');
        } else {
            continue;
        }

        $notifications[] = [
            'type' => in_array($type, ['success', 'error', 'warning', 'info'], true) ? $type : $defaultType,
            'title' => trim(strip_tags($title)),
            'message' => trim(strip_tags($message)),
            'options' => [],
        ];
    }
@endphp

@if ($notifications !== [])
    <script>
        (function (notifications) {
            if (window.ErpAdminNotifier) {
                window.ErpAdminNotifier.notify(notifications);
            }
        })({!! json_encode($notifications, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!});
    </script>
@endif


