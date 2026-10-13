@if (Session::has('toastr'))
    @php
        $toastr = Session::pull('toastr');

        if ($toastr instanceof \Illuminate\Support\MessageBag) {
            $type = (string) \Illuminate\Support\Arr::get($toastr->get('type'), 0, 'success');
            $message = (string) \Illuminate\Support\Arr::get($toastr->get('message'), 0, '');
            $options = \Illuminate\Support\Arr::get($toastr->get('options'), 0, []);
        } else {
            $type = is_array($toastr) ? (string) ($toastr['type'] ?? 'success') : 'success';
            $message = is_array($toastr) ? (string) ($toastr['message'] ?? '') : '';
            $options = is_array($toastr) && is_array($toastr['options'] ?? null) ? $toastr['options'] : [];
        }

        $notification = [
            'type' => in_array($type, ['success', 'error', 'warning', 'info'], true) ? $type : 'info',
            'title' => '',
            'message' => trim(strip_tags($message)),
            'options' => is_array($options) ? $options : [],
        ];
    @endphp
    <script>
        (function (notification) {
            if (window.ErpAdminNotifier) {
                window.ErpAdminNotifier.notify(notification);
            }
        })({!! json_encode($notification, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!});
    </script>
@endif


